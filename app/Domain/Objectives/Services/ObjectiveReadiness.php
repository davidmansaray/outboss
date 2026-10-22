<?php

namespace App\Domain\Objectives\Services;

use App\Domain\Conversions\Enums\ConversionDefinitionStatus;
use App\Domain\Gtm\Enums\OfferReadinessState;
use App\Domain\Gtm\Enums\OfferStatus;
use App\Domain\Gtm\Services\EvaluateOfferReadiness;
use App\Domain\Intelligence\Enums\QualificationReviewState;
use App\Domain\Objectives\DTOs\ObjectiveReadinessResult;
use App\Domain\Objectives\Enums\AudienceMemberResult;
use App\Domain\Objectives\Enums\OutreachPolicyStatus;
use App\Domain\Objectives\Support\CanonicalObjectivePayload;
use App\Models\ConversionDefinition;
use App\Models\ConversionDefinitionVersion;
use App\Models\Mailbox;
use App\Models\Objective;
use App\Models\ObjectiveVersion;
use App\Models\Offer;
use App\Models\OutreachPolicy;
use App\Models\PersonSourceMembership;
use App\Models\ProspectSource;
use App\Models\QualificationDecision;
use App\Models\ReasonToContactVersion;

final class ObjectiveReadiness
{
    public const VERSION = 'objective_readiness_v2';

    public function __construct(private readonly EvaluateOfferReadiness $offerReadiness) {}

    public function handle(Objective $objective): ObjectiveReadinessResult
    {
        $objective = Objective::query()->where('workspace_id', $objective->workspace_id)
            ->whereKey($objective->id)->with('currentVersion.audienceSnapshot.members.sources')->firstOrFail();
        $version = $objective->currentVersion;
        $blockers = [];
        $warnings = [];
        $inputs = ['evaluator_version' => self::VERSION, 'objective_id' => (string) $objective->id];
        if (! $version instanceof ObjectiveVersion) {
            $this->block($blockers, 'objective_version_missing', 'Configure the Objective before requesting readiness.');

            return new ObjectiveReadinessResult($blockers, $warnings, CanonicalObjectivePayload::hash($inputs));
        }
        $inputs['objective_version_id'] = (string) $version->id;
        $inputs['objective_content_hash'] = (string) $version->content_hash;
        $this->assessHorizon($version, $blockers, $inputs);

        $snapshot = $version->audienceSnapshot;
        if ($snapshot === null || (string) $snapshot->objective_id !== (string) $objective->id || $snapshot->status !== 'frozen') {
            $this->block($blockers, 'audience_snapshot_invalid', 'Use the frozen Audience Snapshot owned by this Objective.');
        } elseif ((int) $snapshot->included_people_count < 1) {
            $this->block($blockers, 'audience_empty', 'The Objective needs at least one included Person.');
        } else {
            $inputs['audience_snapshot_id'] = (string) $snapshot->id;
            $inputs['audience_evaluation_hash'] = (string) $snapshot->evaluation_hash;
            $this->assessIncludedMembers($version, $blockers, $warnings, $inputs);
        }

        $definition = ConversionDefinition::query()->where('workspace_id', $objective->workspace_id)
            ->whereKey($version->conversion_definition_id)->first();
        $definitionVersion = ConversionDefinitionVersion::query()->where('workspace_id', $objective->workspace_id)
            ->where('conversion_definition_id', $version->conversion_definition_id)
            ->whereKey($version->conversion_definition_version_id)->first();
        $inputs['conversion_definition_version_id'] = (string) $version->conversion_definition_version_id;
        if ($definition === null || $definitionVersion === null
            || $definition->status !== ConversionDefinitionStatus::Active
            || (string) $definition->current_version_id !== (string) $definitionVersion->id
            || strtolower((string) $definitionVersion->subject_type) !== 'account') {
            $this->block($blockers, 'conversion_definition_unavailable', 'Use the exact current active Account Conversion Definition Version.');
        } else {
            $definitionWindow = (int) ($definitionVersion->attribution_window['days'] ?? 0);
            if ($definitionWindow < (int) $version->attribution_window_days) {
                $this->block($blockers, 'attribution_window_unsupported', 'The Conversion Definition does not cover this attribution window.');
            }
            $inputs['conversion_content_hash'] = (string) $definitionVersion->content_hash;
        }

        $offer = Offer::query()->where('workspace_id', $objective->workspace_id)->whereKey($version->offer_id)->first();
        $inputs['offer_version_id'] = (string) $version->offer_version_id;
        if ($offer === null || $offer->status !== OfferStatus::Active || (string) $offer->current_version_id !== (string) $version->offer_version_id) {
            $this->block($blockers, 'offer_not_active', 'Use the exact current Version of an active Offer.');
        } else {
            $evaluation = $this->offerReadiness->handle($offer, 'objective_readiness');
            $inputs['offer_readiness_hash'] = (string) $evaluation->dependency_hash;
            if ($evaluation->offer_version_id !== $version->offer_version_id
                || $evaluation->state !== OfferReadinessState::Approved
                || ($evaluation->valid_until !== null && $evaluation->valid_until->isPast())) {
                $this->block($blockers, 'offer_not_ready', 'The exact Offer Version must have current owner-approved readiness.');
            } elseif ($evaluation->valid_until !== null && $evaluation->valid_until->lessThan(now()->addMinutes(5))) {
                $this->warn($warnings, 'offer_readiness_near_expiry', 'Offer readiness will need another check soon.');
            }
        }

        $policy = OutreachPolicy::query()->where('workspace_id', $objective->workspace_id)
            ->where('status', OutreachPolicyStatus::Active->value)->with('currentVersion')->first();
        if ($policy === null || $policy->currentVersion === null || $policy->execution_paused_at !== null) {
            $this->block($blockers, 'outreach_policy_unavailable', 'An active workspace outreach policy is required.');
        } else {
            $policyVersion = $policy->currentVersion;
            $inputs['outreach_policy_version_id'] = (string) $policyVersion->id;
            $inputs['outreach_policy_hash'] = (string) $policyVersion->content_hash;
            foreach (['maximum_people', 'maximum_accounts', 'maximum_messages', 'maximum_ai_cost_micros'] as $cap) {
                if ((int) $version->{$cap} > (int) $policyVersion->{$cap}) {
                    $this->block($blockers, 'outreach_policy_cap_exceeded', 'The Objective exceeds the current outreach policy caps.');
                    break;
                }
            }
        }

        $mailboxReady = Mailbox::query()->where('workspace_id', $objective->workspace_id)
            ->where('status', 'connected')->whereNull('sending_paused_at')
            ->whereNotNull('selected_sender_identity_key')
            ->whereHas('connection', fn ($query) => $query->where('status', 'connected'))
            ->exists();
        $inputs['mailbox_ready'] = $mailboxReady;
        if (! $mailboxReady) {
            $this->block($blockers, 'mailbox_unavailable', 'Connect an unpaused mailbox with a selected sender identity.');
        }

        $base = $version->forecast_projection['assumption_ranges']['base'] ?? null;
        if (is_array($base) && ($base['status'] ?? null) === 'estimated' && (float) ($base['shortfall'] ?? 0) > 0) {
            $this->warn($warnings, 'forecast_shortfall', 'The base forecast does not meet the Objective target.');
        }

        return new ObjectiveReadinessResult($blockers, $warnings, CanonicalObjectivePayload::hash($inputs));
    }

    /**
     * @param  list<array{code:string,message:string}>  $blockers
     * @param  list<array{code:string,message:string}>  $warnings
     * @param  array<string, mixed>  $inputs
     */
    private function assessIncludedMembers(ObjectiveVersion $version, array &$blockers, array &$warnings, array &$inputs): void
    {
        $members = $version->audienceSnapshot->members
            ->filter(fn ($member): bool => $member->result === AudienceMemberResult::Included)
            ->sortBy('id');
        $inputs['included_member_integrity'] = [];
        foreach ($members as $member) {
            $memberInput = [
                'member_id' => (string) $member->id,
                'account_id' => $member->account_id,
                'person_id' => $member->person_id,
                'qualification_decision_id' => (string) ($member->qualification_decision_id ?? ''),
                'reason_to_contact_version_id' => (string) ($member->reason_to_contact_version_id ?? ''),
                'reason_content_hash' => (string) ($member->reason_content_hash ?? ''),
            ];
            if ($member->account_id === null || $member->person_id === null) {
                $memberInput['identity_current'] = false;
                $inputs['included_member_integrity'][] = $memberInput;
                $this->block($blockers, 'audience_identity_detached', 'An included audience identity has been deleted.');
                break;
            }
            $decision = QualificationDecision::query()->where('workspace_id', $version->workspace_id)
                ->where('account_id', $member->account_id)
                ->whereIn('review_state', [QualificationReviewState::Accepted->value, QualificationReviewState::Corrected->value])
                ->latest('created_at')->first();
            if ($decision === null || (string) $decision->id !== (string) $member->qualification_decision_id
                || ! in_array($decision->outcome->value, $version->required_qualification_outcomes, true)
                || (float) $decision->confidence < (float) $version->minimum_qualification_confidence) {
                $memberInput['qualification_current'] = false;
                $inputs['included_member_integrity'][] = $memberInput;
                $this->block($blockers, 'qualification_changed', 'An included Account no longer has the required reviewed qualification.');
                break;
            }
            $memberInput['qualification_current'] = true;
            $reason = ReasonToContactVersion::query()->where('workspace_id', $version->workspace_id)
                ->whereKey($member->reason_to_contact_version_id)
                ->where('account_id', $member->account_id)
                ->where('qualification_decision_id', $member->qualification_decision_id)
                ->where('content_hash', $member->reason_content_hash)
                ->whereNull('successor_id')
                ->where('safe_for_message', true)
                ->where('sensitive_inference', false)
                ->where('prohibited_inference', false)
                ->where('fresh_until', '>', now())
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->first();
            $memberInput['reason_current'] = $reason !== null;
            if ($reason !== null) {
                $memberInput['reason_fresh_until'] = $reason->getRawOriginal('fresh_until');
                $memberInput['reason_expires_at'] = $reason->getRawOriginal('expires_at');
            }
            $inputs['included_member_integrity'][] = $memberInput;
            if ($reason === null) {
                $this->block($blockers, 'reason_to_contact_unavailable', 'An included Account lacks a current safe Reason to Contact.');
                break;
            }
            if ($member->evidence_fresh_until === null || $member->evidence_fresh_until->isPast()) {
                $this->block($blockers, 'evidence_expired', 'An included Account has stale supporting evidence.');
                break;
            }
            if ($member->evidence_fresh_until->lessThan(now()->addDays(3))) {
                $this->warn($warnings, 'evidence_near_expiry', 'Some supporting evidence expires within three days.');
            }
            if ($version->audienceSnapshot->frozen_at->lessThan(now()->subDays((int) $version->maximum_evidence_age_days))) {
                $this->block($blockers, 'evidence_snapshot_too_old', 'The frozen evidence exceeds the Objective maximum age.');
                break;
            }
            $currentRight = $member->sources->contains(function ($source): bool {
                if ($source->rights_result !== 'allowed' || $source->person_source_membership_id === null) {
                    return false;
                }

                $sourceCurrent = ProspectSource::query()
                    ->whereKey($source->prospect_source_id)
                    ->where('status', 'active')
                    ->where('current_version_id', $source->prospect_source_version_id)
                    ->exists();
                if (! $sourceCurrent) {
                    return false;
                }

                return PersonSourceMembership::query()
                    ->whereKey($source->person_source_membership_id)
                    ->whereIn('state', ['active', 'restricted'])
                    ->where(fn ($query) => $query->whereNull('expired_at')->orWhere('expired_at', '>', now()))
                    ->exists();
            });
            if (! $currentRight) {
                $this->block($blockers, 'source_rights_changed', 'An included Person no longer has active prospecting rights.');
                break;
            }
        }
    }

    /**
     * @param  list<array{code:string,message:string}>  $blockers
     * @param  array<string, mixed>  $inputs
     */
    private function assessHorizon(ObjectiveVersion $version, array &$blockers, array &$inputs): void
    {
        $maximumDays = (int) config('objectives.safety.maximum_horizon_days');
        $ordered = $version->starts_at->lessThan($version->ends_at);
        $open = $version->ends_at->isFuture();
        $withinMaximum = $maximumDays > 0
            && ! $version->ends_at->greaterThan($version->starts_at->copy()->addDays($maximumDays));
        $inputs['horizon'] = [
            'starts_at' => $version->starts_at->toAtomString(),
            'ends_at' => $version->ends_at->toAtomString(),
            'maximum_days' => $maximumDays,
            'ordered' => $ordered,
            'open' => $open,
            'within_maximum' => $withinMaximum,
        ];

        if (! $ordered) {
            $this->block($blockers, 'objective_horizon_invalid', 'The Objective horizon must start before it ends.');
        }
        if (! $open) {
            $this->block($blockers, 'objective_horizon_expired', 'The Objective horizon has already ended.');
        }
        if (! $withinMaximum) {
            $this->block($blockers, 'objective_horizon_too_long', 'The Objective horizon exceeds the configured pilot duration.');
        }
    }

    /** @param list<array{code:string,message:string}> $items */
    private function block(array &$items, string $code, string $message): void
    {
        if (! collect($items)->contains('code', $code)) {
            $items[] = compact('code', 'message');
        }
    }

    /** @param list<array{code:string,message:string}> $items */
    private function warn(array &$items, string $code, string $message): void
    {
        if (! collect($items)->contains('code', $code)) {
            $items[] = compact('code', 'message');
        }
    }
}
