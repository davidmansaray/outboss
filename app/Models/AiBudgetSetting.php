<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['workspace_id', 'currency', 'monthly_limit_micros', 'tavily_credit_price_micros', 'version', 'updated_by_user_id'])]
class AiBudgetSetting extends Model
{
    protected $primaryKey = 'workspace_id';

    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'monthly_limit_micros' => 'integer',
            'tavily_credit_price_micros' => 'integer',
            'version' => 'integer',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
