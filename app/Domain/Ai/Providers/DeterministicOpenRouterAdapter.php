<?php

namespace App\Domain\Ai\Providers;

use App\Domain\Ai\Contracts\AiProviderAdapter;
use App\Domain\Ai\Contracts\LlmProvider;
use App\Domain\Ai\DTOs\LlmRequest;
use App\Domain\Ai\DTOs\LlmResult;
use App\Domain\Ai\DTOs\ProviderTestResult;
use App\Models\AiProviderOperation;
use RuntimeException;

/** Deterministic, network-free adapter available only to the S2 browser-test app. */
final class DeterministicOpenRouterAdapter implements AiProviderAdapter, LlmProvider
{
    public const FIXTURE_ID = 's2-browser-v1';

    public function test(string $secret): ProviderTestResult
    {
        $this->assertFixture($secret);

        return new ProviderTestResult(
            nativeUnits: 0,
            nativeUnit: 'tokens',
            costMicros: 0,
            costBasis: 'provider_exact',
            providerRequestId: 'fake-openrouter-test-v1',
            providerGenerationId: 'fake-generation-test-v1',
            balanceSummary: 'Deterministic browser fixture',
            rawProviderCost: '0.000000',
            evidenceSource: 'response',
            providerResponseId: 'fake-response-test-v1',
        );
    }

    public function generate(string $secret, LlmRequest $request): LlmResult
    {
        $this->assertFixture($secret);
        if ($request->responseSchemaName === 'qualification') {
            $claimIds = [];
            try {
                $context = json_decode($request->content, true, 32, JSON_THROW_ON_ERROR);
                if (is_array($context) && is_array($context['accepted_claims'] ?? null)) {
                    foreach ($context['accepted_claims'] as $claim) {
                        if (is_array($claim) && is_string($claim['claim_id'] ?? null) && $claim['claim_id'] !== '') {
                            $claimIds[] = $claim['claim_id'];
                        }
                    }
                }
            } catch (\Throwable) {
                // The production boundary validates the input before this
                // adapter is reached. Keep the fixture deterministic if a
                // direct test supplies a non-JSON prompt.
            }
            $content = json_encode([
                'outcome' => 'needs_review',
                'confidence' => 0.61,
                'reasons' => ['The deterministic qualification fixture requires review.'],
                'missing_facts' => [],
                'disqualifiers' => [],
                'claim_ids' => array_values(array_unique(array_slice($claimIds, 0, 10))),
                'prohibited_inference' => false,
                'unsupported_causality' => false,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

            return new LlmResult(
                content: $content,
                inputTokens: 320,
                outputTokens: 80,
                reasoningTokens: 0,
                cacheTokens: 0,
                costMicros: 600,
                costBasis: 'provider_exact',
                providerRequestId: 'fake-openrouter-qualification-request-v1',
                providerGenerationId: 'fake-openrouter-qualification-generation-v1',
                rawProviderCost: '0.000600',
                providerResponseId: 'fake-openrouter-qualification-response-v1',
                totalTokens: 400,
                providerMetadata: ['fixture_id' => self::FIXTURE_ID, 'task' => 'qualification'],
            );
        }
        if ($request->responseSchemaName === 'message_drafting') {
            $outcome = $this->messageDraftingOutcome();
            $choiceIds = [];
            try {
                $context = json_decode($request->content, true, 32, JSON_THROW_ON_ERROR);
                if (is_array($context) && is_array($context['evidence_choices'] ?? null)) {
                    foreach ($context['evidence_choices'] as $choice) {
                        if (is_array($choice) && is_string($choice['choice_id'] ?? null) && $choice['choice_id'] !== '') {
                            $choiceIds[] = $choice['choice_id'];
                        }
                    }
                }
            } catch (\Throwable) {
                // Keep the browser fixture deterministic for direct tests.
            }
            $choiceIds = array_values(array_unique(array_slice($choiceIds, 0, 10)));
            $abstain = $outcome === 'abstain' || ($outcome === 'valid' && $choiceIds === []);
            $payload = [
                'message_pattern' => 'claim_offer_v1',
                'evidence_choice' => $abstain ? '' : (string) ($choiceIds[0] ?? ''),
                'cta_kind' => 'offer_action',
                'abstain' => $abstain,
                'abstention_reasons' => $abstain ? ['No approved evidence was available for this arm.'] : [],
            ];
            if ($outcome === 'unsupported_claim') {
                $payload['evidence_choice'] = 'evidence_v1_'.str_repeat('1', 64);
            } elseif ($outcome === 'unsafe_content') {
                $payload['body_template'] = 'I noticed you and can guarantee results against every competitor.';
            } elseif ($outcome === 'malformed_schema') {
                $payload = ['message_pattern' => 42];
            }
            $content = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

            return new LlmResult(
                content: $content,
                inputTokens: 280,
                outputTokens: 90,
                reasoningTokens: 0,
                cacheTokens: 0,
                costMicros: 700,
                costBasis: 'provider_exact',
                providerRequestId: 'fake-openrouter-message-drafting-request-v1',
                providerGenerationId: 'fake-openrouter-message-drafting-generation-v1',
                rawProviderCost: '0.000700',
                providerResponseId: 'fake-openrouter-message-drafting-response-v1',
                totalTokens: 370,
                providerMetadata: [
                    'fixture_id' => self::FIXTURE_ID,
                    'task' => 'message_drafting',
                    'outcome' => $outcome,
                ],
            );
        }
        $content = json_encode([
            'claims' => [
                [
                    'predicate' => 'company.business_model',
                    'value' => 'B2B software',
                    'value_type' => 'string',
                    'status' => 'supported',
                    'confidence' => 0.94,
                    'evidence' => [['source_handle' => 'SRC_1', 'edge_type' => 'supports', 'locator' => 'Business model']],
                ],
                [
                    'predicate' => 'company.gtm_motion',
                    'value' => 'Founder-led sales',
                    'value_type' => 'string',
                    'status' => 'inferred',
                    'confidence' => 0.61,
                    'evidence' => [['source_handle' => 'SRC_1', 'edge_type' => 'supports', 'locator' => 'Founder-led motion']],
                ],
                [
                    'predicate' => 'company.positioning',
                    'value' => 'Evidence automation',
                    'value_type' => 'string',
                    'status' => 'supported',
                    'confidence' => 0.82,
                    'evidence' => [
                        ['source_handle' => 'SRC_1', 'edge_type' => 'supports', 'locator' => 'Positioning statement'],
                        ['source_handle' => 'SRC_1', 'edge_type' => 'contradicts', 'locator' => 'Legacy positioning statement'],
                    ],
                ],
                [
                    'predicate' => 'company.employee_range',
                    'value' => 'Not publicly available',
                    'value_type' => 'string',
                    'status' => 'unsupported',
                    'confidence' => 0.0,
                    'evidence' => [],
                ],
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return new LlmResult(
            content: $content,
            inputTokens: 320,
            outputTokens: 210,
            reasoningTokens: 0,
            cacheTokens: 0,
            costMicros: 1200,
            costBasis: 'provider_exact',
            providerRequestId: 'fake-openrouter-request-v1',
            providerGenerationId: 'fake-openrouter-generation-v1',
            rawProviderCost: '0.001200',
            providerResponseId: 'fake-openrouter-response-v1',
            totalTokens: 530,
            providerMetadata: ['fixture_id' => self::FIXTURE_ID],
        );
    }

    public function reconcile(string $secret, AiProviderOperation $operation): ?ProviderTestResult
    {
        $this->assertFixture($secret);

        return null;
    }

    private function assertFixture(string $secret): void
    {
        if (! hash_equals(self::FIXTURE_ID, $secret)) {
            throw new RuntimeException('The deterministic OpenRouter fixture is unavailable.');
        }
    }

    private function messageDraftingOutcome(): string
    {
        if (! app()->environment('testing') || ! (bool) config('intelligence.testing.fake_providers', false)) {
            return 'valid';
        }
        $outcome = config('intelligence.testing.message_drafting_outcome', 'valid');
        if (! is_string($outcome) || ! in_array($outcome, [
            'valid',
            'abstain',
            'unsupported_claim',
            'unsafe_content',
            'malformed_schema',
        ], true)) {
            throw new RuntimeException('The deterministic message-drafting outcome is invalid.');
        }

        return $outcome;
    }
}
