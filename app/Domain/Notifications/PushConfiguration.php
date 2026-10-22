<?php

namespace App\Domain\Notifications;

/**
 * Validates the APNs settings. Push is available exactly when they are
 * complete and the private key is a usable EC key; otherwise the app keeps
 * working without alerts and /api/v1/me reports push: false.
 */
class PushConfiguration
{
    public function apns(): ?ApnsCredentials
    {
        return $this->check()['credentials'];
    }

    public function available(): bool
    {
        return $this->apns() !== null;
    }

    /**
     * @return array{credentials: ApnsCredentials|null, problem: string|null}
     */
    public function check(): array
    {
        $config = (array) config('outboss.push.apns', []);
        $keyId = trim((string) ($config['key_id'] ?? ''));
        $teamId = trim((string) ($config['team_id'] ?? ''));
        $bundleId = trim((string) ($config['bundle_id'] ?? ''));

        if ($keyId === '' && $teamId === '' && $bundleId === '' && $this->rawKey($config) === null) {
            return ['credentials' => null, 'problem' => 'APNs is not configured.'];
        }

        if (preg_match('/^[A-Z0-9]{10}$/', $keyId) !== 1) {
            return ['credentials' => null, 'problem' => 'APNS_KEY_ID must be the 10-character key ID from Apple.'];
        }

        if (preg_match('/^[A-Z0-9]{10}$/', $teamId) !== 1) {
            return ['credentials' => null, 'problem' => 'APNS_TEAM_ID must be the 10-character team ID from Apple.'];
        }

        if ($bundleId === '') {
            return ['credentials' => null, 'problem' => 'APNS_BUNDLE_ID is required.'];
        }

        $key = $this->rawKey($config);

        if ($key === null) {
            return ['credentials' => null, 'problem' => 'Set APNS_PRIVATE_KEY_PATH to a readable .p8 file, or APNS_PRIVATE_KEY to its contents.'];
        }

        $resource = openssl_pkey_get_private($key);
        $details = $resource === false ? false : openssl_pkey_get_details($resource);

        if ($details === false || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_EC) {
            return ['credentials' => null, 'problem' => 'The APNs private key is not a valid EC (.p8) key.'];
        }

        return [
            'credentials' => new ApnsCredentials(
                keyId: $keyId,
                teamId: $teamId,
                bundleId: $bundleId,
                privateKey: $key,
                timeoutSeconds: max(1, (int) ($config['timeout_seconds'] ?? 10)),
            ),
            'problem' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function rawKey(array $config): ?string
    {
        $inline = trim((string) ($config['private_key'] ?? ''));

        if ($inline !== '') {
            // Allow a single-line env value with escaped newlines.
            return str_replace('\n', "\n", $inline);
        }

        $path = trim((string) ($config['private_key_path'] ?? ''));

        if ($path === '' || ! is_readable($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        return $contents === false || trim($contents) === '' ? null : $contents;
    }
}
