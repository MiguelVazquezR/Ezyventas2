<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Expense; // Asegúrate de que este es tu nuevo modelo de Gasto
use Carbon\Carbon;

class MigrateExpenses extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'migrate:expenses';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migra los gastos (expenses) de la base de datos antigua a la nueva.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Iniciando la migración de gastos...');

        // Mapeo de store_id (viejo) a branch_id (nuevo)
        $branchMap = [
            24 => 2,
            25 => 3,
            30 => 3, // Como en categorías, 30 también apunta a 3
        ];

        // 1. Filtramos los gastos que pertenecen a las sucursales que estamos migrando
        $oldExpenses = DB::connection('mysql_old')->table('expenses')
            ->whereIn('store_id', array_keys($branchMap))
            ->get();

        if ($oldExpenses->isEmpty()) {
            $this->warn('No se encontraron gastos para las sucursales especificadas. No hay nada que migrar.');
            return 0;
        }

        $progressBar = $this->output->createProgressBar($oldExpenses->count());
        $progressBar->start();

        foreach ($oldExpenses as $oldExpense) {
            // 2. Generamos un folio único basado en el ID antiguo para mantener la trazabilidad
            $folio = $oldExpense->concept;

            // 3. Mapeamos el método de pago a un valor estandarizado (puedes ajustar esto si es necesario)
            $paymentMethod = match (strtolower($oldExpense->payment_method)) {
                'tarjeta' => 'tarjeta',
                'transferencia' => 'transferencia',
                default => 'efectivo', // 'Efectivo' y cualquier otro caso se convierte en 'cash'
            };

            // --- NUEVA LÓGICA ---
            // Mapeamos el branch_id y luego asignamos el user_id basado en él.
            $newBranchId = $branchMap[$oldExpense->store_id];
            $newUserId = match ($newBranchId) {
                2 => 2,
                3 => 3,
                default => null, // Fallback por si acaso
            };
            // --- FIN DE LA NUEVA LÓGICA ---

            // 4. Creamos el array de datos para el nuevo gasto
            $expenseData = [
                'user_id' => $newUserId, // Se asigna el usuario correspondiente
                'branch_id' => $newBranchId,
                'expense_category_id' => null, // No hay categoría en la versión antigua
                'amount' => $oldExpense->current_price, // El monto principal del gasto
                'expense_date' => Carbon::parse($oldExpense->created_at)->toDateString(),
                'status' => 'pagado', // Asumimos que todos los gastos migrados están pagados
                'payment_method' => $paymentMethod,
                'bank_account_id' => null,
                'session_cash_movement_id' => null,
                'created_at' => $oldExpense->created_at,
                'updated_at' => $oldExpense->updated_at,
            ];

            // 5. Usamos updateOrCreate con el folio único para evitar duplicados
            Expense::updateOrCreate(
                ['folio' => $folio],
                $expenseData
            );

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->info("\n\n¡Migración de gastos completada exitosamente!");

        return 0;
    }
}