<?php

namespace Database\Factories;

use App\Domain\Mail\Enums\SendResolution;
use App\Models\SendAttempt;
use App\Models\SendAttemptResolution;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SendAttemptResolution>
 */
class SendAttemptResolutionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'send_attempt_id' => SendAttempt::factory(),
            'review_cycle' => 1,
            'resolution' => SendResolution::ProvenNotSent,
            'operator_identifier' => 'ops@outboss.test',
            'safe_evidence' => 'Provider sent folder empty for the attempt window.',
        ];
    }
}
