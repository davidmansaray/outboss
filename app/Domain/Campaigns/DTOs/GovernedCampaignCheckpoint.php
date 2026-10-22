<?php

namespace App\Domain\Campaigns\DTOs;

use App\Domain\Objectives\DTOs\ApprovalCheckpointDecision;
use App\Models\ExecutionPlanAction;

/** A typed result that distinguishes an ordinary Campaign from governed mail. */
final readonly class GovernedCampaignCheckpoint
{
    /** @param list<array{code:string,message:string,action_id?:string}> $blockers */
    public function __construct(
        public bool $governed,
        public array $blockers = [],
        public ?ExecutionPlanAction $action = null,
        public ?ApprovalCheckpointDecision $approval = null,
    ) {}

    public static function generic(): self
    {
        return new self(false);
    }

    public function allowed(): bool
    {
        return ! $this->governed || $this->blockers === [];
    }

    public function primaryCode(): string
    {
        return (string) ($this->blockers[0]['code'] ?? 'governed_execution_blocked');
    }
}
