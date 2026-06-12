<?php

namespace Tests\Feature;

use App\Enums\CustomerBalanceMovementType;
use App\Enums\QuoteStatus;
use App\Enums\TransactionChannel;
use App\Enums\TransactionStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomFieldDefinition;
use App\Models\Product;
use App\Models\ProductAttribute; 
use App\Models\Quote;
use App\Models\Service;
use App\Models\SubscriptionVersion;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use App\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Inertia\Testing\AssertableInertia as Assert;

class QuoteControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Branch $branch;
    private Customer $customer;
    private Product $product;
    private Service $service;
    private CustomFieldDefinition $customField;
    private ProductAttribute $variant; 

    /**
     * Prepara el entorno para cada prueba.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 1. Crear datos base
        $this->branch = Branch::factory()->create();
        $this->user = User::factory()->create(['branch_id' => $this->branch->id]);
        $subscription = $this->branch->subscription;

        $subscription->update([
            'onboarding_completed_at' => now()
        ]);

        // 2. Simular suscripción activa
        SubscriptionVersion::create([
            'subscription_id' => $subscription->id,
            'start_date' => Carbon::yesterday(),
            'end_date' => Carbon::tomorrow(),
        ]);

        // 3. Configurar Permisos
        $permissions = [
            'quotes.access',
            'quotes.create',
            'quotes.see_details',
            'quotes.edit',
            'quotes.delete',
            'quotes.change_status',
            'quotes.create_sale',
        ];
        foreach ($permissions as $permission) {
            Permission::create(['name' => $permission, 'module' => 'quotes']);
        }
        $role = Role::create(['name' => 'Admin Cotizaciones', 'branch_id' => $this->branch->id]);
        $role->givePermissionTo($permissions);
        $this->user->assignRole($role);

        // 4. Limpiar caché de Spatie
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();

        // 5. Crear datos de prueba (CON STOCK EN PIVOTES)
        $this->customer = Customer::factory()->create(['branch_id' => $this->branch->id]);
        
        $this->product = Product::factory()->create([
            'branch_id' => $this->branch->id,
            'selling_price' => 100,
        ]);
        $this->product->branches()->attach($this->branch->id, ['current_stock' => 100, 'reserved_stock' => 0]);
        
        $this->service = Service::factory()->create(['branch_id' => $this->branch->id, 'base_price' => 50]);

        // 6. Crear variante (CON STOCK EN PIVOTE)
        // Nota: La variante está ligada a $this->product
        $this->variant = $this->product->productAttributes()->create([
            'attributes' => ['color' => 'rojo', 'talla' => 'M'],
            'selling_price_modifier' => 10,
        ]);
        $this->variant->branches()->attach($this->branch->id, ['current_stock' => 50, 'reserved_stock' => 0]);

        // 7. Crear campo personalizado
        $this->customField = CustomFieldDefinition::factory()->create([
            'subscription_id' => $subscription->id,
            'module' => 'quotes',
            'name' => 'Número de Serie',
            'key' => 'numero_de_serie',
            'type' => 'text',
        ]);

        // 8. Autenticar al usuario
        $this->actingAs($this->user);
    }

    #[Test]
    public function it_can_create_a_quote_successfully(): void
    {
        // --- ARRANGE ---
        $payload = [
            'customer_id' => $this->customer->id,
            'expiry_date' => now()->addDays(15)->format('Y-m-d'),
            'recipient_name' => 'Cliente de Prueba',
            'recipient_email' => 'cliente@prueba.com',
            'recipient_phone' => '1234567890',
            'shipping_address' => 'Calle Falsa 123',
            'notes' => 'Notas de prueba',
            'subtotal' => 250,
            'total_discount' => 0,
            'total_tax' => 0,
            'shipping_cost' => 10,
            'total_amount' => 260,
            'items' => [
                [ // Producto existente
                    'itemable_id' => $this->product->id,
                    'itemable_type' => Product::class,
                    'description' => $this->product->name,
                    'quantity' => 2,
                    'unit_price' => 100,
                    'line_total' => 200,
                ],
                [ // Item personalizado (sin ID)
                    'itemable_id' => 0,
                    'itemable_type' => Service::class, 
                    'description' => 'Instalación Manual',
                    'quantity' => 1,
                    'unit_price' => 50,
                    'line_total' => 50,
                ]
            ],
            'custom_fields' => [
                $this->customField->key => 'ABC-123',
            ],
        ];

        // --- ACT ---
        $response = $this->post(route('quotes.store'), $payload);

        // --- ASSERT ---
        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('quotes.index'));

        // 1. Verificar que se creó la Cotización
        $this->assertDatabaseHas('quotes', [
            'folio' => 'COT-001', // Tu backend lo autogenera con formato 001
            'customer_id' => $this->customer->id,
            'recipient_name' => 'Cliente de Prueba',
            'total_amount' => 260,
            'status' => QuoteStatus::DRAFT->value,
        ]);

        // 2. Verificar que el campo personalizado se guardó
        $quoteHasCustomField = Quote::where('folio', 'COT-001')
            ->whereJsonContains('custom_fields', ['numero_de_serie' => 'ABC-123'])
            ->exists();
        $this->assertTrue($quoteHasCustomField, 'El campo personalizado JSON no se guardó correctamente.');

        // 3. Verificar que se crearon los Items
        $this->assertDatabaseCount('quote_items', 2);
    }

    #[Test]
    public function it_can_update_a_quote(): void
    {
        // --- ARRANGE ---
        $quote = Quote::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
            'recipient_name' => 'Nombre Antiguo',
        ]);

        $quote->items()->create([
            'itemable_id' => 0, 
            'itemable_type' => Service::class, 
            'description' => 'Item Antiguo',
            'quantity' => 1,
            'unit_price' => 10,
            'line_total' => 10
        ]);

        $payload = [
            'recipient_name' => 'Nombre Actualizado',
            'notes' => 'Notas actualizadas',
            'subtotal' => 50,
            'total_discount' => 0,
            'total_tax' => 0,
            'shipping_cost' => 0,
            'total_amount' => 50,
            'items' => [
                [
                    'itemable_id' => 0,
                    'itemable_type' => Service::class,
                    'description' => 'Item Actualizado',
                    'quantity' => 1,
                    'unit_price' => 50,
                    'line_total' => 50,
                ]
            ],
            'custom_fields' => [],
        ];

        // --- ACT ---
        $response = $this->put(route('quotes.update', $quote), $payload);

        // --- ASSERT ---
        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('quotes.index'));

        $this->assertDatabaseHas('quotes', [
            'id' => $quote->id,
            'recipient_name' => 'Nombre Actualizado',
            'notes' => 'Notas actualizadas',
        ]);
        $this->assertDatabaseMissing('quote_items', [
            'quote_id' => $quote->id,
            'description' => 'Item Antiguo',
        ]);
        $this->assertDatabaseHas('quote_items', [
            'quote_id' => $quote->id,
            'description' => 'Item Actualizado',
        ]);
    }

    #[Test]
    public function it_can_create_a_new_version(): void
    {
        // --- ARRANGE ---
        $baseQuote = Quote::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
            'folio' => 'COT-100',
            'version_number' => 1,
        ]);
        $baseQuote->items()->create([
            'itemable_id' => 0,
            'itemable_type' => Service::class,
            'description' => 'Item de V1',
            'quantity' => 1,
            'unit_price' => 10,
            'line_total' => 10
        ]);

        // --- ACT ---
        $response = $this->post(route('quotes.newVersion', $baseQuote));

        // --- ASSERT ---
        $this->assertDatabaseCount('quotes', 2);

        $newQuote = Quote::find(2);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('quotes.edit', $newQuote->id));

        $this->assertDatabaseHas('quotes', [
            'id' => $newQuote->id,
            'parent_quote_id' => $baseQuote->id,
            'version_number' => 2,
            'folio' => 'COT-100-V2',
            'status' => QuoteStatus::DRAFT->value,
        ]);
    }

    #[Test]
    public function it_can_convert_quote_to_sale_and_decrements_stock_for_products_and_variants(): void
    {
        // --- ARRANGE ---
        $this->customer->update(['balance' => 0.00]); 
        $quoteTotal = (100 * 2) + (110 * 3); // 200 (Prod) + 330 (Var) = 530

        $quote = Quote::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
            'customer_id' => $this->customer->id,
            'status' => QuoteStatus::AUTHORIZED, 
            'subtotal' => $quoteTotal,
            'total_discount' => 0,
            'total_tax' => 0,
            'total_amount' => $quoteTotal,
        ]);
        
        $quote->items()->create([
            'itemable_id' => $this->product->id, 
            'itemable_type' => Product::class,
            'description' => 'Producto Simple', 'quantity' => 2, 'unit_price' => 100, 'line_total' => 200
        ]);
        
        $quote->items()->create([
            'itemable_id' => $this->variant->id, 
            'itemable_type' => ProductAttribute::class,
            'description' => 'Producto Variante', 'quantity' => 3, 'unit_price' => 110, 'line_total' => 330
        ]);

        // --- ACT ---
        $response = $this->post(route('quotes.convertToSale', $quote));

        // --- ASSERT ---
        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('quotes.show', $quote->id));

        // 1. Verificar que se creó la Transacción
        $this->assertDatabaseHas('transactions', [
            'status' => TransactionStatus::PENDING->value,
            'channel' => TransactionChannel::QUOTE->value,
            'subtotal' => $quoteTotal,
        ]);

        $transaction = Transaction::where('customer_id', $this->customer->id)->first();

        // 2. Verificar que la cotización se actualizó
        $this->assertDatabaseHas('quotes', [
            'id' => $quote->id,
            'status' => QuoteStatus::SALE_GENERATED->value,
            'transaction_id' => $transaction->id,
        ]);

        // 3. Verificar STOCK EN LAS TABLAS PIVOTE (REFACTOR)
        $productPivot = DB::table('branch_product')->where('product_id', $this->product->id)->where('branch_id', $this->branch->id)->first();
        // Aserción Corregida: El producto padre descuenta 2 (simples) + 3 (variantes hijas). 100 - 5 = 95.
        $this->assertEquals(95, $productPivot->current_stock, 'El stock del producto simple (y el de su variante hija) no se descontó del padre.');

        $variantPivot = DB::table('branch_product_attribute')->where('product_attribute_id', $this->variant->id)->where('branch_id', $this->branch->id)->first();
        $this->assertEquals(47, $variantPivot->current_stock, 'El stock de la variante no se descontó.');

        // 4. Verificar que se generó la deuda al cliente
        $expectedDebt = -$quoteTotal;
        $this->assertEquals($expectedDebt, $this->customer->fresh()->balance, 'El saldo del cliente no se actualizó a la deuda correcta.');
        $this->assertDatabaseHas('customer_balance_movements', [
            'customer_id' => $this->customer->id,
            'transaction_id' => $transaction->id,
            'type' => CustomerBalanceMovementType::CREDIT_SALE->value,
            'amount' => $expectedDebt, 
            'balance_after' => $expectedDebt,
        ]);
    }

    #[Test]
    public function it_prevents_converting_non_authorized_quote(): void
    {
        $quote = Quote::factory()->create([
            'branch_id' => $this->branch->id,
            'status' => QuoteStatus::DRAFT, // No está autorizada
        ]);

        $response = $this->post(route('quotes.convertToSale', $quote));

        $response->assertSessionHas('error');
        $response->assertRedirect();
        $this->assertDatabaseMissing('transactions', ['customer_id' => $quote->customer_id]);
        $this->assertEquals(QuoteStatus::DRAFT, $quote->fresh()->status);
    }

    #[Test]
    public function it_can_list_quotes_with_version_grouping(): void
    {
        $parentQuote = Quote::factory()->create([
            'branch_id' => $this->branch->id,
            'parent_quote_id' => null,
            'created_at' => now()->subDay(),
        ]);
        $childQuote = Quote::factory()->create([
            'branch_id' => $this->branch->id,
            'parent_quote_id' => $parentQuote->id,
        ]);
        $parentQuote2 = Quote::factory()->create([
            'branch_id' => $this->branch->id,
            'parent_quote_id' => null,
            'created_at' => now(),
        ]);

        $response = $this->get(route('quotes.index'));

        $response->assertOk();
        $response->assertInertia(
            fn(Assert $assert) => $assert
                ->has('quotes.data', 2)
                ->where('quotes.data.0.id', $parentQuote2->id)
                ->where('quotes.data.1.id', $parentQuote->id)
                ->has('quotes.data.1.versions', 1)
                ->where('quotes.data.1.versions.0.id', $childQuote->id)
                ->has('quotes.data.0.versions', 0)
        );
    }

    #[Test]
    public function it_can_cancel_a_sale_generated_quote_and_returns_stock(): void
    {
        // --- ARRANGE ---
        // 1. Establecer stock inicial (simulando que ya se descontó)
        DB::table('branch_product')->where('product_id', $this->product->id)->update(['current_stock' => 98]);
        DB::table('branch_product_attribute')->where('product_attribute_id', $this->variant->id)->update(['current_stock' => 47]);
        
        // 2. Crear Transacción (sin pagos)
        $transaction = Transaction::factory()->create([
            'branch_id' => $this->branch->id,
            'status' => TransactionStatus::PENDING,
        ]);

        // 3. Crear Cotización "Venta Generada" y ligarla
        $quote = Quote::factory()->create([
            'branch_id' => $this->branch->id,
            'status' => QuoteStatus::SALE_GENERATED,
            'transaction_id' => $transaction->id,
        ]);

        // 4. Añadir items a la cotización (los que se van a devolver)
        $quote->items()->create([
            'itemable_id' => $this->product->id,
            'itemable_type' => Product::class,
            'description' => 'Producto Simple',
            'quantity' => 2,
            'unit_price' => 100,
            'line_total' => 200
        ]);
        $quote->items()->create([
            'itemable_id' => $this->variant->id,
            'itemable_type' => ProductAttribute::class,
            'description' => 'Producto Variante',
            'quantity' => 3,
            'unit_price' => 110,
            'line_total' => 330
        ]);

        // --- ACT ---
        // Cambiamos el estatus a CANCELADO
        $response = $this->patch(route('quotes.updateStatus', $quote), [
            'status' => QuoteStatus::CANCELLED->value
        ]);

        // --- ASSERT ---
        $response->assertSessionHasNoErrors();
        $response->assertRedirect();

        // 1. Verificar estatus
        $this->assertEquals(QuoteStatus::CANCELLED, $quote->fresh()->status);
        $this->assertEquals(TransactionStatus::CANCELLED, $transaction->fresh()->status);

        // 2. Verificar que el stock se DEVOLVIÓ en las tablas pivote
        $productPivot = DB::table('branch_product')->where('product_id', $this->product->id)->where('branch_id', $this->branch->id)->first();
        // Aserción Corregida: El padre recupera 2 del item simple + 3 del item variante = 5. (98 + 5 = 103).
        $this->assertEquals(103, $productPivot->current_stock, 'El stock del producto padre no recuperó la suma de unidades de la variante y el simple.');

        $variantPivot = DB::table('branch_product_attribute')->where('product_attribute_id', $this->variant->id)->where('branch_id', $this->branch->id)->first();
        $this->assertEquals(50, $variantPivot->current_stock);
    }

    #[Test]
    public function it_does_not_return_stock_when_rejecting_a_draft_quote(): void
    {
        // --- ARRANGE ---
        $quote = Quote::factory()->create([
            'branch_id' => $this->branch->id,
            'status' => QuoteStatus::DRAFT,
        ]);
        $quote->items()->create([
            'itemable_id' => $this->product->id,
            'itemable_type' => Product::class,
            'description' => 'Producto',
            'quantity' => 2,
            'unit_price' => 100,
            'line_total' => 200
        ]);

        // --- ACT ---
        $response = $this->patch(route('quotes.updateStatus', $quote), [
            'status' => QuoteStatus::REJECTED->value
        ]);

        // --- ASSERT ---
        $response->assertSessionHasNoErrors();
        $this->assertEquals(QuoteStatus::REJECTED, $quote->fresh()->status);
        
        // El stock en la pivote no debe cambiar porque la cotización nunca fue convertida
        $productPivot = DB::table('branch_product')->where('product_id', $this->product->id)->where('branch_id', $this->branch->id)->first();
        $this->assertEquals(100, $productPivot->current_stock);
    }

    // --- NUEVAS PRUEBAS AÑADIDAS PARA COBERTURA AL 100% ---

    #[Test]
    public function it_renders_create_quote_page(): void
    {
        $response = $this->get(route('quotes.create'));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Quote/Create')
                ->has('customers')
                ->has('products')
                ->has('services')
            );
    }

    #[Test]
    public function it_renders_edit_quote_page(): void
    {
        $quote = Quote::factory()->create(['branch_id' => $this->branch->id]);

        $response = $this->get(route('quotes.edit', $quote));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Quote/Edit')
                ->has('quote')
            );
    }

    #[Test]
    public function it_shows_quote_details(): void
    {
        $quote = Quote::factory()->create(['branch_id' => $this->branch->id]);

        $response = $this->get(route('quotes.show', $quote));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Quote/Show')
                ->has('quote')
                ->has('activities')
            );
    }

    #[Test]
    public function it_prints_a_quote(): void
    {
        $quote = Quote::factory()->create(['branch_id' => $this->branch->id]);

        $response = $this->get(route('quotes.print', $quote));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Quote/Print')
                ->has('quote')
            );
    }

    #[Test]
    public function it_can_delete_a_quote(): void
    {
        $quote = Quote::factory()->create(['branch_id' => $this->branch->id]);

        $response = $this->delete(route('quotes.destroy', $quote));

        $response->assertRedirect(route('quotes.index'));
        $this->assertDatabaseMissing('quotes', ['id' => $quote->id]);
    }

    #[Test]
    public function it_can_batch_delete_quotes(): void
    {
        $quotes = Quote::factory()->count(3)->create(['branch_id' => $this->branch->id]);
        $ids = $quotes->pluck('id')->toArray();

        $response = $this->post(route('quotes.batchDestroy'), ['ids' => $ids]);

        $response->assertRedirect(route('quotes.index'));
        foreach ($ids as $id) {
            $this->assertDatabaseMissing('quotes', ['id' => $id]);
        }
    }

    #[Test]
    public function it_denies_access_without_permissions(): void
    {
        // Quitar rol y permisos
        $this->user->roles()->detach();

        // Middleware intercepta
        $response = $this->get(route('quotes.index'));
        $response->assertForbidden(); 
    }
}