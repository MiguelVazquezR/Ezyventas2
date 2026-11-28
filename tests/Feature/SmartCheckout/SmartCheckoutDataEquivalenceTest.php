<?php

namespace Tests\Feature\SmartCheckout;

use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\TransactionItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Activitylog\Models\Activity;

/**
 * Phase 2 acceptance criterion: registering detection metrics never changes a
 * sale.
 *
 * Two identical sales are booked through `pos.checkout` (same products, same
 * quantities, same payments). One of them has analysis and sale events recorded
 * around it. Both must produce the same transaction, the same lines and the same
 * stock movements.
 */
class SmartCheckoutDataEquivalenceTest extends SmartCheckoutTestCase
{
    private Customer $customer;

    private CashRegisterSession $session;

    private Product $drink;

    private Product $snack;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = Customer::factory()->create([
            'branch_id' => $this->branch->id,
            'balance' => 0.00,
            'credit_limit' => 1000.00,
        ]);

        $cashRegister = CashRegister::factory()->create(['branch_id' => $this->branch->id]);
        $this->session = CashRegisterSession::factory()->create([
            'cash_register_id' => $cashRegister->id,
            'user_id' => $this->user->id,
            'status' => 'abierta',
        ]);

        $this->drink = Product::factory()->withStock($this->branch->id, 50)->create([
            'name' => 'Refresco de lata',
            'sku' => 'REF-001',
            'is_bulk' => false,
            'measure_unit' => 'Pza',
            'selling_price' => 50.00,
        ]);

        $this->snack = Product::factory()->withStock($this->branch->id, 50)->create([
            'name' => 'Papas fritas',
            'sku' => 'PAP-001',
            'is_bulk' => false,
            'measure_unit' => 'Pza',
            'selling_price' => 20.00,
        ]);
    }

    #[Test]
    public function recording_events_does_not_change_the_sale(): void
    {
        $this->enableSmartCheckout();

        // Events around the first sale: an analysis before, and the sale plus a
        // second analysis right after.
        $this->postJson(route('pos.smart-checkout.events.store'), $this->eventPayload())
            ->assertCreated();

        $saleWithEvents = $this->bookSale();

        $this->postJson(route('pos.smart-checkout.events.store'), $this->eventPayload([
            'event_type' => 'sale',
            'sale_id' => $saleWithEvents->id,
            'duration_seconds' => 96,
        ]))->assertCreated();

        $this->postJson(route('pos.smart-checkout.events.store'), $this->eventPayload())
            ->assertCreated();

        // The control sale: identical cart, no metric events around it.
        $saleWithoutEvents = $this->bookSale();

        $this->assertDatabaseCount('transactions', 2);
        $this->assertDatabaseCount('smartpos_detection_events', 3);

        // 1. Same transaction amounts (the engine still recalculates them from
        //    the cart; metrics play no part in the calculation).
        foreach (['subtotal', 'total_discount', 'total_tax'] as $field) {
            $this->assertEqualsWithDelta(
                (float) $saleWithEvents->fresh()->{$field},
                (float) $saleWithoutEvents->fresh()->{$field},
                0.001,
                "Field {$field} differs between the two sales.",
            );
        }

        $this->assertEqualsWithDelta(
            (float) $saleWithEvents->fresh()->total,
            (float) $saleWithoutEvents->fresh()->total,
            0.001,
            'The total differs between the two sales.',
        );

        // Both sales are exactly the expected cart: 2 × 50 + 3 × 20 = 160.
        $this->assertEqualsWithDelta(160.00, (float) $saleWithoutEvents->fresh()->total, 0.001);

        // 2. Same lines: quantity, unit price and line total.
        $this->assertSame(
            $this->transactionLines($saleWithEvents),
            $this->transactionLines($saleWithoutEvents),
        );

        // 3. Same stock movements: one per product and the same delta.
        $movements = $this->stockMovements($saleWithEvents->id);

        $this->assertCount(2, $movements);
        $this->assertSame($movements, $this->stockMovements($saleWithoutEvents->id));

        // And the final stock matches the sum of both sales.
        $this->assertEqualsWithDelta(
            46.0,
            (float) DB::table('branch_product')
                ->where('branch_id', $this->branch->id)
                ->where('product_id', $this->drink->id)
                ->value('current_stock'),
            0.001,
        );
        $this->assertEqualsWithDelta(
            44.0,
            (float) DB::table('branch_product')
                ->where('branch_id', $this->branch->id)
                ->where('product_id', $this->snack->id)
                ->value('current_stock'),
            0.001,
        );
    }

    /**
     * Books the reference sale and returns the transaction that was created.
     */
    private function bookSale(): Transaction
    {
        $this->post(route('pos.checkout'), $this->salePayload())
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('pos.index'));

        return Transaction::query()
            ->where('branch_id', $this->branch->id)
            ->latest('id')
            ->firstOrFail();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function transactionLines(Transaction $transaction): array
    {
        return TransactionItem::query()
            ->where('transaction_id', $transaction->id)
            ->orderBy('description')
            ->get()
            ->map(fn (TransactionItem $item) => [
                'description' => $item->description,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'line_total' => $item->line_total,
            ])
            ->all();
    }

    /**
     * Stock movements recorded for one sale, normalised so both sales can be
     * compared even though they leave the stock at a different level.
     *
     * @return array<int, array{product_id: int, quantity_changed: float}>
     */
    private function stockMovements(int $transactionId): array
    {
        return Activity::query()
            ->where('event', 'stock_update')
            ->get()
            ->filter(fn (Activity $activity) => (int) ($activity->properties['transaction_id'] ?? 0) === $transactionId)
            ->map(fn (Activity $activity) => [
                'product_id' => (int) $activity->subject_id,
                'quantity_changed' => (float) $activity->properties['quantity_changed'],
            ])
            ->sortBy('product_id')
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function salePayload(): array
    {
        $items = [
            [
                'id' => $this->drink->id,
                'product_attribute_id' => null,
                'quantity' => 2,
                'unit_price' => 50.00,
                'description' => $this->drink->name,
                'discount' => 0,
                'discount_reason' => null,
            ],
            [
                'id' => $this->snack->id,
                'product_attribute_id' => null,
                'quantity' => 3,
                'unit_price' => 20.00,
                'description' => $this->snack->name,
                'discount' => 0,
                'discount_reason' => null,
            ],
        ];

        $total = 2 * 50.00 + 3 * 20.00;

        return [
            'cash_register_session_id' => $this->session->id,
            'cartItems' => $items,
            'customerId' => $this->customer->id,
            'subtotal' => $total,
            'total_discount' => 0,
            'total' => $total,
            'use_balance' => false,
            'payments' => [
                ['amount' => $total, 'method' => 'efectivo', 'bank_account_id' => null],
            ],
        ];
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
            'objects' => 2,
            'auto_accepted' => 2,
            'confirmed' => 0,
            'corrected' => 0,
            'unknown' => 0,
            'latency_ms' => 120,
            'model_version' => 'mock',
        ], $overrides);
    }
}
