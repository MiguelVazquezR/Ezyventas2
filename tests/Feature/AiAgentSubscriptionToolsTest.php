<?php

namespace Tests\Feature;

use App\AiTools\Registrars\SubscriptionTools;
use App\Models\Branch;
use App\Models\PlanItem;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\SubscriptionItem;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionVersion;
use App\Models\User;
use App\Services\SubscriptionStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AiAgentSubscriptionToolsTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Subscription $subscription;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->branch = Branch::factory()->create();
        $this->subscription = $this->branch->subscription;
        $this->owner = User::factory()->create(['branch_id' => $this->branch->id]);

        $this->createPlanItems();
    }

    #[Test]
    public function snapshot_reports_plan_expiration_and_renewal_amount_for_a_monthly_plan(): void
    {
        $version = $this->createVersion(endDate: now());

        SubscriptionItem::create($this->itemAttributes($version, 'module_pos', 'Punto de Venta', 130.0, 1));
        SubscriptionItem::create($this->itemAttributes($version, 'limit_products', 'Productos', 1.5, 200));

        $snapshot = app(SubscriptionStatusService::class)->snapshot($this->subscription);

        // Expiration: the subscription is still valid on its end date.
        $this->assertSame('activo', $snapshot['status']);
        $this->assertTrue($snapshot['expiration']['expires_today']);
        $this->assertFalse($snapshot['expiration']['is_expired']);
        $this->assertSame(0, $snapshot['expiration']['days_remaining']);
        $this->assertStringContainsString('vence hoy', $snapshot['expiration']['message']);

        // Plan: monthly cost normalizes package sizes (1.5 per 100-unit package x 200 units = 3.0).
        $this->assertSame('mensual', $snapshot['plan']['billing_period']);
        $this->assertSame(133.0, $snapshot['plan']['monthly_cost']);
        $this->assertContains('Punto de Venta', $snapshot['plan']['modules']);
        $this->assertCount(2, $snapshot['plan']['items']);

        $productLimit = collect($snapshot['plan']['items'])->firstWhere('key', 'limit_products');
        $this->assertSame(100, $productLimit['package_size']);
        $this->assertSame(0.015, $productLimit['price_per_unit']);
        $this->assertSame(3.0, $productLimit['subtotal']);

        // Renewal estimate: current prices, no discounts.
        $this->assertSame(133.0, $snapshot['renewal_estimate']['subtotal']);
        $this->assertSame(0.0, $snapshot['renewal_estimate']['referrer_discount_pct']);
        $this->assertSame(0.0, $snapshot['renewal_estimate']['referral_code_discount_pct']);
        $this->assertSame(133.0, $snapshot['renewal_estimate']['total']);
    }

    #[Test]
    public function snapshot_normalizes_monthly_cost_for_annual_billing(): void
    {
        $version = $this->createVersion(endDate: now()->addDays(300));

        SubscriptionItem::create($this->itemAttributes($version, 'module_pos', 'Punto de Venta', 1300.0, 1, 'anual'));

        $snapshot = app(SubscriptionStatusService::class)->snapshot($this->subscription);

        $this->assertSame('anual', $snapshot['plan']['billing_period']);
        $this->assertSame(108.33, $snapshot['plan']['monthly_cost']);
        $this->assertSame(1300.0, $snapshot['renewal_estimate']['subtotal']);
        $this->assertFalse($snapshot['expiration']['expires_today']);
        $this->assertStringContainsString('vigente hasta', $snapshot['expiration']['message']);
    }

    #[Test]
    public function snapshot_includes_the_pending_payment_as_the_amount_due(): void
    {
        $version = $this->createVersion(endDate: now());

        SubscriptionPayment::create([
            'subscription_version_id' => $version->id,
            'amount' => 133.50,
            'payment_method' => 'transferencia',
            'invoiced' => false,
            'invoice_status' => 'no_solicitada',
            'status' => 'pending',
        ]);

        $snapshot = app(SubscriptionStatusService::class)->snapshot($this->subscription);

        $this->assertNotNull($snapshot['pending_payment']);
        $this->assertSame(133.5, $snapshot['pending_payment']['amount']);
        $this->assertSame('pending', $snapshot['pending_payment']['status']);
        $this->assertSame('transferencia', $snapshot['pending_payment']['payment_method']);
        $this->assertNull($snapshot['last_rejected_payment']);
    }

    #[Test]
    public function snapshot_orders_payment_history_newest_first_and_flags_rejections(): void
    {
        $version = $this->createVersion(endDate: now());

        $older = SubscriptionPayment::create([
            'subscription_version_id' => $version->id,
            'amount' => 100.00,
            'payment_method' => 'transferencia',
            'invoiced' => true,
            'invoice_status' => 'generada',
            'status' => 'approved',
        ]);

        $recent = SubscriptionPayment::create([
            'subscription_version_id' => $version->id,
            'amount' => 200.00,
            'payment_method' => 'mercadopago',
            'invoiced' => false,
            'invoice_status' => 'no_solicitada',
            'status' => 'rejected',
            'payment_details' => ['rejection_reason' => 'Comprobante ilegible'],
        ]);

        $snapshot = app(SubscriptionStatusService::class)->snapshot($this->subscription);
        $history = $snapshot['payment_history'];

        $this->assertCount(2, $history);
        $this->assertSame($recent->id, $history[0]['id']);
        $this->assertSame($older->id, $history[1]['id']);
        $this->assertSame('rejected', $history[0]['status']);
        $this->assertSame('Comprobante ilegible', $history[0]['rejection_reason']);
        $this->assertSame('generada', $history[1]['invoice_status']);

        $this->assertNotNull($snapshot['last_rejected_payment']);
        $this->assertSame(200.0, $snapshot['last_rejected_payment']['amount']);
    }

    #[Test]
    public function registrar_hides_subscription_billing_from_users_with_roles(): void
    {
        $role = Role::create(['name' => 'Vendedor', 'branch_id' => $this->branch->id]);
        $employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $employee->assignRole($role);

        $this->assertSame([], (new SubscriptionTools())->definitions($employee));
    }

    #[Test]
    public function registrar_exposes_the_subscription_status_tool_for_owners(): void
    {
        if (! class_exists('Prism\\Prism\\Tool')) {
            $this->markTestSkipped('prism-php/prism is not installed in this environment.');
        }

        $definitions = (new SubscriptionTools())->definitions($this->owner);

        $this->assertCount(1, $definitions);
        $this->assertSame('subscription and billing', $definitions[0]['category']);
        $this->assertSame('subscription_status', $definitions[0]['tool']->name());

        $payload = json_decode($definitions[0]['tool']->handle(), true);

        $this->assertSame($this->subscription->id, $payload['subscription_id']);
        $this->assertArrayHasKey('plan', $payload);
        $this->assertArrayHasKey('expiration', $payload);
        $this->assertArrayHasKey('renewal_estimate', $payload);
        $this->assertArrayHasKey('payment_history', $payload);
    }

    private function createPlanItems(): void
    {
        PlanItem::create([
            'key' => 'module_pos',
            'type' => 'module',
            'name' => 'Punto de Venta',
            'description' => 'Módulo principal',
            'monthly_price' => 130.0,
            'is_active' => true,
            'meta' => ['icon' => 'pi pi-shop'],
        ]);

        PlanItem::create([
            'key' => 'limit_products',
            'type' => 'limit',
            'name' => 'Productos',
            'description' => 'Límite de productos',
            'monthly_price' => 1.5,
            'is_active' => true,
            'meta' => ['quantity' => 100],
        ]);
    }

    private function createVersion(\DateTimeInterface $endDate): SubscriptionVersion
    {
        return SubscriptionVersion::create([
            'subscription_id' => $this->subscription->id,
            'start_date' => now()->subMonth(),
            'end_date' => $endDate,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function itemAttributes(
        SubscriptionVersion $version,
        string $key,
        string $name,
        float $unitPrice,
        int $quantity,
        string $billingPeriod = 'mensual',
    ): array {
        return [
            'subscription_version_id' => $version->id,
            'item_key' => $key,
            'item_type' => str_starts_with($key, 'module_') ? 'module' : 'limit',
            'name' => $name,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'billing_period' => $billingPeriod,
        ];
    }
}
