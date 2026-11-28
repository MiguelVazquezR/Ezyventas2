<?php

namespace Tests\Feature\SmartCheckout;

use App\Enums\PlanItemType;
use App\Enums\TransactionStatus;
use App\Http\Middleware\CheckOnboardingStatus;
use App\Http\Middleware\CheckSubscriptionStatus;
use App\Http\Middleware\EnsureSubscriptionScope;
use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use App\Models\Customer;
use App\Models\PlanItem;
use App\Models\Product;
use App\Models\SmartPosSetting;
use App\Models\Subscription;
use App\Models\SubscriptionItem;
use App\Models\SubscriptionVersion;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Fractional quantities at the POS checkout (decision D1).
 *
 * The invariant under test: with `scale_enabled` off the POS validates and books
 * exactly what it booked before (a 0.5 line is rejected with the same message),
 * and only bulk products of the cashier's branch can be sold by weight when the
 * business enabled the scale.
 */
class ScaleCartValidationTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $user;

    private Customer $customer;

    private CashRegisterSession $session;

    private Product $bulkProduct;

    private Product $pieceProduct;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            CheckOnboardingStatus::class,
            CheckSubscriptionStatus::class,
            EnsureSubscriptionScope::class,
        ]);

        // Platform support user (id 1 passes every gate): create it first so the
        // cashier of these tests is a regular one.
        User::factory()->create();

        $this->branch = $this->makeTenant();
        $this->user = User::factory()->create(['branch_id' => $this->branch->id]);

        $this->createPosPermissions();

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

        // Bulk product sold by weight: 100.00 per kg.
        $this->bulkProduct = Product::factory()->withStock($this->branch->id, 50)->create([
            'name' => 'Harina de trigo',
            'sku' => 'HAR-001',
            'is_bulk' => true,
            'measure_unit' => 'Kg',
            'selling_price' => 100.00,
        ]);

        // Regular product: still one unit at a time.
        $this->pieceProduct = Product::factory()->withStock($this->branch->id, 50)->create([
            'name' => 'Refresco de lata',
            'sku' => 'REF-001',
            'is_bulk' => false,
            'measure_unit' => 'Pza',
            'selling_price' => 50.00,
        ]);

        $this->actingAs($this->user);
    }

    #[Test]
    public function it_rejects_fractional_quantities_with_the_current_message_when_the_scale_is_disabled(): void
    {
        $expectedMessage = $this->currentMinimumMessage(0.5);

        $this->post(route('pos.checkout'), $this->salePayload(
            $this->cartItem($this->bulkProduct, 0.5),
        ))->assertSessionHasErrors(['cartItems.0.quantity' => $expectedMessage]);

        $this->assertDatabaseCount('transactions', 0);
    }

    #[Test]
    public function it_accepts_a_whole_unit_when_the_scale_is_disabled(): void
    {
        $this->post(route('pos.checkout'), $this->salePayload(
            $this->cartItem($this->bulkProduct, 1),
        ))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('pos.index'));

        $this->assertDatabaseHas('transactions', [
            'branch_id' => $this->branch->id,
            'subtotal' => 100.00,
            'status' => TransactionStatus::COMPLETED->value,
        ]);
    }

    #[Test]
    public function it_keeps_a_regular_sale_working_after_the_rule_change(): void
    {
        // Same sale the POS has always booked: two units of a regular product.
        $this->post(route('pos.checkout'), $this->salePayload(
            $this->cartItem($this->pieceProduct, 2),
        ))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('pos.index'));

        $this->assertDatabaseHas('transactions', [
            'branch_id' => $this->branch->id,
            'subtotal' => 100.00,
            'total_discount' => 0,
        ]);

        $transaction = Transaction::where('branch_id', $this->branch->id)->firstOrFail();
        $item = TransactionItem::where('transaction_id', $transaction->id)->firstOrFail();

        $this->assertSame('2.000', $item->quantity);

        $pivot = DB::table('branch_product')
            ->where('branch_id', $this->branch->id)
            ->where('product_id', $this->pieceProduct->id)
            ->first();
        $this->assertEquals(48, (float) $pivot->current_stock);
    }

    #[Test]
    public function it_accepts_a_weighted_quantity_for_a_bulk_product_when_the_scale_is_enabled(): void
    {
        $this->enableScale();

        $this->post(route('pos.checkout'), $this->salePayload(
            $this->cartItem($this->bulkProduct, 0.75),
        ))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('pos.index'));

        $transaction = Transaction::where('branch_id', $this->branch->id)->firstOrFail();
        $item = TransactionItem::where('transaction_id', $transaction->id)->firstOrFail();

        // decimal(10,3) column read through the `decimal:3` cast.
        $this->assertSame('0.750', $item->quantity);

        // total = 0.75 kg × 100.00 / kg
        $this->assertEqualsWithDelta(75.00, (float) $transaction->fresh()->total, 0.001);
        $this->assertEqualsWithDelta(75.00, (float) $transaction->subtotal, 0.001);

        $pivot = DB::table('branch_product')
            ->where('branch_id', $this->branch->id)
            ->where('product_id', $this->bulkProduct->id)
            ->first();
        $this->assertEqualsWithDelta(49.25, (float) $pivot->current_stock, 0.001);
    }

    #[Test]
    public function it_still_rejects_fractional_quantities_for_a_regular_product_when_the_scale_is_enabled(): void
    {
        $this->enableScale();

        $expectedMessage = $this->currentMinimumMessage(0.5);

        $this->post(route('pos.checkout'), $this->salePayload(
            $this->cartItem($this->pieceProduct, 0.5),
        ))->assertSessionHasErrors(['cartItems.0.quantity' => $expectedMessage]);

        $this->assertDatabaseCount('transactions', 0);
    }

    #[Test]
    public function it_does_not_weigh_a_bulk_product_of_another_branch(): void
    {
        $this->enableScale();

        $otherBranch = Branch::factory()->create(['subscription_id' => $this->branch->subscription_id]);
        $foreignProduct = Product::factory()->withStock($otherBranch->id, 50)->create([
            'name' => 'Harina de otra sucursal',
            'sku' => 'HAR-777',
            'is_bulk' => true,
            'measure_unit' => 'Kg',
            'selling_price' => 100.00,
        ]);

        $expectedMessage = $this->currentMinimumMessage(0.75);

        $this->post(route('pos.checkout'), $this->salePayload(
            $this->cartItem($foreignProduct, 0.75),
        ))->assertSessionHasErrors(['cartItems.0.quantity' => $expectedMessage]);

        $this->assertDatabaseCount('transactions', 0);
    }

    /*
    |--------------------------------------------------------------------------
    | HELPERS
    |--------------------------------------------------------------------------
    */

    /**
     * Message the framework produces today for `cartItems.*.quantity => min:1`
     * (and `min:0.001` when the quantity is fractional), so the assertion proves
     * the wording was not changed by moving the rule into the controller.
     */
    private function currentMinimumMessage(float $quantity, float $minimum = 1): string
    {
        $message = Validator::make(
            ['cartItems' => [['quantity' => $quantity]]],
            ['cartItems.*.quantity' => 'required|numeric|min:'.$minimum],
        )->errors()->first('cartItems.0.quantity');

        $this->assertIsString($message);
        $this->assertNotSame('', $message);

        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    private function cartItem(Product $product, float $quantity): array
    {
        return [
            'id' => $product->id,
            'product_attribute_id' => null,
            'quantity' => $quantity,
            'unit_price' => (float) $product->selling_price,
            'description' => $product->name,
            'discount' => 0,
            'discount_reason' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $cartItem
     * @return array<string, mixed>
     */
    private function salePayload(array $cartItem): array
    {
        $total = ((float) $cartItem['unit_price'] + (float) $cartItem['discount']) * (float) $cartItem['quantity'];

        return [
            'cash_register_session_id' => $this->session->id,
            'cartItems' => [$cartItem],
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
     * Branch of a fresh tenant with the Point of Sale module contracted. The
     * permissions of the feature only work when their module ("Punto de Venta")
     * is one of the contracted module names.
     */
    private function makeTenant(): Branch
    {
        $subscription = Subscription::factory()->create();

        SubscriptionVersion::create([
            'subscription_id' => $subscription->id,
            'start_date' => now()->subDay(),
            'end_date' => now()->addDay(),
        ]);

        PlanItem::firstOrCreate(
            ['key' => 'module_pos'],
            [
                'type' => PlanItemType::MODULE,
                'name' => 'Punto de Venta',
                'monthly_price' => 0,
                'is_active' => true,
            ],
        );

        SubscriptionItem::create([
            'subscription_version_id' => $subscription->currentVersion()->id,
            'item_key' => 'module_pos',
            'item_type' => 'module',
            'name' => 'Punto de Venta',
            'quantity' => 1,
            'unit_price' => 0,
        ]);

        return Branch::factory()->create(['subscription_id' => $subscription->id]);
    }

    private function createPosPermissions(): void
    {
        // `scale.*` come from the data migration 2026_11_27_000002.
        foreach ([
            'pos.access' => 'Acceder al Punto de Venta',
            'pos.create_sale' => 'Crear nuevas ventas',
        ] as $name => $description) {
            Permission::create([
                'name' => $name,
                'description' => $description,
                'module' => 'Punto de Venta',
                'guard_name' => 'web',
            ]);
        }

        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function enableScale(): SmartPosSetting
    {
        return SmartPosSetting::create([
            'subscription_id' => $this->branch->subscription_id,
            'scale_enabled' => true,
        ]);
    }
}
