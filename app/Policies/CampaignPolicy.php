<?php

namespace App\Policies;

use App\Models\Campaign;
use App\Models\CampaignComposition;
use App\Models\ExecutionPlanActionCompilation;
use App\Models\ExecutionPlanCampaign;
use App\Models\User;
use App\Models\WorkspaceMembership;
use App\Policies\Concerns\BelongsToWorkspace;

class CampaignPolicy
{
    use BelongsToWorkspace;

    public function view(User $user, Campaign $campaign): bool
    {
        return $this->memberOfWorkspace($user, $campaign);
    }

    public function update(User $user, Campaign $campaign): bool
    {
        return $this->memberOfWorkspace($user, $campaign);
    }

    public function delete(User $user, Campaign $campaign): bool
    {
        return $this->memberOfWorkspace($user, $campaign);
    }

    public function launch(User $user, Campaign $campaign): bool
    {
        return $this->canControlExecution($user, $campaign);
    }

    public function resume(User $user, Campaign $campaign): bool
    {
        return $this->canControlExecution($user, $campaign);
    }

    private function canControlExecution(User $user, Campaign $campaign): bool
    {
        if (! $this->memberOfWorkspace($user, $campaign)) {
            return false;
        }
        $governed = CampaignComposition::query()->where('campaign_id', $campaign->id)->exists()
            || ExecutionPlanCampaign::query()->where('campaign_id', $campaign->id)->exists()
            || ExecutionPlanActionCompilation::query()->where('campaign_id', $campaign->id)->exists();
        if (! $governed) {
            return true;
        }

        return WorkspaceMembership::query()
            ->where('workspace_id', $campaign->getAttribute('workspace_id'))
            ->where('user_id', $user->id)
            ->where('role', WorkspaceMembership::ROLE_OWNER)
            ->exists();
    }
}
