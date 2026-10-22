<?php

namespace App\Http\Requests;

use App\Domain\Campaigns\Enums\CampaignStatus;
use App\Models\Campaign;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('campaign')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'send_mode' => ['sometimes', 'in:rotate,pin'],
            'mailbox_ids' => ['sometimes', 'array'],
            'mailbox_ids.*' => ['integer'],
            'send_window_start' => ['sometimes', 'date_format:H:i'],
            'send_window_end' => ['sometimes', 'date_format:H:i', 'after:send_window_start'],
            'business_days_only' => ['sometimes', 'boolean'],
            'open_tracking_enabled' => ['sometimes', 'boolean'],
            'daily_send_limit' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'automatic_allowance' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var Campaign $campaign */
            $campaign = $this->route('campaign');
            $sendingSetupFields = [
                'send_mode',
                'mailbox_ids',
                'send_window_start',
                'send_window_end',
                'business_days_only',
                'open_tracking_enabled',
                'daily_send_limit',
                'automatic_allowance',
            ];
            $changingSendingSetup = collect($sendingSetupFields)->contains(
                fn (string $field): bool => $this->has($field)
            );

            if (! $changingSendingSetup || $this->allowsEditing($campaign->getAttribute('status'))) {
                return;
            }

            $message = 'Pause the campaign before changing the sending setup.';

            foreach ($sendingSetupFields as $field) {
                if ($this->has($field)) {
                    $validator->errors()->add($field, $message);
                }
            }
        });
    }

    private function allowsEditing(mixed $status): bool
    {
        if ($status instanceof CampaignStatus) {
            return $status->allowsEditing();
        }

        return in_array($status, [CampaignStatus::Draft->value, CampaignStatus::Paused->value], true);
    }
}
