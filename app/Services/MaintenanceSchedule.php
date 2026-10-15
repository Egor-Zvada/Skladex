<?php

namespace App\Services;

use Carbon\CarbonImmutable;

final class MaintenanceSchedule
{
    public const TIMEZONE = 'Asia/Sakhalin';

    public static function date(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, self::TIMEZONE)->startOfDay();
    }

    /** Calculate from the ORIGINAL anchor, preserving its day after short months. */
    public static function at(string $anchor, string $unit, int $count, int $period = 1): CarbonImmutable
    {
        $base = self::date($anchor);
        $n = $count * $period;

        return match ($unit) {
            'day' => $base->addDays($n), 'week' => $base->addWeeks($n),
            'month' => $base->addMonthsNoOverflow($n), 'year' => $base->addYearsNoOverflow($n),
            default => throw new \InvalidArgumentException('Unsupported maintenance interval'),
        };
    }

    /** First fixed-calendar milestone strictly after a fact; does not use acceptance time. */
    public static function after(string $anchor, string $unit, int $count, string $fact): array
    {
        $end = self::date($fact);
        $start = self::date($anchor);
        if ($start->greaterThan($end)) {
            return ['date' => $start, 'period' => 0];
        }
        $low = 0;
        $high = 1;
        while (self::at($anchor, $unit, $count, $high)->lessThanOrEqualTo($end)) {
            $high *= 2;
        }
        while ($low + 1 < $high) {
            $mid = intdiv($low + $high, 2);
            if (self::at($anchor, $unit, $count, $mid)->lessThanOrEqualTo($end)) {
                $low = $mid;
            } else {
                $high = $mid;
            }
        }

        return ['date' => self::at($anchor, $unit, $count, $high), 'period' => $high];
    }
}
