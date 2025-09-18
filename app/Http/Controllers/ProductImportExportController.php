<?php

namespace App\Http\Controllers;

use App\Exports\ProductsExport;
use App\Imports\ProductsImport;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class ProductImportExportController extends Controller
{
    /**
     * Exporta los productos del suscriptor a un archivo Excel.
     */
    public function export()
    {
        // El constructor de ProductsExport se encargará de filtrar los productos
        return Excel::download(new ProductsExport, 'productos.xlsx');
    }

    /**
     * Importa productos desde un archivo Excel.
     */
    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:xlsx,xls'
        ]);

        Excel::import(new ProductsImport, $request->file('file'));

        return redirect()->route('products.index')->with('success', 'Productos importados con éxito.');
    }
}