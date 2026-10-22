<?php

namespace App\Mcp\Tools;

use App\Domain\Campaigns\Exceptions\GovernedCampaignInputMutation;
use App\Domain\Campaigns\Exceptions\GovernedCampaignLaunchRequired;
use App\Domain\Conversions\Exceptions\ConversionIdempotencyConflict;
use App\Domain\Gtm\Exceptions\GtmPayloadValidationException;
use App\Domain\Intelligence\Exceptions\AccountResearchBusy;
use App\Domain\Intelligence\Exceptions\AccountResearchConflict;
use App\Domain\Intelligence\Exceptions\AccountResearchPaused;
use App\Domain\Intelligence\Exceptions\ClaimValidationException;
use App\Domain\Intelligence\Exceptions\QualificationValidationException;
use App\Domain\Mail\Exceptions\SenderIdentityUnavailable;
use App\Http\Controllers\Api\V1\Concerns\MapsDomainExceptions;
use App\Mcp\Concerns\ChecksScopes;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use JsonException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Support\ValidationMessages;
use RuntimeException;
use Throwable;

abstract class OutbossTool extends Tool
{
    use ChecksScopes;
    use MapsDomainExceptions;

    protected const DEFAULT_PER_PAGE = 20;

    protected const MAX_PER_PAGE = 100;

    protected function perPage(Request $request): int
    {
        return min(self::MAX_PER_PAGE, max(1, $request->integer('per_page', self::DEFAULT_PER_PAGE)));
    }

    protected function page(Request $request): int
    {
        return max(1, $request->integer('page', 1));
    }

    /**
     * @return array<string, mixed>
     */
    protected function paginationSchema(JsonSchema $schema): array
    {
        return [
            'page' => $schema->integer()
                ->min(1)
                ->default(1)
                ->description('Page number. Defaults to 1.'),
            'per_page' => $schema->integer()
                ->min(1)
                ->max(self::MAX_PER_PAGE)
                ->default(self::DEFAULT_PER_PAGE)
                ->description('Results per page. Defaults to 20, maximum 100. Use page to fetch further results.'),
        ];
    }

    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $class
     * @return TModel
     */
    protected function authorised(Request $request, string $class, int $id, string $ability = 'view'): Model
    {
        $model = $class::query()->find($id);

        if (! $model instanceof $class) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        Gate::forUser($this->actor($request))->authorize($ability, $model);

        return $model;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function json(array $payload): Response
    {
        try {
            return Response::text(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } catch (JsonException) {
            return Response::error('The tool could not encode its response.');
        }
    }

    /**
     * @param  array<int, mixed>  $data
     * @return array{data: array<int, mixed>, meta: array{current_page: int, last_page: int, per_page: int, total: int}}
     */
    protected function pagePayload(array $data, int $page, int $perPage, int $total): array
    {
        $lastPage = max(1, (int) ceil($total / $perPage));

        return [
            'data' => $data,
            'meta' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
            ],
        ];
    }

    protected function senderIdentityError(): Response
    {
        return Response::error(SenderIdentityUnavailable::USER_MESSAGE);
    }

    protected function governedCampaignError(
        GovernedCampaignInputMutation|GovernedCampaignLaunchRequired $exception,
    ): Response {
        $code = $exception instanceof GovernedCampaignInputMutation
            ? GovernedCampaignInputMutation::CODE
            : GovernedCampaignLaunchRequired::CODE;

        return Response::error("Conflict [{$code}]. {$exception->getMessage()}");
    }

    /**
     * Turn known S2 domain failures into bounded, stable MCP errors. Unknown
     * failures are deliberately rethrown so the server's normal safe error
     * handling can report them without exposing provider payloads.
     */
    protected function s2Failure(Throwable $exception): Response
    {
        if ($exception instanceof ValidationException) {
            return Response::error(ValidationMessages::from($exception));
        }

        if ($exception instanceof AuthenticationException || $exception instanceof AuthorizationException) {
            return Response::error($exception->getMessage());
        }

        $code = match (true) {
            $exception instanceof AccountResearchPaused => 'account_research_paused',
            $exception instanceof AccountResearchConflict => 'account_research_conflict',
            $exception instanceof AccountResearchBusy => 'account_research_busy',
            $exception instanceof ConversionIdempotencyConflict => 'conversion_idempotency_conflict',
            $exception instanceof ClaimValidationException => 'claim_validation_failed',
            $exception instanceof QualificationValidationException => 'qualification_validation_failed',
            $exception instanceof GtmPayloadValidationException => 'offer_payload_invalid',
            $exception instanceof RuntimeException && in_array($exception->getMessage(), [
                'stale_version',
                'operation_replay_mismatch',
                'conversion_definition_already_exists',
                'conversion_account_required',
                'conversion_review_action_invalid',
                'conversion_event_already_reversed',
            ], true) => $exception->getMessage(),
            default => null,
        };

        if ($code === null) {
            throw $exception;
        }

        return Response::error("Conflict [{$code}]. Retry with current workspace state.");
    }

    /**
     * Resolve an ID through the authenticated workspace. This helper avoids
     * the global-ID lookup used by legacy V1 tools, which is unsafe for S2.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $class
     * @return TModel
     */
    protected function workspaceModel(Request $request, string $class, string|int $id): Model
    {
        $workspace = $this->actor($request)->currentWorkspace();
        $model = $class::query()
            ->where('workspace_id', $workspace->id)
            ->whereKey($id)
            ->first();

        if (! $model instanceof $class) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        return $model;
    }

    /**
     * @param  array<array-key,mixed>  $payload
     * @return array<string,mixed>
     */
    protected function boundedS2Payload(array $payload, string $field = 'payload', int $maxBytes = 512_000): array
    {
        if (array_is_list($payload)) {
            throw ValidationException::withMessages([$field => 'The payload must be an object.']);
        }

        $object = [];
        foreach ($payload as $key => $value) {
            if (! is_string($key)) {
                throw ValidationException::withMessages([$field => 'The payload must be an object.']);
            }

            $object[$key] = $value;
        }

        $encoded = json_encode($object, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (strlen($encoded) > $maxBytes) {
            throw ValidationException::withMessages([$field => 'The payload exceeds the bounded size limit.']);
        }

        return $object;
    }

    /**
     * Normalize a validated request list into the list shape expected by the
     * domain boundary. Invalid values are rejected even if validation rules
     * are changed or bypassed by a direct tool invocation.
     *
     * @return list<string>
     */
    protected function stringList(mixed $value, string $field): array
    {
        if (! is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (! is_string($item)) {
                throw ValidationException::withMessages([$field => 'Every item must be a string.']);
            }

            $items[] = $item;
        }

        return $items;
    }

    /**
     * Normalize validated qualification claim links before passing them to
     * the immutable evaluation DTO.
     *
     * @return list<array{claim_id:string,role:string}>
     */
    protected function qualificationClaims(mixed $value, string $field): array
    {
        if (! is_array($value)) {
            return [];
        }

        $claims = [];
        foreach ($value as $claim) {
            if (! is_array($claim) || ! is_string($claim['claim_id'] ?? null) || ! is_string($claim['role'] ?? null)) {
                throw ValidationException::withMessages([$field => 'Every claim must include a string claim_id and role.']);
            }

            $claims[] = [
                'claim_id' => $claim['claim_id'],
                'role' => $claim['role'],
            ];
        }

        return $claims;
    }

    protected function requireOwner(Request $request, string $message = 'Only the workspace owner can perform this action.'): void
    {
        $actor = $this->actor($request);
        if (! $actor->currentWorkspace()->isOwner($actor)) {
            throw new AuthorizationException($message);
        }
    }
}
