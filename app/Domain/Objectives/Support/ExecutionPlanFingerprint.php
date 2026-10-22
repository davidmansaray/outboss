<?php

namespace App\Domain\Objectives\Support;

use InvalidArgumentException;

/** Canonical exact-action and immutable-plan approval fingerprints. */
final class ExecutionPlanFingerprint
{
    public const VERSION = 'execution_action_fingerprint_v3';

    /** Every value copied into an executable action, including tenant scope. */
    public const ACTION_FIELDS = [
        'workspace_id',
        'gtm_experiment_assignment_id',
        'gtm_experiment_arm_id',
        'objective_run_arm_draft_id',
        'strategy_version_id',
        'audience_snapshot_member_id',
        'account_id',
        'person_id',
        'offer_version_id',
        'conversion_definition_version_id',
        'selected_claim_ids',
        'selected_evidence_ids',
        'selected_claim_safety_hashes',
        'offer_content_hash',
        'offer_contract_hash',
        'cta_destination_url',
        'cta_response_route',
        'action_kind',
        'position',
        'destination_email_hash',
        'destination_email_address',
        'subject',
        'body_text',
        'body_html',
        'template_hash',
        'recipient_snapshot_hash',
        'content_hash',
        'mailbox_id',
        'provider_connection_id',
        'provider_connection_version',
        'sender_address_snapshot',
        'sender_display_name_snapshot',
        'reply_to_snapshot',
        'scheduled_not_before',
        'scheduled_not_after',
        'policy_input_hash',
    ];

    /** @param array<string, mixed> $action */
    public static function action(array $action): string
    {
        return CanonicalObjectivePayload::hash([
            'version' => self::VERSION,
            'action' => self::only($action, self::ACTION_FIELDS),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $actions
     */
    public static function plan(string $planId, string $canonicalPayloadHash, array $actions): string
    {
        $payloads = array_map(static function (array $action): array {
            return self::only($action, [
                'id',
                'execution_plan_id',
                ...self::ACTION_FIELDS,
                'action_fingerprint',
            ]);
        }, $actions);
        usort($payloads, static fn (array $left, array $right): int => strcmp((string) $left['id'], (string) $right['id']));

        return CanonicalObjectivePayload::hash([
            'version' => 'execution_plan_hash_v3',
            'plan_id' => $planId,
            'canonical_payload_hash' => $canonicalPayloadHash,
            'immutable_actions' => $payloads,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    private static function only(array $payload, array $fields): array
    {
        $missing = array_values(array_filter($fields, static fn (string $field): bool => ! array_key_exists($field, $payload)));
        if ($missing !== []) {
            throw new InvalidArgumentException('The exact action payload is incomplete: '.implode(', ', $missing));
        }

        return array_intersect_key($payload, array_flip($fields));
    }
}
