<?php

namespace App\Domain\Mail\Services;

use App\Domain\Mail\Enums\SuppressionReason;
use App\Domain\Mail\Support\EmailNormalizer;
use App\Models\SuppressionEntry;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Validation\ValidationException;

class AddSuppressionEntry
{
    public function handle(User $user, Workspace $workspace, string $email, SuppressionReason $reason = SuppressionReason::Manual): SuppressionEntry
    {
        if (! EmailNormalizer::isValid($email)) {
            throw ValidationException::withMessages([
                'email' => 'Enter a valid email address.',
            ]);
        }

        $normalized = EmailNormalizer::normalize($email);

        return SuppressionEntry::query()->firstOrCreate(
            [
                'workspace_id' => $workspace->id,
                'email_normalized' => $normalized,
            ],
            [
                'user_id' => $user->id,
                'email_address' => trim($email),
                'reason' => $reason,
            ],
        );
    }
}
