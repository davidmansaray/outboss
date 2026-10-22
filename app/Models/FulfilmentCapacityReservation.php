<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'id', 'workspace_id', 'fulfilment_request_id', 'fulfilment_capability_id',
    'capacity_window_id', 'units', 'state', 'reserved_at', 'released_at',
])]
class FulfilmentCapacityReservation extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'units' => 'integer',
            'reserved_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }
}
