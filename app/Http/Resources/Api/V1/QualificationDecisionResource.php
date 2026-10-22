<?php

namespace App\Http\Resources\Api\V1;

use App\Models\QualificationDecision;
use App\Models\QualificationDecisionClaim;
use App\Models\QualificationReviewEvent;
use App\Models\ReasonToContactVersion;
use Illuminate\Http\Request;

class QualificationDecisionResource extends ApiResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        $decision = $this->model(QualificationDecision::class);

        $payload = [
            'id' => (string) $decision->id,
            'account_id' => (int) $decision->account_id,
            'run_id' => (string) $decision->research_run_id,
            'rubric_version_id' => (string) $decision->rubric_version_id,
            'gtm_profile_version_id' => $decision->gtm_profile_version_id === null ? null : (string) $decision->gtm_profile_version_id,
            'offer_version_id' => $decision->offer_version_id === null ? null : (string) $decision->offer_version_id,
            'prior_decision_id' => $decision->prior_decision_id === null ? null : (string) $decision->prior_decision_id,
            'input_hash' => (string) $decision->input_hash,
            'outcome' => $this->enumValue($decision->getAttribute('outcome')) ?? (string) $decision->getRawOriginal('outcome'),
            'confidence' => (float) $decision->confidence,
            'calibration_band' => $decision->calibration_band,
            'missing_facts' => is_array($decision->getAttribute('missing_facts')) ? $decision->getAttribute('missing_facts') : [],
            'disqualifiers' => is_array($decision->getAttribute('disqualifiers')) ? $decision->getAttribute('disqualifiers') : [],
            'explanation' => mb_substr((string) $decision->explanation, 0, 4000),
            'policy_version' => $decision->policy_version,
            'provider_cost_micros' => (int) $decision->provider_cost_micros,
            'review_state' => $this->enumValue($decision->getAttribute('review_state')) ?? (string) $decision->getRawOriginal('review_state'),
            'generated_at' => $this->iso8601($decision->getAttribute('generated_at')),
            'reviewed_at' => $this->iso8601($decision->getAttribute('reviewed_at')),
            'corrected_at' => $this->iso8601($decision->getAttribute('corrected_at')),
        ];
        if ($decision->relationLoaded('claimLinks')) {
            $payload['claims'] = $decision->claimLinks->map(fn (QualificationDecisionClaim $link): array => [
                'claim_id' => (string) $link->claim_id,
                'role' => $this->enumValue($link->getAttribute('role')) ?? (string) $link->getRawOriginal('role'),
            ])->values()->all();
        }
        if ($decision->relationLoaded('reasonsToContact')) {
            $payload['reasons_to_contact'] = $decision->reasonsToContact->map(fn (ReasonToContactVersion $reason): array => [
                'id' => (string) $reason->id,
                'why_account' => $reason->why_account,
                'why_offer' => $reason->why_offer,
                'why_now' => $reason->why_now,
                'factual_basis' => $reason->factual_basis,
                'internal_rationale' => $reason->internal_rationale,
                'confidence' => (float) $reason->confidence,
                'safe_for_message' => (bool) $reason->safe_for_message,
                'sensitive_inference' => (bool) $reason->sensitive_inference,
                'prohibited_inference' => (bool) $reason->prohibited_inference,
                'fresh_until' => $this->iso8601($reason->getAttribute('fresh_until')),
                'expires_at' => $this->iso8601($reason->getAttribute('expires_at')),
            ])->values()->all();
        }
        if ($decision->relationLoaded('reviewEvents')) {
            $payload['review_events'] = $decision->reviewEvents->map(fn (QualificationReviewEvent $event): array => [
                'id' => (string) $event->id,
                'action' => $this->enumValue($event->getAttribute('action')) ?? (string) $event->getRawOriginal('action'),
                'reason' => $event->reason,
                'prior_review_state' => $event->prior_review_state,
                'projected_review_state' => $event->projected_review_state,
                'successor_decision_id' => $event->successor_decision_id === null ? null : (string) $event->successor_decision_id,
                'successor_run_id' => $event->successor_run_id === null ? null : (string) $event->successor_run_id,
                'actor_user_id' => $event->actor_user_id === null ? null : (int) $event->actor_user_id,
                'created_at' => $this->iso8601($event->getAttribute('created_at')),
            ])->values()->all();
        }

        return $payload;
    }
}
