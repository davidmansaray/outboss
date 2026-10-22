<?php

namespace App\Models;

use App\Domain\Mail\Enums\ManagedMailboxAccountStatus;
use App\Domain\Mail\Exceptions\InvalidManagedMailStateTransition;
use Database\Factories\ManagedMailboxAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

#[Fillable([
    'managed_mail_domain_id',
    'mailbox_id',
    'remote_account_id',
    'local_part',
    'display_name',
    'credential_version',
    'status',
    'provisioned_at',
    'last_verified_at',
    'last_error_code',
    'last_error_message',
])]
/**
 * @property int $id
 * @property int $managed_mail_domain_id
 * @property int|null $mailbox_id
 * @property string|null $remote_account_id
 * @property string $local_part
 * @property int $credential_version
 * @property Carbon|null $provisioned_at
 * @property-read ManagedMailDomain|null $managedMailDomain
 */
class ManagedMailboxAccount extends Model
{
    /** @use HasFactory<ManagedMailboxAccountFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => ManagedMailboxAccountStatus::class,
            'credential_version' => 'integer',
            'provisioned_at' => 'datetime',
            'last_verified_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ManagedMailDomain, $this> */
    public function managedMailDomain(): BelongsTo
    {
        return $this->belongsTo(ManagedMailDomain::class);
    }

    /** @return BelongsTo<ManagedMailDomain, $this> */
    public function managedDomain(): BelongsTo
    {
        return $this->managedMailDomain();
    }

    /** @return BelongsTo<Mailbox, $this> */
    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }

    /** @return HasMany<ManagedMailEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(ManagedMailEvent::class);
    }

    public function getEmailAddressAttribute(): string
    {
        $domain = $this->relationLoaded('managedMailDomain')
            ? $this->managedMailDomain
            : $this->managedMailDomain()->first();

        return $domain === null
            ? $this->local_part
            : $this->local_part.'@'.$domain->domain;
    }

    public function workspaceId(): ?int
    {
        $domain = $this->relationLoaded('managedMailDomain')
            ? $this->managedMailDomain
            : $this->managedMailDomain()->first();

        return $domain?->workspace_id;
    }

    public function isActive(): bool
    {
        $domain = $this->relationLoaded('managedMailDomain')
            ? $this->managedMailDomain
            : $this->managedMailDomain()->first();

        return $this->statusEnum() === ManagedMailboxAccountStatus::Active
            && $domain?->isActive() === true;
    }

    public function canTransitionTo(ManagedMailboxAccountStatus|string $target): bool
    {
        $target = $target instanceof ManagedMailboxAccountStatus
            ? $target
            : ManagedMailboxAccountStatus::from($target);

        if ($target === $this->statusEnum()) {
            return true;
        }

        return in_array($target, self::allowedTransitions()[$this->statusEnum()->value] ?? [], true);
    }

    public function transitionTo(ManagedMailboxAccountStatus|string $target, bool $persist = true): self
    {
        $target = $target instanceof ManagedMailboxAccountStatus
            ? $target
            : ManagedMailboxAccountStatus::from($target);

        $current = $this->statusEnum();

        if (! $this->canTransitionTo($target)) {
            throw InvalidManagedMailStateTransition::mailbox($current, $target);
        }

        if ($target !== $current) {
            $this->forceFill(['status' => $target]);

            if ($target === ManagedMailboxAccountStatus::Active && $this->provisioned_at === null) {
                $this->forceFill(['provisioned_at' => now()]);
            }
        }

        if ($persist && $this->exists && $this->isDirty()) {
            $this->save();
        }

        return $this;
    }

    private function statusEnum(): ManagedMailboxAccountStatus
    {
        $status = $this->getAttribute('status');

        return $status instanceof ManagedMailboxAccountStatus
            ? $status
            : ManagedMailboxAccountStatus::from((string) $status);
    }

    public function markActive(bool $persist = true): self
    {
        return $this->transitionTo(ManagedMailboxAccountStatus::Active, $persist);
    }

    public function markSuspended(bool $persist = true): self
    {
        return $this->transitionTo(ManagedMailboxAccountStatus::Suspended, $persist);
    }

    public function markRotationRequired(bool $persist = true): self
    {
        return $this->transitionTo(ManagedMailboxAccountStatus::RotationRequired, $persist);
    }

    public function markFailed(bool $persist = true): self
    {
        return $this->transitionTo(ManagedMailboxAccountStatus::Failed, $persist);
    }

    public function markDeleted(bool $persist = true): self
    {
        return $this->transitionTo(ManagedMailboxAccountStatus::Deleted, $persist);
    }

    /** @return array<string, list<ManagedMailboxAccountStatus>> */
    private static function allowedTransitions(): array
    {
        return [
            ManagedMailboxAccountStatus::Provisioning->value => [
                ManagedMailboxAccountStatus::Active,
                ManagedMailboxAccountStatus::Failed,
                ManagedMailboxAccountStatus::Deleted,
            ],
            ManagedMailboxAccountStatus::Active->value => [
                ManagedMailboxAccountStatus::Suspended,
                ManagedMailboxAccountStatus::RotationRequired,
                ManagedMailboxAccountStatus::Failed,
                ManagedMailboxAccountStatus::Deleted,
            ],
            ManagedMailboxAccountStatus::Suspended->value => [
                ManagedMailboxAccountStatus::Active,
                ManagedMailboxAccountStatus::RotationRequired,
                ManagedMailboxAccountStatus::Deleted,
            ],
            ManagedMailboxAccountStatus::RotationRequired->value => [
                ManagedMailboxAccountStatus::Active,
                ManagedMailboxAccountStatus::Suspended,
                ManagedMailboxAccountStatus::Failed,
                ManagedMailboxAccountStatus::Deleted,
            ],
            ManagedMailboxAccountStatus::Failed->value => [
                ManagedMailboxAccountStatus::Provisioning,
                ManagedMailboxAccountStatus::Deleted,
            ],
            ManagedMailboxAccountStatus::Deleted->value => [],
        ];
    }
}
