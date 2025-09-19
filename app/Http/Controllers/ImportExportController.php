<?php

namespace App\Http\Controllers;

use App\Exports\CustomersExport;
use App\Exports\ExpensesExport;
use App\Exports\ProductsExport;
use App\Exports\ServiceOrdersExport;
use App\Exports\ServicesExport;
use App\Imports\CustomersImport;
use App\Imports\ExpensesImport;
use App\Imports\ProductsImport;
use App\Imports\ServiceOrdersImport;
use App\Imports\ServicesImport;
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

    // --- SERVICIOS ---
    public function exportServices()
    {
        return Excel::download(new ServicesExport, 'servicios.xlsx');
    }

    public function importServices(Request $request)
    {
        $request->validate(['file' => 'required|mimes:xlsx,xls']);
        Excel::import(new ServicesImport, $request->file('file'));
        return redirect()->route('services.index')->with('success', 'Servicios importados con éxito.');
    }

     // --- ÓRDENES DE SERVICIO ---
    public function exportServiceOrders()
    {
        return Excel::download(new ServiceOrdersExport, 'ordenes-de-servicio.xlsx');
    }

    public function importServiceOrders(Request $request)
    {
        $request->validate(['file' => 'required|mimes:xlsx,xls']);
        Excel::import(new ServiceOrdersImport, $request->file('file'));
        return redirect()->route('service-orders.index')->with('success', 'Órdenes de servicio importadas con éxito.');
    }
}