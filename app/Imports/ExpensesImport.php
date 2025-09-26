<?php

namespace App\Imports;

use App\Enums\ExpenseStatus;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class ExpensesImport implements ToModel, WithHeadingRow
{
    private $categories;

    public function __construct()
    {
        $subscriptionId = Auth::user()->branch->subscription_id;
        // Cache para evitar consultas repetidas a la BD
        $this->categories = ExpenseCategory::where('subscription_id', $subscriptionId)->pluck('id', 'name');
    }

    /**
    * @param array $row
    *
    * @return \Illuminate\Database\Eloquent\Model|null
    */
    public function model(array $row)
    {
        $categoryId = $this->categories->get($row['categoria']);

        if (!$categoryId) {
            // Opcional: Omitir fila si la categoría no existe
            return null;
        }

        return new Expense([
            'folio' => $row['folio'],
            'description' => $row['descripcion'],
            'amount' => $row['monto'],
            'expense_date' => Carbon::parse($row['fecha'])->toDateString(),
            'status' => ExpenseStatus::tryFrom($row['estatus']) ?? ExpenseStatus::PENDING,
            'expense_category_id' => $categoryId,
            'user_id' => Auth::id(),
            'branch_id' => Auth::user()->branch_id,
        ]);
    }
}
