<?php

namespace App\Domain\Devices\Support;

use Illuminate\Support\Facades\Validator;

/**
 * The query the iOS app opens /mobile/authorize with. Anything unexpected is
 * refused without redirecting, so an unlisted redirect URI never receives a
 * code or an error.
 */
final class MobileAuthorizationRequest
{
    /**
     * @param  array<string, mixed>  $input
     * @return array{client: string, redirect_uri: string, code_challenge: string, code_challenge_method: string, state: string, device_name?: string|null}|null
     */
    public static function parameters(array $input): ?array
    {
        $validator = Validator::make($input, [
            'client' => ['required', 'in:ios'],
            'redirect_uri' => ['required', 'string', 'in:'.implode(',', (array) config('outboss.mobile.redirect_uris', []))],
            'code_challenge' => ['required', 'string', 'regex:'.Pkce::CHALLENGE_PATTERN],
            'code_challenge_method' => ['required', 'in:S256'],
            'state' => ['required', 'string', 'max:255'],
            'device_name' => ['nullable', 'string', 'max:120'],
        ]);

        if ($validator->fails()) {
            return null;
        }

        /** @var array{client: string, redirect_uri: string, code_challenge: string, code_challenge_method: string, state: string, device_name?: string|null} $validated */
        $validated = $validator->validated();

        return $validated;
    }

    /**
     * Identifies one sign-in attempt: a new attempt from the app always has a
     * new challenge and state.
     *
     * @param  array{client: string, redirect_uri: string, code_challenge: string, state: string}  $parameters
     */
    public static function fingerprint(array $parameters): string
    {
        return hash('sha256', implode("\n", [
            $parameters['client'],
            $parameters['redirect_uri'],
            $parameters['code_challenge'],
            $parameters['state'],
        ]));
    }
}
