<?php

namespace App\Domain\Gtm\Services;

use App\Models\ConversionDefinitionVersion;
use App\Models\Offer;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Human form adapter to the existing compositional contract, not a new Offer type. */
final class SaveConsultationOffer
{
    public function __construct(private readonly ConfigureOperationalOffer $configure) {}

    /** @param array<string, mixed> $input */
    public function handle(Offer $offer, User $actor, array $input): Offer
    {
        if (! $offer->workspace()->firstOrFail()->isOwner($actor)) {
            throw new AuthorizationException('Only the owner can configure an Offer.');
        }
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:160'],
            'promise' => ['required', 'string', 'max:5000'],
            'target_audience' => ['required', 'string', 'max:5000'],
            'desired_outcome' => ['required', 'string', 'max:5000'],
            'problem' => ['required', 'string', 'max:5000'],
            'value' => ['required', 'string', 'max:5000'],
            'proof' => ['nullable', 'string', 'max:5000'],
            'commitment' => ['required', 'string', 'max:2000'],
            'destination_url' => ['required', 'url:https', 'max:2048'],
            'acceptance_version_id' => ['required', 'uuid'],
            'approved_claims' => ['required', 'array', 'min:1', 'max:50'],
            'approved_claims.*' => ['required', 'string', 'max:500'],
            'commercial_approved' => ['required', 'boolean'],
            'commercial_notes' => ['nullable', 'string', 'max:2000'],
            'expected_lock_version' => ['required', 'integer', 'min:0'],
            'client_operation_id' => ['required', 'uuid'],
        ])->validate();

        return DB::transaction(function () use ($offer, $actor, $data): Offer {
            $locked = Offer::query()->where('workspace_id', $offer->workspace_id)->whereKey($offer->id)->lockForUpdate()->firstOrFail();
            $current = $locked->currentVersion()->firstOrFail();
            $payload = $current->payload;
            if (($payload['schema_version'] ?? 1) === 2 && ($payload['fulfilment']['mode'] ?? '') !== 'external_destination') {
                throw ValidationException::withMessages(['offer' => 'This editor cannot replace an asset or human-assisted fulfilment contract.']);
            }
            $definition = ConversionDefinitionVersion::query()->where('workspace_id', $locked->workspace_id)
                ->whereKey($data['acceptance_version_id'])->first();
            if ($definition === null) {
                throw ValidationException::withMessages(['acceptance_version_id' => 'Choose a measurement from this workspace.']);
            }
            $payload = array_replace($payload, [
                'schema_version' => 2,
                'name' => $data['name'], 'promise' => $data['promise'],
                'target_audience' => $data['target_audience'], 'desired_outcome' => $data['desired_outcome'],
                'call_to_action' => $data['commitment'], 'notes' => $payload['notes'] ?? '',
                'proposition' => ['problem_or_opportunity' => $data['problem'], 'value' => $data['value'], 'proof' => $data['proof'] ?? ''],
                'requested_commitment' => $data['commitment'],
                'cta' => array_replace($payload['cta'] ?? [], [
                    'action' => 'book_meeting', 'destination_url' => $data['destination_url'],
                    'response_route' => $payload['cta']['response_route'] ?? '',
                    'required_inputs' => $payload['cta']['required_inputs'] ?? [],
                    'acceptance_conversion_definition_version_id' => $data['acceptance_version_id'],
                ]),
                'fulfilment' => $payload['fulfilment'] ?? ['mode' => 'external_destination', 'asset_version_ids' => []],
                'eligibility' => $payload['eligibility'] ?? ['includes' => [], 'excludes' => []],
                'commercial_authority' => ['approved' => (bool) $data['commercial_approved'], 'notes' => $data['commercial_notes'] ?? ''],
                'approved_claims' => $data['approved_claims'],
                'prohibited_claims' => $payload['prohibited_claims'] ?? [],
                'expires_at' => $payload['expires_at'] ?? null,
                'agent_parameter_policy' => $payload['agent_parameter_policy'] ?? ['allowed_keys' => []],
            ]);

            return $this->configure->handle($locked, $actor, $payload, (int) $data['expected_lock_version'], $data['client_operation_id']);
        }, 3);
    }
}
