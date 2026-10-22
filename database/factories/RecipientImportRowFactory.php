<?php

namespace Database\Factories;

use App\Domain\Campaigns\Enums\ImportRowIssue;
use App\Models\RecipientImport;
use App\Models\RecipientImportRow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecipientImportRow>
 */
class RecipientImportRowFactory extends Factory
{
    public function definition(): array
    {
        $email = fake()->unique()->safeEmail();

        return [
            'recipient_import_id' => RecipientImport::factory(),
            'row_number' => 1,
            'raw_values' => ['email' => $email, 'name' => 'Jane Doe', 'company' => 'Acme'],
            'email_address' => $email,
            'email_normalized' => strtolower($email),
            'full_name' => 'Jane Doe',
            'first_name' => 'Jane',
            'company' => 'Acme',
            'issue' => ImportRowIssue::Ready,
            'row_hash' => hash('sha256', $email),
        ];
    }
}
