<?php

namespace Tests\Feature;

use App\Enums\ExpenseStatus;
use App\Enums\PaymentMethod;
use App\Models\Branch;
use App\Models\Expense;
use App\Models\User;
use App\Services\FinancialReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FinancialReportServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();
        $this->user = User::factory()->create(['branch_id' => $this->branch->id]);
    }

    #[Test]
    public function expenses_by_method_totals_are_not_truncated_by_the_detail_limit(): void
    {
        $today = Carbon::today();
        $rows = [];

        // Seed more expenses than the 1000-row detail limit so a truncated
        // list would produce lower totals than the KPIs.
        for ($i = 0; $i < 1005; $i++) {
            $rows[] = [
                'folio' => 'G-INT-' . $i,
                'user_id' => $this->user->id,
                'branch_id' => $this->branch->id,
                'expense_category_id' => null,
                'amount' => 100.00,
                'expense_date' => $today->format('Y-m-d') . ' 12:00:00',
                'status' => ExpenseStatus::PAID->value,
                'description' => 'Internal expense ' . $i,
                'payment_method' => PaymentMethod::CASH->value,
                'bank_account_id' => null,
                'session_cash_movement_id' => null,
                'is_external' => false,
                'created_at' => $today->format('Y-m-d H:i:s'),
                'updated_at' => $today->format('Y-m-d H:i:s'),
            ];
        }

        for ($i = 0; $i < 5; $i++) {
            $rows[] = [
                'folio' => 'G-EXT-' . $i,
                'user_id' => $this->user->id,
                'branch_id' => $this->branch->id,
                'expense_category_id' => null,
                'amount' => 10.00,
                'expense_date' => $today->format('Y-m-d') . ' 12:00:00',
                'status' => ExpenseStatus::PAID->value,
                'description' => 'External expense ' . $i,
                'payment_method' => PaymentMethod::TRANSFER->value,
                'bank_account_id' => null,
                'session_cash_movement_id' => null,
                'is_external' => true,
                'created_at' => $today->format('Y-m-d H:i:s'),
                'updated_at' => $today->format('Y-m-d H:i:s'),
            ];
        }

        DB::table('expenses')->insert($rows);

        $service = new FinancialReportService(
            $this->branch->id,
            $today->copy()->startOfDay(),
            $today->copy()->endOfDay(),
        );

        $aggregates = $service->getExpensesByOriginAndMethod();

        $internalTotal = array_sum(array_column($aggregates['internal'], 'total'));
        $externalTotal = array_sum(array_column($aggregates['external'], 'total'));

        // 1005 internal x $100.00 and 5 external x $10.00
        $this->assertEqualsWithDelta(100500.00, $internalTotal, 0.01);
        $this->assertEqualsWithDelta(50.00, $externalTotal, 0.01);

        // Aggregates must match the same conditions used by the KPIs
        // (paid, same branch, same date range, no row limit).
        $expectedInternal = Expense::where('branch_id', $this->branch->id)
            ->where('status', ExpenseStatus::PAID)
            ->where('is_external', false)
            ->whereBetween('expense_date', [$today->copy()->startOfDay(), $today->copy()->endOfDay()])
            ->sum('amount');

        $expectedExternal = Expense::where('branch_id', $this->branch->id)
            ->where('status', ExpenseStatus::PAID)
            ->where('is_external', true)
            ->whereBetween('expense_date', [$today->copy()->startOfDay(), $today->copy()->endOfDay()])
            ->sum('amount');

        $this->assertEqualsWithDelta((float) $expectedInternal, $internalTotal, 0.01);
        $this->assertEqualsWithDelta((float) $expectedExternal, $externalTotal, 0.01);
    }
}