<?php

namespace App\Http\Requests;

class VersionConversionDefinitionRequest extends StoreConversionDefinitionRequest
{
    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'expected_lock_version' => ['required', 'integer', 'min:0'],
        ];
    }
}
