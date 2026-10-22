<?php

namespace App\Domain\Prospecting\DTOs;

use App\Domain\Prospecting\Enums\SourceRunState;
use App\Models\Account;
use App\Models\Person;
use App\Models\SourceObservation;
use App\Models\SourceRun;

final readonly class IngestionResult
{
    /**
     * @param  list<SourceObservation>  $observations
     * @param  list<Account>  $accounts
     * @param  list<Person>  $people
     * @param  array<string, int>  $counts
     */
    public function __construct(
        public SourceRun $run,
        public array $observations = [],
        public array $accounts = [],
        public array $people = [],
        public array $counts = [],
        public bool $replayed = false,
    ) {}

    public function firstCanonical(): Account|Person|null
    {
        return $this->accounts[0] ?? $this->people[0] ?? null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'run_id' => $this->run->id,
            'state' => SourceRunState::from((string) $this->run->getRawOriginal('state'))->value,
            'observation_ids' => array_map(static fn (SourceObservation $observation): string => (string) $observation->id, $this->observations),
            'account_ids' => array_map(static fn (Account $account): int => (int) $account->id, $this->accounts),
            'person_ids' => array_map(static fn (Person $person): int => (int) $person->id, $this->people),
            'counts' => $this->counts,
            'replayed' => $this->replayed,
        ];
    }
}
