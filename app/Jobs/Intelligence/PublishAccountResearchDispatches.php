<?php

namespace App\Jobs\Intelligence;

use App\Domain\Intelligence\Support\ModelAttribute;
use App\Models\AccountResearchDispatch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Publish one durable dispatch; the row remains retryable until the worker runs. */
final class PublishAccountResearchDispatches implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly string $dispatchId)
    {
        $this->onQueue((string) config('intelligence.queue', 'ai'));
    }

    public function handle(): void
    {
        $dispatch = AccountResearchDispatch::query()->find($this->dispatchId);
        if ($dispatch === null || $dispatch->published_at !== null || ModelAttribute::enumValue($dispatch->state) === 'retired') {
            return;
        }
        $stage = $dispatch->researchRun()->first()?->stages()
            ->where('stage_key', (string) $dispatch->stage_key)
            ->first();
        if ($stage === null) {
            $this->failLease('dispatch_missing');

            return;
        }
        RunAccountResearchStage::dispatch((int) $dispatch->workspace_id, (string) $dispatch->research_run_id, (string) $stage->id);
        // published_at intentionally remains null until the stage worker has
        // claimed the row. An expired lease safely republishes suppressed
        // unique jobs.
    }

    public function failed(?Throwable $exception): void
    {
        $this->failLease('dispatch_failed');
    }

    private function failLease(string $code): void
    {
        DB::table('account_research_dispatches')->where('id', $this->dispatchId)->update([
            'state' => 'pending',
            'available_at' => now()->addSeconds(30),
            'publish_lease_owner' => null,
            'publish_lease_expires_at' => null,
            'safe_error_code' => $code,
            'updated_at' => now(),
        ]);
    }
}
