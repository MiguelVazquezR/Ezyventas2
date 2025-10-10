<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\CashRegisterSession; // Asegúrate de que este es tu nuevo modelo
use Carbon\Carbon;

class MigrateCashRegisterSessions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'migrate:cash-sessions';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migra las sesiones de caja (cash_cuts) de la base de datos antigua a la nueva.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Iniciando la migración de sesiones de caja...');

        // 1. Conectamos a la DB antigua y filtramos por los store_id especificados
        $oldSessions = DB::connection('mysql_old')->table('cash_cuts')
            ->whereIn('store_id', [24, 25, 30])
            ->get();

        if ($oldSessions->isEmpty()) {
            $this->warn('No se encontraron cortes de caja con los store_id [24, 25, 30]. No hay nada que migrar.');
            return 0;
        }

        $progressBar = $this->output->createProgressBar($oldSessions->count());
        $progressBar->start();

        foreach ($oldSessions as $oldSession) {
            // 2. Mapeamos los IDs de cash_register
            $newCashRegisterId = match ((int)$oldSession->cash_register_id) {
                29 => 3,
                34 => 4,
                28 => 2,
                default => null,
            };

            // 3. Mapeamos los IDs de user
            $newUserId = match ((int)$oldSession->user_id) {
                33 => 2,
                35 => 3,
                44 => 5,
                45 => 6,
                default => null,
            };

            // Si no se encuentra un mapeo para el usuario o la caja, saltamos este registro para evitar errores.
            if (is_null($newCashRegisterId) || is_null($newUserId)) {
                $this->warn("\nSaltando registro de cash_cut ID: {$oldSession->id} por no tener mapeo de ID de caja o usuario.");
                $progressBar->advance();
                continue;
            }

            // 4. Transformamos las fechas
            $openedAt = Carbon::parse($oldSession->created_at)->setTime(10, 0, 0);

            // 5. Creamos el nuevo registro en la tabla 'cash_register_sessions'
            CashRegisterSession::updateOrCreate(
                [
                    // Condición para evitar duplicados si se corre el script varias veces
                    'cash_register_id' => $newCashRegisterId,
                    'user_id' => $newUserId,
                    'opened_at' => $openedAt,
                ],
                [
                    'closed_at' => $oldSession->updated_at,
                    'status' => 'cerrada',
                    'opening_cash_balance' => $oldSession->started_cash ?? 0.00,
                    'closing_cash_balance' => $oldSession->counted_cash,
                    'calculated_cash_total' => $oldSession->expected_cash,
                    'cash_difference' => $oldSession->difference_cash,
                    'notes' => $oldSession->notes,
                    'created_at' => Carbon::parse($oldSession->created_at),
                    'updated_at' => Carbon::parse($oldSession->updated_at),
                ]
            );

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->info("\n\n¡Migración de sesiones de caja completada exitosamente!");

        return 0;
    }
}