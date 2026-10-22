<?php

namespace App\Domain\Campaigns\Services;

use App\Domain\Objectives\Services\CreateExecutionAuthorityScope;
use App\Domain\Objectives\Services\ExecutionAllowanceLedger;
use App\Models\Campaign;
use App\Models\CampaignComposition;
use App\Models\ExecutionAuthorityRevocation;
use App\Models\ExecutionAuthorityScope;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/** Derive finite draft permission; this never approves messages or renews a grant. */
final class PrepareCampaignSendingAllowance
{
    public function handle(Campaign $campaign, User $actor): void
    {
        // Caller holds the campaign lock. A replay reuses the selected scope.
        $composition = CampaignComposition::query()->where('workspace_id', $campaign->getAttribute('workspace_id'))
            ->where('campaign_id', $campaign->id)->orderByDesc('revision')->first();
        if ($composition === null) {
            return;
        }
        $recipients = $campaign->recipients()->where('workspace_id', $campaign->getAttribute('workspace_id'))->humanReviewAudience()->get();
        if ($recipients->isEmpty()) {
            return;
        }
        $people = $recipients->count();
        $accounts = $recipients->map(fn ($recipient) => $recipient->account_id ?? 'unlinked:'.$recipient->id)->unique()->count();
        $messages = $people * max(1, ($campaign->draftVersion ?? $campaign->publishedVersion)?->activeSteps->count() ?? 1);
        if ($composition->execution_authority_scope_id !== null) {
            // A manual/shared scope or any committed approval is never expanded.
            if ($composition->execution_authority_scope_id !== $campaign->automatic_sending_scope_id
                || ExecutionAuthorityRevocation::query()->where('workspace_id', $campaign->getAttribute('workspace_id'))->where('execution_authority_scope_id', $composition->execution_authority_scope_id)->exists()
                || app(ExecutionAllowanceLedger::class)->committed((int) $campaign->getAttribute('workspace_id'), $composition->execution_authority_scope_id)->isNotEmpty()) {
                return;
            }
            $existing = ExecutionAuthorityScope::query()->where('workspace_id', $campaign->getAttribute('workspace_id'))->findOrFail($composition->execution_authority_scope_id);
            $authority = $existing->authority;
            if ((int) $authority['maximum_people'] >= $people && (int) $authority['maximum_accounts'] >= $accounts
                && (int) $authority['maximum_messages'] >= $messages && Carbon::parse($authority['ends_at'])->isFuture()) {
                return;
            }
        }
        $start = $composition->sending_starts_at ?? now()->utc()->startOfSecond();
        // Campaign deadlines can be shortened/extended inside this finite
        // safety horizon without asking the user to edit a second date range.
        $end = $start->copy()->addDays((int) config('objectives.safety.maximum_horizon_days', 30));
        $scope = app(CreateExecutionAuthorityScope::class)->handle($actor->currentWorkspace(), $actor, [
            'name' => mb_substr($campaign->name.' — campaign sending', 0, 160),
            'operation_id' => (string) Str::uuid(),
            'authority' => [
                'starts_at' => $start->toAtomString(), 'ends_at' => $end->toAtomString(),
                'maximum_people' => $people,
                'maximum_accounts' => $accounts,
                'maximum_messages' => $messages,
                'maximum_ai_cost_micros' => 0, 'approval_required' => true,
            ],
        ]);
        app(SaveCampaignComposition::class)->handle($campaign, $actor, [
            'objective_goal_version_id' => $composition->objective_goal_version_id,
            'offer_version_id' => $composition->offer_version_id,
            'execution_authority_scope_id' => $scope->id,
            'expected_revision' => $composition->revision,
            'operation_id' => (string) Str::uuid(),
        ]);
        $campaign->forceFill(['automatic_sending_scope_id' => $scope->id])->save();
    }
}
