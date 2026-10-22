<?php

namespace App\Domain\Prospecting\Services;

use App\Domain\Mail\Support\EmailNormalizer;
use App\Domain\People\Support\DomainNormalizer;
use App\Domain\Prospecting\DTOs\IngestionBatch;
use App\Domain\Prospecting\DTOs\IngestionObservation;
use App\Domain\Prospecting\DTOs\IngestionResult;
use App\Domain\Prospecting\Enums\MembershipState;
use App\Domain\Prospecting\Enums\ObservationDisposition;
use App\Domain\Prospecting\Enums\ObservationEntityType;
use App\Domain\Prospecting\Enums\ProspectSourceKind;
use App\Domain\Prospecting\Enums\SourceRunState;
use App\Domain\Prospecting\Exceptions\SourceIdempotencyConflict;
use App\Domain\Prospecting\Exceptions\SourceRunConflict;
use App\Domain\Prospecting\Support\CanonicalSourceConfiguration;
use App\Domain\Prospecting\Support\ObservationRedactor;
use App\Domain\Prospecting\Support\SourcePolicyEnforcer;
use App\Models\Account;
use App\Models\AccountSourceMembership;
use App\Models\ExternalIdentity;
use App\Models\Person;
use App\Models\PersonSourceMembership;
use App\Models\ProspectSource;
use App\Models\ProspectSourceVersion;
use App\Models\SourceObservation;
use App\Models\SourceRun;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ProspectIngestionService
{
    public function __construct(
        private readonly StartSourceRun $startRun,
        private readonly ResolveAccountIdentity $accountIdentity,
        private readonly ResolvePersonIdentity $personIdentity,
        private readonly MergeAccountObservation $mergeAccount,
        private readonly MergePersonObservation $mergePerson,
        private readonly ReconcileSourceRunCounts $reconcileCounts,
        private readonly CreateProspectSource $createSource,
        private readonly SourcePolicyEnforcer $policyEnforcer,
    ) {}

    public function handle(IngestionBatch $batch): IngestionResult
    {
        $source = $this->source($batch->workspace, $batch->source);
        $version = $this->version($source, $batch->sourceVersion);
        if ($batch->observations === []) {
            $run = $this->startRun($batch, $source, $version, []);

            return new IngestionResult($run, counts: $this->counts($run));
        }
        if (count($batch->observations) > (int) config('prospecting.limits.observations_per_batch', 5000)) {
            throw new SourceRunConflict('The source run contains too many observations.');
        }
        $this->assertUniqueObservationKeys($batch->observations);

        $input = [
            'observations' => array_map(static fn (IngestionObservation $observation): array => [
                'entity_type' => $observation->type()->value,
                'input_key' => $observation->inputKey,
                'row_number' => $observation->rowNumber,
                'observation_key' => $observation->observationKey,
                'observed_values' => $observation->observedValues,
            ], $batch->observations),
        ];
        $run = $this->startRun($batch, $source, $version, $input);

        $existingCount = $run->observations()->whereIn('observation_key', array_map(
            static fn (IngestionObservation $observation): string => $observation->stableKey((string) $run->id),
            $batch->observations,
        ))->count();
        $runState = $this->runState($run);
        if ($runState->isTerminal() && $existingCount === count($batch->observations)) {
            return $this->resultFromRun($run, true);
        }
        if (! $runState->canAcceptObservations() && $existingCount !== count($batch->observations)) {
            // A bounded importer may resume a completed chunk against the same
            // logical run. It is safe because observation keys make each row
            // idempotent and the run fingerprint remains fixed.
            $run->forceFill(['state' => SourceRunState::Draft, 'finished_at' => null])->save();
            $run = $run->fresh();
        }

        /** @var list<SourceObservation> $observations */
        $observations = [];
        /** @var array<int|string, Account> $accounts */
        $accounts = [];
        /** @var array<int|string, Person> $people */
        $people = [];
        /** @var array<string, int> $seen */
        $seen = [];
        foreach ($batch->observations as $inputObservation) {
            $key = $inputObservation->stableKey((string) $run->id);
            $existing = SourceObservation::query()
                ->where('source_run_id', $run->id)
                ->where('observation_key', $key)
                ->first();
            if ($existing !== null) {
                $expectedHash = $this->valuesHash($this->sanitize($inputObservation->type(), $inputObservation->observedValues));
                if ($existing->observed_values_hash !== null && ! hash_equals((string) $existing->observed_values_hash, $expectedHash)) {
                    throw new SourceIdempotencyConflict('The observation key was reused for different values.');
                }
                $observations[] = $existing;
                $this->collectCanonical($existing, $accounts, $people);

                continue;
            }

            $result = DB::transaction(function () use ($batch, $source, $version, $run, $inputObservation, &$seen): SourceObservation {
                return $this->ingestObservation($batch, $source, $version, $run, $inputObservation, $seen);
            }, 3);
            $observations[] = $result;
            $this->collectCanonical($result, $accounts, $people);
        }

        $run = $this->reconcileCounts->handle($run);
        $run->forceFill([
            'state' => SourceRunState::Completed,
            'finished_at' => now(),
            'lease_owner' => null,
            'lease_expires_at' => null,
        ])->save();

        return new IngestionResult(
            run: $run->fresh(),
            observations: $observations,
            accounts: array_values($accounts),
            people: array_values($people),
            counts: $this->counts($run),
        );
    }

    /**
     * Incremental adoption helper for the manual/API editors. It returns the
     * canonical record while retaining the editor-style ValidationException
     * for invalid input, duplicate confirmation and suppression.
     *
     * @param  array<string,mixed>  $record
     */
    public function ingestOne(
        Workspace $workspace,
        User $actor,
        array $record,
        string $entityType = 'person',
        ?string $idempotencyKey = null,
        ?ProspectSource $source = null,
        ?string $sourceKey = null,
        ?string $ingress = null,
        ?string $initiatorType = null,
        ?string $origin = null,
    ): Account|Person {
        $type = ObservationEntityType::from(strtolower(trim($entityType)));
        $context = $this->systemSourceContext($sourceKey, $ingress, $initiatorType, $origin);
        $sourceKey = $context['source_key'];
        if ($source === null) {
            $source = $this->createSource->ensureSystemSource(
                $workspace,
                $sourceKey,
                $actor,
                $context['kind'],
                $context['display_name'],
                $context['configuration'],
                origin: $context['origin'],
            );
        } elseif ((int) $source->workspace_id !== (int) $workspace->id) {
            throw ValidationException::withMessages(['source' => 'Choose a source from this workspace.']);
        }
        $idempotencyKey ??= $sourceKey.':'.(string) Str::uuid();
        $result = $this->handle(new IngestionBatch(
            workspace: $workspace,
            source: $source,
            sourceVersion: $source->currentVersion()->first(),
            idempotencyKey: $idempotencyKey,
            observations: [new IngestionObservation($type, $record)],
            actor: $actor,
            initiatorType: $context['initiator_type'],
        ));
        $canonical = $result->firstCanonical();
        if ($canonical === null) {
            $field = $type === ObservationEntityType::Person ? 'email' : 'name';
            $observation = $result->observations[0] ?? null;
            $message = $observation === null
                ? 'The record was rejected by the source policy.'
                : (string) ($observation->getAttribute('safe_error_message') ?? 'The record was rejected by the source policy.');
            throw ValidationException::withMessages([$field => $message]);
        }

        return $canonical;
    }

    /** @param array<string,mixed> $record */
    public function createManualAccount(
        Workspace $workspace,
        User $actor,
        array $record,
        ?string $idempotencyKey = null,
        ?string $sourceKey = null,
        ?string $ingress = null,
        ?string $initiatorType = null,
        ?string $origin = null,
    ): Account {
        $canonical = $this->ingestOne(
            workspace: $workspace,
            actor: $actor,
            record: $record,
            entityType: ObservationEntityType::Account->value,
            idempotencyKey: $idempotencyKey,
            sourceKey: $sourceKey,
            ingress: $ingress,
            initiatorType: $initiatorType,
            origin: $origin,
        );
        if (! $canonical instanceof Account) {
            throw new \LogicException('The manual account ingestion returned the wrong entity type.');
        }

        return $canonical;
    }

    /** @param array<string,mixed> $record */
    public function createManualPerson(
        Workspace $workspace,
        User $actor,
        array $record,
        ?string $idempotencyKey = null,
        ?string $sourceKey = null,
        ?string $ingress = null,
        ?string $initiatorType = null,
        ?string $origin = null,
    ): Person {
        $canonical = $this->ingestOne(
            workspace: $workspace,
            actor: $actor,
            record: $record,
            entityType: ObservationEntityType::Person->value,
            idempotencyKey: $idempotencyKey,
            sourceKey: $sourceKey,
            ingress: $ingress,
            initiatorType: $initiatorType,
            origin: $origin,
        );
        if (! $canonical instanceof Person) {
            throw new \LogicException('The manual person ingestion returned the wrong entity type.');
        }

        return $canonical;
    }

    /**
     * @return array{
     *     source_key: string,
     *     ingress: string,
     *     initiator_type: string,
     *     origin: string,
     *     kind: ProspectSourceKind,
     *     display_name: string,
     *     configuration: array<string, mixed>
     * }
     */
    private function systemSourceContext(
        ?string $sourceKey,
        ?string $ingress,
        ?string $initiatorType,
        ?string $origin,
    ): array {
        $sourceKey = trim((string) ($sourceKey ?? 'manual'));
        $context = match ($sourceKey) {
            'manual' => [
                'ingress' => 'web',
                'initiator_type' => 'user',
                'kind' => ProspectSourceKind::Manual,
                'display_name' => 'Manual',
            ],
            'rest_api' => [
                'ingress' => 'rest',
                'initiator_type' => 'api',
                'kind' => ProspectSourceKind::ApiImport,
                'display_name' => 'REST API',
            ],
            'mcp' => [
                'ingress' => 'mcp',
                'initiator_type' => 'mcp',
                'kind' => ProspectSourceKind::ApiImport,
                'display_name' => 'MCP',
            ],
            default => throw ValidationException::withMessages([
                'source' => 'Choose a supported ingestion source.',
            ]),
        };

        $context['ingress'] = trim((string) ($ingress ?? $context['ingress']));
        $context['initiator_type'] = trim((string) ($initiatorType ?? $context['initiator_type']));
        $context['origin'] = trim((string) ($origin ?? 'system'));

        return [
            'source_key' => $sourceKey,
            'ingress' => $context['ingress'],
            'initiator_type' => $context['initiator_type'],
            'origin' => $context['origin'],
            'kind' => $context['kind'],
            'display_name' => $context['display_name'],
            'configuration' => [
                'ingress' => $context['ingress'],
                'system_managed' => true,
            ],
        ];
    }

    /** @param array<string, mixed> $input */
    private function startRun(IngestionBatch $batch, ProspectSource $source, ProspectSourceVersion $version, array $input): SourceRun
    {
        return $this->startRun->handle(
            workspace: $batch->workspace,
            source: $source,
            version: $version,
            idempotencyKey: $batch->idempotencyKey,
            input: $batch->inputReference ?? ['observation_count' => count($input)],
            actor: $batch->actor,
            initiatorType: $batch->initiatorType,
            personImportId: $batch->personImportId,
            recipientImportId: $batch->recipientImportId,
            requestFingerprint: $batch->requestFingerprint ?? hash('sha256', json_encode([
                'source_id' => $source->id,
                'version_id' => $version->id,
                'observations' => $input,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
        );
    }

    /**
     * Stable observation keys are unique within a run. Rejecting duplicates at
     * the batch boundary keeps two rows from being silently represented by one
     * stored observation (and avoids creating a partially populated run).
     *
     * @param  list<IngestionObservation>  $observations
     */
    private function assertUniqueObservationKeys(array $observations): void
    {
        /** @var array<string, string> $seen */
        $seen = [];
        foreach ($observations as $observation) {
            // The source run ID is intentionally a fixed placeholder here.
            // Explicit observation keys do not use it, and implicit stable
            // keys use the same run ID for every row in a batch.
            $key = $observation->stableKey('__batch__');
            $valuesHash = $this->valuesHash($this->sanitize($observation->type(), $observation->observedValues));
            if (isset($seen[$key])) {
                throw new SourceIdempotencyConflict(
                    $seen[$key] === $valuesHash
                        ? 'The batch contains duplicate observation keys.'
                        : 'The observation key was reused for different values.',
                );
            }
            $seen[$key] = $valuesHash;
        }
    }

    private function source(Workspace $workspace, ProspectSource|string $source): ProspectSource
    {
        $id = $source instanceof ProspectSource ? $source->id : $source;

        return ProspectSource::query()
            ->where('workspace_id', $workspace->id)
            ->whereKey($id)
            ->with('currentVersion')
            ->firstOrFail();
    }

    private function version(ProspectSource $source, ProspectSourceVersion|string|null $version): ProspectSourceVersion
    {
        if ($version instanceof ProspectSourceVersion) {
            if ((string) $version->prospect_source_id !== (string) $source->id || (int) $version->workspace_id !== (int) $source->workspace_id) {
                throw new SourceRunConflict('The source version does not belong to this source.');
            }

            return $version->fresh();
        }

        return $source->versions()->when($version !== null, fn ($query) => $query->whereKey($version))->when($version === null, fn ($query) => $query->whereKey($source->current_version_id))->firstOrFail();
    }

    /** @param array<string, int> $seen */
    private function ingestObservation(IngestionBatch $batch, ProspectSource $source, ProspectSourceVersion $version, SourceRun $run, IngestionObservation $input, array &$seen): SourceObservation
    {
        $type = $input->type();
        $values = $this->sanitize($type, $input->observedValues);
        $hash = $this->valuesHash($values);
        $retrievedAt = now();
        $observation = SourceObservation::query()->create([
            'workspace_id' => $batch->workspace->id,
            'prospect_source_id' => $source->id,
            'prospect_source_version_id' => $version->id,
            'source_run_id' => $run->id,
            'row_number' => $input->rowNumber,
            'input_key' => $input->inputKey,
            'observation_key' => $input->stableKey((string) $run->id),
            'entity_type' => $type,
            'observed_values' => $values,
            'observed_values_hash' => $hash,
            'raw_input_hash' => $this->hashOrNull($input->rawInputHash),
            'provider_record_id' => $this->bounded($input->providerRecordId, 255),
            'provider_snapshot_id' => $this->bounded($input->providerSnapshotId, 255),
            'canonical_url' => $this->bounded($input->canonicalUrl, 2048),
            'provider_observed_at' => $input->providerObservedAt,
            'retrieved_at' => $retrievedAt,
            'disposition' => ObservationDisposition::PendingReview,
            'policy_classification' => (string) ($version->policy_snapshot['personal_data_classification'] ?? 'standard'),
            'policy_hash' => $version->policy_hash,
            'retain_until' => $this->policyEnforcer->retentionDate((array) $version->policy_snapshot, $retrievedAt),
            'display_classification' => (string) ($version->policy_snapshot['display_classification'] ?? 'standard'),
            'export_classification' => (string) ($version->policy_snapshot['export_classification'] ?? 'standard'),
        ]);

        if ($values === []) {
            return $this->finishObservation($observation, ObservationDisposition::Rejected, errorCode: 'invalid_observation', errorMessage: 'The observation did not contain an allowed value.');
        }

        return $type === ObservationEntityType::Account
            ? $this->ingestAccount($batch, $source, $version, $run, $observation, $values, $seen)
            : $this->ingestPerson($batch, $source, $version, $run, $observation, $values, $seen);
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, int>  $seen
     */
    private function ingestAccount(IngestionBatch $batch, ProspectSource $source, ProspectSourceVersion $version, SourceRun $run, SourceObservation $observation, array $values, array &$seen): SourceObservation
    {
        $name = trim((string) ($values['name'] ?? ''));
        $domain = DomainNormalizer::normalize((string) ($values['domain_normalized'] ?? ($values['domain'] ?? ($values['website'] ?? ''))));
        if ($name === '' || ($domain !== null && DomainNormalizer::isFreeMailDomain($domain))) {
            return $this->finishObservation($observation, ObservationDisposition::Rejected, errorCode: 'invalid_account_identity', errorMessage: 'An account requires a non-empty name and a trustworthy domain.');
        }
        $identityKey = $domain !== null ? 'domain:'.$domain : 'name:'.mb_strtolower($name);
        $duplicate = isset($seen[$identityKey]);
        $externalIdentity = $this->findExternalIdentity($source, $version, $observation, 'account');
        try {
            $account = isset($values['canonical_account_id'])
                ? Account::query()->where('workspace_id', $batch->workspace->id)->whereKey((int) $values['canonical_account_id'])->lockForUpdate()->first()
                : $this->accountIdentity->handle($batch->workspace, $values, (bool) ($values['confirmed_duplicate'] ?? false));
        } catch (ValidationException $exception) {
            return $this->finishObservation($observation, ObservationDisposition::Conflicted, errorCode: 'account_identity_conflict', errorMessage: $this->validationMessage($exception));
        }

        $created = false;
        if (array_key_exists('canonical_account_id', $values) && $account === null) {
            return $this->finishObservation($observation, ObservationDisposition::Rejected, errorCode: 'canonical_scope', errorMessage: 'The referenced account does not belong to this workspace.');
        }
        if ($externalIdentity !== null) {
            $mappedAccount = Account::query()
                ->where('workspace_id', $batch->workspace->id)
                ->whereKey((int) $externalIdentity->account_id)
                ->lockForUpdate()
                ->first();
            if ($mappedAccount === null || ($account !== null && (int) $account->id !== (int) $mappedAccount->id)) {
                return $this->finishExternalIdentityConflict($observation);
            }
            $account ??= $mappedAccount;
        }
        if ($account === null) {
            $account = new Account([
                'workspace_id' => $batch->workspace->id,
                'source' => $this->legacyAccountSource($source),
                'created_by_user_id' => $batch->actor?->id,
            ]);
            try {
                $account = $this->mergeAccount->handle($account, $observation, $values, $batch->actor);
                $created = true;
            } catch (QueryException $exception) {
                $existing = $this->accountIdentity->handle($batch->workspace, $values, true);
                if ($existing === null) {
                    throw $exception;
                }
                $account = $this->mergeAccount->handle($existing, $observation, $values, $batch->actor);
            }
        } else {
            $account = $this->mergeAccount->handle($account, $observation, $values, $batch->actor);
        }
        $observation->forceFill([
            'account_id' => $account->id,
            'person_id' => null,
            'disposition' => $duplicate ? ObservationDisposition::Duplicate : ($created ? ObservationDisposition::New : ObservationDisposition::Linked),
            'match_method' => $domain !== null ? 'exact_domain' : 'confirmed_duplicate',
            'match_confidence' => $domain !== null ? 1.0 : null,
        ])->save();
        if ($this->externalIdentity($source, $version, $observation, $account, null)) {
            return $this->finishExternalIdentityConflict($observation);
        }
        $this->membershipForAccount($source, $run, $observation, $account);
        $seen[$identityKey] = $account->id;

        return $observation->fresh();
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, int>  $seen
     */
    private function ingestPerson(IngestionBatch $batch, ProspectSource $source, ProspectSourceVersion $version, SourceRun $run, SourceObservation $observation, array $values, array &$seen): SourceObservation
    {
        $email = trim((string) ($values['email'] ?? ($values['email_address'] ?? '')));
        if (! EmailNormalizer::isValid($email)) {
            return $this->finishObservation($observation, ObservationDisposition::Rejected, errorCode: 'invalid_email', errorMessage: 'Enter a valid email address.');
        }
        $normalized = EmailNormalizer::normalize($email);
        $suppressed = $this->personIdentity->isSuppressed($batch->workspace, $normalized);
        $account = null;
        if (isset($values['account_id']) && $values['account_id'] !== '') {
            $account = Account::query()->where('workspace_id', $batch->workspace->id)->whereKey((int) $values['account_id'])->first();
            if ($account === null) {
                return $this->finishObservation($observation, ObservationDisposition::Rejected, errorCode: 'account_scope', errorMessage: 'Choose an account from this workspace.');
            }
        }
        $duplicate = isset($seen['email:'.$normalized]);
        $externalIdentity = $this->findExternalIdentity($source, $version, $observation, 'person');
        $person = isset($values['canonical_person_id'])
            ? Person::query()->where('workspace_id', $batch->workspace->id)->whereKey((int) $values['canonical_person_id'])->lockForUpdate()->first()
            : $this->personIdentity->handle($batch->workspace, $email);
        if (array_key_exists('canonical_person_id', $values) && $person === null) {
            return $this->finishObservation($observation, ObservationDisposition::Rejected, errorCode: 'canonical_scope', errorMessage: 'The referenced person does not belong to this workspace.');
        }
        if ($externalIdentity !== null) {
            $mappedPerson = Person::query()
                ->where('workspace_id', $batch->workspace->id)
                ->whereKey((int) $externalIdentity->person_id)
                ->lockForUpdate()
                ->first();
            if ($mappedPerson === null || ($person !== null && (int) $person->id !== (int) $mappedPerson->id)) {
                return $this->finishExternalIdentityConflict($observation);
            }
            $person ??= $mappedPerson;
        }
        $conflicted = $person !== null && $account !== null && $person->account_id !== null && (int) $person->account_id !== (int) $account->id;
        if ($conflicted) {
            unset($values['account_id']);
            $person = $this->mergePerson->handle(
                $person,
                $observation,
                array_merge($values, ['email' => $email]),
                $batch->actor,
                $batch->personImportId !== null || $batch->recipientImportId !== null,
            );
            $observation->forceFill([
                'person_id' => $person->id,
                'account_id' => null,
                'disposition' => ObservationDisposition::Conflicted,
                'match_method' => 'exact_email',
                'match_confidence' => 1.0,
                'safe_error_code' => 'account_conflict',
                'safe_error_message' => ObservationRedactor::safeError('The person matched by email, but their existing account was retained.'),
            ])->save();
            if ($this->externalIdentity($source, $version, $observation, null, $person)) {
                return $this->finishExternalIdentityConflict($observation);
            }
            $this->membershipForPerson($source, $run, $observation, $person, MembershipState::Restricted);

            return $observation->fresh();
        }
        $created = false;
        if ($person === null) {
            $person = new Person([
                'workspace_id' => $batch->workspace->id,
                'source' => $this->legacyPersonSource($source),
                'created_by_user_id' => $batch->actor?->id,
            ]);
            if ($account !== null) {
                $values['account_id'] = $account->id;
            }
            $person = $this->mergePerson->handle(
                $person,
                $observation,
                array_merge($values, ['email' => $email]),
                $batch->actor,
                $batch->personImportId !== null || $batch->recipientImportId !== null,
            );
            $created = true;
        } else {
            $person = $this->mergePerson->handle(
                $person,
                $observation,
                array_merge($values, ['email' => $email]),
                $batch->actor,
                $batch->personImportId !== null || $batch->recipientImportId !== null,
            );
        }
        $observation->forceFill([
            'person_id' => $person->id,
            'account_id' => null,
            'disposition' => $suppressed
                ? ObservationDisposition::Suppressed
                : ($duplicate ? ObservationDisposition::Duplicate : ($created ? ObservationDisposition::New : ObservationDisposition::Linked)),
            'match_method' => 'exact_email',
            'match_confidence' => 1.0,
            'safe_error_code' => $suppressed ? 'suppressed_email' : null,
            'safe_error_message' => $suppressed
                ? ObservationRedactor::safeError('The email is suppressed; no contactable create was made.')
                : null,
        ])->save();
        if ($this->externalIdentity($source, $version, $observation, null, $person)) {
            return $this->finishExternalIdentityConflict($observation);
        }
        $this->membershipForPerson(
            $source,
            $run,
            $observation,
            $person,
            $suppressed ? MembershipState::Suppressed : MembershipState::Active,
        );
        $seen['email:'.$normalized] = $person->id;

        return $observation->fresh();
    }

    private function finishObservation(SourceObservation $observation, ObservationDisposition $disposition, ?string $errorCode = null, ?string $errorMessage = null): SourceObservation
    {
        $observation->forceFill([
            'disposition' => $disposition,
            'safe_error_code' => $errorCode,
            'safe_error_message' => ObservationRedactor::safeError($errorMessage),
        ])->save();

        return $observation->fresh();
    }

    private function membershipForAccount(ProspectSource $source, SourceRun $run, SourceObservation $observation, Account $account): void
    {
        $at = $observation->retrieved_at ?? now();
        $membership = AccountSourceMembership::query()
            ->where('workspace_id', $source->workspace_id)
            ->where('prospect_source_id', $source->id)
            ->where('account_id', $account->id)
            ->lockForUpdate()
            ->first();
        if ($membership === null) {
            AccountSourceMembership::query()->create([
                'public_id' => (string) Str::uuid(),
                'workspace_id' => $source->workspace_id,
                'prospect_source_id' => $source->id,
                'account_id' => $account->id,
                'first_source_run_id' => $run->id,
                'first_source_observation_id' => $observation->id,
                'last_source_run_id' => $run->id,
                'last_source_observation_id' => $observation->id,
                'state' => MembershipState::Active,
                'first_observed_at' => $at,
                'last_observed_at' => $at,
            ]);

            return;
        }
        $membership->forceFill([
            'last_source_run_id' => $run->id,
            'last_source_observation_id' => $observation->id,
            'last_observed_at' => $at,
            'state' => MembershipState::Active,
            'expired_at' => null,
        ])->save();
    }

    private function membershipForPerson(ProspectSource $source, SourceRun $run, SourceObservation $observation, Person $person, MembershipState $state = MembershipState::Active): void
    {
        $at = $observation->retrieved_at ?? now();
        $membership = PersonSourceMembership::query()
            ->where('workspace_id', $source->workspace_id)
            ->where('prospect_source_id', $source->id)
            ->where('person_id', $person->id)
            ->lockForUpdate()
            ->first();
        if ($membership === null) {
            PersonSourceMembership::query()->create([
                'public_id' => (string) Str::uuid(),
                'workspace_id' => $source->workspace_id,
                'prospect_source_id' => $source->id,
                'person_id' => $person->id,
                'first_source_run_id' => $run->id,
                'first_source_observation_id' => $observation->id,
                'last_source_run_id' => $run->id,
                'last_source_observation_id' => $observation->id,
                'state' => $state,
                'first_observed_at' => $at,
                'last_observed_at' => $at,
            ]);

            return;
        }
        $membership->forceFill([
            'last_source_run_id' => $run->id,
            'last_source_observation_id' => $observation->id,
            'last_observed_at' => $at,
            'state' => $state,
            'expired_at' => null,
        ])->save();
    }

    /**
     * Persist an external identity after the canonical target has been
     * resolved. The boolean result is true when a concurrent or pre-existing
     * identity points at another canonical row; callers must then classify the
     * observation as conflicted before creating a source membership.
     */
    private function externalIdentity(ProspectSource $source, ProspectSourceVersion $version, SourceObservation $observation, ?Account $account, ?Person $person): bool
    {
        $identityKey = $this->externalIdentityKey($source, $version, $observation);
        if ($identityKey === null) {
            return false;
        }

        $entityType = $account !== null ? 'account' : 'person';
        $entityId = $account !== null ? $account->id : $person?->id;
        $identity = $this->findExternalIdentity($source, $version, $observation, $entityType);
        if ($identity !== null) {
            $existingId = $account !== null ? $identity->account_id : $identity->person_id;
            if ((int) $existingId !== (int) $entityId) {
                return true;
            }

            $this->refreshExternalIdentity($identity, $version, $observation);

            return false;
        }

        try {
            ExternalIdentity::query()->create([
                'id' => (string) Str::uuid(),
                'workspace_id' => $source->workspace_id,
                'entity_type' => $entityType,
                'account_id' => $account?->id,
                'person_id' => $person?->id,
                'provider' => $identityKey['provider'],
                'identity_type' => $identityKey['identity_type'],
                'value' => $identityKey['value'],
                'value_hash' => $identityKey['value_hash'],
                'first_source_observation_id' => $observation->id,
                'last_source_observation_id' => $observation->id,
                'first_observed_at' => $observation->retrieved_at ?? now(),
                'last_observed_at' => $observation->retrieved_at ?? now(),
                'state' => 'active',
                'policy_hash' => $version->policy_hash,
                'retain_until' => $observation->retain_until,
            ]);
        } catch (QueryException $exception) {
            // A database unique-key race can insert the identity after the
            // initial locked lookup. Re-read it and classify a different
            // canonical target rather than silently dropping the mapping.
            $identity = $this->findExternalIdentity($source, $version, $observation, $entityType);
            if ($identity === null) {
                throw $exception;
            }
            $existingId = $account !== null ? $identity->account_id : $identity->person_id;
            if ((int) $existingId !== (int) $entityId) {
                return true;
            }
            $this->refreshExternalIdentity($identity, $version, $observation);
        }

        return false;
    }

    /**
     * @return array{value: string, provider: string, identity_type: string, value_hash: string}|null
     */
    private function externalIdentityKey(ProspectSource $source, ProspectSourceVersion $version, SourceObservation $observation): ?array
    {
        $value = $observation->provider_record_id ?: $observation->canonical_url;
        if ($value === null || trim($value) === '') {
            return null;
        }
        $configuration = $version->getAttribute('configuration');
        $provider = (string) (is_array($configuration) ? ($configuration['provider'] ?? null) : null);

        return [
            'value' => $value,
            'provider' => $provider !== '' ? $provider : 'source:'.$source->id,
            'identity_type' => $observation->provider_record_id !== null ? 'provider_record_id' : 'canonical_url',
            'value_hash' => hash('sha256', strtolower(trim($value))),
        ];
    }

    private function findExternalIdentity(ProspectSource $source, ProspectSourceVersion $version, SourceObservation $observation, string $entityType): ?ExternalIdentity
    {
        $identityKey = $this->externalIdentityKey($source, $version, $observation);
        if ($identityKey === null) {
            return null;
        }

        return ExternalIdentity::query()
            ->where('workspace_id', $source->workspace_id)
            ->where('entity_type', $entityType)
            ->where('provider', $identityKey['provider'])
            ->where('identity_type', $identityKey['identity_type'])
            ->where('value_hash', $identityKey['value_hash'])
            ->lockForUpdate()
            ->first();
    }

    private function refreshExternalIdentity(ExternalIdentity $identity, ProspectSourceVersion $version, SourceObservation $observation): void
    {
        $identityValue = $observation->provider_record_id ?: $observation->canonical_url;
        $identity->forceFill([
            'value' => $identityValue,
            'last_source_observation_id' => $observation->id,
            'last_observed_at' => $observation->retrieved_at ?? now(),
            'policy_hash' => $version->policy_hash,
            'retain_until' => $observation->retain_until,
            'state' => 'active',
        ])->save();
    }

    private function finishExternalIdentityConflict(SourceObservation $observation): SourceObservation
    {
        $observation->forceFill([
            'account_id' => null,
            'person_id' => null,
            'disposition' => ObservationDisposition::Conflicted,
            'match_method' => 'external_identity',
            'match_confidence' => null,
            'safe_error_code' => 'external_identity_conflict',
            'safe_error_message' => ObservationRedactor::safeError('The provider identity is already mapped to another canonical record and requires review.'),
        ])->save();

        return $observation->fresh();
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function sanitize(ObservationEntityType $type, array $values): array
    {
        $configured = config('prospecting.field_allowlist.'.$type->value, []);
        $allowed = is_array($configured) ? array_values(array_filter($configured, 'is_string')) : [];
        $result = [];
        foreach ($allowed as $field) {
            if (! array_key_exists($field, $values)) {
                continue;
            }
            $value = $values[$field];
            // The legacy source column is a compatibility projection whose
            // exact value (including null/empty historical values) is part of
            // the backfill evidence. Other nullable fields intentionally omit
            // empty input, but dropping this key would make an unknown legacy
            // value indistinguishable from a missing observation field.
            $preserveLegacySource = $field === 'legacy_source'
                && (is_scalar($value) || $value === null);
            if (is_array($value)) {
                if (! array_is_list($value)) {
                    continue;
                }
                $value = array_values(array_filter(array_map(fn (mixed $item): string => $this->bounded((string) $item, 120) ?? '', $value), static fn (string $item): bool => $item !== ''));
            } elseif (is_scalar($value) || $value === null) {
                $value = $value === null
                    ? null
                    : ($preserveLegacySource
                        ? mb_strcut((string) $value, 0, (int) config('prospecting.limits.observed_value_max_length', 2048), 'UTF-8')
                        : $this->bounded((string) $value, (int) config('prospecting.limits.observed_value_max_length', 2048)));
            } else {
                continue;
            }
            if ($preserveLegacySource || ($value !== null && $value !== [] && $value !== '')) {
                $result[$field] = $value;
            }
        }
        if (isset($values['confirmed_duplicate']) && is_bool($values['confirmed_duplicate'])) {
            $result['confirmed_duplicate'] = $values['confirmed_duplicate'];
        }

        ksort($result);

        return $result;
    }

    /** @param array<string,mixed> $values */
    private function valuesHash(array $values): string
    {
        return hash('sha256', CanonicalSourceConfiguration::json($values));
    }

    private function hashOrNull(?string $hash): ?string
    {
        return $hash !== null && preg_match('/^[a-f0-9]{64}$/i', $hash) === 1 ? strtolower($hash) : null;
    }

    private function bounded(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : mb_strcut($value, 0, $max, 'UTF-8');
    }

    private function legacyAccountSource(ProspectSource $source): string
    {
        return ProspectSourceKind::from((string) $source->getRawOriginal('kind')) === ProspectSourceKind::Manual
            ? Account::SOURCE_MANUAL
            : Account::SOURCE_IMPORT;
    }

    private function legacyPersonSource(ProspectSource $source): string
    {
        return ProspectSourceKind::from((string) $source->getRawOriginal('kind')) === ProspectSourceKind::Manual
            ? Person::SOURCE_MANUAL
            : Person::SOURCE_IMPORT;
    }

    /**
     * @param  array<int|string, Account>  $accounts
     * @param  array<int|string, Person>  $people
     */
    private function collectCanonical(SourceObservation $observation, array &$accounts, array &$people): void
    {
        if ($observation->account_id !== null) {
            $account = $observation->account()->first();
            if ($account !== null) {
                $accounts[(string) $account->id] = $account;
            }
        }
        if ($observation->person_id !== null) {
            $person = $observation->person()->first();
            if ($person !== null) {
                $people[(string) $person->id] = $person;
            }
        }
    }

    /** @return array<string,int> */
    private function counts(SourceRun $run): array
    {
        return [
            'received' => (int) $run->received,
            'valid' => (int) $run->valid,
            'accepted' => (int) $run->accepted,
            'created' => (int) $run->created,
            'linked' => (int) $run->linked,
            'duplicate' => (int) $run->duplicate,
            'conflicted' => (int) $run->conflicted,
            'suppressed' => (int) $run->suppressed,
            'rejected' => (int) $run->rejected,
            'expired' => (int) $run->expired,
        ];
    }

    private function runState(SourceRun $run): SourceRunState
    {
        return SourceRunState::from((string) $run->getRawOriginal('state'));
    }

    private function resultFromRun(SourceRun $run, bool $replayed): IngestionResult
    {
        $observations = array_values($run->observations()->get()->all());
        /** @var array<int|string, Account> $accounts */
        $accounts = [];
        /** @var array<int|string, Person> $people */
        $people = [];
        foreach ($observations as $observation) {
            $this->collectCanonical($observation, $accounts, $people);
        }

        return new IngestionResult(
            run: $run->fresh(),
            observations: $observations,
            accounts: array_values($accounts),
            people: array_values($people),
            counts: $this->counts($run),
            replayed: $replayed,
        );
    }

    private function validationMessage(ValidationException $exception): string
    {
        $messages = $exception->errors();

        return (string) (array_values($messages)[0][0] ?? $exception->getMessage());
    }
}
