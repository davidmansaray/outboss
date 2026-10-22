<?php

use App\Domain\Objectives\Support\ExecutionPlanFingerprint;
use Illuminate\Support\Str;

/** @return array<string, mixed> */
function exactExecutionActionPayload(): array
{
    return [
        'id' => (string) Str::uuid(),
        'workspace_id' => 17,
        'execution_plan_id' => (string) Str::uuid(),
        'gtm_experiment_assignment_id' => (string) Str::uuid(),
        'gtm_experiment_arm_id' => (string) Str::uuid(),
        'objective_run_arm_draft_id' => (string) Str::uuid(),
        'strategy_version_id' => (string) Str::uuid(),
        'audience_snapshot_member_id' => (string) Str::uuid(),
        'account_id' => 23,
        'person_id' => 29,
        'offer_version_id' => (string) Str::uuid(),
        'conversion_definition_version_id' => (string) Str::uuid(),
        'selected_claim_ids' => [(string) Str::uuid()],
        'selected_evidence_ids' => [(string) Str::uuid()],
        'selected_claim_safety_hashes' => ['claim-id' => str_repeat('a', 64)],
        'offer_content_hash' => str_repeat('1', 64),
        'offer_contract_hash' => str_repeat('2', 64),
        'cta_destination_url' => 'https://example.test/workshop',
        'cta_response_route' => 'calendar',
        'action_kind' => 'email_new_thread',
        'position' => 1,
        'destination_email_hash' => str_repeat('b', 64),
        'destination_email_address' => 'recipient@example.test',
        'subject' => 'Exact subject',
        'body_text' => 'Exact body',
        'body_html' => null,
        'template_hash' => str_repeat('c', 64),
        'recipient_snapshot_hash' => str_repeat('d', 64),
        'content_hash' => str_repeat('e', 64),
        'mailbox_id' => 31,
        'provider_connection_id' => 37,
        'provider_connection_version' => 4,
        'sender_address_snapshot' => 'sender@example.test',
        'sender_display_name_snapshot' => 'Exact Sender Name',
        'reply_to_snapshot' => 'replies@example.test',
        'scheduled_not_before' => '2026-09-05T09:00:00+00:00',
        'scheduled_not_after' => '2026-09-05T17:00:00+00:00',
        'policy_input_hash' => str_repeat('f', 64),
    ];
}

function changedExactValue(mixed $value): mixed
{
    return match (true) {
        is_int($value) => $value + 1,
        is_array($value) => [...$value, 'changed'],
        $value === null => 'changed',
        default => (string) $value.'-changed',
    };
}

it('changes the action fingerprint for every exact executable field', function (): void {
    $action = exactExecutionActionPayload();
    $baseline = ExecutionPlanFingerprint::action($action);

    expect(ExecutionPlanFingerprint::ACTION_FIELDS)->toContain('workspace_id', 'sender_display_name_snapshot');
    foreach (ExecutionPlanFingerprint::ACTION_FIELDS as $field) {
        $mutated = $action;
        $mutated[$field] = changedExactValue($mutated[$field]);
        expect(ExecutionPlanFingerprint::action($mutated))
            ->not->toBe($baseline, "Mutating {$field} must invalidate the exact action fingerprint.");
    }
});

it('hashes complete immutable action payloads into the plan hash', function (): void {
    $action = exactExecutionActionPayload();
    $action['action_fingerprint'] = ExecutionPlanFingerprint::action($action);
    $baseline = ExecutionPlanFingerprint::plan(
        $action['execution_plan_id'],
        str_repeat('1', 64),
        [$action],
    );

    foreach (ExecutionPlanFingerprint::ACTION_FIELDS as $field) {
        $mutated = $action;
        $mutated[$field] = changedExactValue($mutated[$field]);
        $mutated['action_fingerprint'] = ExecutionPlanFingerprint::action($mutated);
        expect(ExecutionPlanFingerprint::plan(
            $mutated['execution_plan_id'],
            str_repeat('1', 64),
            [$mutated],
        ))->not->toBe($baseline, "Mutating {$field} must invalidate the complete plan hash.");
    }
});
