<?php

namespace Database\Factories;

use App\Domain\Integrations\Enums\ExternalOperationAttemptKind;
use App\Domain\Integrations\Enums\ExternalOperationAttemptStatus;
use App\Models\ExternalOperation;
use App\Models\ExternalOperationAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ExternalOperationAttempt> */
class ExternalOperationAttemptFactory extends Factory
{
    protected $model = ExternalOperationAttempt::class;

    public function definition(): array
    {
        return [
            'operation_id' => ExternalOperation::factory(),
            'attempt_number' => 1,
            'kind' => ExternalOperationAttemptKind::Execute,
            'status' => ExternalOperationAttemptStatus::Started,
            'provider_request_id' => null,
            'provider_snapshot_id' => null,
            'safe_error_code' => null,
            'http_status' => null,
            'retry_after_at' => null,
            'latency_ms' => null,
        ];
    }
}
