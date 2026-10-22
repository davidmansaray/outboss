<?php

namespace Database\Factories;

use App\Models\PersonImport;
use App\Models\PersonImportRow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PersonImportRow>
 */
class PersonImportRowFactory extends Factory
{
    public function definition(): array
    {
        $email = fake()->unique()->safeEmail();

        return [
            'person_import_id' => PersonImport::factory(),
            'row_number' => 1,
            'raw_values' => ['email' => $email, 'name' => 'Jane Doe', 'company' => 'Acme'],
            'email_address' => $email,
            'email_normalized' => strtolower($email),
            'full_name' => 'Jane Doe',
            'first_name' => 'Jane',
            'job_title' => null,
            'company' => 'Acme',
            'tags' => [],
            'issue' => PersonImportRow::ISSUE_NEW,
            'action' => PersonImportRow::ACTION_CREATE,
            'row_hash' => hash('sha256', $email),
        ];
    }
}
