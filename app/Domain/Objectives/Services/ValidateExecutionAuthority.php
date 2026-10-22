<?php

namespace App\Domain\Objectives\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** A finite manual sending allowance, independent of any outcome definition. */
final class ValidateExecutionAuthority
{
    /** @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function handle(array $input): array
    {
        $data = Validator::make(['authority' => $input], [
            'authority' => ['required', 'array:starts_at,ends_at,maximum_people,maximum_accounts,maximum_messages,maximum_ai_cost_micros,approval_required'],
            'authority.starts_at' => ['required', 'date'],
            'authority.ends_at' => ['required', 'date', 'after:authority.starts_at'],
            'authority.maximum_people' => ['required', 'integer', 'min:1', 'max:'.config('objectives.safety.maximum_people')],
            'authority.maximum_accounts' => ['required', 'integer', 'min:1', 'max:'.config('objectives.safety.maximum_accounts')],
            'authority.maximum_messages' => ['required', 'integer', 'min:1', 'max:'.config('objectives.safety.maximum_messages')],
            'authority.maximum_ai_cost_micros' => ['required', 'integer', 'in:0'],
            'authority.approval_required' => ['required', 'boolean', 'accepted'],
        ])->validate()['authority'];
        $start = Carbon::parse($data['starts_at'])->utc();
        $end = Carbon::parse($data['ends_at'])->utc();
        if ($end->greaterThan($start->copy()->addDays((int) config('objectives.safety.maximum_horizon_days')))) {
            throw ValidationException::withMessages(['authority.ends_at' => 'The authority window exceeds the server duration limit.']);
        }

        // The one-message-per-person invariant belongs to each exact campaign;
        // a shared scope may authorize several campaigns contacting the same people.
        return [
            'starts_at' => $start->toAtomString(), 'ends_at' => $end->toAtomString(),
            'maximum_people' => (int) $data['maximum_people'],
            'maximum_accounts' => (int) $data['maximum_accounts'],
            'maximum_messages' => (int) $data['maximum_messages'],
            'maximum_ai_cost_micros' => 0, 'approval_required' => true,
        ];
    }
}
