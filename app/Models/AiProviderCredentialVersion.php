<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $credential_id
 * @property int $version
 * @property string|null $encrypted_secret
 * @property string $status
 * @property Carbon|null $wiped_at
 * @property Carbon|null $retire_after
 */
#[Fillable(['credential_id', 'version', 'encrypted_secret', 'status', 'created_by_user_id', 'tested_at', 'activated_at', 'retire_after', 'wiped_at'])]
#[Hidden(['encrypted_secret'])]
class AiProviderCredentialVersion extends Model
{
    protected function casts(): array
    {
        return [
            'encrypted_secret' => 'encrypted',
            'tested_at' => 'datetime',
            'activated_at' => 'datetime',
            'retire_after' => 'datetime',
            'wiped_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AiProviderCredential, $this> */
    public function credential(): BelongsTo
    {
        return $this->belongsTo(AiProviderCredential::class, 'credential_id');
    }
}
