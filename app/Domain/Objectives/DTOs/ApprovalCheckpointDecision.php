<?php

namespace App\Domain\Objectives\DTOs;

use App\Models\ApprovalBatch;
use App\Models\PolicyEvaluation;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

final readonly class ApprovalCheckpointDecision
{
    /**
     * @param  list<array{code:string,message:string,action_id?:string}>  $blockers
     * @param  list<array{code:string,message:string,action_id?:string}>  $warnings
     * @param  array<string,string>  $actionInputHashes
     * @param  Collection<int, PolicyEvaluation>  $evaluations
     */
    public function __construct(
        public array $blockers,
        public array $warnings,
        public array $actionInputHashes,
        public string $dependencyHash,
        public string $policyVersionId,
        public ?CarbonInterface $validUntil,
        public Collection $evaluations,
        public ?ApprovalBatch $batch,
    ) {}

    public function allowed(): bool
    {
        return $this->blockers === [];
    }

    public function primaryCode(): string
    {
        return (string) ($this->blockers[0]['code'] ?? 'approval_blocked');
    }
}
