<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Enums\SubscriptionPaymentStatus;
use App\Models\ReferralUsage;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Carbon\Carbon;

class ReportController extends Controller
{
    /**
     * Muestra el panel de métricas generales y gráficos para el Superadmin.
     */
    public function index(Request $request)
    {
        $now = Carbon::now();
        
        // 1. Manejo del periodo (Por defecto: Mes en curso)
        $startDate = $request->input('start_date') 
            ? Carbon::parse($request->input('start_date'))->startOfDay() 
            : $now->copy()->startOfMonth();
            
        $endDate = $request->input('end_date') 
            ? Carbon::parse($request->input('end_date'))->endOfDay() 
            : $now->copy()->endOfDay();

        // 2. Métricas Generales (KPIs)
        $totalRevenue = SubscriptionPayment::where('status', SubscriptionPaymentStatus::APPROVED)
            ->sum('amount');

        $periodRevenue = SubscriptionPayment::where('status', SubscriptionPaymentStatus::APPROVED)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->sum('amount');

        // Las suscripciones activas son un "estado actual" global, no filtrado por periodo
        $activeSubscriptionsCount = Subscription::whereHas('versions', function($q) {
            $q->where('end_date', '>=', now()->startOfDay());
        })->count();

        $newSubscriptionsCount = Subscription::whereBetween('created_at', [$startDate, $endDate])->count();

        // 2.5. Métricas de referidos
        $referralDiscountsGiven = SubscriptionPayment::where('status', SubscriptionPaymentStatus::APPROVED)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->whereNotNull('referral_discount_amount')
            ->sum('referral_discount_amount');

        $periodGrossRevenue = $periodRevenue + $referralDiscountsGiven;

        $activeReferrals = ReferralUsage::where('reward_status', 'pending')
            ->whereHas('referredSubscription', fn($q) => $q->whereHas('versions', fn($v) => $v->where('end_date', '>=', now()->startOfDay())))
            ->count();

        $totalReferralRewards = (float) ReferralUsage::where('reward_status', 'paid')->sum('reward_amount');
        $pendingReferralRewards = (float) ReferralUsage::where('reward_status', 'pending')->sum('reward_amount');

        // 3. Preparación de datos para la Telemetría Gráfica (Evolución diaria)
        $periodDays = [];
        $currentDate = $startDate->copy();
        
        // Inicializamos los días en 0 para evitar "huecos" en la gráfica
        while ($currentDate <= $endDate) {
            $periodDays[$currentDate->format('Y-m-d')] = [
                'date' => $currentDate->translatedFormat('d M'), // Ej: 15 Abr
                'revenue' => 0,
                'new_subs' => 0
            ];
            $currentDate->addDay();
        }

        // Consultamos ingresos agrupados por día
        $dailyRevenue = SubscriptionPayment::where('status', SubscriptionPaymentStatus::APPROVED)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw('DATE(created_at) as date, SUM(amount) as total')
            ->groupBy('date')
            ->get();

        foreach ($dailyRevenue as $day) {
            if (isset($periodDays[$day->date])) {
                $periodDays[$day->date]['revenue'] = (float) $day->total;
            }
        }

        // Consultamos nuevas suscripciones agrupadas por día
        $dailySubs = Subscription::whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw('DATE(created_at) as date, COUNT(*) as total')
            ->groupBy('date')
            ->get();

        foreach ($dailySubs as $day) {
            if (isset($periodDays[$day->date])) {
                $periodDays[$day->date]['new_subs'] = (int) $day->total;
            }
        }

        // 4. Retorno a la vista
        return Inertia::render('Admin/Reports/Index', [
            'metrics' => [
                'totalRevenue' => (float) $totalRevenue,
                'periodRevenue' => (float) $periodRevenue,
                'periodGrossRevenue' => (float) $periodGrossRevenue,
                'referralDiscountsGiven' => (float) $referralDiscountsGiven,
                'activeReferrals' => $activeReferrals,
                'totalReferralRewards' => $totalReferralRewards,
                'pendingReferralRewards' => $pendingReferralRewards,
                'activeSubscriptions' => $activeSubscriptionsCount,
                'newSubscriptions' => $newSubscriptionsCount,
            ],
            'chartData' => array_values($periodDays),
            'filters' => [
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d'),
            ]
        ]);
    }
}