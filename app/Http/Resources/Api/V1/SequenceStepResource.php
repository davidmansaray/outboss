<?php

namespace App\Http\Resources\Api\V1;

use App\Models\SequenceStep;
use Illuminate\Http\Request;

class SequenceStepResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $step = $this->model(SequenceStep::class);

        return [
            'id' => $step->id,
            'title' => $step->title,
            'position' => $step->position,
            'delay_minutes' => $step->delay_minutes,
            'send_as' => $this->enumValue($step->getAttribute('send_as')),
            'subject' => $step->subject_template,
            'body' => $step->body_template,
            'created_at' => $this->iso8601($step->getAttribute('created_at')),
            'updated_at' => $this->iso8601($step->getAttribute('updated_at')),
        ];
    }
}
