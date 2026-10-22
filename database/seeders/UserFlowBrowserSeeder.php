<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Isolated sample-data workspace for operator user-flow browser tests.
 * Reuses the demo mailboxes, people, campaigns, inbox threads, and
 * suppressions. Does not talk to real mail providers.
 */
final class UserFlowBrowserSeeder extends Seeder
{
    public const USER_EMAIL = UniboxDemoSeeder::USER_EMAIL;

    public function run(): void
    {
        if (! app()->environment('testing')) {
            throw new \RuntimeException('The user-flow browser fixture can only run in the explicit testing environment.');
        }

        $this->call(UniboxDemoSeeder::class);
        $this->call(WorkflowDemoSeeder::class);
    }
}
