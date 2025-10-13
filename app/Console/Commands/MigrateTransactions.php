<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\ServiceOrder;
use App\Models\Product;
use App\Models\Payment;
use App\Models\Service;
use App\Enums\TransactionChannel;
use App\Enums\TransactionStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class MigrateTransactions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'migrate:transactions';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migra las ventas desde las tablas sales y service_reports a la nueva estructura de transactions, incluyendo sus pagos.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Iniciando la migración de Transacciones y Pagos...');

        $oldDatabaseConnection = 'mysql_old';

        try {
            DB::connection($oldDatabaseConnection)->getPdo();
        } catch (\Exception $e) {
            $this->error("No se pudo conectar a la base de datos antigua ('{$oldDatabaseConnection}').");
            $this->error("Por favor, configura la conexión en config/database.php.");
            return 1;
        }

        $storeToBranchMap = [24 => 2, 25 => 3, 30 => 4];
        $cashRegisterMap = [28 => 2, 29 => 3, 34 => 4];
        $userMap = [33 => 2, 35 => 3, 44 => 5, 45 => 6];

        $paymentMethodMap = [
            'efectivo' => PaymentMethod::CASH,
            'tarjeta' => PaymentMethod::CARD,
            'transferencia' => PaymentMethod::TRANSFER,
            'saldo a favor' => PaymentMethod::BALANCE,
        ];

        DB::transaction(function () use ($oldDatabaseConnection, $storeToBranchMap, $cashRegisterMap, $paymentMethodMap, $userMap) {
            $this->line('Limpiando tablas de la nueva estructura de transacciones y pagos...');
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
            Transaction::truncate();
            TransactionItem::truncate();
            Payment::truncate();
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');

            // --- FASE 1: Migrar desde la tabla 'sales' ---
            $this->info("\nProcesando ventas de la tabla 'sales'...");
            // $oldSalesByFolio = DB::connection($oldDatabaseConnection)->table('sales')->whereIn('store_id', [24,25,30])->get()->groupBy('folio');
            // $progressBarSales = $this->output->createProgressBar(count($oldSalesByFolio));
            // $progressBarSales->start();

            $salesByStoreAndFolio = DB::connection($oldDatabaseConnection)->table('sales')
                ->whereIn('store_id', array_keys($storeToBranchMap))
                ->get()
                ->groupBy(['store_id', 'folio']);

            $totalTransactions = $salesByStoreAndFolio->collapse()->count();
            $progressBarSales = $this->output->createProgressBar($totalTransactions);
            $progressBarSales->start();

            foreach ($salesByStoreAndFolio as $storeId => $salesByFolio) {
                foreach ($salesByFolio as $folio => $saleItems) {
                    $firstItem = $saleItems->first();

                    // Ya conocemos el storeId, por lo que la asignación es directa y segura
                    $branchId = $storeToBranchMap[$storeId] ?? null;
                    $newCashRegisterId = $cashRegisterMap[$firstItem->cash_register_id] ?? null;

                    if (!$branchId || !$newCashRegisterId) {
                        $progressBarSales->advance();
                        continue;
                    }

                    $transactionTimestamp = Carbon::parse($firstItem->created_at);
                    $session = $this->findActiveSession($newCashRegisterId, $transactionTimestamp);

                    $newCustomerId = null;
                    if ($firstItem->client_id) {
                        $oldClient = DB::connection($oldDatabaseConnection)->table('clients')->find($firstItem->client_id);
                        if ($oldClient) {
                            $newCustomer = DB::table('customers')->where('name', $oldClient->name)->where('branch_id', $branchId)->first();
                            $newCustomerId = $newCustomer->id ?? null;
                        }
                    }

                    $subtotal = $saleItems->sum(fn($item) => $item->quantity * $item->current_price);
                    $totalDiscount = $saleItems->sum(fn($item) => $item->quantity * ($item->original_price - $item->current_price));
                    $totalTransaction = $subtotal - ($totalDiscount > 0 ? $totalDiscount : 0);

                    $transaction = Transaction::create([
                        'folio' => 'V-' . str_pad($folio, 3, '0', STR_PAD_LEFT),
                        'customer_id' => $newCustomerId,
                        'branch_id' => $branchId,
                        'user_id' => $userMap[$firstItem->user_id] ?? null,
                        'cash_register_session_id' => $session->id ?? null,
                        'status' => $firstItem->refunded_at ? TransactionStatus::REFUNDED : TransactionStatus::COMPLETED,
                        'channel' => TransactionChannel::POS,
                        'subtotal' => $subtotal,
                        'total_discount' => $totalDiscount > 0 ? $totalDiscount : 0,
                        'total_tax' => 0,
                        'created_at' => $transactionTimestamp,
                        'updated_at' => Carbon::parse($firstItem->updated_at),
                    ]);

                    $paymentMethod = $paymentMethodMap[strtolower($firstItem->payment_method)] ?? PaymentMethod::CASH;
                    $transaction->payments()->create([
                        'cash_register_session_id' => $session->id ?? null,
                        'amount' => $totalTransaction,
                        'payment_method' => $paymentMethod,
                        'payment_date' => $transactionTimestamp,
                        'status' => PaymentStatus::COMPLETED,
                    ]);

                    foreach ($saleItems as $item) {
                        $newProduct = Product::where('sku', $item->product_name)->orWhere('name', $item->product_name)->first();
                        $transaction->items()->create([
                            'itemable_type' => Product::class,
                            'itemable_id' => $newProduct->id ?? null,
                            'description' => $item->product_name,
                            'quantity' => $item->quantity,
                            'unit_price' => $item->current_price,
                            'line_total' => $item->quantity * $item->current_price,
                        ]);
                    }
                    $progressBarSales->advance();
                }
            }
            $progressBarSales->finish();

            // --- FASE 2: Migrar desde 'service_reports' ---
            $this->info("\nProcesando ventas de 'service_reports'...");
            // --- CORREGIDO: Obtener TODAS las órdenes de servicio ---
            $oldServiceReports = DB::connection($oldDatabaseConnection)->table('service_reports')->get();
            $progressBarServices = $this->output->createProgressBar($oldServiceReports->count());
            $progressBarServices->start();

            foreach ($oldServiceReports as $report) {
                $branchId = $storeToBranchMap[$report->store_id] ?? null;
                if (!$branchId) {
                    $progressBarServices->advance();
                    continue;
                }

                $newServiceOrder = ServiceOrder::where('folio', 'OS-' . str_pad($report->folio, 3, '0', STR_PAD_LEFT))
                ->where('branch_id', $storeToBranchMap[$report->store_id])
                ->first();

                $transactionStatus = TransactionStatus::PENDING;
                if (trim($report->status) === 'Entregado/Pagado') {
                    $transactionStatus = TransactionStatus::COMPLETED;
                } elseif (trim($report->status) === 'Cancelado') {
                    $transactionStatus = TransactionStatus::CANCELLED;
                }

                // --- CORREGIDO: Usar paid_at si existe, si no, created_at ---
                $transactionTimestamp = $report->paid_at ? Carbon::parse($report->paid_at) : Carbon::parse($report->created_at);
                $session = $this->findActiveSessionByBranch($branchId, $transactionTimestamp);

                $transaction = Transaction::create([
                    'folio' => 'OS-V-' . str_pad($report->folio, 3, '0', STR_PAD_LEFT),
                    'transactionable_type' => ServiceOrder::class,
                    'transactionable_id' => $newServiceOrder->id ?? null,
                    'customer_id' => $newServiceOrder->customer_id ?? null,
                    'branch_id' => $branchId,
                    'user_id' => $newServiceOrder->user_id ?? null,
                    'cash_register_session_id' => $session->id ?? null,
                    'status' => $transactionStatus,
                    'channel' => TransactionChannel::SERVICE_ORDER,
                    'subtotal' => $report->total_cost ?? 0,
                    'total_discount' => 0,
                    'total_tax' => 0,
                    'created_at' => $transactionTimestamp,
                    'updated_at' => Carbon::parse($report->updated_at),
                ]);

                if ($report->advance_payment > 0) {
                    $transaction->payments()->create([
                        'cash_register_session_id' => $this->findActiveSessionByBranch($branchId, $report->created_at)->id ?? null,
                        'amount' => $report->advance_payment,
                        'payment_method' => PaymentMethod::CASH,
                        'payment_date' => Carbon::parse($report->created_at),
                        'status' => PaymentStatus::COMPLETED,
                        'notes' => 'Anticipo de orden de servicio',
                    ]);
                }

                $finalPaymentAmount = ($report->total_cost ?? 0) - ($report->advance_payment ?? 0);
                // --- CORREGIDO: Añadido chequeo de paid_at para el pago final ---
                if ($report->paid_at && $finalPaymentAmount > 0) {
                    $finalPaymentMethod = $paymentMethodMap[strtolower($report->payment_method)] ?? PaymentMethod::CASH;
                    $transaction->payments()->create([
                        'cash_register_session_id' => $this->findActiveSessionByBranch($branchId, $report->paid_at)->id ?? null,
                        'amount' => $finalPaymentAmount,
                        'payment_method' => $finalPaymentMethod,
                        'payment_date' => $report->paid_at,
                        'status' => PaymentStatus::COMPLETED,
                        'notes' => 'Liquidación de orden de servicio',
                    ]);
                }

                // if ($report->service_cost > 0) {
                //      $transaction->items()->create([
                //         'itemable_type' => Service::class,
                //         'itemable_id' => null,
                //         'description' => 'Mano de Obra / Servicio Técnico', 
                //         'quantity' => 1, 
                //         'unit_price' => $report->service_cost, 
                //         'line_total' => $report->service_cost
                //     ]);
                // }
                $spareParts = json_decode($report->spare_parts, true);
                if (is_array($spareParts)) {
                    foreach ($spareParts as $part) {
                        $quantity = $part['quantity'] ?? 1;
                        $unitPrice = $part['unitPrice'] ?? 0;
                        $transaction->items()->create(['itemable_type' => Product::class, 'description' => $part['name'] ?? 'Refacción', 'quantity' => $quantity, 'unit_price' => $unitPrice, 'line_total' => $quantity * $unitPrice]);
                    }
                }
                $progressBarServices->advance();
            }
            $progressBarServices->finish();
        });

        $this->info("\n\n¡Migración de transacciones y pagos completada exitosamente!");
        return 0;
    }

    private function findActiveSession($cashRegisterId, $timestamp)
    {
        return DB::table('cash_register_sessions')->where('cash_register_id', $cashRegisterId)->where('opened_at', '<=', $timestamp)->where(function ($query) use ($timestamp) {
            $query->where('closed_at', '>=', $timestamp)->orWhereNull('closed_at');
        })->first();
    }

    private function findActiveSessionByBranch($branchId, $timestamp)
    {
        return DB::table('cash_register_sessions as crs')->join('cash_registers as cr', 'crs.cash_register_id', '=', 'cr.id')->where('cr.branch_id', $branchId)->where('crs.opened_at', '<=', $timestamp)->where(function ($query) use ($timestamp) {
            $query->where('crs.closed_at', '>=', $timestamp)->orWhereNull('crs.closed_at');
        })->select('crs.*')->first();
    }
}
