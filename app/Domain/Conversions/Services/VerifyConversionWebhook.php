<?php

namespace App\Domain\Conversions\Services;

use App\Domain\Conversions\DTOs\ConversionWebhookVerification;
use App\Models\ConversionSourceConnection;
use Carbon\CarbonImmutable;

/** Verify timestamped HMAC-SHA256 webhook signatures without exposing secrets. */
final class VerifyConversionWebhook
{
    public function __construct(private readonly ConversionSourceConnectionManager $connections) {}

    public function handle(
        ConversionSourceConnection $connection,
        string $rawBody,
        string $signatureHeader,
        ?int $timestamp = null,
    ): ConversionWebhookVerification {
        [$headerTimestamp, $signatures] = $this->parseHeader($signatureHeader);
        $timestamp ??= $headerTimestamp;
        if ($timestamp === null || $timestamp <= 0 || $signatures === []) {
            return new ConversionWebhookVerification(false, safeErrorCode: 'signature_invalid');
        }
        $signedAt = CarbonImmutable::createFromTimestamp($timestamp);
        $now = CarbonImmutable::now();
        if (abs($now->getTimestamp() - $timestamp) > $this->maximumReplayWindow($connection)) {
            return new ConversionWebhookVerification(false, timestamp: $timestamp, safeErrorCode: 'signature_replay');
        }

        foreach ($this->connections->verificationVersions($connection, $signedAt) as $version) {
            $secret = $version->encrypted_secret;
            if (! is_string($secret) || $secret === '') {
                continue;
            }
            $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);
            $legacyExpected = hash_hmac('sha256', $rawBody, $secret);
            foreach ($signatures as $signature) {
                if (hash_equals($expected, $signature) || hash_equals($legacyExpected, $signature)) {
                    return new ConversionWebhookVerification(true, $version, $timestamp);
                }
            }
        }

        return new ConversionWebhookVerification(false, timestamp: $timestamp, safeErrorCode: 'signature_invalid');
    }

    public function verify(
        ConversionSourceConnection $connection,
        string $rawBody,
        string $signatureHeader,
        ?int $timestamp = null,
    ): bool {
        return $this->handle($connection, $rawBody, $signatureHeader, $timestamp)->verified;
    }

    /** @return array{0:int|null,1:list<string>} */
    private function parseHeader(string $header): array
    {
        $timestamp = null;
        $signatures = [];
        foreach (preg_split('/\s*,\s*/', trim($header)) ?: [] as $part) {
            [$key, $value] = array_pad(explode('=', $part, 2), 2, null);
            if ($key === null || $value === null) {
                continue;
            }
            $key = strtolower(trim($key));
            $value = trim($value);
            if ($key === 't' && preg_match('/^[0-9]{1,12}$/', $value) === 1) {
                $timestamp = (int) $value;
            }
            if (in_array($key, ['v1', 'sha256', 'signature'], true) && preg_match('/^[a-f0-9]{64}$/i', $value) === 1) {
                $signatures[] = strtolower($value);
            }
        }
        if ($signatures === [] && preg_match('/^[a-f0-9]{64}$/i', trim($header)) === 1) {
            $signatures[] = strtolower(trim($header));
        }

        return [$timestamp, array_values(array_unique($signatures))];
    }

    private function maximumReplayWindow(ConversionSourceConnection $connection): int
    {
        $configured = $connection->versions()->max('replay_window_seconds');

        return max(30, min(3600, (int) ($configured ?: 300)));
    }
}
