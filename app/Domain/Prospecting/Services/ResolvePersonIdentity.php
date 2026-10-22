<?php

namespace App\Domain\Prospecting\Services;

use App\Domain\Mail\Support\EmailNormalizer;
use App\Models\Person;
use App\Models\SuppressionEntry;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

final class ResolvePersonIdentity
{
    public function handle(Workspace $workspace, string $email): ?Person
    {
        if (! EmailNormalizer::isValid($email)) {
            throw ValidationException::withMessages(['email' => 'Enter a valid email address.']);
        }

        return $this->query($workspace, EmailNormalizer::normalize($email))
            ->lockForUpdate()
            ->first();
    }

    public function resolve(Workspace $workspace, string $email): ?Person
    {
        return $this->handle($workspace, $email);
    }

    public function isSuppressed(Workspace $workspace, string $email): bool
    {
        $normalized = EmailNormalizer::normalize($email);

        return SuppressionEntry::query()
            ->where('workspace_id', $workspace->id)
            ->where('email_normalized', $normalized)
            ->exists();
    }

    /** @return Builder<Person> */
    private function query(Workspace $workspace, string $email): Builder
    {
        return Person::query()
            ->where('workspace_id', $workspace->id)
            ->where('email_normalized', $email);
    }
}
