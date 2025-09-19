<?php

namespace App\Http\Controllers;

use App\Exports\ExpensesExport;
use App\Imports\ExpensesImport;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class ExpenseImportExportController extends Controller
{
    /**
     * Exporta los gastos de la suscripción a un archivo Excel.
     */
    public function export()
    {
        return Excel::download(new ExpensesExport, 'gastos.xlsx');
    }

    /**
     * Importa gastos desde un archivo Excel.
     */
    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:xlsx,xls'
        ]);

        Excel::import(new ExpensesImport, $request->file('file'));

        return redirect()->route('expenses.index')->with('success', 'Gastos importados con éxito.');
    }
}