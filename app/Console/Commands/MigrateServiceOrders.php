<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderItem;
use App\Models\Customer;
use App\Models\User;
use Carbon\Carbon;
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
    protected $description = 'Migra las órdenes de servicio y sus imágenes desde la tabla service_reports a la nueva estructura.';

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
            'Recibida' => 'pendiente',
            'En proceso' => 'en_progreso',
            'Listo para entregar' => 'terminado',
            'Entregado/Pagado' => 'entregado',
            'Cancelado' => 'cancelado',
        ];

        DB::transaction(function () use ($oldDatabaseConnection, $storeToBranchMap, $branchToSubscriptionMap, $statusMap) {
            $this->line('Limpiando tablas de la nueva estructura de servicios...');
            
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
            ServiceOrder::truncate();
            ServiceOrderItem::truncate();
            // --- AÑADIDO: Limpiar solo la media de órdenes de servicio ---
            DB::table('media')->where('model_type', ServiceOrder::class)->delete();
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');

            $oldReports = DB::connection($oldDatabaseConnection)->table('service_reports')->get();
            $progressBar = $this->output->createProgressBar($oldReports->count());
            $progressBar->start();
            
            $defaultUsers = [];

            foreach ($oldReports as $oldReport) {
                if (!isset($storeToBranchMap[$oldReport->store_id])) {
                    $progressBar->advance();
                    continue;
                }
                $branchId = $storeToBranchMap[$oldReport->store_id];
                
                if (!isset($branchToSubscriptionMap[$branchId])) {
                    $progressBar->advance();
                    continue;
                }
                
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

                // --- INICIO: Lógica de Campos Personalizados modificada ---
                $customFieldsPayload = [
                    'desbloqueo' => '',
                    'accesorios' => [],
                    'imei' => '',
                    'estado_previo_del_equipo' => '',
                    'servicios_a_realizar' => $oldReport->service_description ?? '', // <-- AÑADIDO
                ];

                $aditionals = json_decode($oldReport->aditionals, true);
                if (is_array($aditionals)) {
                    if (isset($aditionals['unlockPassword'])) {
                        $customFieldsPayload['desbloqueo'] = $aditionals['unlockPassword'];
                    }
                    if (isset($aditionals['accessories']) && is_array($aditionals['accessories'])) {
                        $customFieldsPayload['accesorios'] = $aditionals['accessories'];
                    }
                }

                $productDetails = json_decode($oldReport->product_details, true);
                if (is_array($productDetails)) {
                    if (isset($productDetails['imei'])) {
                        $customFieldsPayload['imei'] = $productDetails['imei'];
                    } elseif (isset($productDetails['imei/serie'])) {
                        $customFieldsPayload['imei'] = $productDetails['imei/serie'];
                    }
                }
                
                // --- CORREGIDO: reported_problems ahora solo usa 'description' ---
                $reportedProblems = $oldReport->description ?? '';
                
                $itemDescription = 'Equipo sin descripción';
                if(is_array($productDetails) && !empty($productDetails['model'])) {
                    $itemDescription = (!empty($productDetails['brand']) ? $productDetails['brand'] . ' ' : '') . $productDetails['model'];
                }

                $newOrderData = [
                    'folio' => 'OS-' . str_pad($oldReport->folio, 3, '0', STR_PAD_LEFT),
                    'branch_id' => $branchId,
                    'user_id' => $userId,
                    'customer_id' => $customerId,
                    'customer_name' => $oldReport->client_name,
                    'customer_phone' => $oldReport->client_phone_number,
                    'technician_name' => $oldReport->technician_name,
                    'technician_commission_type' => $oldReport->comision_percentage ? 'percentage' : null,
                    'technician_commission_value' => $oldReport->comision_percentage,
                    'status' => $statusMap[trim($oldReport->status)] ?? 'pendiente',
                    'received_at' => $oldReport->service_date,
                    'item_description' => $itemDescription,
                    'reported_problems' => $reportedProblems,
                    'final_total' => $oldReport->total_cost,
                    'custom_fields' => $customFieldsPayload,
                    'created_at' => Carbon::parse($oldReport->created_at),
                    'updated_at' => Carbon::parse($oldReport->updated_at),
                ];

                $newServiceOrder = ServiceOrder::create($newOrderData);

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
                
                $oldModelType = 'App\\Models\\ServiceReport';
                
                $allOldMedia = DB::connection($oldDatabaseConnection)
                    ->table('media')
                    ->where('model_type', $oldModelType)
                    ->where('model_id', $oldReport->id)
                    ->get();

                foreach ($allOldMedia as $oldMedia) {
                    $newCollectionName = null;
                    if ($oldMedia->collection_name === 'default') {
                        $newCollectionName = 'initial-service-order-evidence';
                    } elseif ($oldMedia->collection_name === 'service_evidence') {
                        $newCollectionName = 'closing-service-order-evidence';
                    }

                    if ($newCollectionName) {
                        DB::connection('mysql')->table('media')->insert([
                            'id' => $oldMedia->id,
                            'model_type' => ServiceOrder::class,
                            'model_id' => $newServiceOrder->id,
                            'uuid' => $oldMedia->uuid ?? (string) Str::uuid(),
                            'collection_name' => $newCollectionName,
                            'name' => pathinfo($oldMedia->file_name, PATHINFO_FILENAME),
                            'file_name' => $oldMedia->file_name,
                            'mime_type' => $oldMedia->mime_type,
                            'disk' => 'public',
                            'conversions_disk' => $oldMedia->conversions_disk ?? 'public',
                            'size' => $oldMedia->size,
                            'manipulations' => '[]',
                            'custom_properties' => '[]',
                            'generated_conversions' => $oldMedia->generated_conversions ?? '[]',
                            'responsive_images' => '[]',
                            'order_column' => $oldMedia->order_column ?? 1,
                            'created_at' => $oldMedia->created_at,
                            'updated_at' => $oldMedia->updated_at,
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

