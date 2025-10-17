<?php

namespace App\Http\Controllers;

use App\Exports\FinancialReportExport;
use App\Models\BankAccount;
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

        // AÑADIDO: Cargar todas las cuentas de la suscripción para el modal de transferencias
        $subscriptionId = $user->branch->subscription_id;
        $allBankAccounts = BankAccount::where('subscription_id', $subscriptionId)->get();
        $reportData['allBankAccounts'] = $allBankAccounts;


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

        $startDate = Carbon::parse($validated['start_date'])->startOfDay();
        $endDate = Carbon::parse($validated['end_date'])->endOfDay();
        
        $branchId = Auth::user()->branch_id;

        $fileName = 'Reporte Financiero ' . $startDate->format('d-m-Y') . ' al ' . $endDate->format('d-m-Y') . '.xlsx';

        return Excel::download(new FinancialReportExport($branchId, $startDate, $endDate), $fileName);
    }
}