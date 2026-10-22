<?php

namespace App\Domain\Mail\Services;

use App\Domain\Mail\DTOs\RemoteSenderIdentity;
use App\Domain\Mail\Enums\MailProviderName;
use App\Domain\Mail\Exceptions\SenderIdentityUnavailable;
use App\Domain\Mail\Support\EmailNormalizer;
use App\Models\Mailbox;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MailboxSenderIdentityService
{
    public const KEY_LENGTH = 64;

    /**
     * Replace the provider snapshot and reconcile the mailbox default.
     *
     * A null sender_identities value is reserved for providers which do not
     * expose configurable From identities (and legacy rows). An empty array
     * means a supported provider was checked but returned no usable identity.
     *
     * @param  list<RemoteSenderIdentity>  $identities
     */
    public function sync(Mailbox $mailbox, array $identities, bool $supported): Mailbox
    {
        if (! $supported) {
            $mailbox->forceFill([
                'sender_identities' => null,
                'selected_sender_identity_key' => null,
            ])->save();

            return $mailbox;
        }

        $normalized = $this->normalizeRemote($identities);
        $selected = $this->chooseDefault(
            $mailbox->getAttribute('selected_sender_identity_key'),
            $normalized,
            $mailbox->email_address,
        );

        $mailbox->forceFill([
            'sender_identities' => $normalized,
            'selected_sender_identity_key' => $selected,
        ])->save();

        return $mailbox;
    }

    /**
     * @return list<array{key: string, email: string, name: string|null, mode: string|null}>
     */
    public function clientOptions(Mailbox $mailbox): array
    {
        $identities = $this->activeStoredIdentities($mailbox);

        return array_map(fn (array $identity): array => [
            'key' => $identity['key'],
            'email' => $identity['email'],
            'name' => $identity['name'] ?? null,
            'mode' => $identity['mode'] ?? null,
        ], $identities);
    }

    /**
     * @return array{key: string, email: string, name: string|null}
     */
    public function effective(Mailbox $mailbox): array
    {
        $stored = $mailbox->getAttribute('sender_identities');

        if (is_array($stored)) {
            $active = $this->activeStoredIdentities($mailbox);
            $selectedKey = $mailbox->getAttribute('selected_sender_identity_key');
            $selected = collect($active)->first(
                fn (array $identity): bool => is_string($selectedKey)
                    && hash_equals($identity['key'], $selectedKey),
            );
            $identity = $selected ?: ($active[0] ?? null);

            if ($identity !== null) {
                return [
                    'key' => $identity['key'],
                    'email' => $identity['email'],
                    'name' => $identity['name'] ?? null,
                ];
            }

            if ($this->isZoho($mailbox)) {
                throw new SenderIdentityUnavailable;
            }
        }

        return [
            'key' => self::keyForEmail($mailbox->email_address),
            'email' => EmailNormalizer::normalize($mailbox->email_address),
            'name' => $this->sanitizeName($mailbox->display_name),
        ];
    }

    /**
     * @return array{key: string, email: string, name: string|null}
     */
    public function forReply(Mailbox $mailbox): array
    {
        return $this->effective($mailbox);
    }

    public function assertSnapshotAllowed(Mailbox $mailbox, ?string $email): void
    {
        $email = EmailNormalizer::normalize((string) $email);

        if (! EmailNormalizer::isValid($email) || ! $this->isAllowed($mailbox, $email)) {
            throw new SenderIdentityUnavailable;
        }
    }

    public function select(Mailbox $mailbox, string $key): Mailbox
    {
        if (! preg_match('/\A[a-f0-9]{'.self::KEY_LENGTH.'}\z/', $key)) {
            throw ValidationException::withMessages([
                'identity_key' => 'Choose one of the available reply identities.',
            ]);
        }

        return DB::transaction(function () use ($mailbox, $key): Mailbox {
            $locked = Mailbox::query()
                ->whereKey($mailbox->id)
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                throw (new ModelNotFoundException)->setModel(Mailbox::class, [$mailbox->id]);
            }

            $identity = collect($this->activeStoredIdentities($locked))->first(
                fn (array $option): bool => hash_equals($option['key'], $key),
            );

            if (! $identity) {
                throw ValidationException::withMessages([
                    'identity_key' => 'That reply identity is no longer available. Refresh and try again.',
                ]);
            }

            $locked->forceFill(['selected_sender_identity_key' => $key])->save();

            return $locked->fresh(['connection']);
        });
    }

    public static function keyForEmail(string $email): string
    {
        return hash('sha256', EmailNormalizer::normalize($email));
    }

    /**
     * @param  list<RemoteSenderIdentity>  $identities
     * @return list<array{key: string, email: string, name: string|null, remote_id: string|null, mode: string|null, active: bool}>
     */
    private function normalizeRemote(array $identities): array
    {
        $byEmail = [];

        foreach ($identities as $identity) {
            $email = EmailNormalizer::normalize($this->bounded($identity->emailAddress, 320));

            if (! EmailNormalizer::isValid($email)) {
                continue;
            }

            $key = self::keyForEmail($email);
            $candidate = [
                'key' => $key,
                'email' => $email,
                'name' => $this->sanitizeName($identity->displayName),
                'remote_id' => $this->bounded($identity->remoteId, 120),
                'mode' => $this->bounded($identity->mode, 40),
                'active' => (bool) $identity->active,
            ];

            // Zoho sends duplicate rows for some aliases. Since the send API
            // only accepts fromAddress, address is the effective identity.
            if (! isset($byEmail[$email]) || ($candidate['active'] && ! $byEmail[$email]['active'])) {
                $byEmail[$email] = $candidate;
            }
        }

        $normalized = array_values($byEmail);
        usort($normalized, fn (array $a, array $b): int => [$a['email'], $a['name'] ?? ''] <=> [$b['email'], $b['name'] ?? '']);

        return $normalized;
    }

    /**
     * @param  list<array{key: string, email: string, name: string|null, remote_id: string|null, mode: string|null, active: bool}>  $identities
     */
    private function chooseDefault(?string $previousKey, array $identities, string $primaryEmail): ?string
    {
        foreach ($identities as $identity) {
            if ($identity['active'] && is_string($previousKey) && hash_equals($identity['key'], $previousKey)) {
                return $identity['key'];
            }
        }

        $primaryKey = self::keyForEmail($primaryEmail);

        foreach ($identities as $identity) {
            if ($identity['active'] && hash_equals($identity['key'], $primaryKey)) {
                return $identity['key'];
            }
        }

        return collect($identities)->firstWhere('active', true)['key'] ?? null;
    }

    /**
     * @return list<array{key: string, email: string, name: string|null, remote_id: string|null, mode: string|null, active: bool}>
     */
    private function activeStoredIdentities(Mailbox $mailbox): array
    {
        $stored = $mailbox->getAttribute('sender_identities');

        if (! is_array($stored)) {
            return [];
        }

        $active = [];

        foreach ($stored as $identity) {
            if (
                ! is_array($identity)
                || ! is_string($identity['key'] ?? null)
                || ! is_string($identity['email'] ?? null)
                || ! EmailNormalizer::isValid($identity['email'])
                || ($identity['active'] ?? true) !== true
            ) {
                continue;
            }

            $active[] = [
                'key' => $identity['key'],
                'email' => EmailNormalizer::normalize($identity['email']),
                'name' => is_string($identity['name'] ?? null) ? $identity['name'] : null,
                'remote_id' => is_string($identity['remote_id'] ?? null) ? $identity['remote_id'] : null,
                'mode' => is_string($identity['mode'] ?? null) ? $identity['mode'] : null,
                'active' => true,
            ];
        }

        return $active;
    }

    private function isAllowed(Mailbox $mailbox, string $email): bool
    {
        if ($mailbox->getAttribute('sender_identities') === null) {
            return strcasecmp(
                EmailNormalizer::normalize($mailbox->email_address),
                EmailNormalizer::normalize($email),
            ) === 0;
        }

        return collect($this->activeStoredIdentities($mailbox))->contains(
            fn (array $identity): bool => strcasecmp($identity['email'], $email) === 0,
        );
    }

    private function isZoho(Mailbox $mailbox): bool
    {
        $connection = $mailbox->relationLoaded('connection')
            ? $mailbox->getRelation('connection')
            : $mailbox->connection()->first();

        return $connection?->provider === MailProviderName::Zoho;
    }

    private function sanitizeName(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        $name = trim(EmailNormalizer::stripHeaderInjection($name));

        return $name === '' ? null : mb_substr($name, 0, 120);
    }

    private function bounded(?string $value, int $length): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';
        $value = trim(EmailNormalizer::stripHeaderInjection($value));

        return $value === '' ? null : mb_substr($value, 0, $length);
    }
}
