<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\Contracts\HasAbilities;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\NewAccessToken;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $timezone
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password', 'timezone'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @var HasAbilities|null */
    protected $accessToken;

    /** @use HasApiTokens<PersonalAccessToken> */
    use HasApiTokens {
        createToken as private createSanctumToken;
    }

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    public const ACTIVE_WORKSPACE_SESSION_KEY = 'active_workspace_id';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /** @return HasMany<Mailbox, $this> */
    public function mailboxes(): HasMany
    {
        return $this->hasMany(Mailbox::class);
    }

    /** @return HasMany<ManagedMailDomain, $this> */
    public function managedMailDomains(): HasMany
    {
        return $this->hasMany(ManagedMailDomain::class, 'created_by_user_id');
    }

    /** @return HasMany<ProviderConnection, $this> */
    public function providerConnections(): HasMany
    {
        return $this->hasMany(ProviderConnection::class);
    }

    /** @return HasMany<Campaign, $this> */
    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    /** @return HasMany<MailExperiment, $this> */
    public function mailExperiments(): HasMany
    {
        return $this->hasMany(MailExperiment::class, 'created_by_user_id');
    }

    /** @return HasMany<Thread, $this> */
    public function threads(): HasMany
    {
        return $this->hasMany(Thread::class);
    }

    /** @return HasMany<SuppressionEntry, $this> */
    public function suppressions(): HasMany
    {
        return $this->hasMany(SuppressionEntry::class);
    }

    /** @return HasMany<WorkspaceMembership, $this> */
    public function workspaceMemberships(): HasMany
    {
        return $this->hasMany(WorkspaceMembership::class);
    }

    /** @return BelongsToMany<Workspace, $this> */
    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_memberships');
    }

    /** Get the access token currently associated with this request, if any. */
    public function currentAccessToken(): ?HasAbilities
    {
        return $this->accessToken;
    }

    /** Resolve the workspace for the current browser session or API token. */
    public function currentWorkspace(): Workspace
    {
        $token = $this->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            return $this->workspaces()
                ->whereKey($token->workspace_id ?? 0)
                ->firstOrFail();
        }

        $request = request();

        if ($request->hasSession()) {
            $sessionWorkspaceId = $request->session()->get(self::ACTIVE_WORKSPACE_SESSION_KEY);

            if (is_numeric($sessionWorkspaceId)) {
                $sessionWorkspace = $this->workspaces()
                    ->whereKey((int) $sessionWorkspaceId)
                    ->first();

                if ($sessionWorkspace !== null) {
                    return $sessionWorkspace;
                }

                $request->session()->forget(self::ACTIVE_WORKSPACE_SESSION_KEY);
            }
        }

        return $this->workspaces()
            ->orderByRaw("case when workspaces.type = 'personal' then 0 else 1 end")
            ->orderBy('workspaces.id')
            ->firstOrFail();
    }

    /**
     * Create a token that remains bound to the active workspace.
     *
     * @param  list<string>  $abilities
     */
    public function createToken(string $name, array $abilities = ['*'], ?\DateTimeInterface $expiresAt = null): NewAccessToken
    {
        $newToken = $this->createSanctumToken($name, $abilities, $expiresAt);

        $newToken->accessToken->forceFill([
            'workspace_id' => $this->currentWorkspace()->id,
        ])->save();

        return $newToken;
    }
}
