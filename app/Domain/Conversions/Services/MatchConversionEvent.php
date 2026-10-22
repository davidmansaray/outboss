<?php

namespace App\Domain\Conversions\Services;

use App\Domain\Conversions\DTOs\ConversionMatchResult;
use App\Domain\Conversions\Enums\ConversionEventState;
use App\Domain\Conversions\Enums\ConversionMatchResult as MatchResultEnum;
use App\Domain\Conversions\Support\ConversionServiceSupport;
use App\Models\Account;
use App\Models\ConversionEvent;
use App\Models\ConversionEventMatch;
use App\Models\ConversionEventReceipt;
use App\Models\ExternalIdentity;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/** Deterministic, Account-only exact matching for S2 conversion events. */
final class MatchConversionEvent
{
    use ConversionServiceSupport;

    public function handle(
        ConversionEvent $event,
        ?User $actor = null,
        ?int $forcedAccountId = null,
        ?string $reason = null,
    ): ConversionMatchResult {
        $this->assertMember($event->workspace, $actor);

        return DB::transaction(function () use ($event, $actor, $forcedAccountId, $reason): ConversionMatchResult {
            $locked = ConversionEvent::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            $this->assertMember($locked->workspace, $actor);
            if ($forcedAccountId === null && $locked->getRawOriginal('state') !== ConversionEventState::Received->value) {
                $latest = $locked->matches()->orderByDesc('attempt_number')->first();
                if ($latest !== null) {
                    return new ConversionMatchResult($locked->fresh(), $latest);
                }
            }

            $rawHandles = $locked->getAttribute('identity_handles');
            $handles = is_array($rawHandles) ? $rawHandles : [];
            $accountIds = [];
            $path = 'no_identity';
            $rejected = false;
            $explicitIds = [];

            foreach ($handles as $handle) {
                if (! is_array($handle)) {
                    continue;
                }
                $type = (string) ($handle['type'] ?? '');
                if ($type === 'account_id') {
                    $id = (int) ($handle['account_id'] ?? 0);
                    if ($id <= 0) {
                        continue;
                    }
                    $account = Account::query()->whereKey($id)->first();
                    if ($account !== null && (int) $account->workspace_id !== (int) $locked->workspace_id) {
                        $rejected = true;
                        $path = 'cross_workspace_account_id';

                        continue;
                    }
                    if ($account !== null) {
                        $explicitIds[] = (int) $account->id;
                        $accountIds[] = (int) $account->id;
                        $path = 'exact_account_id';
                    }
                }
                if ($type === 'external_identity') {
                    $matches = ExternalIdentity::query()
                        ->where('workspace_id', $locked->workspace_id)
                        ->where('entity_type', 'account')
                        ->where('provider', (string) ($handle['provider'] ?? ''))
                        ->where('identity_type', (string) ($handle['identity_type'] ?? ''))
                        ->where('value_hash', (string) ($handle['value_hash'] ?? ''))
                        ->where('state', 'active')
                        ->whereNotNull('account_id')
                        ->pluck('account_id')
                        ->map(static fn (mixed $id): int => (int) $id)
                        ->all();
                    if ($matches !== []) {
                        $accountIds = array_merge($accountIds, $matches);
                        $path = $path === 'no_identity' ? 'exact_external_identity' : $path;
                    }
                }
                if ($type === 'domain') {
                    $domain = (string) ($handle['domain'] ?? '');
                    if ($domain !== '') {
                        $matches = Account::query()
                            ->where('workspace_id', $locked->workspace_id)
                            ->where('domain_normalized', $domain)
                            ->pluck('id')
                            ->map(static fn (mixed $id): int => (int) $id)
                            ->all();
                        if ($matches !== []) {
                            $accountIds = array_merge($accountIds, $matches);
                            $path = $path === 'no_identity' ? 'exact_domain' : $path;
                        }
                    }
                }
            }

            if ($forcedAccountId !== null) {
                $forced = Account::query()->whereKey($forcedAccountId)->first();
                if ($forced === null || (int) $forced->workspace_id !== (int) $locked->workspace_id) {
                    throw new RuntimeException('conversion_account_not_in_workspace');
                }
                $accountIds = [(int) $forced->id];
                $path = 'manual_account_id';
                $rejected = false;
            }

            $accountIds = array_values(array_unique(array_map('intval', $accountIds)));
            if ($rejected) {
                $result = MatchResultEnum::Rejected;
                $state = ConversionEventState::Rejected;
                $matchedAccountId = null;
            } elseif (count($accountIds) === 1 && ($explicitIds === [] || count(array_unique($explicitIds)) === 1)) {
                $result = MatchResultEnum::Matched;
                $state = ConversionEventState::Matched;
                $matchedAccountId = $accountIds[0];
            } elseif (count($accountIds) > 1) {
                $result = MatchResultEnum::Ambiguous;
                $state = ConversionEventState::Ambiguous;
                $matchedAccountId = null;
                $path = 'conflicting_exact_identities';
            } else {
                $result = MatchResultEnum::Unmatched;
                $state = ConversionEventState::Unmatched;
                $matchedAccountId = null;
                $path = $path === 'no_identity' ? 'no_exact_match' : $path;
            }

            $attempt = ((int) $locked->matches()->max('attempt_number')) + 1;
            $match = ConversionEventMatch::query()->create([
                'id' => (string) Str::uuid(),
                'workspace_id' => $locked->workspace_id,
                'conversion_event_id' => $locked->id,
                'attempt_number' => $attempt,
                'supplied_identity_hashes' => array_values(array_filter(array_map(
                    static fn (mixed $handle): ?string => is_array($handle) && isset($handle['value_hash']) ? (string) $handle['value_hash'] : null,
                    $handles,
                ))),
                'identity_handles' => $handles,
                'resolution_path' => $path,
                'candidate_count' => count($accountIds),
                'result' => $result,
                'matched_account_id' => $matchedAccountId,
                'actor_user_id' => $actor?->id,
                'actor_display_name' => $actor?->name,
                'reason' => $reason,
                'created_at' => now(),
            ]);
            $locked->project([
                'state' => $state,
                'matched_account_id' => $matchedAccountId,
                'safe_rejection_code' => $rejected ? 'cross_workspace_identity' : null,
            ]);

            if ($result === MatchResultEnum::Matched) {
                $receipt = ConversionEventReceipt::query()->whereKey($locked->receipt_id)->first();
                $receipt?->project([
                    'unmatched_identity_envelope' => null,
                    'identity_redacted_at' => now(),
                ]);
            }

            return new ConversionMatchResult($locked->fresh(), $match);
        }, 3);
    }

    public function match(ConversionEvent $event, ?User $actor = null, ?int $forcedAccountId = null, ?string $reason = null): ConversionMatchResult
    {
        return $this->handle($event, $actor, $forcedAccountId, $reason);
    }
}
