<?php

namespace Tests\Feature;

use App\Services\FinancialReports\PeriodComparisonService;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PeriodComparisonServiceTest extends TestCase
{
    private PeriodComparisonService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new PeriodComparisonService();
    }

    #[Test]
    public function day_preset_compares_with_the_previous_day(): void
    {
        $comparison = $this->service->resolve(
            Carbon::parse('2026-10-17 00:00:00'),
            Carbon::parse('2026-10-17 23:59:59'),
            'day',
        );

        $this->assertSame('día anterior', $comparison['label']);
        $this->assertTrue($comparison['previous']['start']->equalTo(Carbon::parse('2026-10-16 00:00:00')));
        $this->assertTrue($comparison['previous']['end']->equalTo(Carbon::parse('2026-10-16 23:59:59')));
    }

    #[Test]
    public function week_preset_compares_with_the_previous_week(): void
    {
        $comparison = $this->service->resolve(
            Carbon::parse('2026-10-12 00:00:00'),
            Carbon::parse('2026-10-18 23:59:59'),
            'week',
        );

        $this->assertSame('semana anterior', $comparison['label']);
        $this->assertTrue($comparison['previous']['start']->equalTo(Carbon::parse('2026-10-05 00:00:00')));
        $this->assertTrue($comparison['previous']['end']->equalTo(Carbon::parse('2026-10-11 23:59:59')));
    }

    #[Test]
    public function month_preset_compares_with_the_previous_calendar_month(): void
    {
        $comparison = $this->service->resolve(
            Carbon::parse('2026-10-01 00:00:00'),
            Carbon::parse('2026-10-31 23:59:59'),
            'month',
        );

        $this->assertSame('mes anterior', $comparison['label']);
        $this->assertTrue($comparison['previous']['start']->equalTo(Carbon::parse('2026-09-01 00:00:00')));
        $this->assertTrue($comparison['previous']['end']->equalTo(Carbon::parse('2026-09-30 23:59:59')));
    }

    #[Test]
    public function year_preset_compares_with_the_previous_year(): void
    {
        $comparison = $this->service->resolve(
            Carbon::parse('2026-01-01 00:00:00'),
            Carbon::parse('2026-12-31 23:59:59'),
            'year',
        );

        $this->assertSame('año anterior', $comparison['label']);
        $this->assertTrue($comparison['previous']['start']->equalTo(Carbon::parse('2025-01-01 00:00:00')));
        $this->assertTrue($comparison['previous']['end']->equalTo(Carbon::parse('2025-12-31 23:59:59')));
    }

    #[Test]
    public function custom_range_compares_with_the_same_number_of_days_before(): void
    {
        $comparison = $this->service->resolve(
            Carbon::parse('2026-10-10 00:00:00'),
            Carbon::parse('2026-10-14 23:59:59'),
            'custom',
        );

        $this->assertSame('periodo anterior', $comparison['label']);
        $this->assertTrue($comparison['previous']['start']->equalTo(Carbon::parse('2026-10-05 00:00:00')));
        $this->assertTrue($comparison['previous']['end']->equalTo(Carbon::parse('2026-10-09 23:59:59')));
    }
}
