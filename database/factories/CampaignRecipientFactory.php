<?php

namespace Database\Factories;

use App\Domain\Campaigns\Enums\MailboxAssignment;
use App\Domain\Campaigns\Enums\RecipientStatus;
use App\Domain\Mail\Support\EmailNormalizer;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Mailbox;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CampaignRecipient>
 */
class CampaignRecipientFactory extends Factory
{
    public function definition(): array
    {
        $email = fake()->unique()->safeEmail();

        return [
            'campaign_id' => Campaign::factory(),
            'workspace_id' => fn (array $attributes) => Campaign::query()
                ->whereKey($attributes['campaign_id'])
                ->firstOrFail()
                ->getAttribute('workspace_id'),
            'email_address' => $email,
            'email_normalized' => strtolower(trim($email)),
            'email_domain' => EmailNormalizer::domain($email),
            'unsubscribe_token' => (string) Str::uuid(),
            'full_name' => fake()->name(),
            'first_name' => fake()->firstName(),
            'company' => fake()->company(),
            'status' => RecipientStatus::Queued,
            'mailbox_assignment' => MailboxAssignment::Rotation,
            'lock_version' => 0,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (CampaignRecipient $recipient): void {
            $campaign = Campaign::query()->findOrFail($recipient->campaign_id);

            if (! $recipient->mailbox_id) {
                $recipient->mailbox_id = Mailbox::factory()->create([
                    'user_id' => $campaign->user_id,
                    'workspace_id' => $campaign->getAttribute('workspace_id'),
                ])->id;
            }

            if (! $recipient->sequence_version_id) {
                $recipient->sequence_version_id = $campaign->draft_sequence_version_id;
            }
        });
    }
}
