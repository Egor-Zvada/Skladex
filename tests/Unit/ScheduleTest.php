<?php

namespace Tests\Unit;

use App\Services\MaintenanceSchedule;
use PHPUnit\Framework\TestCase;

class ScheduleTest extends TestCase
{
    public function test_calendar_month_uses_original_anchor_after_short_month(): void
    {
        $this->assertSame('2027-02-28', MaintenanceSchedule::at('2027-01-31', 'month', 1, 1)->toDateString());
        $this->assertSame('2027-03-31', MaintenanceSchedule::at('2027-01-31', 'month', 1, 2)->toDateString());
        $this->assertSame('2028-02-29', MaintenanceSchedule::at('2028-01-31', 'month', 1, 1)->toDateString());
    }

    public function test_fixed_calendar_and_completion_based_interval_differ(): void
    {
        $this->assertSame('2026-11-01', MaintenanceSchedule::after('2026-10-01', 'month', 1, '2026-10-10')['date']->toDateString());
        $this->assertSame('2026-11-10', MaintenanceSchedule::at('2026-10-10', 'month', 1)->toDateString());
    }

    public function test_calendar_after_uses_strict_next_and_handles_long_gap_without_daily_loop(): void
    {
        $next = MaintenanceSchedule::after('2000-01-01', 'day', 1, '2026-10-15');
        $this->assertSame('2026-10-16', $next['date']->toDateString());
        $this->assertGreaterThan(9000, $next['period']);
        $this->assertSame('2026-10-01', MaintenanceSchedule::after('2026-10-01', 'month', 1, '2026-09-20')['date']->toDateString());
    }

    public function test_year_interval_does_not_turn_february_29_into_march(): void
    {
        $this->assertSame('2025-02-28', MaintenanceSchedule::at('2024-02-29', 'year', 1)->toDateString());
        $this->assertSame('2028-02-29',MaintenanceSchedule::at('2024-02-29','year',1,4)->toDateString());
    }
}
