<?php

namespace Tests\Feature\Api\V1;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ServiceOrderStatus;
use App\Enums\TransactionChannel;
use App\Enums\TransactionStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\ServiceOrder;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Api\V1\Concerns\BuildsMobileApiContext;
use Tests\TestCase;

/**
 * Covers GET /api/v1/dashboard and the two alert lists the home screen links to.
 */
class DashboardApiTest extends TestCase
{
    use RefreshDatabase;
    use BuildsMobileApiContext;

    private Customer $customer;

    /**
     * Permissions of the home screen. In the seeder they belong to the
     * "Sistema" module, so they are created here with that same module: the
     * access rules of AppServiceProvider depend on it.
     *
     * @var array<int, string>
     */
    private array $dashboardPermissions = [
        'dashboard.see_sales',
        'dashboard.see_layaways',
        'dashboard.see_orders',
        'dashboard.see_outstanding_balances',
        'dashboard.see_inventory_details',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpMobileApiContext();

        foreach ($this->dashboardPermissions as $permission) {
            Permission::create(['name' => $permission, 'module' => 'Sistema', 'guard_name' => 'web']);
        }

        $this->customer = Customer::factory()->create([
            'branch_id' => $this->branch->id,
            'name' => 'Ana Ramírez',
            'phone' => '4771112233',
            // The factory randomizes the balance, which would break the debt KPI.
            'balance' => 0,
        ]);
    }

    /**
     * Sale of 270 in total (300 - 30), created today unless stated otherwise.
     */
    private function sale(array $attributes = []): Transaction
    {
        return Transaction::factory()->create(array_merge([
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customer->id,
            'user_id' => $this->branch->users()->first()?->id,
            'status' => TransactionStatus::COMPLETED,
            'channel' => TransactionChannel::POS,
            'subtotal' => 300,
            'total_discount' => 30,
            'total_tax' => 0,
            'shipping_cost' => 0,
            'invoiced' => false,
            'created_at' => now(),
        ], $attributes));
    }

    /**
     * Layaway or credit sale that expires on the given date, with an optional
     * payment already collected.
     */
    private function pendingSale(TransactionStatus $status, string $expirationDate, float $paid = 0, array $attributes = []): Transaction
    {
        $transaction = $this->sale(array_merge([
            'status' => $status,
            'subtotal' => 200,
            'total_discount' => 0,
            'layaway_expiration_date' => $expirationDate,
        ], $attributes));

        $this->pay($transaction, $paid);

        return $transaction;
    }

    /**
     * Order waiting to be delivered (400 - 50 + 30 shipping = 380).
     */
    private function order(string $deliveryDate, float $paid = 0, array $attributes = []): Transaction
    {
        $transaction = $this->sale(array_merge([
            'status' => TransactionStatus::TO_DELIVER,
            'subtotal' => 400,
            'total_discount' => 50,
            'shipping_cost' => 30,
            'delivery_date' => $deliveryDate,
            'shipping_address' => 'Av. Hidalgo 120, León',
            'notes' => 'Entregar después de las 6 pm',
            'contact_info' => ['name' => 'Luis Pérez', 'phone' => '4779998877'],
        ], $attributes));

        $this->pay($transaction, $paid);

        return $transaction;
    }

    private function pay(Transaction $transaction, float $amount): void
    {
        if ($amount <= 0) {
            return;
        }

        Payment::factory()->create([
            'transaction_id' => $transaction->id,
            'amount' => $amount,
            'payment_method' => PaymentMethod::CASH,
            'status' => PaymentStatus::COMPLETED,
        ]);
    }

    #[Test]
    public function it_returns_every_block_for_the_owner(): void
    {
        $owner = $this->ownerUser();
        $this->sale(['folio' => 'V-014']);
        // Yesterday's sale only feeds the comparison figure.
        $this->sale(['folio' => 'V-013', 'created_at' => now()->subDay()]);
        ServiceOrder::factory()->create([
            'branch_id' => $this->branch->id,
            'status' => ServiceOrderStatus::PENDING,
        ]);

        $response = $this->withToken($this->tokenFor($owner))->getJson('/api/v1/dashboard');

        $response->assertOk()
            ->assertJsonStructure([
                'generated_at',
                'sales' => ['today_total', 'today_count', 'average_ticket', 'yesterday_total', 'weekly_trend'],
                'layaways' => ['expiring_count'],
                'orders' => ['upcoming_deliveries_count'],
                'receivables' => ['total_customer_debt'],
                'inventory' => ['total_items', 'healthy_stock_count', 'low_stock_count', 'out_of_stock_count', 'total_cost', 'total_sale_value', 'low_stock_products'],
                'service_orders' => ['total', 'by_status'],
                'cash_register' => ['has_open_session', 'session'],
            ])
            ->assertJsonPath('sales.today_total', '270.00')
            ->assertJsonPath('sales.today_count', 1)
            ->assertJsonPath('sales.average_ticket', '270.00')
            ->assertJsonPath('sales.yesterday_total', '270.00')
            ->assertJsonCount(7, 'sales.weekly_trend')
            ->assertJsonPath('layaways.expiring_count', 0)
            ->assertJsonPath('orders.upcoming_deliveries_count', 0)
            ->assertJsonPath('receivables.total_customer_debt', '0.00')
            ->assertJsonPath('inventory.total_items', 0)
            ->assertJsonPath('inventory.healthy_stock_count', 0)
            ->assertJsonPath('inventory.total_cost', '0.00')
            ->assertJsonPath('inventory.low_stock_products', [])
            ->assertJsonPath('service_orders.total', 1)
            ->assertJsonPath('service_orders.by_status.pendiente', 1)
            ->assertJsonPath('service_orders.by_status.entregado', 0)
            ->assertJsonPath('cash_register.has_open_session', false)
            ->assertJsonPath('cash_register.session', null);

        // Dates travel in ISO-8601 UTC (same shape as the rest of the API).
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/',
            $response->json('generated_at')
        );
    }

    #[Test]
    public function it_hides_the_blocks_the_employee_may_not_see(): void
    {
        $employee = $this->employeeUser(['pos.access', 'dashboard.see_layaways']);
        $this->pendingSale(TransactionStatus::ON_LAYAWAY, now()->addDays(2)->toDateString());

        $this->withToken($this->tokenFor($employee))
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('sales', null)
            ->assertJsonPath('layaways.expiring_count', 1)
            ->assertJsonPath('orders', null)
            ->assertJsonPath('receivables', null)
            ->assertJsonPath('inventory', null)
            ->assertJsonPath('service_orders', null)
            ->assertJsonPath('cash_register.has_open_session', false);
    }

    #[Test]
    public function it_lists_the_layaways_that_expire_in_the_window(): void
    {
        $owner = $this->ownerUser();
        $layaway = $this->pendingSale(TransactionStatus::ON_LAYAWAY, now()->addDays(2)->toDateString(), 50, ['folio' => 'A-001']);
        // Outside the window asked by the app.
        $this->pendingSale(TransactionStatus::PENDING, now()->addDays(20)->toDateString(), 0, ['folio' => 'C-001']);
        // Same situation in another branch: never listed.
        Transaction::factory()->create([
            'branch_id' => Branch::factory()->create()->id,
            'status' => TransactionStatus::ON_LAYAWAY,
            'subtotal' => 100,
            'total_discount' => 0,
            'layaway_expiration_date' => now()->addDays(1)->toDateString(),
        ]);

        $this->withToken($this->tokenFor($owner))
            ->getJson('/api/v1/dashboard/expiring-layaways?days=5')
            ->assertOk()
            ->assertJsonPath('days', 5)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $layaway->id)
            ->assertJsonPath('data.0.folio', 'A-001')
            ->assertJsonPath('data.0.type', 'apartado')
            ->assertJsonPath('data.0.status', 'apartado')
            ->assertJsonPath('data.0.customer_id', $this->customer->id)
            ->assertJsonPath('data.0.customer_name', 'Ana Ramírez')
            ->assertJsonPath('data.0.customer_phone', '4771112233')
            ->assertJsonPath('data.0.total_amount', '200.00')
            ->assertJsonPath('data.0.total_paid', '50.00')
            ->assertJsonPath('data.0.pending_amount', '150.00')
            ->assertJsonPath('data.0.expiration_date', now()->addDays(2)->toDateString())
            ->assertJsonPath('data.0.days_remaining', 2)
            ->assertJsonPath('data.0.is_overdue', false);
    }

    #[Test]
    public function it_marks_the_layaways_whose_date_already_passed(): void
    {
        $owner = $this->ownerUser();
        $this->pendingSale(TransactionStatus::PENDING, now()->subDays(3)->toDateString(), 20, ['folio' => 'C-002']);

        $this->withToken($this->tokenFor($owner))
            ->getJson('/api/v1/dashboard/expiring-layaways')
            ->assertOk()
            ->assertJsonPath('days', 3)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'credito')
            ->assertJsonPath('data.0.status', 'pendiente')
            ->assertJsonPath('data.0.days_remaining', -3)
            ->assertJsonPath('data.0.is_overdue', true);
    }

    #[Test]
    public function it_lists_the_orders_waiting_to_be_delivered(): void
    {
        $owner = $this->ownerUser();
        $today = $this->order(now()->setTime(18, 0)->toDateTimeString(), 50, ['folio' => 'P-001']);
        $this->order(now()->addDay()->toDateTimeString(), 100, ['folio' => 'P-002']);
        // Outside the default window of three days.
        $this->order(now()->addDays(20)->toDateTimeString(), 0, ['folio' => 'P-003']);
        // Another branch: never listed.
        Transaction::factory()->create([
            'branch_id' => Branch::factory()->create()->id,
            'status' => TransactionStatus::TO_DELIVER,
            'subtotal' => 100,
            'total_discount' => 0,
            'delivery_date' => now()->addDay(),
        ]);

        $token = $this->tokenFor($owner);

        $response = $this->withToken($token)->getJson('/api/v1/dashboard/upcoming-deliveries');

        $response->assertOk()
            ->assertJsonPath('days', 3)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $today->id)
            ->assertJsonPath('data.0.folio', 'P-001')
            ->assertJsonPath('data.0.status', 'por_entregar')
            ->assertJsonPath('data.0.customer_name', 'Ana Ramírez')
            ->assertJsonPath('data.0.customer_phone', '4771112233')
            ->assertJsonPath('data.0.shipping_address', 'Av. Hidalgo 120, León')
            ->assertJsonPath('data.0.notes', 'Entregar después de las 6 pm')
            ->assertJsonPath('data.0.total_amount', '380.00')
            ->assertJsonPath('data.0.total_paid', '50.00')
            ->assertJsonPath('data.0.pending_amount', '330.00')
            ->assertJsonPath('data.0.days_remaining', 0)
            ->assertJsonPath('data.0.is_today', true)
            ->assertJsonPath('data.0.is_overdue', false)
            ->assertJsonPath('data.1.folio', 'P-002')
            ->assertJsonPath('data.1.days_remaining', 1)
            ->assertJsonPath('data.1.is_today', false)
            ->assertJsonPath('data.1.total_paid', '100.00')
            ->assertJsonPath('data.1.pending_amount', '280.00');

        // The delivery keeps its hour: ISO-8601 UTC, the app converts it to local.
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/',
            $response->json('data.0.delivery_date')
        );

        // The tile of the home screen counts the very same orders.
        $this->withToken($token)
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('orders.upcoming_deliveries_count', 2);
    }

    #[Test]
    public function it_requires_the_permission_of_each_alert_list(): void
    {
        $employee = $this->employeeUser(['pos.access']);

        $this->withToken($this->tokenFor($employee))
            ->getJson('/api/v1/dashboard/expiring-layaways')
            ->assertForbidden();

        $this->withToken($this->tokenFor($employee))
            ->getJson('/api/v1/dashboard/upcoming-deliveries')
            ->assertForbidden();
    }

    #[Test]
    public function it_validates_the_days_window(): void
    {
        $owner = $this->ownerUser();
        $token = $this->tokenFor($owner);

        $this->withToken($token)
            ->getJson('/api/v1/dashboard/expiring-layaways?days=0')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['days' => 'El número de días debe ser al menos 1.']);

        $this->withToken($token)
            ->getJson('/api/v1/dashboard/upcoming-deliveries?days=31')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['days' => 'El número de días no puede ser mayor a 30.']);

        $this->withToken($token)
            ->getJson('/api/v1/dashboard/expiring-layaways?days=abc')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['days' => 'El número de días debe ser un valor entero.']);
    }

    #[Test]
    public function it_reports_the_money_the_customers_owe(): void
    {
        $owner = $this->ownerUser();
        Customer::factory()->create(['branch_id' => $this->branch->id, 'balance' => -500]);
        // A balance in favour of the customer does not reduce the debt.
        Customer::factory()->create(['branch_id' => $this->branch->id, 'balance' => 120]);

        $this->withToken($this->tokenFor($owner))
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('receivables.total_customer_debt', '500.00');
    }
}
