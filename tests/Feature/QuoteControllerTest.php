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
use App\Models\ProductAttribute; // <-- IMPORTADO
use App\Models\Quote;
use App\Models\Service;
use App\Models\SubscriptionVersion;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class QuoteControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Branch $branch;
    private Customer $customer;
    private Product $product;
    private Service $service;
    private CustomFieldDefinition $customField;
    private ProductAttribute $variant; // <-- AÑADIDO

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

        // 5. Crear datos de prueba (CON STOCK CONOCIDO)
        $this->customer = Customer::factory()->create(['branch_id' => $this->branch->id]);
        $this->product = Product::factory()->create([
            'branch_id' => $this->branch->id,
            'selling_price' => 100,
            'current_stock' => 100 // Stock inicial conocido
        ]);
        $this->service = Service::factory()->create(['branch_id' => $this->branch->id, 'base_price' => 50]);

        // 6. Crear variante (CON STOCK CONOCIDO)
        $this->variant = $this->product->productAttributes()->create([
            'attributes' => ['color' => 'rojo', 'talla' => 'M'],
            'selling_price_modifier' => 10,
            'current_stock' => 50, // Stock inicial conocido
        ]);

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
        // ... (Este test no necesita cambios, ya que prueba la creación)
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
                    'itemable_type' => Service::class, // O 'App\Models\Service'
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
            'folio' => 'COT-1',
            'customer_id' => $this->customer->id,
            'recipient_name' => 'Cliente de Prueba',
            'total_amount' => 260,
            'status' => QuoteStatus::DRAFT->value,
        ]);

        // 2. Verificar que el campo personalizado se guardó
        $quoteHasCustomField = Quote::where('folio', 'COT-1')
            ->whereJsonContains('custom_fields', ['numero_de_serie' => 'ABC-123'])
            ->exists();
        $this->assertTrue($quoteHasCustomField, 'El campo personalizado JSON no se guardó correctamente.');

        // 3. Verificar que se crearon los Items
        $this->assertDatabaseCount('quote_items', 2);
        $this->assertDatabaseHas('quote_items', [
            'quote_id' => 1,
            'itemable_id' => $this->product->id,
            'quantity' => 2,
        ]);
        $this->assertDatabaseHas('quote_items', [
            'quote_id' => 1,
            'itemable_id' => 0, // Item personalizado
            'description' => 'Instalación Manual',
        ]);
    }

    #[Test]
    public function it_can_update_a_quote(): void
    {
        // ... (Este test está bien, ya que prueba la actualización de campos)
        // --- ARRANGE ---
        $quote = Quote::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
            'recipient_name' => 'Nombre Antiguo',
        ]);

        // Añadimos los campos polimórficos que faltaban
        $quote->items()->create([
            'itemable_id' => 0, // 0 para item personalizado
            'itemable_type' => Service::class, // O 'App\Models\Service'
            'description' => 'Item Antiguo',
            'quantity' => 1,
            'unit_price' => 10,
            'line_total' => 10
        ]);

        $payload = [
            'recipient_name' => 'Nombre Actualizado',
            'notes' => 'Notas actualizadas',
            // ... (el resto de campos del form)
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
        // ... (Este test está bien, prueba la lógica de versionado)
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

        $this->assertDatabaseHas('quote_items', [
            'quote_id' => $newQuote->id,
            'description' => 'Item de V1',
        ]);
    }

    #[Test]
    public function it_can_convert_quote_to_sale_and_decrements_stock_for_products_and_variants(): void
    {
        // --- ARRANGE ---
        // Obtenemos stock inicial conocido
        $initialProductStock = $this->product->current_stock; // 100
        $initialVariantStock = $this->variant->current_stock; // 50
        $this->customer->update(['balance' => 0.00]); // <-- ASEGURAR SALDO INICIAL
        $quoteTotal = (100 * 2) + (110 * 3); // 200 (Prod) + 330 (Var) = 530

        $quote = Quote::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
            'customer_id' => $this->customer->id,
            'status' => QuoteStatus::AUTHORIZED, // Requisito
            'subtotal' => $quoteTotal,
            'total_discount' => 0,
            'total_tax' => 0,
            'total_amount' => $quoteTotal,
        ]);
        
        // Item 1: Producto Simple (Cantidad 2)
        $quote->items()->create([
            'itemable_id' => $this->product->id, 
            'itemable_type' => Product::class,
            'description' => 'Producto Simple', 'quantity' => 2, 'unit_price' => 100, 'line_total' => 200
        ]);
        // Item 2: Variante (Cantidad 3)
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
            'folio' => 'V-001',
            'status' => TransactionStatus::PENDING->value,
            'channel' => TransactionChannel::QUOTE->value,
            'subtotal' => $quoteTotal,
        ]);

        // 2. Verificar que los items se copiaron (uno como Product, uno como ProductAttribute)
        $transaction = Transaction::where('folio', 'V-001')->first();
        $this->assertDatabaseHas('transactions_items', [
            'transaction_id' => $transaction->id,
            'itemable_id' => $this->product->id,
            'itemable_type' => Product::class,
            'quantity' => 2,
        ]);
        $this->assertDatabaseHas('transactions_items', [
            'transaction_id' => $transaction->id,
            'itemable_id' => $this->variant->id,
            'itemable_type' => ProductAttribute::class,
            'quantity' => 3,
        ]);

        // 3. Verificar que la cotización se actualizó
        $this->assertDatabaseHas('quotes', [
            'id' => $quote->id,
            'status' => QuoteStatus::SALE_GENERATED->value,
            'transaction_id' => $transaction->id,
        ]);

        // 4. Verificar que el stock se DESCONTÓ
        $this->assertEquals($initialProductStock - 2, $this->product->fresh()->current_stock, 'El stock del producto simple no se descontó.');
        $this->assertEquals($initialVariantStock - 3, $this->variant->fresh()->current_stock, 'El stock de la variante no se descontó.');

        // 5. Verificar que se generó la deuda al cliente
        $expectedDebt = -$quoteTotal;
        $this->assertEquals($expectedDebt, $this->customer->fresh()->balance, 'El saldo del cliente no se actualizó a la deuda correcta.');
        $this->assertDatabaseHas('customer_balance_movements', [
            'customer_id' => $this->customer->id,
            'transaction_id' => $transaction->id,
            'type' => CustomerBalanceMovementType::CREDIT_SALE->value,
            'amount' => $expectedDebt, // La deuda es un movimiento negativo
            'balance_after' => $expectedDebt,
        ]);
    }

    #[Test]
    public function it_prevents_converting_non_authorized_quote(): void
    {
        // ... (Este test está bien, prueba la validación)
        // --- ARRANGE ---
        $quote = Quote::factory()->create([
            'branch_id' => $this->branch->id,
            'status' => QuoteStatus::DRAFT, // No está autorizada
        ]);

        // --- ACT ---
        $response = $this->post(route('quotes.convertToSale', $quote));

        // --- ASSERT ---
        $response->assertSessionHas('error');
        $response->assertRedirect();
        $this->assertDatabaseMissing('transactions', ['transactionable_id' => $quote->id]);
        $this->assertEquals(QuoteStatus::DRAFT, $quote->fresh()->status);
    }

    #[Test]
    public function it_can_list_quotes_with_version_grouping(): void
    {
        // ... (Este test está bien, prueba el index)
        // --- ARRANGE ---
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

        // --- ACT ---
        $response = $this->get(route('quotes.index'));

        // --- ASSERT ---
        $response->assertOk();
        $response->assertInertia(
            fn($assert) => $assert
                ->has('quotes.data', 2)
                ->where('quotes.data.0.id', $parentQuote2->id)
                ->where('quotes.data.1.id', $parentQuote->id)
                ->has('quotes.data.1.versions', 1)
                ->where('quotes.data.1.versions.0.id', $childQuote->id)
                ->has('quotes.data.0.versions', 0)
        );
    }

    // --- INICIO: NUEVOS TESTS ---

    #[Test]
    public function it_can_cancel_a_sale_generated_quote_and_returns_stock(): void
    {
        // --- ARRANGE ---
        // 1. Establecer stock inicial (simulando que ya se descontó)
        $this->product->update(['current_stock' => 98]); // Stock inicial 100 - 2 vendidos
        $this->variant->update(['current_stock' => 47]); // Stock inicial 50 - 3 vendidos
        $initialProductStock = $this->product->current_stock;
        $initialVariantStock = $this->variant->current_stock;

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

        // 1. Verificar estatus de la Cotización
        $this->assertEquals(QuoteStatus::CANCELLED, $quote->fresh()->status);

        // 2. Verificar estatus de la Transacción (CANCELLED porque no tenía pagos)
        $this->assertEquals(TransactionStatus::CANCELLED, $transaction->fresh()->status);

        // 3. Verificar que el stock se DEVOLVIÓ
        $this->assertEquals($initialProductStock + 2, $this->product->fresh()->current_stock, 'El stock del producto simple no se devolvió.');
        $this->assertEquals($initialVariantStock + 3, $this->variant->fresh()->current_stock, 'El stock de la variante no se devolvió.');
    }

    #[Test]
    public function it_does_not_return_stock_when_rejecting_a_draft_quote(): void
    {
        // --- ARRANGE ---
        $initialProductStock = $this->product->current_stock; // 100
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
        // El stock no debe cambiar porque la cotización nunca fue 'SALE_GENERATED'
        $this->assertEquals($initialProductStock, $this->product->fresh()->current_stock, 'El stock no debió cambiar.');
    }

    // --- FIN: NUEVOS TESTS ---
}
