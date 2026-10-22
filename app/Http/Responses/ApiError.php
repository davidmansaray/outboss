<?php

namespace App\Http\Responses;

use App\Domain\Campaigns\Exceptions\GovernedCampaignInputMutation;
use App\Domain\Campaigns\Exceptions\GovernedCampaignLaunchRequired;
use App\Domain\Campaigns\Exceptions\GovernedExecutionBlocked;
use App\Domain\Devices\Exceptions\DeviceAuthException;
use App\Domain\Mail\Exceptions\ClientOperationConflict;
use App\Domain\Mail\Exceptions\SendOperationIncomplete;
use App\Domain\Objectives\Exceptions\ObjectiveRunConflict;
use App\Domain\Prospecting\Exceptions\SourceIdempotencyConflict;
use App\Models\PersonalAccessToken;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

class ApiError
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public static function json(string $type, string $message, int $status, array $extra = []): JsonResponse
    {
        return response()->json([
            'error' => [
                'type' => $type,
                'message' => $message,
                ...$extra,
            ],
        ], $status);
    }

    public static function fromException(Throwable $exception): ?JsonResponse
    {
        return match (true) {
            $exception instanceof DeviceAuthException => self::json(
                $exception->type,
                $exception->getMessage(),
                $exception->status,
            ),
            $exception instanceof GovernedCampaignInputMutation => self::json(
                GovernedCampaignInputMutation::CODE,
                GovernedCampaignInputMutation::USER_MESSAGE,
                409,
            ),
            $exception instanceof GovernedCampaignLaunchRequired => self::json(
                GovernedCampaignLaunchRequired::CODE,
                GovernedCampaignLaunchRequired::USER_MESSAGE,
                409,
            ),
            $exception instanceof GovernedExecutionBlocked => self::json(
                $exception->reasonCode,
                $exception->getMessage(),
                409,
                ['blockers' => $exception->blockers],
            ),
            $exception instanceof SourceIdempotencyConflict => self::json(
                'source_idempotency_conflict',
                $exception->getMessage(),
                409,
            ),
            $exception instanceof ObjectiveRunConflict => self::json(
                'objective_run_conflict',
                $exception->getMessage(),
                409,
            ),
            $exception instanceof ClientOperationConflict => self::json(
                'client_operation_conflict',
                ClientOperationConflict::USER_MESSAGE,
                409,
            ),
            $exception instanceof SendOperationIncomplete => self::json(
                'send_not_confirmed',
                SendOperationIncomplete::USER_MESSAGE,
                409,
            ),
            $exception instanceof ValidationException => self::json(
                'validation_error',
                $exception->getMessage(),
                422,
                ['errors' => $exception->errors()],
            ),
            $exception instanceof AuthenticationException => self::json(
                'unauthenticated',
                'Authentication required.',
                401,
            ),
            $exception instanceof AuthorizationException => self::json(
                'forbidden',
                $exception->getMessage() !== '' ? $exception->getMessage() : 'This action is unauthorised.',
                403,
            ),
            $exception instanceof ModelNotFoundException,
            $exception instanceof NotFoundHttpException => self::json(
                'not_found',
                'The requested resource was not found.',
                404,
            ),
            $exception instanceof AccessDeniedHttpException => self::json(
                'forbidden',
                $exception->getMessage() !== '' ? $exception->getMessage() : 'This action is unauthorised.',
                403,
            ),
            $exception instanceof TooManyRequestsHttpException => self::json(
                'rate_limited',
                'Too many requests.',
                429,
            ),
            $exception instanceof HttpExceptionInterface => self::json(
                self::typeForStatus($exception->getStatusCode()),
                $exception->getMessage() !== '' ? $exception->getMessage() : 'Request failed.',
                $exception->getStatusCode(),
            ),
            default => null,
        };
    }

    /**
     * An expired access token gets its own error type so the iOS app knows to
     * refresh and retry instead of sending the person back to sign-in.
     */
    public static function forUnauthenticated(Request $request): JsonResponse
    {
        $bearer = $request->bearerToken();
        $token = $bearer === null ? null : PersonalAccessToken::findToken($bearer);

        if ($token instanceof PersonalAccessToken
            && $token->expires_at !== null
            && $token->expires_at->isPast()) {
            return self::json('token_expired', 'This access token has expired. Refresh it and retry.', 401);
        }

        return self::json('unauthenticated', 'Authentication required.', 401);
    }

    private static function typeForStatus(int $status): string
    {
        return match ($status) {
            Response::HTTP_BAD_REQUEST => 'bad_request',
            Response::HTTP_UNAUTHORIZED => 'unauthenticated',
            Response::HTTP_FORBIDDEN => 'forbidden',
            Response::HTTP_NOT_FOUND => 'not_found',
            Response::HTTP_UNPROCESSABLE_ENTITY => 'validation_error',
            Response::HTTP_TOO_MANY_REQUESTS => 'rate_limited',
            default => 'http_error',
        };
    }
}
