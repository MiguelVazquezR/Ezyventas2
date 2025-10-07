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
        // --- MODIFICADO: Ahora se usa el ID de la sucursal ---
        $branchId = $user->branch_id;

        $startDate = $request->input('start_date') ? Carbon::parse($request->input('start_date')) : Carbon::today();
        $endDate = $request->input('end_date') ? Carbon::parse($request->input('end_date')) : Carbon::today();

        // Se instancia el servicio con el ID de la sucursal y las fechas
        $reportService = new FinancialReportService($branchId, $startDate, $endDate);
        
        // Se generan todos los datos con un solo método
        $reportData = $reportService->generateReportData();

        return Inertia::render('FinancialControl/Index', $reportData);
    }

    public function export(Request $request)
    {
        $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        $branchId = Auth::user()->branch_id;
        $startDate = Carbon::parse($request->input('start_date'));
        $endDate = Carbon::parse($request->input('end_date'));

        $fileName = "ReporteFinanciero_{$startDate->format('Y-m-d')}_a_{$endDate->format('Y-m-d')}.xlsx";

        return Excel::download(new FinancialReportExport($branchId, $startDate, $endDate), $fileName);
    }
}