<?php

namespace Tests\Feature\SmartCheckout;

use App\Models\Branch;
use App\Models\SmartPosDetectionEvent;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;

/**
 * Indicator bar of the "Modo Caja Inteligente" (Phase 2).
 *
 * The server is the single source of truth: `todayMetrics` counts only the
 * branch of the authenticated user and only the events whose `occurred_at` is
 * today, with portable SQL (MySQL in production, SQLite in the tests).
 */
class SmartCheckoutMetricsTest extends SmartCheckoutTestCase
{
    #[Test]
    public function it_returns_403_while_the_mode_is_disabled(): void
    {
        $this->getJson(route('pos.smart-checkout.metrics.today'))
            ->assertForbidden()
            ->assertJsonPath('message', 'El Modo Caja Inteligente no está habilitado para este negocio.');
    }

    #[Test]
    public function it_returns_zeros_when_there_are_no_events(): void
    {
        $this->enableSmartCheckout();

        $response = $this->getJson(route('pos.smart-checkout.metrics.today'))
            ->assertOk();

        $this->assertNotNull($response->json('generated_at'));

        $today = $response->json('today');

        $this->assertSame(0, $today['detected']);
        $this->assertSame(0, $today['auto_accepted']);
        $this->assertSame(0, $today['confirmed']);
        $this->assertSame(0, $today['corrected']);
        $this->assertSame(0, $today['unknown']);
        $this->assertEqualsWithDelta(0.0, $today['accuracy'], 0.0001);
        $this->assertSame(0, $today['sales_with_mode']);
        $this->assertSame(0, $today['unknowns_registered']);
        $this->assertEqualsWithDelta(0.0, $today['avg_seconds_per_sale'], 0.0001);
        $this->assertEqualsWithDelta(0.0, $today['avg_latency_ms'], 0.0001);
        $this->assertSame(0, $today['analyses']);
        $this->assertNull($today['last_event_at']);
    }

    #[Test]
    public function it_aggregates_the_analyses_and_the_sales_of_the_day(): void
    {
        $this->enableSmartCheckout();

        $saleA = Transaction::factory()->create(['branch_id' => $this->branch->id]);
        $saleB = Transaction::factory()->create(['branch_id' => $this->branch->id]);

        // Two analyses: 10 + 5 objects, 6 + 3 auto accepted, 2 + 1 confirmed,
        // 1 + 0 corrected, 1 + 1 unknown, latencies 120 + 80.
        $this->event([
            'objects' => 10,
            'auto_accepted' => 6,
            'confirmed' => 2,
            'corrected' => 1,
            'unknown' => 1,
            'latency_ms' => 120,
            'occurred_at' => now()->subHours(3),
        ]);

        $this->event([
            'objects' => 5,
            'auto_accepted' => 3,
            'confirmed' => 1,
            'corrected' => 0,
            'unknown' => 1,
            'latency_ms' => 80,
            'occurred_at' => now()->subHours(2),
        ]);

        // Two sales with the mode active, plus one sale event without a sale id
        // (it must not count as a sale with the mode).
        $this->event([
            'event_type' => 'sale',
            'sale_id' => $saleA->id,
            'duration_seconds' => 300,
            'occurred_at' => now()->subHour(),
        ]);

        $this->event([
            'event_type' => 'sale',
            'sale_id' => $saleB->id,
            'duration_seconds' => 420,
            'occurred_at' => now()->subMinutes(5),
        ]);

        $this->event([
            'event_type' => 'sale',
            'sale_id' => null,
            'duration_seconds' => null,
            'occurred_at' => now()->subMinutes(10),
        ]);

        $today = $this->getJson(route('pos.smart-checkout.metrics.today'))
            ->assertOk()
            ->json('today');

        $this->assertSame(15, $today['detected']);
        $this->assertSame(9, $today['auto_accepted']);
        $this->assertSame(3, $today['confirmed']);
        $this->assertSame(1, $today['corrected']);
        $this->assertSame(2, $today['unknown']);
        // 9 / (9 + 3 + 1) × 100 = 69.230… → 69.2
        $this->assertEqualsWithDelta(69.2, $today['accuracy'], 0.0001);
        $this->assertSame(2, $today['sales_with_mode']);
        $this->assertSame(2, $today['unknowns_registered']);
        // (300 + 420) / 2 = 360.0, the null duration is ignored by AVG.
        $this->assertEqualsWithDelta(360.0, $today['avg_seconds_per_sale'], 0.0001);
        // (120 + 80) / 2 = 100.0
        $this->assertEqualsWithDelta(100.0, $today['avg_latency_ms'], 0.0001);
        $this->assertSame(2, $today['analyses']);

        $this->assertNotNull($today['last_event_at']);
        $this->assertSame(
            now()->subMinutes(5)->toDateTimeString(),
            Carbon::parse($today['last_event_at'])->toDateTimeString(),
        );
    }

    #[Test]
    public function it_ignores_the_events_of_yesterday(): void
    {
        $this->enableSmartCheckout();

        $this->event([
            'objects' => 10,
            'auto_accepted' => 5,
            'confirmed' => 2,
            'corrected' => 1,
            'unknown' => 2,
            'latency_ms' => 120,
            'occurred_at' => now()->subMinutes(5),
        ]);

        // Yesterday, same branch: it must not contaminate today's bar.
        $this->event([
            'objects' => 100,
            'auto_accepted' => 90,
            'confirmed' => 5,
            'corrected' => 5,
            'unknown' => 50,
            'latency_ms' => 999,
            'occurred_at' => now()->subDay()->setTime(23, 0),
        ]);

        $today = $this->getJson(route('pos.smart-checkout.metrics.today'))
            ->assertOk()
            ->json('today');

        $this->assertSame(10, $today['detected']);
        $this->assertSame(5, $today['auto_accepted']);
        $this->assertSame(2, $today['confirmed']);
        $this->assertSame(1, $today['corrected']);
        $this->assertSame(2, $today['unknown']);
        $this->assertSame(1, $today['analyses']);
        $this->assertEqualsWithDelta(120.0, $today['avg_latency_ms'], 0.0001);
    }

    #[Test]
    public function it_ignores_the_events_of_another_branch(): void
    {
        $this->enableSmartCheckout();

        $this->event([
            'objects' => 4,
            'auto_accepted' => 3,
            'confirmed' => 1,
            'corrected' => 0,
            'unknown' => 0,
            'latency_ms' => 100,
        ]);

        $otherBranch = Branch::factory()->create([
            'subscription_id' => $this->branch->subscription_id,
        ]);

        SmartPosDetectionEvent::create([
            'subscription_id' => $this->branch->subscription_id,
            'branch_id' => $otherBranch->id,
            'user_id' => $this->user->id,
            'session_id' => (string) Str::uuid(),
            'event_type' => 'analysis',
            'objects' => 99,
            'auto_accepted' => 99,
            'confirmed' => 0,
            'corrected' => 0,
            'unknown' => 0,
            'latency_ms' => 999,
            'occurred_at' => now(),
        ]);

        $today = $this->getJson(route('pos.smart-checkout.metrics.today'))
            ->assertOk()
            ->json('today');

        $this->assertSame(4, $today['detected']);
        $this->assertSame(3, $today['auto_accepted']);
        $this->assertSame(1, $today['analyses']);
        $this->assertEqualsWithDelta(100.0, $today['avg_latency_ms'], 0.0001);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function event(array $overrides = []): SmartPosDetectionEvent
    {
        return SmartPosDetectionEvent::create(array_merge([
            'subscription_id' => $this->branch->subscription_id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
            'session_id' => (string) Str::uuid(),
            'event_type' => 'analysis',
            'objects' => 0,
            'auto_accepted' => 0,
            'confirmed' => 0,
            'corrected' => 0,
            'unknown' => 0,
            'occurred_at' => now(),
        ], $overrides));
    }
}
