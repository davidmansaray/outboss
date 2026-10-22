<?php

namespace App\Http\Resources\Api\V1;

use App\Domain\People\Support\ProvenanceSummary;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use RuntimeException;

abstract class ApiResource extends JsonResource
{
    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $class
     * @return TModel
     */
    protected function model(string $class): Model
    {
        $resource = $this->resource;

        if (! $resource instanceof $class) {
            throw new RuntimeException('Unexpected API resource model.');
        }

        return $resource;
    }

    protected function enumValue(mixed $value): ?string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        return is_string($value) ? $value : null;
    }

    protected function iso8601(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return Carbon::parse($value)->toIso8601String();
        }

        return null;
    }

    /**
     * Return the compact provenance projection shared by Account and Person
     * resources. The source tables are additive in S1, so this intentionally
     * remains safe while an older deployment is serving the resource during
     * the migration window.
     *
     * @return array{primary_source: array{id: string, name: string}|null, source_count: int, last_observed_at: string|null, has_conflicts: bool, has_restrictions: bool, source_omission_reason: string|null}
     */
    protected function provenanceSummary(Model $entity): array
    {
        return ProvenanceSummary::for($entity);
    }
}
