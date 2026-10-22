<?php

namespace App\Domain\Campaigns\Services;

use App\Domain\Campaigns\Enums\ImportStatus;
use App\Models\RecipientImport;
use Illuminate\Support\Facades\Storage;

class ExpireRecipientImport
{
    public function handle(): int
    {
        $expired = 0;

        RecipientImport::query()
            ->whereIn('status', [ImportStatus::Previewed, ImportStatus::Failed])
            ->where('expires_at', '<=', now())
            ->each(function (RecipientImport $import) use (&$expired): void {
                $this->purge($import, ImportStatus::Expired);
                $expired++;
            });

        RecipientImport::query()
            ->where('status', ImportStatus::Completed)
            ->where('updated_at', '<=', now()->subDay())
            ->each(fn (RecipientImport $import) => $this->purge($import, ImportStatus::Completed));

        return $expired;
    }

    private function purge(RecipientImport $import, ImportStatus $status): void
    {
        if ($import->private_path) {
            Storage::disk('local')->delete($import->private_path);
        }

        $import->rows()->delete();
        $import->forceFill([
            'status' => $status,
            'private_path' => null,
        ])->save();
    }
}
