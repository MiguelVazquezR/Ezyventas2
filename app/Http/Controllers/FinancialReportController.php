<?php

namespace App\Http\Controllers;

use App\Exports\FinancialReportExport;
use App\Services\FinancialReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Carbon\Carbon;
use Maatwebsite\Excel\Facades\Excel;

class FinancialReportController extends Controller
{
    public function index(Request $request): Response
    {
        $user = Auth::user();
        $branchId = $user->branch_id;

        $startDate = $request->input('start_date') ? Carbon::parse($request->input('start_date')) : Carbon::today();
        $endDate = $request->input('end_date') ? Carbon::parse($request->input('end_date')) : Carbon::today();

        $reportService = new FinancialReportService($branchId, $startDate, $endDate);
        
        $reportData = $reportService->generateReportData();

        return Inertia::render('FinancialControl/Index', $reportData);
    }

    /**
     * Genera y descarga el reporte financiero en formato Excel.
     */
    public function export(Request $request)
    {
        $validated = $request->validate([
            'start_date' => 'required|date_format:Y-m-d',
            'end_date' => 'required|date_format:Y-m-d',
        ]);

        // --- CORRECCIÓN ---
        // Se ajusta la fecha de inicio al principio del día y la de fin, al final del día.
        // Esto asegura que la consulta `whereBetween` en columnas `datetime` incluya todo el rango.
        $startDate = Carbon::parse($validated['start_date'])->startOfDay();
        $endDate = Carbon::parse($validated['end_date'])->endOfDay();
        
        $branchId = Auth::user()->branch_id;

        $fileName = 'Reporte Financiero ' . $startDate->format('d-m-Y') . ' al ' . $endDate->format('d-m-Y') . '.xlsx';

        return Excel::download(new FinancialReportExport($branchId, $startDate, $endDate), $fileName);
    }
}