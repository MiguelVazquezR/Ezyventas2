<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Customer; // Asegúrate de que este es tu nuevo modelo de cliente

class MigrateCustomers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'migrate:customers';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migra los clientes (clients) de la base de datos antigua a la nueva (customers).';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Iniciando la migración de clientes...');

        // 1. Filtramos solo los clientes de los store_id especificados
        $oldClients = DB::connection('mysql_old')->table('clients')
            ->whereIn('store_id', [24, 25])
            ->get();

        if ($oldClients->isEmpty()) {
            $this->warn('No se encontraron clientes para los store_id [24, 25]. No hay nada que migrar.');
            return 0;
        }

        $progressBar = $this->output->createProgressBar($oldClients->count());
        $progressBar->start();

        foreach ($oldClients as $oldClient) {
            // 2. Mapeamos el store_id al nuevo branch_id
            $newBranchId = match ((int)$oldClient->store_id) {
                25 => 3,
                24 => 2,
                default => null,
            };

            // Si por alguna razón no hay un branch_id, saltamos el registro
            if (is_null($newBranchId)) {
                $this->warn("\nSaltando cliente ID: {$oldClient->id} por no tener un store_id mapeable.");
                $progressBar->advance();
                continue;
            }

            // 4. Creamos el array de datos para el nuevo cliente
            $customerData = [
                'branch_id' => $newBranchId,
                'name' => $oldClient->name,
                'company_name' => $oldClient->company,
                'email' => $oldClient->email,
                'phone' => $oldClient->phone,
                // 'tax_id' => $oldClient->rfc,
                'balance' => -1 * ($oldClient->debt ?? 0.00), // La deuda se convierte en saldo negativo
                'created_at' => $oldClient->created_at,
                'updated_at' => $oldClient->updated_at,
            ];

            // 5. Usamos updateOrCreate para evitar duplicados.
            // La clave única más fiable es el email.
            // Si el email es nulo, usamos una combinación de nombre, teléfono y sucursal.
            if (!empty($oldClient->email)) {
                Customer::updateOrCreate(
                    ['email' => $oldClient->email],
                    $customerData
                );
            } else {
                Customer::updateOrCreate(
                    [
                        'name' => $oldClient->name,
                        'phone' => $oldClient->phone,
                        'branch_id' => $newBranchId
                    ],
                    $customerData
                );
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->info("\n\n¡Migración de clientes completada exitosamente!");

        return 0;
    }
}