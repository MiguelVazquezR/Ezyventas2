<?php

namespace Tests\Feature\Api\V1\Concerns;

use App\Enums\CashRegisterSessionStatus;
use App\Models\BankAccount;
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Fixtures of the POS write endpoints: an open shift, a customer, a bank
 * account and a product with stock in the pivot of the branch.
 */
trait BuildsPosApiContext
{
    protected CashRegister $cashRegister;

    protected CashRegisterSession $cashRegisterSession;

    protected Customer $customer;

    protected BankAccount $bankAccount;

    protected Product $product;

    protected function setUpPosApiContext(User $opener, float $creditLimit = 1000, float $stock = 20): void
    {
        $this->cashRegister = CashRegister::factory()->create([
            'branch_id' => $this->branch->id,
            'name' => 'Caja 1',
            'is_active' => true,
            'in_use' => true,
        ]);

        $this->cashRegisterSession = CashRegisterSession::factory()->create([
            'cash_register_id' => $this->cashRegister->id,
            'user_id' => $opener->id,
            'status' => CashRegisterSessionStatus::OPEN,
            'opened_at' => now()->subHour(),
            'closed_at' => null,
            'opening_cash_balance' => 1000,
        ]);
        $this->cashRegisterSession->users()->attach($opener->id);

        $this->customer = Customer::factory()->create([
            'branch_id' => $this->branch->id,
            'name' => 'Ana Ramírez',
            'phone' => '4771112233',
            'balance' => 0,
            'credit_limit' => $creditLimit,
        ]);

        $this->bankAccount = BankAccount::factory()->create([
            'subscription_id' => $this->subscription->id,
            'bank_name' => 'BBVA',
            'account_name' => 'Cuenta principal',
            'balance' => 5000,
        ]);
        $this->bankAccount->branches()->attach($this->branch->id);

        $this->product = Product::factory()->create([
            'branch_id' => $this->branch->id,
            'name' => 'Filtro de aceite',
            'selling_price' => 150,
        ]);
        $this->product->branches()->attach($this->branch->id, [
            'current_stock' => $stock,
            'reserved_stock' => 0,
        ]);
    }

    /**
     * Line of the cart as the app sends it.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function cartItem(array $overrides = []): array
    {
        return array_merge([
            'id' => $this->product->id,
            'product_attribute_id' => null,
            'quantity' => 2,
            'unit_price' => 150,
            'description' => $this->product->name,
            'discount' => 0,
            'discount_reason' => null,
        ], $overrides);
    }

    /**
     * Stock row of the product in the branch (current + reserved).
     */
    protected function productStock(): object
    {
        return DB::table('branch_product')
            ->where('branch_id', $this->branch->id)
            ->where('product_id', $this->product->id)
            ->first();
    }
}
