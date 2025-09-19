<?php

namespace App\Http\Controllers;

use App\Exports\CustomersExport;
use App\Exports\ExpensesExport;
use App\Exports\ProductsExport;
use App\Imports\CustomersImport;
use App\Imports\ExpensesImport;
use App\Imports\ProductsImport;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class ImportExportController extends Controller
{
    // --- PRODUCTOS ---
    public function exportProducts()
    {
        return Excel::download(new ProductsExport, 'productos.xlsx');
    }

    public function importProducts(Request $request)
    {
        $request->validate(['file' => 'required|mimes:xlsx,xls']);
        Excel::import(new ProductsImport, $request->file('file'));
        return redirect()->route('products.index')->with('success', 'Productos importados con éxito.');
    }

    // --- CLIENTES ---
    public function exportCustomers()
    {
        return Excel::download(new CustomersExport, 'clientes.xlsx');
    }

    public function importCustomers(Request $request)
    {
        $request->validate(['file' => 'required|mimes:xlsx,xls']);
        Excel::import(new CustomersImport, $request->file('file'));
        return redirect()->route('customers.index')->with('success', 'Clientes importados con éxito.');
    }

    // --- GASTOS ---
    public function exportExpenses()
    {
        return Excel::download(new ExpensesExport, 'gastos.xlsx');
    }

    public function importExpenses(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:xlsx,xls'
        ]);

        Excel::import(new ExpensesImport, $request->file('file'));

        return redirect()->route('expenses.index')->with('success', 'Gastos importados con éxito.');
    }
}