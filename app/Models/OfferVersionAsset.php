<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'workspace_id', 'offer_id', 'offer_version_id', 'asset_id',
    'asset_version_id', 'role', 'delivery_rule',
])]
class OfferVersionAsset extends Model
{
    protected function casts(): array
    {
        return ['delivery_rule' => 'array'];
    }
}
