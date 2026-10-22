<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'workspace_id', 'fulfilment_capability_id', 'window_key',
    'limit_units', 'reserved_units', 'consumed_units',
])]
class FulfilmentCapacityWindow extends Model
{
    protected function casts(): array
    {
        return [
            'limit_units' => 'integer',
            'reserved_units' => 'integer',
            'consumed_units' => 'integer',
        ];
    }
}
