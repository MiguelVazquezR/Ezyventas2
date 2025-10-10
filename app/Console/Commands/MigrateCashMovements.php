<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\SessionCashMovement;
use Carbon\Carbon;

class MigrateCashMovements extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'migrate:cash-movements';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migra los movimientos de caja desde cash_register_movements a la nueva tabla session_cash_movements.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Iniciando la migración de Movimientos de Caja...');

        $oldDatabaseConnection = 'mysql_old';

        try {
            DB::connection($oldDatabaseConnection)->getPdo();
        } catch (\Exception $e) {
            $this->error("No se pudo conectar a la base de datos antigua ('{$oldDatabaseConnection}').");
            $this->error("Por favor, configura la conexión en config/database.php.");
            return 1;
        }

        // Mapeo de IDs de cash_register de la tabla vieja a la nueva.
        $cashRegisterMap = [
            28 => 2,
            29 => 3,
        ];

        DB::transaction(function () use ($oldDatabaseConnection, $cashRegisterMap) {
            $this->line('Limpiando la tabla de la nueva estructura de movimientos de caja...');
            
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
            SessionCashMovement::truncate();
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');

            $oldMovements = DB::connection($oldDatabaseConnection)->table('cash_register_movements')->get();
            $progressBar = $this->output->createProgressBar($oldMovements->count());
            $progressBar->start();

            foreach ($oldMovements as $oldMovement) {
                // 1. Obtener el ID de la nueva caja registradora
                if (!isset($cashRegisterMap[$oldMovement->cash_register_id])) {
                    // $this->warn("\nSaltando movimiento ID {$oldMovement->id} porque su cash_register_id ({$oldMovement->cash_register_id}) no tiene un ID correspondiente en el nuevo sistema.");
                    $progressBar->advance();
                    continue;
                }
                $newCashRegisterId = $cashRegisterMap[$oldMovement->cash_register_id];
                $movementTimestamp = $oldMovement->created_at;

                // 2. Encontrar la sesión de caja que estaba activa en ese momento
                $activeSession = DB::connection('mysql')->table('cash_register_sessions')
                    ->where('cash_register_id', $newCashRegisterId)
                    ->where('opened_at', '<=', $movementTimestamp)
                    ->where(function ($query) use ($movementTimestamp) {
                        $query->where('closed_at', '>=', $movementTimestamp)
                              ->orWhereNull('closed_at');
                    })
                    ->first();

                if (!$activeSession) {
                    // $this->warn("\nSaltando movimiento ID {$oldMovement->id} porque no se encontró una sesión de caja activa para la caja #{$newCashRegisterId} en la fecha {$movementTimestamp}.");
                    $progressBar->advance();
                    continue;
                }

                // 3. Crear el nuevo movimiento de caja
                SessionCashMovement::create([
                    'cash_register_session_id' => $activeSession->id,
                    'type' => $oldMovement->type == "Retiro" ? 'egreso' : 'ingreso', // 'ingreso' o 'egreso'
                    'amount' => $oldMovement->amount,
                    'description' => $oldMovement->notes ?? 'Sin descripción',
                    'created_at' => $oldMovement->created_at,
                    'updated_at' => $oldMovement->updated_at,
                ]);
                
                $progressBar->advance();
            }

            $progressBar->finish();
        });
        
        $this->info("\n¡Migración de movimientos de caja completada exitosamente!");
        return 0;
    }
}