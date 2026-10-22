<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CommitPersonImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('commit', $this->route('import')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'expected_mapping_version' => ['required', 'integer'],
        ];
    }
}
