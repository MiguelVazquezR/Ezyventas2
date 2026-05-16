<?php

namespace App\Actions\ServiceOrders;

use App\Enums\CustomerBalanceMovementType;
use App\Enums\ServiceOrderStatus;
use App\Enums\TransactionChannel;
use App\Enums\TransactionStatus;
use App\Models\Customer;
use App\Models\ServiceOrder;
use App\Models\Transaction;
use App\Models\User;
use App\Traits\OptimizeMediaLocal;
use Illuminate\Support\Facades\DB;

class CreateServiceOrderAction
{
    use OptimizeMediaLocal;

    public function execute(array $data, User $user, ?array $evidenceImages = []): ServiceOrder
    {
        return DB::transaction(function () use ($data, $user, $evidenceImages) {
            $customer = $this->resolveCustomer($data, $user);

            $serviceOrder = ServiceOrder::create(array_merge($data, [
                'folio' => ServiceOrder::generateFolio($user->branch_id),
                'user_id' => $user->id,
                'branch_id' => $user->branch_id,
                'received_at' => now(),
                'customer_id' => $customer?->id,
                'status' => ServiceOrderStatus::PENDING,
            ]));

            $transaction = $serviceOrder->transaction()->create([
                'folio' => $this->generateTransactionFolio($user->branch_id),
                'customer_id' => $customer?->id,
                'branch_id' => $user->branch_id,
                'user_id' => $user->id,
                'cash_register_session_id' => $data['cash_register_session_id'],
                'subtotal' => $serviceOrder->subtotal,
                'total_discount' => $serviceOrder->discount_amount,
                'total_tax' => 0,
                'channel' => TransactionChannel::SERVICE_ORDER,
                'status' => $serviceOrder->final_total > 0 ? TransactionStatus::PENDING : TransactionStatus::COMPLETED,
            ]);

            if ($customer && $serviceOrder->final_total > 0) {
                $customer->addDebt(
                    amount: $serviceOrder->final_total,
                    debtType: CustomerBalanceMovementType::CREDIT_SALE,
                    transactionId: $transaction->id,
                    notes: "Cargo por Orden de Servicio #{$serviceOrder->folio}"
                );
            }

            $serviceOrder->addItemsWithStock($data['items'] ?? [], $user);

            if (!empty($evidenceImages)) {
                foreach ($evidenceImages as $file) {
                    $this->optimizeMediaLocal($serviceOrder->addMedia($file)->toMediaCollection('initial-service-order-evidence'));
                }
            }

            return $serviceOrder;
        });
    }

    private function resolveCustomer(array $data, User $user): ?Customer
    {
        if (!empty($data['customer_id'])) {
            return Customer::find($data['customer_id']);
        }

        if ($data['create_customer'] ?? false) {
            return Customer::create([
                'branch_id' => $user->branch_id,
                'name' => $data['customer_name'],
                'phone' => $data['customer_phone'] ?? null,
                'email' => $data['customer_email'] ?? null,
                'address' => $data['customer_address'] ?? null,
                'credit_limit' => $data['credit_limit'] ?? 0,
                'balance' => 0,
            ]);
        }

        return null;
    }

    private function generateTransactionFolio(int $branchId): string
    {
        $lastTransaction = Transaction::where('branch_id', $branchId)
            ->where('folio', 'like', 'OS-V-%')
            ->orderByRaw('CAST(SUBSTRING(folio, 6) AS UNSIGNED) DESC')
            ->first();

        $sequence = $lastTransaction ? ((int) substr($lastTransaction->folio, 5)) + 1 : 1;
        return 'OS-V-' . str_pad($sequence, 3, '0', STR_PAD_LEFT);
    }
}