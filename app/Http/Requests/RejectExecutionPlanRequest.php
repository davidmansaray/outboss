<?php

namespace App\Http\Requests;

class RejectExecutionPlanRequest extends ApproveExecutionPlanRequest
{
    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
