<?php

namespace App\Domain\Ai\Services;

use App\Domain\Ai\DTOs\AiCostEstimate;
use InvalidArgumentException;

class AiCostEstimator
{
    public function openRouter(string $modelId, int $requestBytes, int $maximumOutputTokens): AiCostEstimate
    {
        $models = config('ai.models', []);
        $model = is_array($models) ? ($models[$modelId] ?? null) : null;
        if (! is_array($model)) {
            throw new InvalidArgumentException('The selected model is not in the curated catalog.');
        }

        $inputTokens = (int) ceil(max(0, $requestBytes) / 4) + 512;
        if ($inputTokens + $maximumOutputTokens > (int) $model['context_tokens']) {
            throw new InvalidArgumentException('The request upper bound exceeds the model context window.');
        }

        $inputCost = (int) ceil(($inputTokens * (int) $model['input_micros_per_million_tokens']) / 1_000_000);
        $outputCost = (int) ceil(($maximumOutputTokens * (int) $model['output_micros_per_million_tokens']) / 1_000_000);

        return new AiCostEstimate($inputTokens + $maximumOutputTokens, 'tokens', $inputCost + $outputCost);
    }

    public function tavilySearch(string $depth, int $creditPriceMicros): AiCostEstimate
    {
        $credits = $depth === 'advanced' ? 2 : 1;

        return new AiCostEstimate($credits, 'credits', $credits * $creditPriceMicros);
    }

    public function tavilyExtract(int $urlCount, string $depth, int $creditPriceMicros): AiCostEstimate
    {
        $multiplier = $depth === 'advanced' ? 2 : 1;
        $credits = (int) ceil(max(1, $urlCount) / 5) * $multiplier;

        return new AiCostEstimate($credits, 'credits', $credits * $creditPriceMicros);
    }
}
