<?php

namespace App\Domain\Objectives\DTOs;

use App\Models\ExecutionPlanAction;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

final readonly class ExactPlanValidation
{
    /**
     * @param  list<array{code:string,message:string,action_id?:string}>  $blockers
     * @param  list<array{code:string,message:string,action_id?:string}>  $warnings
     * @param  array<string,string>  $actionInputHashes
     * @param  Collection<int, ExecutionPlanAction>  $actions
     */
    public function __construct(
        public array $blockers,
        public array $warnings,
        public array $actionInputHashes,
        public string $dependencyHash,
        public string $policyVersionId,
        public ?CarbonInterface $validUntil,
        public Collection $actions,
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
