<?php

namespace App\Domain\Prospecting\Services;

use App\Domain\Prospecting\Enums\ProspectSourceKind;
use App\Domain\Prospecting\Enums\ProspectSourceStatus;
use App\Domain\Prospecting\Enums\SourceRunState;
use App\Domain\Prospecting\Exceptions\SourceIdempotencyConflict;
use App\Domain\Prospecting\Exceptions\SourcePolicyViolation;
use App\Domain\Prospecting\Exceptions\SourceRunConflict;
use App\Domain\Prospecting\Support\SourceConfigurationValidator;
use App\Domain\Prospecting\Support\SourcePolicyEnforcer;
use App\Models\ProspectSource;
use App\Models\ProspectSourceVersion;
use App\Models\SourceRun;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

final class StartSourceRun
{
    public function __construct(
        private readonly SourcePolicyEnforcer $policyEnforcer,
        private readonly SourceConfigurationValidator $configurationValidator,
    ) {}

    /**
     * Resolve an exact source version, validate run policy/bounds and return
     * the existing run for a matching idempotency fingerprint.
     *
     * @param  array<string,mixed>  $input
     */
    public function handle(
        Workspace $workspace,
        ProspectSource $source,
        ProspectSourceVersion|string|null $version,
        string $idempotencyKey,
        array $input = [],
        ?User $actor = null,
        string $initiatorType = 'user',
        ?int $personImportId = null,
        ?int $recipientImportId = null,
        ?string $requestFingerprint = null,
    ): SourceRun {
        $key = trim($idempotencyKey);
        if ($key === '' || mb_strlen($key) > (int) config('prospecting.limits.idempotency_key_max_length', 160)) {
            throw new SourceRunConflict('A bounded idempotency key is required.');
        }

        return DB::transaction(function () use ($workspace, $source, $version, $key, $input, $actor, $initiatorType, $personImportId, $recipientImportId, $requestFingerprint): SourceRun {
            $lockedSource = ProspectSource::query()
                ->where('workspace_id', $workspace->id)
                ->whereKey($source->id)
                ->lockForUpdate()
                ->firstOrFail();
            $kind = ProspectSourceKind::from((string) $lockedSource->getRawOriginal('kind'));
            if (! $kind->isEnabledInS1()) {
                throw new SourceRunConflict('This prospect source kind is not enabled in S1.');
            }
            $status = ProspectSourceStatus::from((string) $lockedSource->getRawOriginal('status'));
            if (! $status->acceptsRuns()) {
                throw new SourceRunConflict('Archived or paused sources cannot start new runs.');
            }

            $resolvedVersion = $this->version($lockedSource, $version);
            $this->policyEnforcer->assertRunnable($resolvedVersion);
            if (($resolvedVersion->policy_snapshot['provenance_class'] ?? 'native') !== 'native') {
                $this->configurationValidator->assertProviderPolicyReady((array) $resolvedVersion->policy_snapshot);
            }
            $fingerprint = $requestFingerprint ?? hash('sha256', json_encode([
                'source_id' => $lockedSource->id,
                'version_id' => $resolvedVersion->id,
                'input' => $input,
                'person_import_id' => $personImportId,
                'recipient_import_id' => $recipientImportId,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            $existing = SourceRun::query()
                ->where('workspace_id', $workspace->id)
                ->where('idempotency_key', $key)
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                if (! hash_equals((string) $existing->request_fingerprint, $fingerprint)) {
                    throw new SourceIdempotencyConflict;
                }
                if ((string) $existing->prospect_source_id !== (string) $lockedSource->id) {
                    throw new SourceIdempotencyConflict;
                }

                return $existing;
            }

            $run = SourceRun::query()->create([
                'workspace_id' => $workspace->id,
                'prospect_source_id' => $lockedSource->id,
                'prospect_source_version_id' => $resolvedVersion->id,
                'state' => SourceRunState::Draft,
                'initiator_type' => $initiatorType,
                'initiated_by_user_id' => $actor?->id,
                'initiator_display_name' => $actor?->name,
                'idempotency_key' => $key,
                'request_fingerprint' => $fingerprint,
                'person_import_id' => $personImportId,
                'recipient_import_id' => $recipientImportId,
                'input_reference' => $this->boundedInput($input),
                'checkpoint' => null,
            ]);
            $lockedSource->forceFill(['last_run_id' => $run->id])->save();

            return $run;
        }, 3);
    }

    private function version(ProspectSource $source, ProspectSourceVersion|string|null $version): ProspectSourceVersion
    {
        if ($version instanceof ProspectSourceVersion) {
            if ((string) $version->prospect_source_id !== (string) $source->id || (int) $version->workspace_id !== (int) $source->workspace_id) {
                throw new SourcePolicyViolation('The source version does not belong to this workspace source.', 'source_version_scope');
            }

            return $version->fresh();
        }

        $query = $source->versions();
        if ($version !== null) {
            $query->whereKey($version);
        } else {
            $query->whereKey($source->current_version_id);
        }

        return $query->firstOrFail();
    }

    /**
     * @param  array<array-key, mixed>  $input
     * @return array<string, scalar|null>
     */
    private function boundedInput(array $input): array
    {
        if (count($input) > (int) config('prospecting.limits.observed_values_max_fields', 40)) {
            throw new SourceRunConflict('Source run input metadata is too large.');
        }
        $result = [];
        foreach ($input as $key => $value) {
            if (! is_string($key) || mb_strlen($key) > 80) {
                throw new SourceRunConflict('Source run input metadata contains an invalid key.');
            }
            if (is_scalar($value) || $value === null) {
                $text = $value;
                if (is_string($text) && mb_strlen($text) > 2048) {
                    throw new SourceRunConflict('Source run input metadata is too large.');
                }
                $result[$key] = $text;
            }
        }

        return $result;
    }
}
