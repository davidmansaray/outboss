<?php

namespace App\Domain\Prospecting\Support;

use App\Domain\Prospecting\Enums\ObservationDisposition;
use App\Models\SourceObservation;

final class ObservationRedactor
{
    public function expire(SourceObservation $observation): SourceObservation
    {
        $now = now();
        $observation->forceFill([
            'observed_values' => null,
            'provider_record_id' => null,
            'provider_snapshot_id' => null,
            'canonical_url' => null,
            'safe_error_message' => null,
            'disposition' => ObservationDisposition::Expired,
            'expired_at' => $now,
            'redacted_at' => $now,
        ])->save();

        return $observation;
    }

    public static function safeError(?string $message, int $max = 500): ?string
    {
        if ($message === null) {
            return null;
        }

        $message = trim(preg_replace('/\s+/u', ' ', $message) ?? $message);

        return $message === '' ? null : mb_strcut($message, 0, $max, 'UTF-8');
    }
}
