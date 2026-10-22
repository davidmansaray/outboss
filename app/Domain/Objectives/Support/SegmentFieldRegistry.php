<?php

namespace App\Domain\Objectives\Support;

final class SegmentFieldRegistry
{
    /** @return array<string, array{type: string, operators: list<string>}> */
    public function definitions(): array
    {
        return [
            'person.id' => ['type' => 'string', 'operators' => ['in']],
            'account.domain_present' => ['type' => 'boolean', 'operators' => ['eq', 'neq', 'present']],
            'account.tags' => ['type' => 'list', 'operators' => ['eq', 'neq', 'in', 'not_in', 'contains_normalized', 'present']],
            'account.source' => ['type' => 'string', 'operators' => ['eq', 'neq', 'in', 'not_in', 'contains_normalized', 'present']],
            'person.email_present' => ['type' => 'boolean', 'operators' => ['eq', 'neq', 'present']],
            'person.job_title' => ['type' => 'string', 'operators' => ['eq', 'neq', 'in', 'not_in', 'contains_normalized', 'present']],
            'person.tags' => ['type' => 'list', 'operators' => ['eq', 'neq', 'in', 'not_in', 'contains_normalized', 'present']],
            'person.source' => ['type' => 'string', 'operators' => ['eq', 'neq', 'in', 'not_in', 'contains_normalized', 'present']],
            'source.id' => ['type' => 'list', 'operators' => ['eq', 'neq', 'in', 'not_in', 'present']],
            'source.kind' => ['type' => 'list', 'operators' => ['eq', 'neq', 'in', 'not_in', 'present']],
            'source.purpose_rights' => ['type' => 'string', 'operators' => ['eq', 'neq', 'in', 'not_in', 'present']],
            'qualification.outcome' => ['type' => 'string', 'operators' => ['eq', 'neq', 'in', 'not_in', 'present']],
            'qualification.confidence' => ['type' => 'number', 'operators' => ['eq', 'neq', 'gte', 'lte', 'present']],
            'evidence.supported_claim_count' => ['type' => 'number', 'operators' => ['eq', 'neq', 'gte', 'lte', 'present']],
            'evidence.fresh_until' => ['type' => 'datetime', 'operators' => ['gte', 'lte', 'present']],
            'claim.company.category' => ['type' => 'string', 'operators' => ['eq', 'neq', 'in', 'not_in', 'contains_normalized', 'present']],
        ];
    }

    /** @return array{type: string, operators: list<string>}|null */
    public function get(string $field): ?array
    {
        return $this->definitions()[$field] ?? null;
    }
}
