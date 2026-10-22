<?php

namespace App\Console\Commands;

use App\Domain\Mail\Services\ManagedMailCapability;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Console\Command;
use Throwable;

/** Shared owner gate and redacted output helpers for managed mail. */
abstract class ManagedMailOperatorCommand extends Command
{
    /**
     * Resolve a verified workspace owner for this command. CLI processes do
     * not have an authenticated session, so an explicit --actor can be used
     * for audit attribution; when omitted, the lowest-ID eligible owner is
     * selected deterministically.
     *
     * @return array{workspace: Workspace, actor: User}|null
     */
    protected function operatorContext(ManagedMailCapability $capability): ?array
    {
        $workspaceOption = $this->option('workspace');
        if (! is_scalar($workspaceOption) || preg_match('/\A[1-9][0-9]*\z/', (string) $workspaceOption) !== 1) {
            $this->error('The --workspace option must be a positive numeric workspace ID.');

            return null;
        }

        $workspace = Workspace::query()->whereKey((int) $workspaceOption)->first();
        if (! $workspace instanceof Workspace) {
            $this->error('The requested workspace is not available for managed mail.');

            return null;
        }

        $actorOption = $this->option('actor');
        $actors = $actorOption !== null && $actorOption !== ''
            ? User::query()->whereKey((int) $actorOption)->get()
            : WorkspaceMembership::query()
                ->where('workspace_id', $workspace->id)
                ->where('role', WorkspaceMembership::ROLE_OWNER)
                ->with('user')
                ->orderBy('user_id')
                ->get()
                ->map(fn (WorkspaceMembership $membership): ?User => $membership->user)
                ->filter()
                ->values();

        foreach ($actors as $actor) {
            if ($capability->allows($actor, $workspace)) {
                return ['workspace' => $workspace, 'actor' => $actor];
            }
        }

        $this->error('A verified owner is required for the requested workspace.');

        return null;
    }

    /** @param array<string, mixed> $payload */
    protected function emit(array $payload): void
    {
        $json = $this->option('json');
        if ($json) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return;
        }

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_SLASHES);
            } elseif (is_bool($value)) {
                $value = $value ? 'yes' : 'no';
            }

            $this->line((string) $key.': '.(string) $value);
        }
    }

    protected function safeFailure(Throwable $exception, string $fallback): string
    {
        // Provider responses and arbitrary exception messages can contain
        // credentials or request payloads. Commands expose only a fixed
        // operator-safe message; detailed provider state stays in local rows.
        return $fallback;
    }
}
