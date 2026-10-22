<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CommitRecipientImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $campaign = $this->route('campaign');
        $import = $this->route('import');

        return ($this->user()?->can('update', $campaign) ?? false)
            && ($this->user()?->can('update', $import) ?? false);
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
