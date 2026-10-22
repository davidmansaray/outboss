<?php

namespace App\Domain\Objectives\Services;

use App\Domain\Mail\Enums\SendAttemptStatus;
use App\Domain\Objectives\Enums\ExecutionActionEventType;
use App\Models\ExecutionActionEvent;
use App\Models\Exposure;
use App\Models\SendAttempt;

/** Bounded repair for accepted/sent mail that is missing its commercial projection. */
final class ReconcileObjectiveExecution
{
    public function __construct(private readonly RecordObjectiveExposure $recordExposure) {}

    public function handle(?int $workspaceId = null, int $limit = 100): int
    {
        $limit = max(1, min($limit, 500));
        $attempts = SendAttempt::query()
            ->when($workspaceId !== null, fn ($query) => $query->where('workspace_id', $workspaceId))
            ->whereIn('status', [SendAttemptStatus::ProviderAccepted->value, SendAttemptStatus::Sent->value])
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('execution_plan_action_compilations')
                    ->whereColumn('execution_plan_action_compilations.workspace_id', 'send_attempts.workspace_id')
                    ->whereColumn('execution_plan_action_compilations.campaign_recipient_id', 'send_attempts.campaign_recipient_id')
                    ->whereColumn('execution_plan_action_compilations.sequence_step_id', 'send_attempts.sequence_step_id');
            })
            ->where(function ($repair): void {
                $repair->whereNotExists(function ($query): void {
                    $query->selectRaw('1')
                        ->from('exposures')
                        ->whereColumn('exposures.workspace_id', 'send_attempts.workspace_id')
                        ->whereColumn('exposures.send_attempt_id', 'send_attempts.id');
                })->orWhere(function ($confirmed): void {
                    $confirmed->where('status', SendAttemptStatus::Sent->value)
                        ->whereNotExists(function ($query): void {
                            $query->selectRaw('1')
                                ->from('execution_action_events')
                                ->whereColumn('execution_action_events.workspace_id', 'send_attempts.workspace_id')
                                ->whereColumn('execution_action_events.send_attempt_id', 'send_attempts.id')
                                ->where('execution_action_events.event', ExecutionActionEventType::ConfirmedSent->value);
                        });
                });
            })
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'workspace_id']);

        $repaired = 0;
        foreach ($attempts as $attempt) {
            $hadExposure = Exposure::query()
                ->where('workspace_id', $attempt->getAttribute('workspace_id'))
                ->where('send_attempt_id', $attempt->id)
                ->exists();
            $hadConfirmation = ExecutionActionEvent::query()
                ->where('workspace_id', $attempt->getAttribute('workspace_id'))
                ->where('send_attempt_id', $attempt->id)
                ->where('event', ExecutionActionEventType::ConfirmedSent->value)
                ->exists();
            $exposure = $this->recordExposure->handle(
                (int) $attempt->getAttribute('workspace_id'),
                (int) $attempt->id,
            );
            $nowConfirmed = $attempt->getRawOriginal('status') !== SendAttemptStatus::Sent->value
                || ExecutionActionEvent::query()
                    ->where('workspace_id', $attempt->getAttribute('workspace_id'))
                    ->where('send_attempt_id', $attempt->id)
                    ->where('event', ExecutionActionEventType::ConfirmedSent->value)
                    ->exists();
            if ($exposure instanceof Exposure && (! $hadExposure || (! $hadConfirmation && $nowConfirmed))) {
                $repaired++;
            }
        }

        return $repaired;
    }
}
