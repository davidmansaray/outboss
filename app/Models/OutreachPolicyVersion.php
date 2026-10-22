<?php

namespace App\Models;

use App\Domain\Objectives\Concerns\ImmutableRecord;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['id', 'workspace_id', 'outreach_policy_id', 'version', 'maximum_people', 'maximum_accounts', 'maximum_messages', 'maximum_ai_cost_micros', 'approval_ttl_hours', 'send_window', 'allowed_source_purposes', 'qualification_constraints', 'evidence_constraints', 'offer_constraints', 'fulfilment_constraints', 'schema_version', 'content_hash', 'created_by_user_id', 'created_at'])]
class OutreachPolicyVersion extends Model
{
    use HasUuids;
    use ImmutableRecord;

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'maximum_people' => 'integer',
            'maximum_accounts' => 'integer',
            'maximum_messages' => 'integer',
            'maximum_ai_cost_micros' => 'integer',
            'approval_ttl_hours' => 'integer',
            'send_window' => 'array',
            'allowed_source_purposes' => 'array',
            'qualification_constraints' => 'array',
            'evidence_constraints' => 'array',
            'offer_constraints' => 'array',
            'fulfilment_constraints' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<OutreachPolicy, $this> */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(OutreachPolicy::class, 'outreach_policy_id');
    }
}
