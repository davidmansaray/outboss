<?php

namespace App\Domain\Objectives\Support;

use Illuminate\Validation\ValidationException;

final class SegmentCriteriaValidator
{
    public const SCHEMA_VERSION = 'segment_criteria_v1';

    public function __construct(private readonly SegmentFieldRegistry $fields) {}

    /** @param array<string, mixed> $criteria
     * @return array<string, mixed>
     */
    public function validate(array $criteria): array
    {
        $nodes = 0;
        $leaves = 0;
        $validated = $this->validateNode($criteria, 1, $nodes, $leaves, 'criteria');

        if ($nodes > (int) config('objectives.criteria.maximum_nodes', 50)) {
            throw ValidationException::withMessages(['criteria' => 'Segment criteria contain too many nodes.']);
        }
        if ($leaves > (int) config('objectives.criteria.maximum_leaves', 50)) {
            throw ValidationException::withMessages(['criteria' => 'Segment criteria contain too many conditions.']);
        }

        return $validated;
    }

    /** @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function validateNode(array $node, int $depth, int &$nodes, int &$leaves, string $path): array
    {
        $nodes++;
        if ($depth > (int) config('objectives.criteria.maximum_depth', 4)) {
            throw ValidationException::withMessages([$path => 'Segment criteria exceed the maximum nesting depth.']);
        }

        $groups = array_values(array_intersect(['all', 'any', 'not'], array_keys($node)));
        $isLeaf = array_key_exists('field', $node) || array_key_exists('operator', $node);
        if (($isLeaf ? 1 : 0) + count($groups) !== 1) {
            throw ValidationException::withMessages([$path => 'Each criteria node must be exactly one group or condition.']);
        }

        if ($isLeaf) {
            $leaves++;

            return $this->validateLeaf($node, $path);
        }

        $group = $groups[0];
        $children = $node[$group];
        if ($group === 'not') {
            if (! is_array($children) || array_is_list($children)) {
                throw ValidationException::withMessages([$path.'.not' => 'The not group must contain one criteria object.']);
            }

            return ['not' => $this->validateNode($children, $depth + 1, $nodes, $leaves, $path.'.not')];
        }
        if (! is_array($children) || ! array_is_list($children) || $children === []) {
            throw ValidationException::withMessages([$path.'.'.$group => 'Criteria groups must contain at least one condition.']);
        }

        $result = [];
        foreach ($children as $index => $child) {
            if (! is_array($child) || array_is_list($child)) {
                throw ValidationException::withMessages(["{$path}.{$group}.{$index}" => 'Each criteria child must be an object.']);
            }
            $result[] = $this->validateNode($child, $depth + 1, $nodes, $leaves, "{$path}.{$group}.{$index}");
        }

        return [$group => $result];
    }

    /** @param array<string, mixed> $node
     * @return array{field: string, operator: string, value?: mixed}
     */
    private function validateLeaf(array $node, string $path): array
    {
        if (! is_string($node['field'] ?? null) || ! is_string($node['operator'] ?? null)) {
            throw ValidationException::withMessages([$path => 'A criteria condition requires a field and operator.']);
        }
        $field = $node['field'];
        $operator = $node['operator'];
        $definition = $this->fields->get($field);
        if ($definition === null || ! in_array($operator, $definition['operators'], true)) {
            throw ValidationException::withMessages([$path => 'The criteria field or operator is not supported.']);
        }

        if ($operator === 'present') {
            return ['field' => $field, 'operator' => $operator];
        }
        if (! array_key_exists('value', $node)) {
            throw ValidationException::withMessages([$path.'.value' => 'A value is required for this condition.']);
        }
        $value = $node['value'];
        if (in_array($operator, ['in', 'not_in'], true) && (! is_array($value) || ! array_is_list($value) || $value === [])) {
            throw ValidationException::withMessages([$path.'.value' => 'This operator requires a non-empty list.']);
        }
        if ($definition['type'] === 'boolean' && ! is_bool($value)) {
            throw ValidationException::withMessages([$path.'.value' => 'This field requires a boolean value.']);
        }
        if ($definition['type'] === 'number' && ! is_int($value) && ! is_float($value)) {
            throw ValidationException::withMessages([$path.'.value' => 'This field requires a numeric value.']);
        }
        if ($operator === 'contains_normalized' && ! is_string($value)) {
            throw ValidationException::withMessages([$path.'.value' => 'This operator requires a string value.']);
        }

        return ['field' => $field, 'operator' => $operator, 'value' => $value];
    }
}
