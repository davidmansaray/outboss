<?php

namespace App\Models;

use App\Domain\Mail\Enums\MailProviderName;
use App\Domain\Mail\Enums\ManagedMailDomainKind;
use App\Domain\Mail\Enums\ManagedMailDomainStatus;
use App\Domain\Mail\Exceptions\InvalidManagedMailStateTransition;
use Database\Factories\ManagedMailDomainFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

#[Fillable([
    'workspace_id',
    'created_by_user_id',
    'provider',
    'managed_instance_key',
    'domain',
    'organisational_domain',
    'domain_kind',
    'remote_domain_id',
    'status',
    'dns_records',
    'registered_at',
    'dns_activated_at',
    'verified_at',
    'last_verified_at',
    'last_error_code',
    'last_error_message',
])]
/**
 * @property int $id
 * @property int $workspace_id
 * @property int $created_by_user_id
 * @property Carbon|null $verified_at
 * @property Carbon|null $last_verified_at
 */
class ManagedMailDomain extends Model
{
    /** @use HasFactory<ManagedMailDomainFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'provider' => MailProviderName::class,
            'domain_kind' => ManagedMailDomainKind::class,
            'status' => ManagedMailDomainStatus::class,
            'dns_records' => 'array',
            'registered_at' => 'datetime',
            'dns_activated_at' => 'datetime',
            'verified_at' => 'datetime',
            'last_verified_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return HasMany<ManagedMailboxAccount, $this> */
    public function mailboxAccounts(): HasMany
    {
        return $this->hasMany(ManagedMailboxAccount::class);
    }

    /** @return HasMany<ManagedMailboxAccount, $this> */
    public function accounts(): HasMany
    {
        return $this->mailboxAccounts();
    }

    /** @return HasMany<ManagedMailEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(ManagedMailEvent::class);
    }

    /**
     * @param  Builder<ManagedMailDomain>  $query
     * @return Builder<ManagedMailDomain>
     */
    public function scopeForWorkspace(Builder $query, Workspace|int $workspace): Builder
    {
        return $query->where('workspace_id', $workspace instanceof Workspace ? $workspace->id : $workspace);
    }

    public function isVerified(): bool
    {
        return in_array($this->statusEnum(), [
            ManagedMailDomainStatus::Verified,
            ManagedMailDomainStatus::Active,
        ], true);
    }

    public function isActive(): bool
    {
        return $this->statusEnum() === ManagedMailDomainStatus::Active;
    }

    public function canTransitionTo(ManagedMailDomainStatus|string $target): bool
    {
        $target = $target instanceof ManagedMailDomainStatus
            ? $target
            : ManagedMailDomainStatus::from($target);

        if ($target === $this->statusEnum()) {
            return true;
        }

        return in_array($target, self::allowedTransitions()[$this->statusEnum()->value] ?? [], true);
    }

    public function transitionTo(ManagedMailDomainStatus|string $target, bool $persist = true): self
    {
        $target = $target instanceof ManagedMailDomainStatus
            ? $target
            : ManagedMailDomainStatus::from($target);

        $current = $this->statusEnum();

        if ($target === ManagedMailDomainStatus::Active && $this->verified_at === null) {
            throw InvalidManagedMailStateTransition::domain($current, $target);
        }

        if (! $this->canTransitionTo($target)) {
            throw InvalidManagedMailStateTransition::domain($current, $target);
        }

        if ($target !== $current) {
            $this->forceFill(['status' => $target]);

            if ($target === ManagedMailDomainStatus::Verified && $this->verified_at === null) {
                $this->forceFill(['verified_at' => now(), 'last_verified_at' => now()]);
            }

        }

        if ($persist && $this->exists && $this->isDirty()) {
            $this->save();
        }

        return $this;
    }

    private function statusEnum(): ManagedMailDomainStatus
    {
        $status = $this->getAttribute('status');

        return $status instanceof ManagedMailDomainStatus
            ? $status
            : ManagedMailDomainStatus::from((string) $status);
    }

    public function markProvisioning(bool $persist = true): self
    {
        return $this->transitionTo(ManagedMailDomainStatus::Provisioning, $persist);
    }

    public function markPendingDns(bool $persist = true): self
    {
        return $this->transitionTo(ManagedMailDomainStatus::PendingDns, $persist);
    }

    public function markVerified(bool $persist = true): self
    {
        return $this->transitionTo(ManagedMailDomainStatus::Verified, $persist);
    }

    public function activate(bool $persist = true): self
    {
        return $this->transitionTo(ManagedMailDomainStatus::Active, $persist);
    }

    public function suspend(bool $persist = true): self
    {
        return $this->transitionTo(ManagedMailDomainStatus::Suspended, $persist);
    }

    public function fail(bool $persist = true): self
    {
        return $this->transitionTo(ManagedMailDomainStatus::Failed, $persist);
    }

    /** @return array<string, list<ManagedMailDomainStatus>> */
    private static function allowedTransitions(): array
    {
        return [
            ManagedMailDomainStatus::Draft->value => [ManagedMailDomainStatus::Provisioning],
            ManagedMailDomainStatus::Provisioning->value => [
                ManagedMailDomainStatus::PendingDns,
                ManagedMailDomainStatus::Failed,
            ],
            ManagedMailDomainStatus::PendingDns->value => [
                ManagedMailDomainStatus::Verified,
                ManagedMailDomainStatus::Failed,
            ],
            ManagedMailDomainStatus::Verified->value => [
                ManagedMailDomainStatus::Active,
                ManagedMailDomainStatus::PendingDns,
                ManagedMailDomainStatus::Suspended,
            ],
            ManagedMailDomainStatus::Active->value => [
                ManagedMailDomainStatus::Suspended,
                ManagedMailDomainStatus::PendingDns,
            ],
            ManagedMailDomainStatus::Suspended->value => [
                ManagedMailDomainStatus::Verified,
                ManagedMailDomainStatus::Failed,
            ],
            ManagedMailDomainStatus::Failed->value => [ManagedMailDomainStatus::Provisioning],
        ];
    }
}
