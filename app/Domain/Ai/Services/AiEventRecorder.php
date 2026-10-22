<?php

namespace App\Domain\Ai\Services;

use App\Models\AiProviderCredential;
use App\Models\AiProviderCredentialVersion;
use App\Models\AiProviderEvent;
use App\Models\User;
use App\Models\Workspace;

class AiEventRecorder
{
    /** @param array<string, bool|int|string|null> $metadata */
    public function record(
        Workspace $workspace,
        string $event,
        ?User $actor = null,
        ?AiProviderCredential $credential = null,
        ?AiProviderCredentialVersion $version = null,
        ?string $fromState = null,
        ?string $toState = null,
        array $metadata = [],
    ): AiProviderEvent {
        return AiProviderEvent::query()->create([
            'workspace_id' => $workspace->id,
            'credential_id' => $credential?->id,
            'credential_version_id' => $version?->id,
            'actor_user_id' => $actor?->id,
            'actor_type' => $actor === null ? 'system' : 'user',
            'event' => $event,
            'from_state' => $fromState,
            'to_state' => $toState,
            'safe_metadata' => $metadata === [] ? null : $metadata,
            'created_at' => now(),
        ]);
    }
}
