<?php

namespace App\Models;

use App\Domain\Integrations\Enums\IntegrationCredentialStatus;
use Carbon\CarbonImmutable;
use Database\Factories\IntegrationCredentialVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $workspace_id
 * @property int $connection_id
 * @property int $version
 * @property string|null $encrypted_secret
 * @property IntegrationCredentialStatus $status
 * @property CarbonImmutable|null $wiped_at
 * @property CarbonImmutable|null $retire_after
 */
#[Fillable([
    'workspace_id',
    'connection_id',
    'version',
    'encrypted_secret',
    'status',
    'created_by_user_id',
    'tested_at',
    'activated_at',
    'retire_after',
    'wiped_at',
])]
#[Hidden(['encrypted_secret'])]
class IntegrationCredentialVersion extends Model
{
    /** @use HasFactory<IntegrationCredentialVersionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'encrypted_secret' => 'encrypted',
            'status' => IntegrationCredentialStatus::class,
            'version' => 'integer',
            'tested_at' => 'datetime',
            'activated_at' => 'datetime',
            'retire_after' => 'datetime',
            'wiped_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<IntegrationConnection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnection::class, 'connection_id');
    }

    public function isUsable(): bool
    {
        return $this->status === IntegrationCredentialStatus::Active
            && $this->wiped_at === null
            && is_string($this->encrypted_secret)
            && $this->encrypted_secret !== '';
    }
}
