<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\BankAccount;
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use App\Models\Product; // <-- Importante
use App\Models\Transaction; // <-- Importante
use App\Enums\TransactionStatus;
use App\Enums\TransactionChannel;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class PointOfSaleControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Branch $branch;
    private Customer $customer;
    private BankAccount $bankAccount;
    private CashRegisterSession $session;
    private Product $product;

    /**
     * Prepara el entorno para cada prueba.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 1. Crear datos base
        $this->branch = Branch::factory()->create();
        $this->user = User::factory()->create(['branch_id' => $this->branch->id]);
        
        // 2. Marcar Onboarding como completado
        $this->branch->subscription->update([
            'onboarding_completed_at' => now()
        ]);

        // Creamos el permiso que el controlador requiere
        $permission = Permission::create([
            'name' => 'pos.create_sale', 
            'module' => 'pos'
        ]);
        // Creamos un rol de prueba
        $role = Role::create([
            'name' => 'Vendedor', 
            'branch_id' => $this->branch->id
        ]);
        // Asignamos el permiso al rol
        $role->givePermissionTo($permission);
        // Asignamos el rol al usuario del test
        $this->user->assignRole($role);
        
        // 3. Crear cliente (para asociar la venta)
        $this->customer = Customer::factory()->create([
            'branch_id' => $this->branch->id,
            'balance' => 0.00 // Cliente sin deuda
        ]);

        // 4. Crear cuenta bancaria
        $this->bankAccount = BankAccount::factory()->create([
            'subscription_id' => $this->branch->subscription_id,
            'balance' => 5000.00
        ]);
        $this->bankAccount->branches()->attach($this->branch->id);

        // 5. Crear sesión de caja
        $cashRegister = CashRegister::factory()->create(['branch_id' => $this->branch->id]);
        $this->session = CashRegisterSession::factory()->create([
            'cash_register_id' => $cashRegister->id,
            'user_id' => $this->user->id,
            'status' => 'abierta'
        ]);

        // 6. Crear producto para vender
        $this->product = Product::factory()->create([
            'branch_id' => $this->branch->id,
            'selling_price' => 150.00,
            'current_stock' => 20
        ]);

        // 7. Autenticar al usuario
        $this->actingAs($this->user);
    }

    #[Test]
    public function it_creates_a_cash_sale_successfully(): void
    {
        // --- ARRANGE ---
        // Simular un carrito que compra 2 unidades del producto
        $payload = [
            'cash_register_session_id' => $this->session->id,
            'cartItems' => [
                [
                    'id' => $this->product->id,
                    'product_attribute_id' => null,
                    'quantity' => 2,
                    'unit_price' => 150.00,
                    'description' => $this->product->name,
                    'discount' => 0,
                    'discount_reason' => null
                ]
            ],
            'customerId' => $this->customer->id,
            'subtotal' => 300.00, // 2 * 150.00
            'total_discount' => 0,
            'total' => 300.00, // (subtotal - discount) + tax
            'use_balance' => false,
            'payments' => [
                // Pagar con $100 en efectivo y $200 en tarjeta
                ['amount' => 100.00, 'method' => 'efectivo', 'bank_account_id' => null],
                ['amount' => 200.00, 'method' => 'tarjeta', 'bank_account_id' => $this->bankAccount->id]
            ]
        ];

        // --- ACT ---
        // Asumo que tu ruta se llama 'pos.checkout'
        // Si es diferente, ajústala.
        $response = $this->post(route('pos.checkout'), $payload);

        // --- ASSERT ---
        
        // 1. Verificar respuesta exitosa
        $response->assertSessionHasNoErrors();
        // Asumo que redirige al index del POS
        $response->assertRedirect(route('pos.index')); 

        // 2. Verificar que se creó la Transacción
        $this->assertDatabaseHas('transactions', [
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'subtotal' => 300.00,
            'total_discount' => 0,
            'status' => TransactionStatus::COMPLETED, // ¡Importante!
            'channel' => TransactionChannel::POS,
            'folio' => 'V-001' // Primera venta
        ]);

        // 3. Verificar que se guardó el Item de la transacción
        $transaction = Transaction::where('folio', 'V-001')->first();
        $this->assertDatabaseHas('transaction_items', [
            'transaction_id' => $transaction->id,
            'itemable_id' => $this->product->id,
            'itemable_type' => Product::class,
            'quantity' => 2,
            'unit_price' => 150.00
        ]);

        // 4. Verificar que se guardaron los Pagos
        $this->assertDatabaseHas('payments', [
            'transaction_id' => $transaction->id,
            'amount' => 100.00,
            'method' => 'efectivo'
        ]);
        $this->assertDatabaseHas('payments', [
            'transaction_id' => $transaction->id,
            'amount' => 200.00,
            'method' => 'tarjeta',
            'bank_account_id' => $this->bankAccount->id
        ]);

        // 5. Verificar que el STOCK se descontó
        $this->assertEquals(
            18, // 20 iniciales - 2 vendidos
            $this->product->fresh()->current_stock,
            'El stock del producto no se descontó.'
        );

        // 6. Verificar que el SALDO BANCARIO aumentó
        $this->assertEquals(
            5200.00, // 5000 iniciales + 200 del pago con tarjeta
            $this->bankAccount->fresh()->balance,
            'El saldo de la cuenta bancaria no aumentó.'
        );

        // 7. Verificar que el SALDO DEL CLIENTE no cambió
        $this->assertEquals(
            0.00,
            $this->customer->fresh()->balance,
            'El saldo del cliente no debió cambiar.'
        );
        $this->assertDatabaseMissing('customer_balance_movements', [
            'customer_id' => $this->customer->id
        ]);
    }
}