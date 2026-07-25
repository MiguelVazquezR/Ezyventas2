<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\Subscription;
use App\Models\SubscriptionVersion;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use App\Services\CashRegisterReportService;
use App\Services\CustomerReportService;
use App\Services\ExpenseReportService;
use App\Services\FinancialReportService;
use App\Services\InventoryReportService;
use App\Services\PromotionReportService;
use App\Services\QuoteInvoiceReportService;
use App\Services\SalesDashboardService;
use App\Services\ServiceOrderReportService;
use App\Services\StaffPerformanceService;
use App\Services\TransactionQueryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use App\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AiAgentToolsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Branch $branch;
    private Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->branch = Branch::factory()->create();
        $this->subscription = $this->branch->subscription;
        $this->subscription->update(['onboarding_completed_at' => now()]);

        SubscriptionVersion::create([
            'subscription_id' => $this->subscription->id,
            'start_date'       => Carbon::yesterday(),
            'end_date'         => Carbon::tomorrow()->addYear(),
        ]);

        $this->user = User::factory()->create(['branch_id' => $this->branch->id]);

        // Create all needed permissions
        $allPermissions = [
            'financial_reports.access',
            'products.access',
            'products.manage_promos',
            'transactions.access',
            'customers.access',
            'customers.see_financial_info',
            'dashboard.see_sales',
            'dashboard.see_inventory_details',
            'cash_registers.sessions.access',
            'services.orders.access',
            'quotes.access',
            'invoices.access',
            'expenses.access',
        ];

        foreach ($allPermissions as $p) {
            Permission::create(['name' => $p, 'module' => 'test']);
        }

        $role = Role::create(['name' => 'Administrador', 'branch_id' => $this->branch->id]);
        $role->givePermissionTo($allPermissions);
        $this->user->assignRole($role);
    }

    // ══════════════════════════════════════════════════════════
    // FIX 1: promotion_usage_stats tenant isolation
    // ══════════════════════════════════════════════════════════

    #[Test]
    public function promotion_usage_stats_rejects_promotion_from_other_subscription(): void
    {
        // Create a promotion in another subscription
        $otherSub = Subscription::factory()->create();
        $otherBranch = Branch::factory()->create(['subscription_id' => $otherSub->id]);
        $otherPromo = Promotion::factory()->create([
            'subscription_id' => $otherSub->id,
            'is_active'       => true,
        ]);

        $service = new PromotionReportService();

        // Should throw ModelNotFoundException because the promotion
        // is scoped to subscription_id before findOrFail
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        $service->getUsageStats(
            $otherPromo->id,
            Carbon::now()->subMonth(),
            Carbon::now(),
            $this->branch->id, // requesting user's branch
        );
    }

    #[Test]
    public function promotion_usage_stats_returns_data_for_own_subscription_promotion(): void
    {
        $promo = Promotion::factory()->create([
            'subscription_id' => $this->subscription->id,
            'is_active'       => true,
        ]);

        $service = new PromotionReportService();

        $result = $service->getUsageStats(
            $promo->id,
            Carbon::now()->subMonth(),
            Carbon::now(),
            $this->branch->id,
        );

        $this->assertEquals($promo->id, $result['promotion_id']);
        $this->assertEquals(0, $result['times_applied']);
    }

    // ══════════════════════════════════════════════════════════
    // FIX 2: financial_report includes hourly sales
    // ══════════════════════════════════════════════════════════

    #[Test]
    public function financial_report_includes_hourly_sales_for_single_day_range(): void
    {
        $service = new FinancialReportService(
            $this->branch->id,
            Carbon::today(),
            Carbon::today(),
        );

        $data = $service->generateReportData();

        $this->assertArrayHasKey('hourlySales', $data);
        $this->assertArrayHasKey('chartData', $data);
    }

    #[Test]
    public function financial_report_omits_hourly_sales_for_multi_day_range(): void
    {
        $service = new FinancialReportService(
            $this->branch->id,
            Carbon::now()->subDays(3),
            Carbon::now(),
        );

        $data = $service->generateReportData();

        $this->assertArrayHasKey('hourlySales', $data);
        $this->assertEmpty($data['hourlySales']);
    }

    // ══════════════════════════════════════════════════════════
    // NEW TOOL 1: sales_by_product
    // ══════════════════════════════════════════════════════════

    #[Test]
    public function sales_by_product_groups_by_product_and_is_scoped_to_branch(): void
    {
        $product = Product::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Test Product', 'sku' => 'TST-001']);
        $tx = Transaction::factory()->create([
            'branch_id' => $this->branch->id,
            'status'    => 'completado',
            'created_at'=> Carbon::now()->subDay(),
        ]);
        TransactionItem::factory()->create([
            'transaction_id' => $tx->id,
            'itemable_id'    => $product->id,
            'itemable_type'  => Product::class,
            'quantity'       => 2,
            'unit_price'     => 100,
            'line_total'     => 200,
        ]);

        $service = new InventoryReportService();
        $result = $service->salesByProduct(
            $this->branch->id,
            Carbon::now()->subDays(2),
            Carbon::now(),
            'product',
            10,
        );

        $this->assertEquals('product', $result['group_by']);
        $this->assertCount(1, $result['rows']);
        $this->assertEquals('Test Product', $result['rows'][0]['name']);
        $this->assertEquals(200.0, $result['rows'][0]['total_revenue']);
    }

    #[Test]
    public function sales_by_product_excludes_other_branch_data(): void
    {
        $otherBranch = Branch::factory()->create(['subscription_id' => $this->subscription->id]);
        $product = Product::factory()->create(['branch_id' => $otherBranch->id, 'name' => 'Other Product']);
        $tx = Transaction::factory()->create([
            'branch_id' => $otherBranch->id,
            'status'    => 'completado',
            'created_at'=> Carbon::now()->subDay(),
        ]);
        TransactionItem::factory()->create([
            'transaction_id' => $tx->id,
            'itemable_id'    => $product->id,
            'itemable_type'  => Product::class,
            'quantity'       => 1,
            'unit_price'     => 50,
            'line_total'     => 50,
        ]);

        $service = new InventoryReportService();
        $result = $service->salesByProduct(
            $this->branch->id,
            Carbon::now()->subDays(2),
            Carbon::now(),
            'product',
            10,
        );

        $this->assertEmpty($result['rows']);
    }

    #[Test]
    public function sales_by_product_groups_by_category(): void
    {
        $category = Category::factory()->create([
            'type'            => 'product',
            'subscription_id' => $this->subscription->id,
            'name'            => 'Electronics',
        ]);
        $product = Product::factory()->create([
            'branch_id'   => $this->branch->id,
            'category_id' => $category->id,
            'name'        => 'Gadget',
        ]);
        $tx = Transaction::factory()->create([
            'branch_id' => $this->branch->id,
            'status'    => 'completado',
            'created_at'=> Carbon::now()->subDay(),
        ]);
        TransactionItem::factory()->create([
            'transaction_id' => $tx->id,
            'itemable_id'    => $product->id,
            'itemable_type'  => Product::class,
            'quantity'       => 1,
            'unit_price'     => 100,
            'line_total'     => 100,
        ]);

        $service = new InventoryReportService();
        $result = $service->salesByProduct(
            $this->branch->id,
            Carbon::now()->subDays(2),
            Carbon::now(),
            'category',
            10,
        );

        $this->assertEquals('category', $result['group_by']);
        $this->assertCount(1, $result['rows']);
        $this->assertEquals('Electronics', $result['rows'][0]['name']);
    }

    // ══════════════════════════════════════════════════════════
    // NEW TOOL 2: product_margin_report
    // ══════════════════════════════════════════════════════════

    #[Test]
    public function product_margin_report_computes_margin_correctly(): void
    {
        $product = Product::factory()->create([
            'branch_id'    => $this->branch->id,
            'name'         => 'Widget',
            'selling_price'=> 200,
            'cost_price'   => 120,
        ]);
        $tx = Transaction::factory()->create([
            'branch_id' => $this->branch->id,
            'status'    => 'completado',
            'created_at'=> Carbon::now()->subDay(),
        ]);
        TransactionItem::factory()->create([
            'transaction_id' => $tx->id,
            'itemable_id'    => $product->id,
            'itemable_type'  => Product::class,
            'quantity'       => 3,
            'unit_price'     => 200,
            'line_total'     => 600,
        ]);

        $service = new InventoryReportService();
        $result = $service->productMarginReport(
            $this->branch->id,
            Carbon::now()->subDays(2),
            Carbon::now(),
            10,
            'margin_amount',
        );

        $this->assertCount(1, $result['rows']);
        $this->assertEquals(600.0, $result['rows'][0]['total_revenue']);
        $this->assertEquals(360.0, $result['rows'][0]['total_cost']);   // 3 * 120
        $this->assertEquals(240.0, $result['rows'][0]['margin_amount']); // 600 - 360
        $this->assertEquals(40.0, $result['rows'][0]['margin_percent']); // 240/600 * 100
    }

    #[Test]
    public function product_margin_report_flags_products_without_cost(): void
    {
        $product = Product::factory()->create([
            'branch_id'    => $this->branch->id,
            'name'         => 'No Cost Item',
            'selling_price'=> 100,
            'cost_price'   => null,
        ]);
        $tx = Transaction::factory()->create([
            'branch_id' => $this->branch->id,
            'status'    => 'completado',
            'created_at'=> Carbon::now()->subDay(),
        ]);
        TransactionItem::factory()->create([
            'transaction_id' => $tx->id,
            'itemable_id'    => $product->id,
            'itemable_type'  => Product::class,
            'quantity'       => 1,
            'unit_price'     => 100,
            'line_total'     => 100,
        ]);

        $service = new InventoryReportService();
        $result = $service->productMarginReport(
            $this->branch->id,
            Carbon::now()->subDays(2),
            Carbon::now(),
        );

        $this->assertTrue($result['rows'][0]['cost_not_set']);
        $this->assertNull($result['rows'][0]['margin_amount']);
        $this->assertEquals(1, $result['summary']['products_without_cost']);
    }

    #[Test]
    public function product_margin_report_is_scoped_to_branch(): void
    {
        $otherBranch = Branch::factory()->create(['subscription_id' => $this->subscription->id]);
        $product = Product::factory()->create([
            'branch_id'    => $otherBranch->id,
            'name'         => 'Other',
            'cost_price'   => 10,
            'selling_price'=> 50,
        ]);
        $tx = Transaction::factory()->create([
            'branch_id' => $otherBranch->id,
            'status'    => 'completado',
            'created_at'=> Carbon::now()->subDay(),
        ]);
        TransactionItem::factory()->create([
            'transaction_id' => $tx->id,
            'itemable_id'    => $product->id,
            'itemable_type'  => Product::class,
            'quantity'       => 1,
            'unit_price'     => 50,
            'line_total'     => 50,
        ]);

        $service = new InventoryReportService();
        $result = $service->productMarginReport(
            $this->branch->id,
            Carbon::now()->subDays(2),
            Carbon::now(),
        );

        $this->assertEmpty($result['rows']);
    }

    // ══════════════════════════════════════════════════════════
    // NEW TOOL 3: customer_recency
    // ══════════════════════════════════════════════════════════

    #[Test]
    public function customer_recency_finds_inactive_customers(): void
    {
        $active = Customer::factory()->create([
            'branch_id' => $this->branch->id,
            'name'      => 'Active Customer',
        ]);
        $inactive = Customer::factory()->create([
            'branch_id' => $this->branch->id,
            'name'      => 'Inactive Customer',
        ]);

        // Active customer has a recent transaction
        Transaction::factory()->create([
            'branch_id'   => $this->branch->id,
            'customer_id' => $active->id,
            'status'      => 'completado',
            'created_at'  => Carbon::now()->subDays(5),
        ]);

        // Inactive customer has an old transaction
        Transaction::factory()->create([
            'branch_id'   => $this->branch->id,
            'customer_id' => $inactive->id,
            'status'      => 'completado',
            'created_at'  => Carbon::now()->subDays(120),
        ]);

        $service = new CustomerReportService();
        $result = $service->getCustomerRecency($this->branch->id, 30, 'inactive', 20);

        $this->assertGreaterThanOrEqual(1, $result['count']);
        $names = array_column($result['rows'], 'name');
        $this->assertContains('Inactive Customer', $names);
        $this->assertNotContains('Active Customer', $names);
    }

    #[Test]
    public function customer_recency_finds_recent_customers(): void
    {
        $recent = Customer::factory()->create([
            'branch_id' => $this->branch->id,
            'name'      => 'Recent Customer',
        ]);
        Transaction::factory()->create([
            'branch_id'   => $this->branch->id,
            'customer_id' => $recent->id,
            'status'      => 'completado',
            'created_at'  => Carbon::now()->subDays(3),
        ]);

        $service = new CustomerReportService();
        $result = $service->getCustomerRecency($this->branch->id, 7, 'recent', 20);

        $this->assertGreaterThanOrEqual(1, $result['count']);
        $this->assertEquals('Recent Customer', $result['rows'][0]['name']);
    }

    #[Test]
    public function customer_recency_includes_never_purchased_in_inactive(): void
    {
        $neverBought = Customer::factory()->create([
            'branch_id' => $this->branch->id,
            'name'      => 'Never Bought',
        ]);

        $service = new CustomerReportService();
        $result = $service->getCustomerRecency($this->branch->id, 30, 'inactive', 20);

        $names = array_column($result['rows'], 'name');
        $this->assertContains('Never Bought', $names);
    }

    #[Test]
    public function customer_recency_is_scoped_to_branch(): void
    {
        $otherBranch = Branch::factory()->create(['subscription_id' => $this->subscription->id]);
        $otherCustomer = Customer::factory()->create([
            'branch_id' => $otherBranch->id,
            'name'      => 'Other Branch Customer',
        ]);
        Transaction::factory()->create([
            'branch_id'   => $otherBranch->id,
            'customer_id' => $otherCustomer->id,
            'status'      => 'completado',
            'created_at'  => Carbon::now()->subDays(100),
        ]);

        $service = new CustomerReportService();
        $result = $service->getCustomerRecency($this->branch->id, 30, 'inactive', 20);

        $names = array_column($result['rows'], 'name');
        $this->assertNotContains('Other Branch Customer', $names);
    }

    // ══════════════════════════════════════════════════════════
    // NEW TOOL 4: monthly_revenue_trend
    // ══════════════════════════════════════════════════════════

    #[Test]
    public function monthly_revenue_trend_returns_monthly_data_with_growth_rates(): void
    {
        // Create transactions across 3 months
        Transaction::factory()->create([
            'branch_id' => $this->branch->id,
            'status'    => 'completado',
            'subtotal'  => 1000,
            'total_discount' => 0,
            'total_tax' => 0,
            'created_at'=> Carbon::now()->subMonths(2)->startOfMonth(),
        ]);
        Transaction::factory()->create([
            'branch_id' => $this->branch->id,
            'status'    => 'completado',
            'subtotal'  => 2000,
            'total_discount' => 0,
            'total_tax' => 0,
            'created_at'=> Carbon::now()->subMonth()->startOfMonth(),
        ]);

        $service = new FinancialReportService($this->branch->id, Carbon::now(), Carbon::now());
        $result = $service->monthlyRevenueTrend($this->branch->id, 3);

        $this->assertCount(3, $result);
        // Each entry should have month, total, transaction_count, mom_growth_pct
        $this->assertArrayHasKey('month', $result[0]);
        $this->assertArrayHasKey('total', $result[0]);
        $this->assertArrayHasKey('transaction_count', $result[0]);
        $this->assertArrayHasKey('mom_growth_pct', $result[0]);
    }

    #[Test]
    public function monthly_revenue_trend_is_scoped_to_branch(): void
    {
        $otherBranch = Branch::factory()->create(['subscription_id' => $this->subscription->id]);
        Transaction::factory()->create([
            'branch_id' => $otherBranch->id,
            'status'    => 'completado',
            'subtotal'  => 9999,
            'total_discount' => 0,
            'total_tax' => 0,
            'created_at'=> Carbon::now()->subMonth()->startOfMonth(),
        ]);

        $service = new FinancialReportService($this->branch->id, Carbon::now(), Carbon::now());
        $result = $service->monthlyRevenueTrend($this->branch->id, 2);

        // Our branch should have zero revenue; the other branch's data should not leak
        $this->assertEquals(0, $result[0]['total']);
        $this->assertEquals(0, $result[1]['total']);
    }

    // ══════════════════════════════════════════════════════════
    // NEW TOOL 5: invoice_aging
    // ══════════════════════════════════════════════════════════

    #[Test]
    public function invoice_aging_buckets_certified_invoices_by_age(): void
    {
        Invoice::factory()->create([
            'branch_id' => $this->branch->id,
            'status'    => InvoiceStatus::CERTIFIED,
            'issued_at' => Carbon::now()->subDays(15),
            'total'     => 500,
        ]);
        Invoice::factory()->create([
            'branch_id' => $this->branch->id,
            'status'    => InvoiceStatus::CERTIFIED,
            'issued_at' => Carbon::now()->subDays(45),
            'total'     => 1000,
        ]);
        Invoice::factory()->create([
            'branch_id' => $this->branch->id,
            'status'    => InvoiceStatus::CERTIFIED,
            'issued_at' => Carbon::now()->subDays(75),
            'total'     => 2000,
        ]);
        Invoice::factory()->create([
            'branch_id' => $this->branch->id,
            'status'    => InvoiceStatus::CERTIFIED,
            'issued_at' => Carbon::now()->subDays(120),
            'total'     => 3000,
        ]);

        $service = new QuoteInvoiceReportService();
        $result = $service->getInvoiceAging($this->branch->id);

        $this->assertEquals(1, $result['buckets']['0-30']['count']);
        $this->assertEquals(1, $result['buckets']['31-60']['count']);
        $this->assertEquals(1, $result['buckets']['61-90']['count']);
        $this->assertEquals(1, $result['buckets']['90+']['count']);
        $this->assertEquals(4, $result['total_outstanding']['count']);
        $this->assertEquals(6500.0, $result['total_outstanding']['total']);
    }

    #[Test]
    public function invoice_aging_excludes_non_certified_invoices(): void
    {
        Invoice::factory()->create([
            'branch_id' => $this->branch->id,
            'status'    => InvoiceStatus::DRAFT,
            'issued_at' => Carbon::now()->subDays(10),
            'total'     => 500,
        ]);
        Invoice::factory()->create([
            'branch_id' => $this->branch->id,
            'status'    => InvoiceStatus::CANCELED,
            'issued_at' => Carbon::now()->subDays(30),
            'total'     => 1000,
        ]);
        Invoice::factory()->create([
            'branch_id' => $this->branch->id,
            'status'    => InvoiceStatus::CERTIFIED,
            'issued_at' => Carbon::now()->subDays(5),
            'total'     => 2000,
        ]);

        $service = new QuoteInvoiceReportService();
        $result = $service->getInvoiceAging($this->branch->id);

        $this->assertEquals(1, $result['total_outstanding']['count']);
        $this->assertEquals(2000.0, $result['total_outstanding']['total']);
    }

    #[Test]
    public function invoice_aging_is_scoped_to_branch(): void
    {
        $otherBranch = Branch::factory()->create(['subscription_id' => $this->subscription->id]);
        Invoice::factory()->create([
            'branch_id' => $otherBranch->id,
            'status'    => InvoiceStatus::CERTIFIED,
            'issued_at' => Carbon::now()->subDays(10),
            'total'     => 5000,
        ]);

        $service = new QuoteInvoiceReportService();
        $result = $service->getInvoiceAging($this->branch->id);

        $this->assertEquals(0, $result['total_outstanding']['count']);
        $this->assertEquals(0.0, $result['total_outstanding']['total']);
    }
}
