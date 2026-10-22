<?php

namespace App\Domain\Ai\Services;

use App\Domain\Ai\Enums\AiOperationStatus;
use App\Models\AiProviderCredentialVersion;
use App\Models\AiProviderOperation;
use Illuminate\Support\Facades\DB;

class WipeRetiredAiCredentials
{
    public function handle(): int
    {
        $wiped = 0;
        AiProviderCredentialVersion::query()
            ->where('status', 'retiring')
            ->whereNotNull('encrypted_secret')
            ->orderBy('id')
            ->limit(100)
            ->pluck('id')
            ->each(function (int $versionId) use (&$wiped): void {
                $didWipe = DB::transaction(function () use ($versionId): bool {
                    $version = AiProviderCredentialVersion::query()->whereKey($versionId)->lockForUpdate()->first();
                    if ($version === null || $version->status !== 'retiring' || $version->encrypted_secret === null) {
                        return false;
                    }

                    $referenced = AiProviderOperation::query()
                        ->where('credential_version_id', $version->id)
                        ->whereIn('status', [
                            AiOperationStatus::Reserved,
                            AiOperationStatus::Executing,
                            AiOperationStatus::Uncertain,
                            AiOperationStatus::Reconciling,
                        ])
                        ->exists();
                    if ($referenced && ($version->retire_after === null || $version->retire_after->isFuture())) {
                        return false;
                    }

                    $version->forceFill([
                        'encrypted_secret' => null,
                        'status' => 'wiped',
                        'wiped_at' => now(),
                    ])->save();

                    return true;
                }, 3);

                if ($didWipe) {
                    $wiped++;
                }
            });

        return $wiped;
    }
}
