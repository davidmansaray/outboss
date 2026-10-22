<?php

namespace App\Domain\Conversions\Support;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

trait ConversionServiceSupport
{
    protected function assertMember(Workspace $workspace, ?User $actor): void
    {
        if ($actor !== null && ! $workspace->membershipFor($actor)) {
            throw new AuthorizationException('The actor is not a member of this workspace.');
        }
    }

    protected function assertOwner(Workspace $workspace, ?User $actor): void
    {
        if ($actor === null || ! $workspace->isOwner($actor)) {
            throw new AuthorizationException('Only the workspace owner may manage conversion credentials.');
        }
    }

    /** @param array<string,mixed> $payload */
    protected function requireBoundedJson(array $payload, string $field, int $bytes = 512_000): void
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false || strlen($encoded) > $bytes) {
            throw ValidationException::withMessages([$field => 'The payload exceeds the bounded size limit.']);
        }
    }
}
