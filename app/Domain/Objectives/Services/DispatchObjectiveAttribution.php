<?php

namespace App\Domain\Objectives\Services;

use App\Jobs\Objectives\AssessObjectiveAttribution;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Publish only scalar identity after the Conversion transaction is durable. */
final class DispatchObjectiveAttribution
{
    public function afterCommit(int $workspaceId, string $conversionEventId): void
    {
        DB::afterCommit(static function () use ($workspaceId, $conversionEventId): void {
            try {
                AssessObjectiveAttribution::dispatch($workspaceId, $conversionEventId);
            } catch (Throwable $exception) {
                Log::warning('Objective attribution dispatch failed.', [
                    'workspace_id' => $workspaceId,
                    'conversion_event_id' => $conversionEventId,
                    'exception' => $exception::class,
                ]);
            }
        });
    }
}
