<?php

namespace App\Models;

use App\Domain\Ai\Enums\AiProviderName;
use App\Domain\Ai\Enums\AiTask;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property AiProviderName $provider
 * @property AiTask $task
 * @property string $operation
 * @property string $model_or_endpoint
 * @property string $native_unit
 * @property int|null $input_tokens
 * @property int|null $output_tokens
 * @property int|null $tavily_credits
 * @property int $cost_micros
 * @property string $cost_basis
 * @property string|null $provider_request_id
 * @property CarbonImmutable $occurred_at
 */
#[Fillable(['workspace_id', 'operation_id', 'budget_period_id', 'budget_run_id', 'budget_envelope_id', 'provider', 'operation', 'task', 'model_or_endpoint', 'entry_type', 'native_unit', 'input_tokens', 'output_tokens', 'reasoning_tokens', 'cache_tokens', 'tavily_credits', 'raw_provider_cost', 'cost_micros', 'cost_basis', 'cost_evidence_source', 'provider_request_id', 'provider_generation_id', 'occurred_at', 'created_at'])]
class AiUsageLedger extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'ai_usage_ledger';

    protected function casts(): array
    {
        return [
            'provider' => AiProviderName::class,
            'task' => AiTask::class,
            'budget_envelope_id' => 'string',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'reasoning_tokens' => 'integer',
            'cache_tokens' => 'integer',
            'tavily_credits' => 'integer',
            'cost_micros' => 'integer',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AiProviderOperation, $this> */
    public function providerOperation(): BelongsTo
    {
        return $this->belongsTo(AiProviderOperation::class, 'operation_id');
    }
}
