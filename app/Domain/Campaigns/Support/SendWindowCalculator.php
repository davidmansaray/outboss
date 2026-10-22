<?php

namespace App\Domain\Campaigns\Support;

use Carbon\CarbonImmutable;

class SendWindowCalculator
{
    public function nextAllowed(
        \DateTimeInterface $from,
        string $timezone,
        string $start = '09:00:00',
        string $end = '17:00:00',
        bool $businessDaysOnly = true,
    ): CarbonImmutable {
        $moment = CarbonImmutable::parse($from)->timezone($timezone);
        [$startHour, $startMinute] = $this->parts($start);
        [$endHour, $endMinute] = $this->parts($end);

        for ($i = 0; $i < 14; $i++) {
            $day = $moment->addDays($i)->startOfDay();

            if ($businessDaysOnly && $day->isWeekend()) {
                continue;
            }

            $windowStart = $day->setTime($startHour, $startMinute);
            $windowEnd = $day->setTime($endHour, $endMinute);

            if ($i === 0 && $moment->lt($windowStart)) {
                return $windowStart->utc();
            }

            if ($i === 0 && $moment->lt($windowEnd)) {
                return $moment->utc();
            }

            if ($i > 0) {
                return $windowStart->utc();
            }
        }

        return $moment->next('Monday')->setTime($startHour, $startMinute)->utc();
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function parts(string $time): array
    {
        $bits = explode(':', $time);

        return [(int) $bits[0], (int) ($bits[1] ?? 0)];
    }
}
