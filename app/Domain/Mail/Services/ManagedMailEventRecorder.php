<?php

namespace App\Domain\Mail\Services;

use App\Models\ManagedMailboxAccount;
use App\Models\ManagedMailDomain;
use App\Models\ManagedMailEvent;
use App\Models\User;
use App\Models\Workspace;
use LogicException;

/**
 * Record append-only managed-mail lifecycle events with a safe metadata
 * allow-list. Secrets and provider payloads are dropped before persistence.
 */
final class ManagedMailEventRecorder
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        Workspace $workspace,
        string $event,
        ?User $actor = null,
        ?ManagedMailDomain $domain = null,
        ?ManagedMailboxAccount $account = null,
        ?string $fromState = null,
        ?string $toState = null,
        array $metadata = [],
    ): ManagedMailEvent {
        if ($domain !== null && (int) $domain->workspace_id !== (int) $workspace->id) {
            throw new LogicException('managed_mail_workspace_mismatch');
        }

        if ($account !== null && $account->workspaceId() !== (int) $workspace->id) {
            throw new LogicException('managed_mail_workspace_mismatch');
        }

        if ($account !== null && $domain !== null && (int) $account->managed_mail_domain_id !== (int) $domain->id) {
            throw new LogicException('managed_mail_target_mismatch');
        }

        return ManagedMailEvent::query()->create([
            'workspace_id' => $workspace->id,
            'managed_mail_domain_id' => $domain?->id,
            'managed_mailbox_account_id' => $account?->id,
            'actor_user_id' => $actor?->id,
            'actor_type' => $actor === null ? 'system' : 'user',
            'event' => substr(trim($event), 0, 48),
            'from_state' => $fromState !== null ? substr($fromState, 0, 32) : null,
            'to_state' => $toState !== null ? substr($toState, 0, 32) : null,
            'safe_metadata' => ($safe = $this->safeMetadata($metadata)) === [] ? null : $safe,
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, bool|int|float|string|null|array<string, mixed>>
     */
    private function safeMetadata(array $metadata, int $depth = 0): array
    {
        if ($depth > 2) {
            return [];
        }

        $safe = [];
        foreach ($metadata as $key => $value) {
            $key = substr((string) $key, 0, 64);
            if ($key === '' || preg_match('/(?:pass(word)?|secret|token|credential|api[_-]?key|authorization|body|payload)/i', $key) === 1) {
                continue;
            }

            if (is_array($value)) {
                $nested = $this->safeMetadata($value, $depth + 1);
                if ($nested !== []) {
                    $safe[$key] = $nested;
                }

                continue;
            }

            if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
                $safe[$key] = $value;

                continue;
            }

            if (is_string($value)) {
                $safe[$key] = substr(str_replace(["\r", "\n"], ' ', trim($value)), 0, 500);
            }
        }

        return $safe;
    }
}
