<?php

namespace App\Models;

use Database\Factories\IntegrationBudgetSettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['workspace_id', 'currency', 'monthly_limit_micros', 'version', 'updated_by_user_id'])]
class IntegrationBudgetSetting extends Model
{
    /** @use HasFactory<IntegrationBudgetSettingFactory> */
    use HasFactory;

    protected $primaryKey = 'workspace_id';

    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'monthly_limit_micros' => 'integer',
            'version' => 'integer',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
