<?php

namespace App\Models;

use Database\Factories\IntegrationCapabilityRouteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $workspace_id
 * @property string $capability
 * @property int|null $active_registration_id
 * @property string $status
 * @property int $version
 */
#[Fillable([
    'workspace_id',
    'capability',
    'active_registration_id',
    'status',
    'version',
    'updated_by_user_id',
])]
class IntegrationCapabilityRoute extends Model
{
    /** @use HasFactory<IntegrationCapabilityRouteFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'active_registration_id' => 'integer',
            'version' => 'integer',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<IntegrationProviderRegistration, $this> */
    public function activeRegistration(): BelongsTo
    {
        return $this->belongsTo(IntegrationProviderRegistration::class, 'active_registration_id');
    }
}
