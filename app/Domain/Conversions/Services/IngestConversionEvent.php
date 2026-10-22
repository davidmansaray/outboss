<?php

namespace App\Domain\Conversions\Services;

use App\Domain\Conversions\DTOs\ConversionIntakeResult;
use App\Domain\Conversions\Enums\ConversionDefinitionStatus;
use App\Domain\Conversions\Enums\ConversionSourceType;
use App\Domain\Conversions\Enums\ConversionSubjectType;
use App\Domain\Conversions\Enums\ConversionVerificationState;
use App\Domain\Conversions\Exceptions\ConversionIdempotencyConflict;
use App\Domain\Conversions\Support\CanonicalConversionPayload;
use App\Domain\Conversions\Support\ConversionEventValidator;
use App\Domain\Conversions\Support\ConversionIdentity;
use App\Domain\Conversions\Support\ConversionServiceSupport;
use App\Domain\Gtm\Services\EvaluateConversionFulfilment;
use App\Domain\Objectives\Services\DispatchObjectiveAttribution;
use App\Models\ConversionDefinition;
use App\Models\ConversionDefinitionVersion;
use App\Models\ConversionEvent;
use App\Models\ConversionEventReceipt;
use App\Models\ConversionSourceConnection;
use App\Models\ConversionSourceConnectionVersion;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Shared manual/API/signed-webhook conversion intake pipeline. */
final class IngestConversionEvent
{
    use ConversionServiceSupport;

    public function __construct(
        private readonly ConversionEventValidator $eventValidator,
        private readonly VerifyConversionWebhook $webhookVerifier,
        private readonly MatchConversionEvent $matcher,
        private readonly EvaluateConversionFulfilment $fulfilment,
        private readonly DispatchObjectiveAttribution $attribution,
    ) {}

    /**
     * @param  array<string,mixed>  $payload
     */
    public function handle(
        Workspace $workspace,
        ConversionDefinition|ConversionDefinitionVersion|string $definition,
        array $payload,
        string|ConversionSourceType $sourceType = 'manual',
        ?ConversionSourceConnection $connection = null,
        ?User $actor = null,
        ?string $idempotencyKey = null,
        ?string $rawBody = null,
        ?string $signature = null,
    ): ConversionIntakeResult {
        $source = $sourceType instanceof ConversionSourceType ? $sourceType->value : strtolower(trim($sourceType));
        $sourceEnum = ConversionSourceType::tryFrom($source);
        if ($sourceEnum === null) {
            throw ValidationException::withMessages(['source' => 'The conversion source type is invalid.']);
        }
        $this->assertMember($workspace, $actor);
        $version = $this->resolveVersion($workspace, $definition);
        $definitionModel = $version->definition()->firstOrFail();
        if ($definitionModel->getRawOriginal('status') !== ConversionDefinitionStatus::Active->value) {
            throw ValidationException::withMessages(['definition' => 'The conversion definition is not active.']);
        }
        if ($version->subject_type !== ConversionSubjectType::Account->value) {
            throw ValidationException::withMessages(['definition' => 'Only Account conversion events are supported in S2.']);
        }
        if (! in_array($source, (array) $version->allowed_sources, true)) {
            throw ValidationException::withMessages(['source' => 'This source is not allowed by the conversion definition.']);
        }
        if ($sourceEnum === ConversionSourceType::Manual && $actor === null) {
            throw ValidationException::withMessages(['actor' => 'Manual conversion intake requires an authenticated actor.']);
        }
        if ($sourceEnum === ConversionSourceType::Api && $actor === null && $connection === null) {
            throw ValidationException::withMessages(['actor' => 'API conversion intake requires an authenticated actor.']);
        }

        $sourceVerification = $this->verifySource(
            $workspace,
            $sourceEnum,
            $connection,
            $payload,
            $rawBody,
            $signature,
            $version,
        );
        $verificationState = $sourceVerification['state'];
        $connectionVersion = $sourceVerification['version'];
        $rawBody ??= CanonicalConversionPayload::json($payload);
        $this->assertBodyLimit($rawBody, $connectionVersion);
        $normalised = $this->eventValidator->validate($version, $payload);
        $this->assertAllowedIdentities($version, $normalised['identities']);
        $payloadHash = hash('sha256', $rawBody);
        $dedupeKey = $this->dedupeKey($version, $normalised, $idempotencyKey, $payloadHash);
        $sourceNamespace = $this->sourceNamespace($sourceEnum, $connection);
        $dedupeHash = hash('sha256', $version->conversion_definition_id.'|'.$dedupeKey);
        $identityEnvelope = $normalised['identities'] === [] ? null : $normalised['identities'];
        $identityExpiresAt = $identityEnvelope === null
            ? null
            : $this->identityExpiry($version);
        $receiptId = (string) Str::uuid();
        $eventId = (string) Str::uuid();

        $result = DB::transaction(function () use (
            $workspace,
            $version,
            $sourceEnum,
            $connection,
            $actor,
            $normalised,
            $verificationState,
            $connectionVersion,
            $payloadHash,
            $dedupeKey,
            $sourceNamespace,
            $dedupeHash,
            $identityEnvelope,
            $identityExpiresAt,
            $receiptId,
            $eventId,
        ): ConversionIntakeResult {
            // Serialising on the workspace keeps the read-check-insert boundary
            // deterministic on both SQLite and MySQL.
            Workspace::query()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            $existing = ConversionEventReceipt::query()
                ->where('workspace_id', $workspace->id)
                ->where('source_namespace', $sourceNamespace)
                ->where('dedupe_hash', $dedupeHash)
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                if (! hash_equals((string) $existing->payload_hash, $payloadHash)) {
                    throw new ConversionIdempotencyConflict;
                }

                return new ConversionIntakeResult(
                    receipt: $existing->fresh(['event']),
                    event: $existing->event()->first(),
                    duplicate: true,
                );
            }

            $receipt = ConversionEventReceipt::query()->create([
                'id' => $receiptId,
                'workspace_id' => $workspace->id,
                'connection_id' => $connection?->id,
                'connection_version_id' => $connectionVersion?->id,
                'manual_actor_id' => $actor?->id,
                'source_namespace' => $sourceNamespace,
                'source_type' => $sourceEnum,
                'external_event_id' => $normalised['external_event_id'],
                'dedupe_key' => mb_strlen($dedupeKey) > 255 ? substr($dedupeKey, 0, 255) : $dedupeKey,
                'dedupe_hash' => $dedupeHash,
                'payload_hash' => $payloadHash,
                'schema_version' => $version->schema_version,
                'received_at' => now(),
                'occurred_at' => $normalised['occurred_at'],
                'verification_state' => $verificationState,
                'intake_state' => 'accepted',
                'safe_rejection_code' => null,
                'unmatched_identity_envelope' => $identityEnvelope,
                'identity_expires_at' => $identityExpiresAt,
                'identity_redacted_at' => null,
                'conversion_event_id' => null,
                'actor_display_name' => $actor?->name,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $event = ConversionEvent::query()->create([
                'id' => $eventId,
                'workspace_id' => $workspace->id,
                'conversion_definition_id' => $version->conversion_definition_id,
                'conversion_definition_version_id' => $version->id,
                'receipt_id' => $receipt->id,
                'source_namespace' => $sourceNamespace,
                'source_type' => $sourceEnum,
                'external_event_id' => $normalised['external_event_id'],
                'properties' => $normalised['properties'],
                'identity_handles' => array_map(
                    static fn (array $identity): array => ConversionIdentity::safeHandle($identity),
                    $normalised['identities'],
                ),
                'occurred_at' => $normalised['occurred_at'],
                'received_at' => now(),
                'value' => $normalised['value'],
                'currency' => $normalised['currency'],
                'verification_state' => $verificationState,
                'state' => 'received',
                'matched_account_id' => null,
                'lineage_root_id' => null,
                'corrects_event_id' => null,
                'reverses_event_id' => null,
                'safe_rejection_code' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $event->project(['lineage_root_id' => $event->id]);
            $receipt->project(['conversion_event_id' => $event->id]);
            $matched = $this->matcher->handle($event, $actor);

            return new ConversionIntakeResult(
                receipt: $receipt->fresh(['event']),
                event: $matched->event->fresh(),
                duplicate: false,
            );
        }, 3);

        if ($result->event !== null) {
            $this->attribution->afterCommit(
                (int) $result->event->workspace_id,
                (string) $result->event->id,
            );
            $this->fulfilment->handle($result->event);
        }

        return $result;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public function ingest(
        Workspace $workspace,
        ConversionDefinition|ConversionDefinitionVersion|string $definition,
        array $payload,
        string|ConversionSourceType $sourceType = 'manual',
        ?ConversionSourceConnection $connection = null,
        ?User $actor = null,
        ?string $idempotencyKey = null,
        ?string $rawBody = null,
        ?string $signature = null,
    ): ConversionIntakeResult {
        return $this->handle($workspace, $definition, $payload, $sourceType, $connection, $actor, $idempotencyKey, $rawBody, $signature);
    }

    private function resolveVersion(Workspace $workspace, ConversionDefinition|ConversionDefinitionVersion|string $definition): ConversionDefinitionVersion
    {
        if ($definition instanceof ConversionDefinitionVersion) {
            $version = ConversionDefinitionVersion::query()
                ->whereKey($definition->id)
                ->where('workspace_id', $workspace->id)
                ->firstOrFail();

            return $version;
        }
        if ($definition instanceof ConversionDefinition) {
            if ((int) $definition->workspace_id !== (int) $workspace->id) {
                throw ValidationException::withMessages(['definition' => 'Choose a definition from this workspace.']);
            }

            return ConversionDefinitionVersion::query()
                ->where('workspace_id', $workspace->id)
                ->where('conversion_definition_id', $definition->id)
                ->whereKey($definition->current_version_id)
                ->firstOrFail();
        }
        $exactVersion = ConversionDefinitionVersion::query()
            ->where('workspace_id', $workspace->id)
            ->whereKey($definition)
            ->first();
        if ($exactVersion !== null) {
            return $exactVersion;
        }
        $query = ConversionDefinition::query()->where('workspace_id', $workspace->id);
        $aggregate = $query->where(function ($builder) use ($definition): void {
            $builder->whereKey($definition)->orWhere('event_key', $definition);
        })->firstOrFail();

        return ConversionDefinitionVersion::query()
            ->where('workspace_id', $workspace->id)
            ->where('conversion_definition_id', $aggregate->id)
            ->whereKey($aggregate->current_version_id)
            ->firstOrFail();
    }

    /** @param array<string,mixed> $normalised */
    private function dedupeKey(ConversionDefinitionVersion $version, array $normalised, ?string $idempotencyKey, string $payloadHash): string
    {
        $strategy = (string) $version->dedupe_strategy;
        $external = $normalised['external_event_id'];
        $candidate = match ($strategy) {
            'external_id', 'source_and_external_id' => $external ?? $idempotencyKey,
            'idempotency_key' => $idempotencyKey ?? $external,
            'payload_hash' => $payloadHash,
            default => $external ?? $idempotencyKey,
        };
        if (! is_string($candidate) || trim($candidate) === '') {
            $candidate = $payloadHash;
        }
        if (mb_strlen($candidate) > 512) {
            throw ValidationException::withMessages(['dedupe_key' => 'The deduplication key is too long.']);
        }

        return trim($candidate);
    }

    private function sourceNamespace(ConversionSourceType $source, ?ConversionSourceConnection $connection): string
    {
        if ($source === ConversionSourceType::Webhook || $source === ConversionSourceType::Api) {
            if ($connection !== null) {
                return 'connection:'.(string) $connection->id;
            }

            return $source->value;
        }

        return $source->value;
    }

    private function identityExpiry(ConversionDefinitionVersion $version): CarbonImmutable
    {
        $policyAttribute = $version->getAttribute('retention_policy');
        $policy = is_array($policyAttribute) ? $policyAttribute : [];
        $days = (int) ($policy['unmatched_identity_days'] ?? 30);
        $days = max(0, min(30, $days));

        return CarbonImmutable::now()->addDays($days);
    }

    /** @param list<array<string,mixed>> $identities */
    private function assertAllowedIdentities(ConversionDefinitionVersion $version, array $identities): void
    {
        $allowed = array_map('strval', (array) $version->allowed_identity_keys);
        foreach ($identities as $identity) {
            if (! in_array((string) ($identity['type'] ?? ''), $allowed, true)) {
                throw ValidationException::withMessages(['identities' => 'An identity type is not allowed by this definition.']);
            }
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{state: ConversionVerificationState, version: ConversionSourceConnectionVersion|null}
     */
    private function verifySource(
        Workspace $workspace,
        ConversionSourceType $source,
        ?ConversionSourceConnection $connection,
        array $payload,
        ?string $rawBody,
        ?string $signature,
        ConversionDefinitionVersion $version,
    ): array {
        if ($source === ConversionSourceType::Webhook) {
            if ($connection === null || (int) $connection->workspace_id !== (int) $workspace->id || $connection->getRawOriginal('kind') !== 'webhook' || $signature === null) {
                throw ValidationException::withMessages(['source' => 'The webhook could not be verified.']);
            }
            $body = $rawBody ?? CanonicalConversionPayload::json($payload);
            $verified = $this->webhookVerifier->handle($connection, $body, $signature);
            if (! $verified->verified) {
                throw ValidationException::withMessages(['source' => 'The webhook could not be verified.']);
            }
            $allowedIds = (array) $verified->version?->getAttribute('allowed_conversion_definition_ids');
            if ($allowedIds !== [] && ! in_array((string) $version->conversion_definition_id, array_map('strval', $allowedIds), true)) {
                throw ValidationException::withMessages(['source' => 'The webhook could not be verified.']);
            }
            $allowedSources = (array) $verified->version?->getAttribute('allowed_source_types');
            if (! in_array($source->value, $allowedSources, true)) {
                throw ValidationException::withMessages(['source' => 'The webhook could not be verified.']);
            }

            return [
                'state' => ConversionVerificationState::Verified,
                'version' => $verified->version,
            ];
        }
        $connectionVersion = null;
        if ($connection !== null) {
            if ((int) $connection->workspace_id !== (int) $workspace->id || $connection->getRawOriginal('kind') !== $source->value) {
                throw ValidationException::withMessages(['source' => 'The source connection is invalid.']);
            }
            if ($connection->getRawOriginal('status') !== 'active') {
                throw ValidationException::withMessages(['source' => 'The source connection is disabled.']);
            }
            $connectionVersion = $connection->currentVersion()->first();
            if ($connectionVersion !== null) {
                $allowedIds = (array) $connectionVersion->getAttribute('allowed_conversion_definition_ids');
                if ($allowedIds !== [] && ! in_array((string) $version->conversion_definition_id, array_map('strval', $allowedIds), true)) {
                    throw ValidationException::withMessages(['source' => 'The source connection is invalid.']);
                }
            }
        }
        if ($source === ConversionSourceType::Api && $connection === null) {
            // A transport-authenticated actor is sufficient for first-party
            // API intake; a webhook always requires its HMAC connection.
            return [
                'state' => ConversionVerificationState::Verified,
                'version' => null,
            ];
        }

        return [
            'state' => $source === ConversionSourceType::Manual || $source === ConversionSourceType::EmailReply
                ? ConversionVerificationState::NotRequired
                : ConversionVerificationState::Verified,
            'version' => $connectionVersion,
        ];
    }

    private function assertBodyLimit(string $rawBody, ?ConversionSourceConnectionVersion $connectionVersion): void
    {
        $limit = $connectionVersion?->getAttribute('payload_limit_bytes') ?? 262144;
        if (strlen($rawBody) > (int) $limit) {
            throw ValidationException::withMessages(['payload' => 'The conversion payload exceeds the source limit.']);
        }
    }
}
