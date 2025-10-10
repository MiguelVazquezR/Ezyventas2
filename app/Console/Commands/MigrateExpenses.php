<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Expense; // Asegúrate de que este es tu nuevo modelo de Gasto
use Carbon\Carbon;
use Illuminate\Support\Str;

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
            30 => 4,
        ];

        // 1. Filtramos los gastos que pertenecen a las sucursales que estamos migrando
        $oldExpenses = DB::connection('mysql_old')->table('expenses')
            ->whereIn('store_id', array_keys($branchMap))
            ->get();

        if ($oldExpenses->isEmpty()) {
            $this->warn('No se encontraron gastos para las sucursales especificadas. No hay nada que migrar.');
            return 0;
        }

        $this->line('Limpiando la tabla de gastos para evitar duplicados...');
        Expense::truncate();

        $progressBar = $this->output->createProgressBar($oldExpenses->count());
        $progressBar->start();

        foreach ($oldExpenses as $oldExpense) {
            // --- CORREGIDO: Usar el 'concept' original directamente como folio ---
            $folio = $oldExpense->concept;

            // 3. Mapeamos el método de pago a un valor estandarizado
            $paymentMethod = match (strtolower($oldExpense->payment_method)) {
                'tarjeta' => 'tarjeta',
                'transferencia' => 'transferencia',
                default => 'efectivo',
            };

            // Mapeamos el branch_id y luego asignamos el user_id basado en él.
            $newBranchId = $branchMap[$oldExpense->store_id];
            $newUserId = match ($newBranchId) {
                2 => 2,
                3 => 3,
                default => null,
            };

            // Lógica para asignar categoría de gasto
            $conceptLower = strtolower($oldExpense->concept ?? '');
            $categoryId = 1; // Categoría por defecto

            if (Str::contains($conceptLower, 'mandadito')) {
                $categoryId = 2;
            } elseif (Str::contains($conceptLower, ['nomina', 'comision'])) {
                $categoryId = 3;
            } elseif (Str::contains($conceptLower, 'compra')) {
                $categoryId = 4;
            }

            // 4. Creamos el array de datos para el nuevo gasto
            $expenseData = [
                'folio' => $folio, // Guardar el folio/concepto original
                'user_id' => $newUserId,
                'branch_id' => $newBranchId,
                'expense_category_id' => $categoryId,
                'amount' => $oldExpense->current_price,
                'expense_date' => Carbon::parse($oldExpense->created_at)->toDateString(),
                'status' => 'pagado',
                'payment_method' => $paymentMethod,
                'bank_account_id' => null,
                'session_cash_movement_id' => null,
                'created_at' => Carbon::parse($oldExpense->created_at),
                'updated_at' => Carbon::parse($oldExpense->updated_at),
            ];

            // 5. Usamos create() ya que la tabla se limpia al inicio y se permiten folios duplicados.
            Expense::create($expenseData);

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->info("\n\n¡Migración de gastos completada exitosamente!");

        return 0;
    }
}
