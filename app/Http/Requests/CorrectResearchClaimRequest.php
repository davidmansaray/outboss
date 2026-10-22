<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CorrectResearchClaimRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'value' => ['required'],
            'value_type' => ['sometimes', 'string', 'in:string,url,date,integer,number,boolean,string_list,object'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
