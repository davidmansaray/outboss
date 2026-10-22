<?php

namespace Database\Factories;

use App\Domain\Campaigns\Enums\SequenceSendAs;
use App\Models\SequenceStep;
use App\Models\SequenceVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SequenceStep>
 */
class SequenceStepFactory extends Factory
{
    public function definition(): array
    {
        return [
            'sequence_version_id' => SequenceVersion::factory(),
            'position' => 1,
            'title' => 'Intro',
            'delay_minutes' => 0,
            'send_as' => SequenceSendAs::NewThread,
            'subject_template' => 'Quick question about {{company}}',
            'body_template' => '{{first_name}} — noticed {{company}} is hiring.',
            'subject_spintax_enabled' => false,
            'body_spintax_enabled' => false,
        ];
    }
}
