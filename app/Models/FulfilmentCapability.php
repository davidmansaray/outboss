<?php

namespace App\Models;

use App\Domain\Gtm\Enums\FulfilmentMode;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** @property FulfilmentMode $mode */
#[Fillable([
    'id', 'workspace_id', 'display_name', 'mode', 'status',
    'configuration_version', 'client_operation_id', 'operation_hash',
    'configuration', 'owner_user_id',
    'response_sla_hours', 'capacity_limit', 'capacity_window',
    'health_state', 'last_checked_at', 'safe_blocker_code',
])]
class FulfilmentCapability extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'mode' => FulfilmentMode::class,
            'configuration_version' => 'integer',
            'configuration' => 'array',
            'response_sla_hours' => 'integer',
            'capacity_limit' => 'integer',
            'last_checked_at' => 'datetime',
        ];
    }
}
