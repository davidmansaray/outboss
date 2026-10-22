<?php

namespace App\Http\Requests;

class VersionAssetRequest extends StoreAssetRequest
{
    /** @return array<string,array<int,mixed>> */
    public function rules(): array
    {
        $rules = parent::rules();
        foreach (['title', 'external_url', 'media_type'] as $requiredField) {
            $rules[$requiredField] = array_values(array_filter(
                $rules[$requiredField], static fn (mixed $rule): bool => $rule !== 'required',
            ));
        }
        $rules['expected_lock_version'] = ['required', 'integer', 'min:0'];

        return $rules;
    }
}
