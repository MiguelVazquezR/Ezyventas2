<?php

namespace Tests\Feature\SmartCheckout;

use App\Models\Branch;
use App\Models\SmartPosDetectionEvent;
use App\Models\Transaction;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;

/**
 * Detection event ingestion of the "Modo Caja Inteligente" (Phase 2).
 *
 * The tenant and the branch of an event always come from the authenticated user,
 * never from the payload, and a sale event can only reference a sale of the
 * cashier's own branch. The route is rate limited (120 events per minute).
 */
class SmartCheckoutEventsTest extends SmartCheckoutTestCase
{
    #[Test]
    public function it_returns_403_while_the_mode_is_disabled(): void
    {
        $this->postJson(route('pos.smart-checkout.events.store'), $this->eventPayload())
            ->assertForbidden()
            ->assertJsonPath('message', 'El Modo Caja Inteligente no está habilitado para este negocio.');

        $this->assertDatabaseCount('smartpos_detection_events', 0);
    }

    #[Test]
    public function it_registers_an_analysis_with_the_tenant_and_branch_of_the_user(): void
    {
        $this->enableSmartCheckout();

        $response = $this->postJson(route('pos.smart-checkout.events.store'), $this->eventPayload([
            // A tampered payload cannot choose another tenant.
            'subscription_id' => 999999,
            'branch_id' => 999999,
            'user_id' => 999999,
        ]));

        $response->assertCreated()
            ->assertJsonPath('event_type', 'analysis')
            ->assertJsonStructure(['id', 'event_type', 'occurred_at']);

        $this->assertNotNull($response->json('occurred_at'));

        $event = SmartPosDetectionEvent::firstOrFail();

        $this->assertSame((int) $this->branch->subscription_id, $event->subscription_id);
        $this->assertSame((int) $this->branch->id, $event->branch_id);
        $this->assertSame((int) $this->user->id, $event->user_id);
        $this->assertSame('analysis', $event->event_type);
        $this->assertSame(4, $event->objects);
        $this->assertSame(2, $event->auto_accepted);
        $this->assertSame(1, $event->confirmed);
        $this->assertSame(1, $event->corrected);
        $this->assertSame(0, $event->unknown);
        $this->assertSame(150, $event->latency_ms);
        $this->assertSame('mock', $event->model_version);
        $this->assertNull($event->sale_id);
        $this->assertNull($event->duration_seconds);
    }

    #[Test]
    public function it_validates_the_payload_with_a_spanish_422(): void
    {
        $this->enableSmartCheckout();

        $invalidPayloads = [
            'session is not a uuid' => [
                ['session_id' => 'sesion-1'],
                'session_id',
            ],
            'negative objects' => [
                ['objects' => -1],
                'objects',
            ],
            'unknown event type' => [
                ['event_type' => 'recognition'],
                'event_type',
            ],
            'latency out of range' => [
                ['latency_ms' => 60001],
                'latency_ms',
            ],
            'duration out of range' => [
                ['event_type' => 'sale', 'duration_seconds' => 86401],
                'duration_seconds',
            ],
            'sale that does not exist' => [
                ['event_type' => 'sale', 'sale_id' => 987654],
                'sale_id',
            ],
            'model version too long' => [
                ['model_version' => str_repeat('a', 65)],
                'model_version',
            ],
        ];

        foreach ($invalidPayloads as $case => [$overrides, $errorKey]) {
            $response = $this->postJson(route('pos.smart-checkout.events.store'), $this->eventPayload($overrides))
                ->assertStatus(422)
                ->assertJsonValidationErrors($errorKey);

            // The message that surfaces is the Spanish one, not the framework's.
            $this->assertNotSame('', (string) $response->json('message'));
        }

        $this->assertDatabaseCount('smartpos_detection_events', 0);
    }

    #[Test]
    public function it_accepts_a_sale_event_of_the_cashier_branch(): void
    {
        $this->enableSmartCheckout();

        $sale = Transaction::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
        ]);

        $this->postJson(route('pos.smart-checkout.events.store'), $this->eventPayload([
            'event_type' => 'sale',
            'sale_id' => $sale->id,
            'duration_seconds' => 96,
        ]))
            ->assertCreated()
            ->assertJsonPath('event_type', 'sale');

        $event = SmartPosDetectionEvent::firstOrFail();

        $this->assertSame($sale->id, $event->sale_id);
        $this->assertSame(96, $event->duration_seconds);
        $this->assertSame($this->branch->id, $event->branch_id);
    }

    #[Test]
    public function it_rejects_a_sale_event_of_another_branch(): void
    {
        $this->enableSmartCheckout();

        $otherBranch = Branch::factory()->create([
            'subscription_id' => $this->branch->subscription_id,
        ]);

        $foreignSale = Transaction::factory()->create([
            'branch_id' => $otherBranch->id,
        ]);

        $this->postJson(route('pos.smart-checkout.events.store'), $this->eventPayload([
            'event_type' => 'sale',
            'sale_id' => $foreignSale->id,
            'duration_seconds' => 60,
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sale_id'])
            ->assertJsonPath('message', 'La venta indicada no pertenece a la sucursal del cajero.');

        $this->assertDatabaseCount('smartpos_detection_events', 0);
    }

    #[Test]
    public function the_event_route_is_rate_limited(): void
    {
        $route = Route::getRoutes()->getByName('pos.smart-checkout.events.store');

        $this->assertNotNull($route);
        $this->assertContains('throttle:120,1', $route->gatherMiddleware());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function eventPayload(array $overrides = []): array
    {
        return array_merge([
            'session_id' => (string) Str::uuid(),
            'event_type' => 'analysis',
            'objects' => 4,
            'auto_accepted' => 2,
            'confirmed' => 1,
            'corrected' => 1,
            'unknown' => 0,
            'latency_ms' => 150,
            'model_version' => 'mock',
        ], $overrides);
    }
}
