<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Domain\Conversions\Exceptions\ConversionIdempotencyConflict;
use App\Domain\Gtm\Exceptions\GtmPayloadValidationException;
use App\Domain\Intelligence\Exceptions\AccountResearchBusy;
use App\Domain\Intelligence\Exceptions\AccountResearchConflict;
use App\Domain\Intelligence\Exceptions\AccountResearchPaused;
use App\Domain\Intelligence\Exceptions\ClaimValidationException;
use App\Domain\Intelligence\Exceptions\QualificationValidationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Small, transport-only helpers shared by the S2 API controllers.
 *
 * Domain services deliberately use domain exceptions rather than HTTP
 * responses. Keeping this translation at the edge gives REST a stable error
 * shape without leaking provider, credential, or raw payload details.
 */
trait HandlesS2Transport
{
    protected function idempotencyKey(Request $request, bool $required = false): ?string
    {
        $bodyKey = $request->input('idempotency_key');
        $headerKey = $request->header('Idempotency-Key');
        $key = is_string($headerKey) && trim($headerKey) !== ''
            ? trim($headerKey)
            : (is_string($bodyKey) ? trim($bodyKey) : '');

        if ($required && $key === '') {
            throw ValidationException::withMessages([
                'idempotency_key' => 'An Idempotency-Key header or body value is required.',
            ]);
        }

        if ($key !== '' && strlen($key) > 160) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'The idempotency key must be 160 characters or fewer.',
            ]);
        }

        return $key === '' ? null : $key;
    }

    /** @return array<string,mixed> */
    protected function boundedPayload(mixed $payload, string $field = 'payload', int $maxBytes = 512_000): array
    {
        if (! is_array($payload) || array_is_list($payload)) {
            throw ValidationException::withMessages([$field => 'The payload must be a JSON object.']);
        }

        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false || strlen($encoded) > $maxBytes) {
            throw ValidationException::withMessages([$field => 'The payload exceeds the bounded size limit.']);
        }

        return $payload;
    }

    protected function s2Failure(Throwable $exception): JsonResponse
    {
        if ($exception instanceof AccountResearchPaused) {
            return $this->errorResponse('account_research_paused', $exception->getMessage(), 503);
        }

        if ($exception instanceof AccountResearchConflict) {
            return $this->errorResponse('conflict', 'The research request conflicts with an existing input or run.', 409, [
                'code' => 'account_research_conflict',
            ]);
        }

        if ($exception instanceof AccountResearchBusy) {
            return $this->errorResponse('conflict', 'An Account research run is already active or requires reconciliation.', 409, [
                'code' => 'account_research_busy',
            ]);
        }

        if ($exception instanceof ConversionIdempotencyConflict) {
            return $this->errorResponse('conflict', 'The conversion delivery key was reused for different content.', 409, [
                'code' => 'conversion_idempotency_conflict',
            ]);
        }

        if ($exception instanceof ClaimValidationException) {
            return $this->errorResponse('validation_error', 'The Claim could not be accepted.', 422, [
                'errors' => ['claim' => [$exception->getMessage()]],
            ]);
        }

        if ($exception instanceof QualificationValidationException) {
            return $this->errorResponse('validation_error', 'The qualification decision could not be accepted.', 422, [
                'errors' => ['qualification' => [$exception->getMessage()]],
            ]);
        }

        if ($exception instanceof GtmPayloadValidationException) {
            return $this->errorResponse('validation_error', 'The Offer payload is invalid.', 422, [
                'errors' => $this->validationErrors($exception->errors),
            ]);
        }

        if ($exception instanceof RuntimeException) {
            $code = match ($exception->getMessage()) {
                'stale_version' => 'stale_version',
                'operation_replay_mismatch' => 'operation_replay_mismatch',
                'asset_version_missing' => 'asset_version_missing',
                'asset_operation_result_missing' => 'asset_operation_result_missing',
                'conversion_definition_already_exists' => 'conversion_definition_conflict',
                'conversion_event_already_reversed' => 'conversion_event_already_reversed',
                'conversion_account_not_in_workspace' => 'conversion_account_not_in_workspace',
                'conversion_account_required' => 'conversion_account_required',
                'conversion_review_action_invalid' => 'conversion_review_action_invalid',
                default => null,
            };
            if ($code !== null) {
                return $this->errorResponse('conflict', 'The requested change conflicts with the current resource state.', 409, [
                    'code' => $code,
                ]);
            }
        }

        throw $exception;
    }

    /**
     * @param  array<string,mixed>  $errors
     * @return array<string,list<string>>
     */
    private function validationErrors(array $errors): array
    {
        $result = [];
        foreach ($errors as $field => $message) {
            $result[(string) $field] = is_array($message)
                ? array_values(array_map('strval', $message))
                : [(string) $message];
        }

        return $result;
    }

    /** @param array<string,mixed> $extra */
    private function errorResponse(string $type, string $message, int $status, array $extra = []): JsonResponse
    {
        return response()->json([
            'error' => [
                'type' => $type,
                'message' => $message,
                ...$extra,
            ],
        ], $status);
    }
}
