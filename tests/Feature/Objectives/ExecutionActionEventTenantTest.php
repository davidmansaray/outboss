<?php

use App\Domain\Objectives\Enums\ExecutionActionEventType;
use App\Domain\Objectives\Services\RecordExecutionActionEvent;
use App\Models\CampaignRecipient;
use App\Models\ExecutionActionEvent;
use App\Models\Message;
use App\Models\SendAttempt;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/ObjectiveScenarioFixtures.php';

it('rejects foreign workspace execution event references in both the service and schema', function (): void {
    $fixture = compiledS3GuardFixture();
    $action = $fixture['plan']->actions()->sole();
    $event = ExecutionActionEvent::query()->where('execution_plan_action_id', $action->id)->firstOrFail();
    $beforeCount = ExecutionActionEvent::query()->where('execution_plan_action_id', $action->id)->count();
    $foreignReferences = [
        'campaign_recipient_id' => CampaignRecipient::factory()->create(),
        'message_id' => Message::factory()->create(),
        'send_attempt_id' => SendAttempt::factory()->create(),
    ];
    foreach ($foreignReferences as $column => $reference) {
        expect((int) $reference->getAttribute('workspace_id'))->not->toBe((int) $action->workspace_id);
        expect(fn () => app(RecordExecutionActionEvent::class)->handle(
            $action,
            ExecutionActionEventType::Compiled,
            recipient: $reference instanceof CampaignRecipient ? $reference : null,
            message: $reference instanceof Message ? $reference : null,
            attempt: $reference instanceof SendAttempt ? $reference : null,
        ))->toThrow(InvalidArgumentException::class);
        $attributes = $event->getRawOriginal();
        $attributes['id'] = (string) Str::uuid();
        $attributes['source_event_fingerprint'] = hash('sha256', $column);
        $attributes[$column] = $reference->id;
        expect(fn () => DB::transaction(fn () => DB::table('execution_action_events')->insert($attributes)))
            ->toThrow(QueryException::class);
    }
    expect(ExecutionActionEvent::query()->where('execution_plan_action_id', $action->id)->count())->toBe($beforeCount);
});
