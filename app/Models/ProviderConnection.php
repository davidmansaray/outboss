<?php

namespace App\Models;

use App\Domain\Mail\Enums\AuthScheme;
use App\Domain\Mail\Enums\ConnectionKind;
use App\Domain\Mail\Enums\ConnectionStatus;
use App\Domain\Mail\Enums\MailProviderName;
use Database\Factories\ProviderConnectionFactory;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $user_id
 * @property int $workspace_id
 * @property MailProviderName $provider
 * @property string|null $access_token
 * @property int $token_version OAuth refresh compare-and-swap version
 * @property int $authorization_version Credential/connection authority generation
 */
#[Fillable([
    'user_id',
    'workspace_id',
    'provider',
    'provider_user_id',
    'connection_kind',
    'auth_scheme',
    'access_token',
    'refresh_token',
    'credential_payload',
    'token_expires_at',
    'token_version',
    'authorization_version',
    'granted_scopes',
    'provider_metadata',
    'managed_instance_key',
    'status',
    'last_error_code',
    'last_error_message',
])]
#[Hidden([
    'access_token',
    'refresh_token',
    'credential_payload',
    'provider_metadata',
    'provider_identity_scope',
])]
class ProviderConnection extends Model
{
    /** @use HasFactory<ProviderConnectionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'provider' => MailProviderName::class,
            'connection_kind' => ConnectionKind::class,
            'auth_scheme' => AuthScheme::class,
            'status' => ConnectionStatus::class,
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'credential_payload' => 'encrypted:array',
            'provider_metadata' => 'encrypted:array',
            'granted_scopes' => 'array',
            'token_expires_at' => 'datetime',
            'token_version' => 'integer',
            'authorization_version' => 'integer',
        ];
    }

    /**
     * Advance the generation to which exact send approvals are bound.
     *
     * Callers changing credentials or connection authority already hold the
     * provider-connection row lock and persist this model with their state
     * transition. Ordinary OAuth access-token refreshes must not call this.
     */
    public function advanceAuthorizationVersion(): static
    {
        $this->setAttribute(
            'authorization_version',
            max(1, (int) $this->getAttribute('authorization_version')) + 1,
        );

        return $this;
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return HasMany<Mailbox, $this> */
    public function mailboxes(): HasMany
    {
        return $this->hasMany(Mailbox::class);
    }

    /** @return HasMany<Mailbox, $this> */
    public function enabledMailboxes(): HasMany
    {
        return $this->mailboxes()->where('status', '!=', ConnectionStatus::Disconnected);
    }

    public function hasDecryptableSecrets(): bool
    {
        foreach (['access_token', 'refresh_token', 'credential_payload', 'provider_metadata'] as $key) {
            $raw = $this->attributes[$key] ?? null;

            if (! is_string($raw) || $raw === '') {
                continue;
            }

            try {
                parent::fromEncryptedString($raw);
            } catch (DecryptException) {
                return false;
            }
        }

        return true;
    }

    public function isManaged(): bool
    {
        $kind = $this->getAttribute('connection_kind');

        return ($kind instanceof ConnectionKind
            ? $kind
            : ConnectionKind::tryFrom((string) $kind)) === ConnectionKind::Managed;
    }

    public function isOAuth(): bool
    {
        return ! $this->isManaged();
    }

    /**
     * Check local credential material without contacting a provider.
     *
     * A managed connection requires a decryptable username/password payload;
     * OAuth rows retain the existing access-token requirement.
     */
    public function hasUsableCredentials(): bool
    {
        if (! $this->hasDecryptableSecrets()) {
            return false;
        }

        if ($this->isManaged()) {
            $payload = $this->getAttribute('credential_payload');
            $username = is_array($payload)
                ? ($payload['username'] ?? $payload['jmap_username'] ?? $payload['email'] ?? null)
                : null;
            $password = is_array($payload)
                ? ($payload['password'] ?? $payload['app_password'] ?? $payload['secret'] ?? null)
                : null;

            return is_string($username)
                && trim($username) !== ''
                && is_string($password)
                && $password !== '';
        }

        return is_string($this->access_token) && trim($this->access_token) !== '';
    }
}
