<?php

namespace Database\Seeders;

use App\Domain\Objectives\Services\StartObjectiveRun;
use App\Models\Objective;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Queue;
use RuntimeException;

/** Disposable, deterministic supplied-prospect fixture for the S3 UI proof. */
final class S3BrowserSeeder extends Seeder
{
    public const USER_EMAIL = 's3-browser@outboss.test';

    public function run(): void
    {
        if (! app()->environment('testing')
            || ! config('intelligence.testing.fake_providers')
            || config('database.default') !== 'sqlite'
            || basename((string) config('database.connections.sqlite.database')) !== 's3-browser.sqlite') {
            throw new RuntimeException('S3 browser fixtures require the dedicated testing SQLite database and fake providers.');
        }
        if (User::query()->exists() || Objective::query()->exists()) {
            throw new RuntimeException('Seed the S3 browser scenario only into a new disposable database.');
        }

        require base_path('tests/Browser/s3-clock.php');
        require_once base_path('tests/Support/ObjectiveScenarioFixtures.php');
        Queue::fake();
        $fixture = s3IncrementThreeReadyFixture(startDelayMinutes: 0);
        $fixture['owner']->forceFill([
            'name' => 'S3 Browser Owner',
            'email' => self::USER_EMAIL,
            'timezone' => 'UTC',
        ])->save();
        $run = app(StartObjectiveRun::class)->handle(
            $fixture['objective'],
            $fixture['experiment'],
            $fixture['owner'],
            's3-browser-draft',
        );
        $run = runS3IncrementThreeToApproval($run);
        $plan = $run->executionPlans()->sole();
        $this->command->info('S3 browser plan ready for owner approval: '.$plan->id);
    }
}
