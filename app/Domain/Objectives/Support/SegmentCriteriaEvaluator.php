<?php

namespace App\Domain\Objectives\Support;

use App\Domain\Objectives\Enums\TruthValue;
use Carbon\CarbonImmutable;

final class SegmentCriteriaEvaluator
{
    /** @param array<string, mixed> $criteria
     * @param  array<string, mixed>  $context
     * @return array{result: TruthValue, conditions: list<array<string, mixed>>, missing_fields: list<string>}
     */
    public function evaluate(array $criteria, array $context): array
    {
        $conditions = [];
        $missing = [];
        $result = $this->node($criteria, $context, $conditions, $missing);

        return ['result' => $result, 'conditions' => $conditions, 'missing_fields' => array_values(array_unique($missing))];
    }

    /** @param array<string, mixed> $node
     * @param  array<string, mixed>  $context
     * @param  list<array<string, mixed>>  $conditions
     * @param  list<string>  $missing
     */
    private function node(array $node, array $context, array &$conditions, array &$missing): TruthValue
    {
        if (isset($node['all'])) {
            $sawUnknown = false;
            foreach ($node['all'] as $child) {
                $value = $this->node($child, $context, $conditions, $missing);
                if ($value === TruthValue::False) {
                    return TruthValue::False;
                }
                $sawUnknown = $sawUnknown || $value === TruthValue::Unknown;
            }

            return $sawUnknown ? TruthValue::Unknown : TruthValue::True;
        }
        if (isset($node['any'])) {
            $sawUnknown = false;
            foreach ($node['any'] as $child) {
                $value = $this->node($child, $context, $conditions, $missing);
                if ($value === TruthValue::True) {
                    return TruthValue::True;
                }
                $sawUnknown = $sawUnknown || $value === TruthValue::Unknown;
            }

            return $sawUnknown ? TruthValue::Unknown : TruthValue::False;
        }
        if (isset($node['not'])) {
            return $this->node($node['not'], $context, $conditions, $missing)->negate();
        }

        $field = (string) $node['field'];
        $actual = data_get($context, $field);
        if ($actual === null) {
            $missing[] = $field;
            $conditions[] = ['field' => $field, 'operator' => $node['operator'], 'result' => TruthValue::Unknown->value];

            return TruthValue::Unknown;
        }
        $result = $this->compare($actual, (string) $node['operator'], $node['value'] ?? null);
        $conditions[] = ['field' => $field, 'operator' => $node['operator'], 'result' => $result->value];

        return $result;
    }

    private function compare(mixed $actual, string $operator, mixed $expected): TruthValue
    {
        $matches = match ($operator) {
            'present' => $actual !== '' && $actual !== [],
            'eq' => is_array($actual) ? in_array($expected, $actual, true) : $actual === $expected,
            'neq' => is_array($actual) ? ! in_array($expected, $actual, true) : $actual !== $expected,
            'in' => is_array($actual)
                ? array_intersect($actual, (array) $expected) !== []
                : in_array($actual, (array) $expected, true),
            'not_in' => is_array($actual)
                ? array_intersect($actual, (array) $expected) === []
                : ! in_array($actual, (array) $expected, true),
            'gte' => $this->comparable($actual) >= $this->comparable($expected),
            'lte' => $this->comparable($actual) <= $this->comparable($expected),
            'contains_normalized' => $this->containsNormalized($actual, (string) $expected),
            default => false,
        };

        return $matches ? TruthValue::True : TruthValue::False;
    }

    private function comparable(mixed $value): float|int
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        return CarbonImmutable::parse((string) $value)->getTimestamp();
    }

    private function containsNormalized(mixed $actual, string $expected): bool
    {
        $needle = mb_strtolower(trim($expected));
        foreach ((array) $actual as $value) {
            if (str_contains(mb_strtolower(trim((string) $value)), $needle)) {
                return true;
            }
        }

        return false;
    }
}
