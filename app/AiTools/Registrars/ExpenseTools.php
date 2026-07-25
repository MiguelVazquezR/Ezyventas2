<?php

namespace App\AiTools\Registrars;

use App\Actions\Expense\StoreExpenseAction;
use App\Models\ExpenseCategory;
use App\Services\ExpenseReportService;
use Carbon\Carbon;
use Illuminate\Contracts\Auth\Authenticatable;
use Prism\Prism\Tool;

class ExpenseTools implements ToolRegistrar
{
    public function definitions(Authenticatable $user): array
    {
        $branchId = $user->branch_id;

        return [
            [
                'permission' => 'expenses.access',
                'category'   => 'expenses',
                'tool'       => (new Tool)->as('expenses_by_category')
                    ->for('Obtener gastos agrupados por categoría en un período')
                    ->withStringParameter('start_date', 'Fecha inicial en formato YYYY-MM-DD')
                    ->withStringParameter('end_date', 'Fecha final en formato YYYY-MM-DD')
                    ->using(function (string $start_date, string $end_date) use ($branchId) {
                        $result = app(ExpenseReportService::class)->byCategory(
                            $branchId,
                            Carbon::parse($start_date),
                            Carbon::parse($end_date),
                        );
                        return json_encode($result, JSON_PRETTY_PRINT);
                    }),
            ],

            [
                'permission' => 'expenses.access',
                'category'   => 'expenses',
                'tool'       => (new Tool)->as('expense_trend')
                    ->for('Obtener la tendencia mensual de gastos de los últimos N meses')
                    ->withNumberParameter('months', 'Cantidad de meses (por defecto 6)')
                    ->using(function (?int $months = 6) use ($branchId) {
                        $result = app(ExpenseReportService::class)->trend($branchId, $months ?? 6);
                        return json_encode($result, JSON_PRETTY_PRINT);
                    }),
            ],

            [
                'permission' => 'expenses.access',
                'category'   => 'expenses',
                'tool'       => (new Tool)->as('search_expense_categories')
                    ->for('Buscar categorías de gasto por nombre. Úsala SIEMPRE antes de registrar un gasto para obtener el ID correcto de la categoría.')
                    ->withStringParameter('query', 'Nombre parcial de la categoría (dejar vacío para listar todas)')
                    ->using(function (?string $query = null) use ($branchId) {
                        $categories = ExpenseCategory::query()
                            ->where('branch_id', $branchId)
                            ->when($query, fn ($q) => $q->where('name', 'LIKE', "%{$query}%"))
                            ->orderBy('name')
                            ->limit(20)
                            ->get(['id', 'name']);

                        if ($categories->isEmpty()) {
                            return json_encode([
                                'message' => $query
                                    ? "No se encontró ninguna categoría de gasto que coincida con '{$query}'. Pregunta al usuario si desea crear una nueva categoría con ese nombre."
                                    : 'No hay categorías de gasto registradas. Pregunta al usuario si desea crear una.',
                                'categories' => [],
                            ], JSON_PRETTY_PRINT);
                        }

                        return json_encode([
                            'message' => 'Categorías encontradas.',
                            'categories' => $categories->toArray(),
                        ], JSON_PRETTY_PRINT);
                    }),
            ],

            [
                'permission' => 'expenses.create',
                'category'   => 'expenses (crear)',
                'tool'       => (new Tool)->as('create_expense')
                    ->for('Registrar un nuevo gasto. REQUIERE modo escritura activado.')
                    ->withStringParameter('description', 'Descripción del gasto')
                    ->withNumberParameter('amount', 'Monto del gasto')
                    ->withNumberParameter('expense_category_id', 'ID de la categoría de gasto')
                    ->withStringParameter('payment_method', 'Método de pago (efectivo, tarjeta, transferencia)')
                    ->withStringParameter('expense_date', 'Fecha del gasto en formato YYYY-MM-DD (por defecto hoy)')
                    ->using(function (string $description, float $amount, int $expense_category_id, string $payment_method = 'efectivo', ?string $expense_date = null) use ($branchId, $user) {
                        $gate = app(\App\AiTools\WriteModeGate::class);
                        if (! $gate->isEnabled()) {
                            return json_encode(['error' => $gate->rejectionMessage()]);
                        }

                        $expense = app(StoreExpenseAction::class)->execute([
                            'branch_id'           => $branchId,
                            'description'         => $description,
                            'amount'              => $amount,
                            'expense_category_id' => $expense_category_id,
                            'payment_method'      => $payment_method,
                            'expense_date'        => $expense_date ?? now()->toDateString(),
                        ], $user);

                        return json_encode([
                            'message' => 'Gasto registrado exitosamente.',
                            'expense' => [
                                'id'          => $expense->id,
                                'description' => $expense->description,
                                'amount'      => $expense->amount,
                                'date'        => $expense->expense_date->toDateString(),
                            ],
                        ], JSON_PRETTY_PRINT);
                    }),
            ],
        ];
    }
}