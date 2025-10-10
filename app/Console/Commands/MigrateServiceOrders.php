<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderItem;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Str;

class MigrateServiceOrders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'migrate:service-orders';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migra las órdenes de servicio desde la tabla service_reports a la nueva estructura service_orders.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Iniciando la migración de Órdenes de Servicio...');

        $oldDatabaseConnection = 'mysql_old';

        try {
            DB::connection($oldDatabaseConnection)->getPdo();
        } catch (\Exception $e) {
            $this->error("No se pudo conectar a la base de datos antigua ('{$oldDatabaseConnection}').");
            $this->error("Por favor, configura la conexión en config/database.php.");
            return 1;
        }
        
        $storeToBranchMap = [
            24 => 2,
            25 => 3,
            30 => 4,
        ];
        
        $branchToSubscriptionMap = [
            2 => 2,
            3 => 2,
            4 => 3,
        ];

         $statusMap = [
            'Recibida' => 'pending',
            'En proceso' => 'en_progreso',
            'Listo para entregar' => 'terminado',
            'Entregado/Pagado' => 'entregado',
            'Cancelado' => 'cancelado',
            // Agrega más mapeos si es necesario
        ];

        DB::transaction(function () use ($oldDatabaseConnection, $storeToBranchMap, $branchToSubscriptionMap, $statusMap) {
            $this->line('Limpiando tablas de la nueva estructura de servicios...');
            
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
            ServiceOrder::truncate();
            ServiceOrderItem::truncate();
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');

            $oldReports = DB::connection($oldDatabaseConnection)->table('service_reports')->get();
            $progressBar = $this->output->createProgressBar($oldReports->count());
            $progressBar->start();
            
            $defaultUsers = [];

            foreach ($oldReports as $oldReport) {
                if (!isset($storeToBranchMap[$oldReport->store_id])) {
                    // $this->warn("\nSaltando reporte ID {$oldReport->id} porque su store_id ({$oldReport->store_id}) no tiene un branch_id correspondiente.");
                    $progressBar->advance();
                    continue;
                }
                $branchId = $storeToBranchMap[$oldReport->store_id];
                
                if (!isset($branchToSubscriptionMap[$branchId])) {
                    //  $this->warn("\nSaltando reporte ID {$oldReport->id} porque su branch_id ({$branchId}) no tiene un subscription_id correspondiente.");
                    $progressBar->advance();
                    continue;
                }
                
                // --- MODIFICADO: Lógica de Clientes ---
                // Buscar cliente por nombre; si no existe, customer_id será null.
                $customer = null;
                if (!empty($oldReport->client_name)) {
                    $customer = Customer::where('branch_id', $branchId)
                                        ->where('name', trim($oldReport->client_name))
                                        ->first();
                }
                $customerId = $customer ? $customer->id : null;
                
                if (!isset($defaultUsers[$branchId])) {
                    $defaultUsers[$branchId] = User::where('branch_id', $branchId)->first()->id ?? null;
                }
                $userId = $defaultUsers[$branchId];

                // --- MODIFICADO: Lógica de Campos Personalizados con estructura fija ---
                $customFieldsPayload = [
                    'desbloqueo' => '',
                    'accesorios' => [],
                    'imei' => '',
                    'estado_previo_del_equipo' => '',
                ];

                // Procesar `aditionals` para desbloqueo y accesorios
                $aditionals = json_decode($oldReport->aditionals, true);
                if (is_array($aditionals)) {
                    if (isset($aditionals['unlockPassword'])) {
                        $customFieldsPayload['desbloqueo'] = $aditionals['unlockPassword'];
                    }
                    if (isset($aditionals['accessories']) && is_array($aditionals['accessories'])) {
                        $customFieldsPayload['accesorios'] = $aditionals['accessories'];
                    }
                }

                // Procesar `product_details` para el IMEI
                $productDetails = json_decode($oldReport->product_details, true);
                if (is_array($productDetails)) {
                    if (isset($productDetails['imei'])) {
                        $customFieldsPayload['imei'] = $productDetails['imei'];
                    } elseif (isset($productDetails['imei/serie'])) {
                        $customFieldsPayload['imei'] = $productDetails['imei/serie'];
                    }
                }
                
                $reportedProblems = implode("\n", array_filter([$oldReport->description, $oldReport->service_description]));
                
                $itemDescription = 'Equipo sin descripción';
                if(is_array($productDetails) && !empty($productDetails['model'])) {
                    $itemDescription = (!empty($productDetails['brand']) ? $productDetails['brand'] . ' ' : '') . $productDetails['model'];
                }

                $newOrderData = [
                    'folio' => 'OS-' . str_pad($oldReport->folio, 3, '0', STR_PAD_LEFT),
                    'branch_id' => $branchId,
                    'user_id' => $userId,
                    'customer_id' => $customerId, // ID del cliente encontrado o null
                    'customer_name' => $oldReport->client_name, // Siempre se guarda el nombre original
                    'customer_phone' => $oldReport->client_phone_number,
                    'technician_name' => $oldReport->technician_name,
                    'technician_commission_type' => $oldReport->comision_percentage ? 'percentage' : null,
                    'technician_commission_value' => $oldReport->comision_percentage,
                    'status' => $statusMap[strtolower(trim($oldReport->status))] ?? 'pendiente',
                    'received_at' => $oldReport->service_date,
                    'item_description' => $itemDescription,
                    'reported_problems' => $reportedProblems,
                    'final_total' => $oldReport->total_cost,
                    'custom_fields' => $customFieldsPayload, // Guarda el JSON con estructura fija
                    'created_at' => $oldReport->created_at,
                    'updated_at' => $oldReport->updated_at,
                ];

                $newServiceOrder = ServiceOrder::create($newOrderData);

                // if ($oldReport->service_cost > 0) {
                //     $newServiceOrder->items()->create([
                //         'description' => 'Mano de Obra / Servicio Técnico',
                //         'quantity' => 1, 'unit_price' => $oldReport->service_cost, 'line_total' => $oldReport->service_cost,
                //     ]);
                // }

                $spareParts = json_decode($oldReport->spare_parts, true);
                if (is_array($spareParts)) {
                    foreach ($spareParts as $part) {
                        $quantity = $part['quantity'] ?? 1;
                        $unitPrice = $part['unitPrice'] ?? 0;
                        $newServiceOrder->items()->create([
                            'itemable_type' => \App\Models\Product::class,
                            'itemable_id' => null,
                            'description' => $part['name'] ?? 'Refacción sin nombre',
                            'quantity' => $quantity,
                            'unit_price' => $unitPrice,
                            'line_total' => $quantity * $unitPrice,
                        ]);
                    }
                }
                
                $progressBar->advance();
            }

            $progressBar->finish();
        });
        
        $this->info("\n¡Migración de órdenes de servicio completada exitosamente!");
        return 0;
    }
}

