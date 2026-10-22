<?php

use App\Models\Account;
use App\Models\AccountResearchRun;
use App\Models\GtmProfileVersion;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('rejects a cross-workspace GTM version at the database boundary', function (): void {
    if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
        $this->markTestSkipped('Composite workspace constraints are verified on MySQL/MariaDB.');
    }

    $owner = User::factory()->create();
    $workspace = $owner->currentWorkspace();
    $account = Account::factory()->create([
        'workspace_id' => $workspace->id,
        'website' => 'https://constraint.example',
        'domain_normalized' => 'constraint.example',
    ]);
    $workspaceVersion = GtmProfileVersion::query()
        ->where('workspace_id', $workspace->id)
        ->firstOrFail();
    $run = AccountResearchRun::factory()->create([
        'workspace_id' => $workspace->id,
        'account_id' => $account->id,
        'gtm_profile_version_id' => $workspaceVersion->id,
    ]);

    $foreignOwner = User::factory()->create();
    $foreignVersion = GtmProfileVersion::query()
        ->where('workspace_id', $foreignOwner->currentWorkspace()->id)
        ->firstOrFail();

    expect(fn () => DB::table('account_research_runs')
        ->where('id', $run->id)
        ->update(['gtm_profile_version_id' => $foreignVersion->id]))
        ->toThrow(QueryException::class);
});
