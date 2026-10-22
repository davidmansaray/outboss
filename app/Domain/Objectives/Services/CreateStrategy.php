<?php

namespace App\Domain\Objectives\Services;

use App\Domain\Objectives\Enums\StrategyStatus;
use App\Domain\Objectives\Exceptions\DraftMessageValidationException;
use App\Domain\Objectives\Support\CanonicalObjectivePayload;
use App\Domain\Objectives\Support\DeterministicMessageTemplate;
use App\Domain\Objectives\Support\MessageEvidenceContract;
use App\Models\ObjectiveVersion;
use App\Models\OfferVersion;
use App\Models\Strategy;
use App\Models\StrategyVersion;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CreateStrategy
{
    public function __construct(
        private readonly MessageEvidenceContract $messageEvidence,
        private readonly DeterministicMessageTemplate $templates,
    ) {}

    /** @param array<string, mixed> $input */
    public function handle(Workspace $workspace, User $actor, array $input): Strategy
    {
        if (! $workspace->isOwner($actor)) {
            throw new AuthorizationException('Only the workspace owner can create Strategies.');
        }

        return DB::transaction(function () use ($workspace, $actor, $input): Strategy {
            $payload = $this->validatedPayload((int) $workspace->id, $input);
            $strategy = Strategy::query()->create([
                'workspace_id' => $workspace->id,
                'status' => StrategyStatus::Draft,
                'lock_version' => 1,
                'created_by_user_id' => $actor->id,
                'updated_by_user_id' => $actor->id,
            ]);
            $version = StrategyVersion::query()->create([
                'workspace_id' => $workspace->id,
                'strategy_id' => $strategy->id,
                'version' => 1,
                ...$payload,
                'content_hash' => CanonicalObjectivePayload::hash($payload),
                'created_by_user_id' => $actor->id,
            ]);
            $strategy->forceFill(['current_version_id' => $version->id])->save();

            return $strategy->fresh('currentVersion');
        }, 3);
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function validatedPayload(int $workspaceId, array $input): array
    {
        $objectiveVersion = ObjectiveVersion::query()->where('workspace_id', $workspaceId)
            ->whereKey($input['objective_version_id'] ?? null)->with('objective')->firstOrFail();
        if ((string) $objectiveVersion->objective->current_version_id !== (string) $objectiveVersion->id) {
            throw ValidationException::withMessages(['objective_version_id' => 'Use the Objective current Version.']);
        }
        if ((string) ($input['audience_snapshot_id'] ?? '') !== (string) $objectiveVersion->audience_snapshot_id
            || (string) ($input['offer_version_id'] ?? '') !== (string) $objectiveVersion->offer_version_id) {
            throw ValidationException::withMessages(['lineage' => 'Strategy inputs must match the Objective audience and Offer Versions.']);
        }

        $name = $this->requiredString($input, 'name', 160);
        $positioning = $this->requiredOutboundString($input, 'positioning', 240, 30);
        $problemFrame = $this->requiredOutboundString($input, 'problem_frame', 240, 30);
        $proofSummary = $this->requiredOutboundString($input, 'proof_summary', 240, 30);
        $ctaKind = $this->requiredOutboundString($input, 'cta_kind', 48, 4);
        $ctaText = $this->requiredOutboundString($input, 'cta_text', 160, 20);
        $combined = implode(' ', [$positioning, $problemFrame, $proofSummary, $ctaKind, $ctaText]);
        if (strlen($combined) > 640 || $this->wordCount($combined) > 60) {
            throw ValidationException::withMessages([
                'strategy' => 'The combined outbound Strategy text exceeds its 640-byte or 60-word message budget.',
            ]);
        }
        $offerVersion = OfferVersion::query()->where('workspace_id', $workspaceId)
            ->whereKey($objectiveVersion->offer_version_id)->firstOrFail();
        $offerAction = $offerVersion->payload['cta']['action'] ?? null;
        if (! is_string($offerAction) || $offerAction === '' || $ctaKind !== $offerAction) {
            throw ValidationException::withMessages(['cta_kind' => 'The Strategy CTA must match the approved Offer action.']);
        }

        $claimIds = $input['proof_claim_ids'] ?? null;
        if (! is_array($claimIds) || ! array_is_list($claimIds) || $claimIds === []
            || array_filter($claimIds, static fn (mixed $value): bool => ! is_string($value) || ! Str::isUuid($value)) !== []
            || count(array_unique($claimIds)) !== count($claimIds)) {
            throw ValidationException::withMessages([
                'proof_claim_ids' => 'Choose a non-empty, duplicate-free list of exact supported snapshot Claim IDs.',
            ]);
        }
        sort($claimIds, SORT_STRING);
        $availableClaims = $objectiveVersion->audienceSnapshot()->firstOrFail()->members()
            ->where('result', 'included')->get()->flatMap(
                fn ($member) => collect($member->evidence_manifest)->pluck('claim_id'),
            )->filter()->unique()->values()->all();
        if (array_diff($claimIds, $availableClaims) !== []) {
            throw ValidationException::withMessages(['proof_claim_ids' => 'Every proof Claim must be present in the frozen audience evidence manifest.']);
        }

        $personalisation = is_array($input['personalisation_contract'] ?? null) ? $input['personalisation_contract'] : [];
        if (array_diff(array_keys($personalisation), ['allowed_placeholders']) !== []
            || ! is_array($personalisation['allowed_placeholders'] ?? null)) {
            throw ValidationException::withMessages(['personalisation_contract' => 'Provide only the supported placeholder list.']);
        }
        $allowedPlaceholders = $personalisation['allowed_placeholders'];
        if (! array_is_list($allowedPlaceholders)
            || array_filter($allowedPlaceholders, static fn (mixed $value): bool => ! is_string($value)) !== []
            || count(array_unique($allowedPlaceholders)) !== count($allowedPlaceholders)) {
            throw ValidationException::withMessages(['personalisation_contract' => 'Provide an exact duplicate-free placeholder list.']);
        }
        sort($allowedPlaceholders, SORT_STRING);
        if ($allowedPlaceholders !== ['company', 'first_name']) {
            throw ValidationException::withMessages(['personalisation_contract' => 'Both and only company and first_name are required by the deterministic template.']);
        }
        $generation = is_array($input['message_generation_contract'] ?? null) ? $input['message_generation_contract'] : [];
        if (array_diff(array_keys($generation), ['message_count', 'maximum_words']) !== []
            || ($generation['message_count'] ?? null) !== 1
            || ! is_int($generation['maximum_words'] ?? null)
            || $generation['maximum_words'] < 40
            || $generation['maximum_words'] > 250) {
            throw ValidationException::withMessages(['message_generation_contract' => 'Use one message with a 40 to 250 word limit.']);
        }
        $stops = is_array($input['stop_conditions'] ?? null) ? $input['stop_conditions'] : [];
        if (array_diff(array_keys($stops), ['on_reply', 'on_conversion']) !== []
            || ($stops['on_reply'] ?? null) !== true || ($stops['on_conversion'] ?? null) !== true) {
            throw ValidationException::withMessages(['stop_conditions' => 'Reply and conversion stop conditions are required.']);
        }

        $payload = [
            'objective_version_id' => $objectiveVersion->id,
            'audience_snapshot_id' => $objectiveVersion->audience_snapshot_id,
            'offer_version_id' => $objectiveVersion->offer_version_id,
            'name' => $name,
            'positioning' => $positioning,
            'problem_frame' => $problemFrame,
            'proof_summary' => $proofSummary,
            'cta_kind' => $ctaKind,
            'cta_text' => $ctaText,
            'proof_claim_ids' => $claimIds,
            'personalisation_contract' => ['allowed_placeholders' => $allowedPlaceholders],
            'message_generation_contract' => [
                'message_count' => 1,
                'maximum_words' => $generation['maximum_words'],
            ],
            'stop_conditions' => $stops,
            'channel' => 'email',
            'sequence_contract' => 'one_new_thread_v1',
            'schema_version' => 'strategy_v1',
        ];

        try {
            $this->templates->strategyContract(new StrategyVersion($payload));
        } catch (DraftMessageValidationException $exception) {
            throw ValidationException::withMessages(['strategy' => $exception->getMessage()]);
        }

        return $payload;
    }

    /** @param array<string, mixed> $input */
    private function requiredString(array $input, string $key, int $maximum): string
    {
        $value = trim((string) ($input[$key] ?? ''));
        if ($value === '' || mb_strlen($value) > $maximum) {
            throw ValidationException::withMessages([$key => "{$key} is required and must be no more than {$maximum} characters."]);
        }

        return $value;
    }

    /** @param array<string, mixed> $input */
    private function requiredOutboundString(
        array $input,
        string $key,
        int $maximumBytes,
        int $maximumWords,
    ): string {
        $value = $input[$key] ?? null;
        if (! is_string($value) || $value === '' || ! hash_equals($value, trim($value))
            || preg_match('/^\s|\s$/u', $value) !== 0
            || strlen($value) > $maximumBytes || $this->wordCount($value) > $maximumWords
            || preg_match('/\p{Cc}|[\x{0085}\x{2028}\x{2029}]/u', $value) !== 0
            || preg_match('/^(?:to|from|cc|bcc|reply-to|sender|return-path|subject|content-type|content-transfer-encoding|mime-version)\s*:/iu', $value) !== 0
            || str_contains($value, '<') || str_contains($value, '>')
            || str_contains($value, '{') || str_contains($value, '}')
            || preg_match('~(?:(?:https?|ftp)://|(?:mailto|data|javascript):|www\.|\b(?:[a-z0-9-]+\.)+[a-z]{2,63}(?:[/?:#][^\s]*)?|\b[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,})~iu', $value) === 1
            || $this->messageEvidence->prohibited('strategy.'.$key, $value)) {
            throw ValidationException::withMessages([
                $key => "{$key} must be exact safe plain text within {$maximumBytes} bytes and {$maximumWords} words.",
            ]);
        }

        return $value;
    }

    private function wordCount(string $value): int
    {
        return count(preg_split('/\s+/u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }
}
