<?php

namespace App\Domain\Conversions\Services;

use App\Domain\Conversions\Enums\ConversionDefinitionStatus;
use App\Domain\Conversions\Enums\ConversionSubjectType;
use App\Domain\Conversions\Support\ConversionServiceSupport;
use App\Models\ConversionDefinition;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ActivateConversionDefinition
{
    use ConversionServiceSupport;

    public function handle(ConversionDefinition $definition, User $actor, ?int $expectedLockVersion = null): ConversionDefinition
    {
        $this->assertMember($definition->workspace, $actor);

        return DB::transaction(function () use ($definition, $actor, $expectedLockVersion): ConversionDefinition {
            $locked = ConversionDefinition::query()->whereKey($definition->id)->lockForUpdate()->firstOrFail();
            $this->assertMember($locked->workspace, $actor);
            if ($expectedLockVersion !== null && (int) $locked->lock_version !== $expectedLockVersion) {
                throw new \RuntimeException('stale_version');
            }
            $version = $locked->currentVersion()->firstOrFail();
            if ($version->subject_type !== ConversionSubjectType::Account->value) {
                throw ValidationException::withMessages(['subject_type' => 'Only Account conversion definitions are activatable in S2.']);
            }
            if ($locked->getRawOriginal('status') !== ConversionDefinitionStatus::Active->value) {
                $locked->forceFill([
                    'status' => ConversionDefinitionStatus::Active,
                    'lock_version' => (int) $locked->lock_version + 1,
                    'updated_by_user_id' => $actor->id,
                ])->save();
            }

            return $locked->fresh(['currentVersion']);
        }, 3);
    }

    public function activate(ConversionDefinition $definition, User $actor, ?int $expectedLockVersion = null): ConversionDefinition
    {
        return $this->handle($definition, $actor, $expectedLockVersion);
    }
}
