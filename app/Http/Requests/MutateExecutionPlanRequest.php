<?php

namespace App\Http\Requests;

use App\Domain\Objectives\Exceptions\ExecutionPlanSnapshotConflict;
use App\Models\ExecutionPlan;
use Illuminate\Foundation\Http\FormRequest;

/** Validate the exact plan snapshot shown to an owner before compile or launch. */
final class MutateExecutionPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->currentWorkspace()->isOwner($user);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'plan_id' => ['required', 'uuid'],
            'plan_hash' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
        ];
    }

    public function assertCurrentSnapshot(ExecutionPlan $plan, string $routePlanId): void
    {
        $submittedPlanId = (string) $this->validated('plan_id');
        $submittedPlanHash = (string) $this->validated('plan_hash');

        if (! hash_equals($routePlanId, $submittedPlanId)
            || ! hash_equals((string) $plan->id, $submittedPlanId)
            || ! hash_equals((string) $plan->plan_hash, $submittedPlanHash)) {
            throw new ExecutionPlanSnapshotConflict;
        }
    }
}
