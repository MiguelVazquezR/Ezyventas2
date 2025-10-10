<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\ServiceOrder;
use App\Models\Product;
use App\Enums\TransactionChannel;
use App\Enums\TransactionStatus;

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
    protected $description = 'Migra las ventas desde las tablas sales y service_reports a la nueva estructura de transactions.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Iniciando la migración de Transacciones...');

        $oldDatabaseConnection = 'mysql_old';

        try {
            DB::connection($oldDatabaseConnection)->getPdo();
        } catch (\Exception $e) {
            $this->error("No se pudo conectar a la base de datos antigua ('{$oldDatabaseConnection}').");
            $this->error("Por favor, configura la conexión en config/database.php.");
            return 1;
        }

        // Mapeos de IDs
        $storeToBranchMap = [24 => 2, 25 => 3, 30 => 4];
        $cashRegisterMap = [28 => 2, 29 => 3];

        DB::transaction(function () use ($oldDatabaseConnection, $storeToBranchMap, $cashRegisterMap) {
            $this->line('Limpiando tablas de la nueva estructura de transacciones...');
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
            Transaction::truncate();
            TransactionItem::truncate();
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');

            // --- FASE 1: Migrar desde la tabla 'sales' ---
            $this->info("\nProcesando ventas de la tabla 'sales'...");
            $oldSalesByFolio = DB::connection($oldDatabaseConnection)->table('sales')->get()->groupBy('folio');
            $progressBarSales = $this->output->createProgressBar(count($oldSalesByFolio));
            $progressBarSales->start();

            foreach ($oldSalesByFolio as $folio => $saleItems) {
                $firstItem = $saleItems->first();
                $branchId = $storeToBranchMap[$firstItem->store_id] ?? null;
                $newCashRegisterId = $cashRegisterMap[$firstItem->cash_register_id] ?? null;

                if (!$branchId || !$newCashRegisterId) {
                    $this->warn("\nSaltando venta con folio {$folio} por mapeo de sucursal/caja no encontrado.");
                    $progressBarSales->advance();
                    continue;
                }

                $transactionTimestamp = $firstItem->created_at;
                $session = $this->findActiveSession($newCashRegisterId, $transactionTimestamp);
                
                // Mapeo de cliente. Asumimos que los clientes ya fueron migrados.
                $newCustomerId = null;
                if ($firstItem->client_id) {
                    $oldClient = DB::connection($oldDatabaseConnection)->table('clients')->find($firstItem->client_id);
                    if ($oldClient) {
                         $newCustomer = DB::table('customers')->where('name', $oldClient->name)->where('branch_id', $branchId)->first();
                         $newCustomerId = $newCustomer->id ?? null;
                    }
                }
                
                // Mapeo de usuario.
                 $newUser = DB::table('users')->where('id', $firstItem->user_id)->first();

                // Calcular totales
                $subtotal = $saleItems->sum(function($item) {
                    return $item->quantity * $item->current_price;
                });
                $totalDiscount = $saleItems->sum(function($item) {
                    return $item->quantity * ($item->original_price - $item->current_price);
                });

                $transaction = Transaction::create([
                    'folio' => 'SALE-' . $folio,
                    'customer_id' => $newCustomerId,
                    'branch_id' => $branchId,
                    'user_id' => $newUser->id ?? null,
                    'cash_register_session_id' => $session->id ?? null,
                    'status' => $firstItem->refunded_at ? TransactionStatus::REFUNDED : TransactionStatus::COMPLETED,
                    'channel' => TransactionChannel::POS,
                    'subtotal' => $subtotal,
                    'total_discount' => $totalDiscount > 0 ? $totalDiscount : 0,
                    'total_tax' => 0,
                    'created_at' => $transactionTimestamp,
                    'updated_at' => $firstItem->updated_at,
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
            $progressBarSales->finish();

            // --- FASE 2: Migrar desde 'service_reports' ---
            $this->info("\nProcesando ventas de 'service_reports'...");
            $oldServiceReports = DB::connection($oldDatabaseConnection)->table('service_reports')->whereNotNull('paid_at')->where('total_cost', '>', 0)->get();
            $progressBarServices = $this->output->createProgressBar($oldServiceReports->count());
            $progressBarServices->start();
            
            foreach($oldServiceReports as $report) {
                $branchId = $storeToBranchMap[$report->store_id] ?? null;
                if (!$branchId) {
                     $progressBarServices->advance();
                     continue;
                }
                
                $newServiceOrder = ServiceOrder::where('folio', 'OS-' . str_pad($report->folio, 3, '0', STR_PAD_LEFT))->first();

                $transaction = Transaction::create([
                    'folio' => 'SRV-' . $report->folio,
                    'transactionable_type' => ServiceOrder::class,
                    'transactionable_id' => $newServiceOrder->id ?? null,
                    'customer_id' => $newServiceOrder->customer_id ?? null,
                    'branch_id' => $branchId,
                    'user_id' => $newServiceOrder->user_id ?? null,
                    'cash_register_session_id' => $this->findActiveSessionByBranch($branchId, $report->paid_at)->id ?? null,
                    'status' => $report->status == 'cancelado' ? TransactionStatus::CANCELLED : TransactionStatus::COMPLETED,
                    'channel' => TransactionChannel::SERVICE_ORDER,
                    'subtotal' => $report->total_cost,
                    'total_discount' => 0,
                    'total_tax' => 0,
                    'created_at' => $report->paid_at,
                    'updated_at' => $report->updated_at,
                ]);
                
                // Items de la orden de servicio
                if ($report->service_cost > 0) {
                     $transaction->items()->create([
                         'description' => 'Mano de Obra / Servicio Técnico', 'quantity' => 1,
                         'unit_price' => $report->service_cost, 'line_total' => $report->service_cost,
                     ]);
                }
                $spareParts = json_decode($report->spare_parts, true);
                if (is_array($spareParts)) {
                    foreach ($spareParts as $part) {
                        $quantity = $part['quantity'] ?? 1;
                        $unitPrice = $part['unitPrice'] ?? 0;
                        $transaction->items()->create([
                            'itemable_type' => Product::class, 'description' => $part['name'] ?? 'Refacción',
                            'quantity' => $quantity, 'unit_price' => $unitPrice, 'line_total' => $quantity * $unitPrice,
                        ]);
                    }
                }
                $progressBarServices->advance();
            }
            $progressBarServices->finish();
        });
        
        $this->info("\n\n¡Migración de transacciones completada exitosamente!");
        return 0;
    }

    private function findActiveSession($cashRegisterId, $timestamp)
    {
        return DB::table('cash_register_sessions')
            ->where('cash_register_id', $cashRegisterId)
            ->where('opened_at', '<=', $timestamp)
            ->where(function ($query) use ($timestamp) {
                $query->where('closed_at', '>=', $timestamp)->orWhereNull('closed_at');
            })->first();
    }

    private function findActiveSessionByBranch($branchId, $timestamp)
    {
        return DB::table('cash_register_sessions as crs')
            ->join('cash_registers as cr', 'crs.cash_register_id', '=', 'cr.id')
            ->where('cr.branch_id', $branchId)
            ->where('crs.opened_at', '<=', $timestamp)
            ->where(function ($query) use ($timestamp) {
                $query->where('crs.closed_at', '>=', $timestamp)->orWhereNull('crs.closed_at');
            })
            ->select('crs.*')
            ->first();
    }
}