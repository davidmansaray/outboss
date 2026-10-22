<?php

namespace App\Domain\Notifications;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends alerts through Apple's HTTP/2 provider API using token-based (.p8)
 * authentication. The signed JWT is reused for 50 minutes; Apple accepts a
 * token for up to an hour and rejects refreshing it more than every 20.
 */
class ApnsPushGateway implements PushGateway
{
    private const HOSTS = [
        'production' => 'https://api.push.apple.com',
        'sandbox' => 'https://api.sandbox.push.apple.com',
    ];

    public function __construct(private ApnsCredentials $credentials) {}

    public function send(string $deviceToken, string $environment, PushMessage $message): PushResult
    {
        $host = self::HOSTS[$environment];
        $headers = array_filter([
            'authorization' => 'bearer '.$this->jwt(),
            'apns-topic' => $this->credentials->bundleId,
            'apns-push-type' => 'alert',
            'apns-priority' => '10',
            'apns-collapse-id' => $message->collapseId,
        ], fn (?string $value): bool => $value !== null);

        try {
            $response = Http::withOptions(['version' => 2.0])
                ->withHeaders($headers)
                ->timeout($this->credentials->timeoutSeconds)
                ->asJson()
                ->post($host.'/3/device/'.$deviceToken, $message->payload());
        } catch (ConnectionException) {
            return PushResult::Retryable;
        }

        if ($response->successful()) {
            return PushResult::Delivered;
        }

        $reason = (string) $response->json('reason', '');

        if ($response->status() === 410 || in_array($reason, ['BadDeviceToken', 'Unregistered', 'DeviceTokenNotForTopic'], true)) {
            return PushResult::Unregistered;
        }

        if ($reason === 'ExpiredProviderToken') {
            Cache::forget($this->cacheKey());

            return PushResult::Retryable;
        }

        if ($response->status() === 429 || $response->serverError()) {
            return PushResult::Retryable;
        }

        Log::warning('APNs rejected a notification.', ['status' => $response->status(), 'reason' => $reason]);

        return PushResult::Rejected;
    }

    private function jwt(): string
    {
        return Cache::remember($this->cacheKey(), now()->addMinutes(50), function (): string {
            $header = $this->base64Url((string) json_encode(['alg' => 'ES256', 'kid' => $this->credentials->keyId]));
            $claims = $this->base64Url((string) json_encode(['iss' => $this->credentials->teamId, 'iat' => now()->getTimestamp()]));
            $signed = $header.'.'.$claims;

            openssl_sign($signed, $der, $this->credentials->privateKey, OPENSSL_ALGO_SHA256);

            return $signed.'.'.$this->base64Url(self::derToJose((string) $der));
        });
    }

    private function cacheKey(): string
    {
        return 'apns-jwt:'.$this->credentials->teamId.':'.$this->credentials->keyId;
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * OpenSSL returns an ASN.1 DER ECDSA signature; JWT ES256 needs the raw
     * 64-byte r||s form.
     */
    public static function derToJose(string $der): string
    {
        $offset = 2;

        if ((ord($der[1]) & 0x80) !== 0) {
            $offset += ord($der[1]) & 0x7F;
        }

        $read = function () use ($der, &$offset): string {
            $length = ord($der[$offset + 1]);
            $value = substr($der, $offset + 2, $length);
            $offset += 2 + $length;

            return str_pad(ltrim($value, "\x00"), 32, "\x00", STR_PAD_LEFT);
        };

        return $read().$read();
    }
}
