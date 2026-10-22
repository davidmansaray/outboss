<?php

namespace App\Domain\Workspaces\Services;

use App\Domain\Gtm\Support\PublicWebsiteUrl;
use App\Jobs\Workspaces\FetchWorkspaceAvatar;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class WorkspaceAvatarManager
{
    public function websiteHash(string $website): string
    {
        return hash('sha256', $website);
    }

    public function dispatchRefresh(int $workspaceId, string $website): void
    {
        $normalized = PublicWebsiteUrl::normalize($website);
        if ($normalized === null) {
            return;
        }

        FetchWorkspaceAvatar::dispatch($workspaceId, $this->websiteHash($normalized));
    }

    public function dispatchRefreshAfterCommit(int $workspaceId, string $website): void
    {
        DB::afterCommit(fn () => $this->dispatchRefresh($workspaceId, $website));
    }

    /**
     * Clear an identity that belongs to the old website, then refresh only
     * after the enclosing profile transaction commits.
     */
    public function websiteChanged(Workspace $workspace, ?string $newWebsite): void
    {
        $locked = Workspace::query()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();
        $oldPath = is_string($locked->avatar_path) ? $locked->avatar_path : null;

        $locked->forceFill([
            'avatar_path' => null,
            'avatar_source_url' => null,
            'avatar_website_hash' => null,
            'avatar_refreshed_at' => null,
            'avatar_missing_since' => null,
        ])->save();

        $workspaceId = (int) $locked->id;
        DB::afterCommit(function () use ($workspaceId, $oldPath, $newWebsite): void {
            $this->deleteCachedPath($workspaceId, $oldPath);
            if ($newWebsite !== null) {
                $this->dispatchRefresh($workspaceId, $newWebsite);
            }
        });
    }

    public function deleteCachedPath(int $workspaceId, ?string $path): void
    {
        if ($path === null || ! str_starts_with($path, "workspace-avatars/{$workspaceId}/")) {
            return;
        }

        Storage::disk('public')->delete($path);
    }
}
