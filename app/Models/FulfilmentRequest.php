<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'id', 'workspace_id', 'offer_id', 'offer_version_id',
    'conversion_event_id', 'account_id', 'fulfilment_capability_id',
    'idempotency_key', 'required_inputs', 'due_at', 'assigned_user_id',
    'state', 'result_references', 'safe_failure_code',
])]
class FulfilmentRequest extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'required_inputs' => 'array',
            'result_references' => 'array',
            'due_at' => 'datetime',
        ];
    }
}
