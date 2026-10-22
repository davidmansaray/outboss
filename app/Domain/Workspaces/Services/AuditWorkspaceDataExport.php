<?php

namespace App\Domain\Workspaces\Services;

use App\Models\ProspectSource;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceDataExportEvent;

final class AuditWorkspaceDataExport
{
    public function record(
        Workspace $workspace,
        User $user,
        string $exportType,
        ?ProspectSource $source = null,
    ): WorkspaceDataExportEvent {
        return WorkspaceDataExportEvent::query()->create([
            'workspace_id' => $workspace->id,
            'prospect_source_id' => $source?->id,
            'user_id' => $user->id,
            'export_type' => mb_strcut($exportType, 0, 80, 'UTF-8'),
            'format' => 'csv',
            'policy_hash' => $source?->currentVersion()->value('policy_hash'),
            'created_at' => now(),
        ]);
    }
}
