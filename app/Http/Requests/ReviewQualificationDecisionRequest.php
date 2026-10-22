<?php

namespace App\Http\Requests;

use App\Domain\Intelligence\Enums\QualificationDecisionClaimRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewQualificationDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'action' => ['required', 'string', 'in:accept,correct,reject,request_research,defect'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'correction' => ['sometimes', 'nullable', 'array', 'max:20'],
            'correction.outcome' => ['sometimes', 'string', 'in:contact,withhold,research_further,insufficient_evidence,needs_review'],
            'correction.confidence' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'correction.calibration_band' => ['sometimes', 'nullable', 'string', 'in:low,medium,high'],
            'correction.missing_facts' => ['sometimes', 'array', 'max:50'],
            'correction.missing_facts.*' => ['string', 'max:160'],
            'correction.disqualifiers' => ['sometimes', 'array', 'max:50'],
            'correction.disqualifiers.*' => ['string', 'max:160'],
            'correction.explanation' => ['sometimes', 'string', 'max:4000'],
            'correction.claims' => ['sometimes', 'array', 'max:100'],
            'correction.claims.*.claim_id' => ['required', 'uuid'],
            'correction.claims.*.role' => ['required', Rule::enum(QualificationDecisionClaimRole::class)],
            'correction.sensitive_inference' => ['sometimes', 'boolean'],
            'correction.unsupported_causality' => ['sometimes', 'boolean'],
            'correction.why_account' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'correction.why_offer' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'correction.why_now' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'correction.factual_basis' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'correction.internal_rationale' => ['sometimes', 'nullable', 'string', 'max:4000'],
        ];
    }
}
