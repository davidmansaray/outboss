<?php

namespace App\Models;

use Database\Factories\WorkspaceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'name',
    'type',
    'avatar_path',
    'avatar_source_url',
    'avatar_website_hash',
    'avatar_refreshed_at',
    'avatar_missing_since',
])]
class Workspace extends Model
{
    /** @use HasFactory<WorkspaceFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'avatar_refreshed_at' => 'datetime',
            'avatar_missing_since' => 'datetime',
        ];
    }

    /** @return HasMany<WorkspaceMembership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(WorkspaceMembership::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_memberships');
    }

    /** @return HasMany<Campaign, $this> */
    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    /** @return HasMany<MailExperiment, $this> */
    public function mailExperiments(): HasMany
    {
        return $this->hasMany(MailExperiment::class);
    }

    /** @return HasMany<Mailbox, $this> */
    public function mailboxes(): HasMany
    {
        return $this->hasMany(Mailbox::class);
    }

    /** @return HasMany<ManagedMailDomain, $this> */
    public function managedMailDomains(): HasMany
    {
        return $this->hasMany(ManagedMailDomain::class);
    }

    /** @return HasManyThrough<ManagedMailboxAccount, ManagedMailDomain, $this> */
    public function managedMailboxAccounts(): HasManyThrough
    {
        return $this->hasManyThrough(ManagedMailboxAccount::class, ManagedMailDomain::class);
    }

    /** @return HasMany<ManagedMailEvent, $this> */
    public function managedMailEvents(): HasMany
    {
        return $this->hasMany(ManagedMailEvent::class);
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

    /**
     * @return HasMany<Account, $this>
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    /**
     * @return HasMany<Person, $this>
     */
    public function people(): HasMany
    {
        return $this->hasMany(Person::class);
    }

    public function membershipFor(User $user): ?WorkspaceMembership
    {
        return $this->memberships()->where('user_id', $user->id)->first();
    }

    public function isOwner(User $user): bool
    {
        return $this->memberships()
            ->where('user_id', $user->id)
            ->where('role', WorkspaceMembership::ROLE_OWNER)
            ->exists();
    }

    /** @return HasMany<AiProviderCredential, $this> */
    public function aiProviderCredentials(): HasMany
    {
        return $this->hasMany(AiProviderCredential::class);
    }

    /** @return HasMany<AiTaskSetting, $this> */
    public function aiTaskSettings(): HasMany
    {
        return $this->hasMany(AiTaskSetting::class);
    }

    /** @return HasMany<AiProviderEvent, $this> */
    public function aiProviderEvents(): HasMany
    {
        return $this->hasMany(AiProviderEvent::class);
    }

    /** @return HasOne<GtmProfile, $this> */
    public function gtmProfile(): HasOne
    {
        return $this->hasOne(GtmProfile::class);
    }

    /** @return HasMany<Offer, $this> */
    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }

    /** @return HasMany<Segment, $this> */
    public function segments(): HasMany
    {
        return $this->hasMany(Segment::class);
    }

    /** @return HasMany<AudienceSnapshot, $this> */
    public function audienceSnapshots(): HasMany
    {
        return $this->hasMany(AudienceSnapshot::class);
    }

    /** @return HasMany<Objective, $this> */
    public function objectives(): HasMany
    {
        return $this->hasMany(Objective::class);
    }

    /** @return HasMany<Strategy, $this> */
    public function strategies(): HasMany
    {
        return $this->hasMany(Strategy::class);
    }

    /** @return HasMany<GtmExperiment, $this> */
    public function gtmExperiments(): HasMany
    {
        return $this->hasMany(GtmExperiment::class);
    }

    /** @return HasOne<OutreachPolicy, $this> */
    public function outreachPolicy(): HasOne
    {
        return $this->hasOne(OutreachPolicy::class);
    }

    /** @return HasMany<ProspectSource, $this> */
    public function prospectSources(): HasMany
    {
        return $this->hasMany(ProspectSource::class);
    }

    /** @return HasMany<SourceRun, $this> */
    public function sourceRuns(): HasMany
    {
        return $this->hasMany(SourceRun::class);
    }

    /** @return HasMany<SourceObservation, $this> */
    public function sourceObservations(): HasMany
    {
        return $this->hasMany(SourceObservation::class);
    }

    /** @return HasMany<AccountSourceMembership, $this> */
    public function accountSourceMemberships(): HasMany
    {
        return $this->hasMany(AccountSourceMembership::class);
    }

    /** @return HasMany<PersonSourceMembership, $this> */
    public function personSourceMemberships(): HasMany
    {
        return $this->hasMany(PersonSourceMembership::class);
    }

    /** @return HasMany<ExternalIdentity, $this> */
    public function externalIdentities(): HasMany
    {
        return $this->hasMany(ExternalIdentity::class);
    }

    /** @return HasMany<CanonicalFieldProvenance, $this> */
    public function canonicalFieldProvenance(): HasMany
    {
        return $this->hasMany(CanonicalFieldProvenance::class);
    }

    /** @return HasMany<IntegrationConnection, $this> */
    public function integrationConnections(): HasMany
    {
        return $this->hasMany(IntegrationConnection::class);
    }

    /** @return HasMany<IntegrationCredentialVersion, $this> */
    public function integrationCredentialVersions(): HasMany
    {
        return $this->hasMany(IntegrationCredentialVersion::class);
    }

    /** @return HasMany<IntegrationProviderRegistration, $this> */
    public function integrationProviderRegistrations(): HasMany
    {
        return $this->hasMany(IntegrationProviderRegistration::class);
    }

    /** @return HasMany<IntegrationCapabilityRoute, $this> */
    public function integrationCapabilityRoutes(): HasMany
    {
        return $this->hasMany(IntegrationCapabilityRoute::class);
    }

    /** @return HasOne<IntegrationBudgetSetting, $this> */
    public function integrationBudgetSetting(): HasOne
    {
        return $this->hasOne(IntegrationBudgetSetting::class, 'workspace_id');
    }

    /** @return HasMany<IntegrationBudgetPeriod, $this> */
    public function integrationBudgetPeriods(): HasMany
    {
        return $this->hasMany(IntegrationBudgetPeriod::class);
    }

    /** @return HasMany<ExternalOperation, $this> */
    public function externalOperations(): HasMany
    {
        return $this->hasMany(ExternalOperation::class);
    }

    /** @return HasManyThrough<ExternalOperationAttempt, ExternalOperation, $this> */
    public function externalOperationAttempts(): HasManyThrough
    {
        return $this->hasManyThrough(
            ExternalOperationAttempt::class,
            ExternalOperation::class,
            'workspace_id',
            'operation_id',
            'id',
            'id',
        );
    }

    /** @return HasMany<ExternalUsageEntry, $this> */
    public function externalUsageEntries(): HasMany
    {
        return $this->hasMany(ExternalUsageEntry::class);
    }

    /** @return HasMany<WorkspaceDataExportEvent, $this> */
    public function dataExportEvents(): HasMany
    {
        return $this->hasMany(WorkspaceDataExportEvent::class);
    }
}
