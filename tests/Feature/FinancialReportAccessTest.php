<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckOnboardingStatus;
use App\Http\Middleware\CheckSubscriptionStatus;
use App\Http\Middleware\EnsureSubscriptionScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class FinancialReportAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            CheckOnboardingStatus::class,
            CheckSubscriptionStatus::class,
            EnsureSubscriptionScope::class,
        ]);
    }

    #[Test]
    public function user_without_financial_permission_cannot_open_the_report(): void
    {
        Permission::create([
            'name' => 'financial_reports.access',
            'module' => 'Reportes financieros',
            'guard_name' => 'web',
        ]);

        // Gate::before grants every ability to user id 1 (platform support).
        // Create it first so the tested user is a regular user with id 2.
        User::factory()->create();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('financial-control.index'))
            ->assertForbidden();
    }

    #[Test]
    public function user_without_financial_permission_cannot_export_the_report(): void
    {
        Permission::create([
            'name' => 'financial_reports.access',
            'module' => 'Reportes financieros',
            'guard_name' => 'web',
        ]);

        // Gate::before grants every ability to user id 1 (platform support).
        // Create it first so the tested user is a regular user with id 2.
        User::factory()->create();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('financial-control.export', [
                'start_date' => '2026-10-01',
                'end_date' => '2026-10-08',
            ]))
            ->assertForbidden();
    }
}
