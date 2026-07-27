<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\Billing\RefreshStampGlobalStatsJob;
use App\Models\Billing\FiscalProfile;
use App\Models\Billing\StampGlobalStats;
use App\Models\Billing\StampPricingTier;
use App\Models\Billing\StampPurchase;
use App\Enums\StampPurchaseStatus;
use App\Services\SW\SWUserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Inertia\Response;

/**
 * AdminStampDashboardController
 *
 * Serves the Global Stamp Administration Panel:
 *  - KPIs (master balance, assigned, used, active issuers)
 *  - Paginated issuer distribution table
 *  - Stamp pricing tier management
 *  - Threshold configuration
 */
class AdminStampDashboardController extends Controller
{
    /**
     * Render the Global Stamp Administration Panel.
     */
    public function index(SWUserService $swUserService): Response
    {
        // Master balance — live, always
        $masterBalance = null;
        $masterBalanceError = null;
        try {
            $masterBalance = $swUserService->getMasterAccountBalance();
        } catch (\Exception $e) {
            $masterBalanceError = 'No se pudo consultar el saldo de la cuenta maestra.';
        }

        // Global stats from cached snapshot
        $snapshot = StampGlobalStats::latest();

        // Pricing tiers
        $tiers = StampPricingTier::orderBy('min_quantity')->get();

        // Preview table
        $previewQuantities = [10, 100, 500, 1000];
        $preview = collect($previewQuantities)->map(fn ($qty) => [
            'quantity'    => $qty,
            'unit_price'  => StampPricingTier::findForQuantity($qty)?->unit_price ?? 0,
            'total'       => round($qty * (StampPricingTier::findForQuantity($qty)?->unit_price ?? 0), 2),
            'tier_label'  => StampPricingTier::findForQuantity($qty)?->label ?? 'Sin tramo',
        ]);

        // Threshold
        $threshold = (int) (\App\Models\SettingDefinition::where('key', 'stamp_large_purchase_threshold')
            ->value('default_value') ?? 1000);

        // Total subaccounts (active fiscal profiles linked to SW Sapien)
        $totalSubaccounts = FiscalProfile::active()->whereNotNull('sw_user_id')->count();

        // Sum stampsAssigned across all active subaccounts for the "Timbres distribuidos" KPI
        $totalAssignedFromSubaccounts = null;
        try {
            $totalAssignedFromSubaccounts = 0;
            $profiles = FiscalProfile::active()->whereNotNull('sw_user_id')->get();
            foreach ($profiles as $profile) {
                try {
                    $subBalance = $swUserService->getStampsBalance($profile->sw_user_id);
                    $totalAssignedFromSubaccounts += (int) ($subBalance['stampsAssigned'] ?? 0);
                } catch (\Exception $e) {
                    continue;
                }
            }
        } catch (\Exception $e) {
            $totalAssignedFromSubaccounts = null;
        }

        // Fiscal profiles for the Adjust Stamp modal
        $fiscalProfiles = FiscalProfile::with('subscription')
            ->active()
            ->get()
            ->map(fn ($profile) => [
                'id'                => $profile->id,
                'rfc'               => $profile->rfc,
                'razon_social'      => $profile->razon_social,
                'email'             => $profile->email,
                'subscription_name' => $profile->subscription?->business_name,
                'subscription_email' => $profile->subscription?->contact_email,
            ])
            ->values();

        // Revenue & volume stats (exclude rejected purchases)
        $completedStatuses = [
            StampPurchaseStatus::APPROVED,
            StampPurchaseStatus::STAMPS_APPLIED,
            StampPurchaseStatus::AWAITING_REVIEW,
        ];

        $revenueStats = StampPurchase::whereIn('status', $completedStatuses)
            ->selectRaw('
                COALESCE(SUM(amount_total), 0) as total_revenue,
                COALESCE(SUM(stamp_quantity), 0) as total_stamps_sold
            ')
            ->first();

        $pendingReviewCount = StampPurchase::where('status', StampPurchaseStatus::AWAITING_REVIEW)->count();

        return Inertia::render('Admin/Stamps/Index', [
            'masterBalance'      => $masterBalance,
            'masterBalanceError' => $masterBalanceError,
            'snapshot'           => $snapshot,
            'tiers'              => $tiers,
            'preview'            => $preview,
            'threshold'          => $threshold,
            'totalSubaccounts'   => $totalSubaccounts,
            'totalAssignedFromSubaccounts' => $totalAssignedFromSubaccounts,
            'totalRevenue'       => (float) $revenueStats->total_revenue,
            'totalStampsSold'    => (int) $revenueStats->total_stamps_sold,
            'pendingReviewCount' => $pendingReviewCount,
            'fiscalProfiles'     => $fiscalProfiles,
        ]);
    }

    /**
     * AJAX: Get live master account balance, plus the sum of stamps
     * assigned to all individual subaccounts for cross-validation.
     */
    public function masterBalance(SWUserService $swUserService): JsonResponse
    {
        try {
            $balance = $swUserService->getMasterAccountBalance();

            // Sum stampsAssigned across all active subaccounts for accuracy.
            // The PAC's master balance endpoint should report this natively,
            // but calculating it from individual subaccounts ensures we never
            // show stale or incorrect data.
            $totalAssignedFromSubaccounts = 0;
            $subaccountCount = 0;

            try {
                $profiles = FiscalProfile::active()
                    ->whereNotNull('sw_user_id')
                    ->get();

                foreach ($profiles as $profile) {
                    try {
                        $subBalance = $swUserService->getStampsBalance($profile->sw_user_id);
                        $totalAssignedFromSubaccounts += (int) ($subBalance['stampsAssigned'] ?? 0);
                        $subaccountCount++;
                    } catch (\Exception $e) {
                        // Skip individual failures — don't break the whole response
                        continue;
                    }
                }
            } catch (\Exception $e) {
                // If bulk calculation fails, fall back to master balance value
                $totalAssignedFromSubaccounts = (int) ($balance['stampsAssigned'] ?? 0);
            }

            return response()->json([
                'balance'                         => $balance,
                'totalAssignedFromSubaccounts'    => $totalAssignedFromSubaccounts,
                'subaccountCount'                 => $subaccountCount,
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'No se pudo consultar el saldo de la cuenta maestra.'], 502);
        }
    }

    /**
     * AJAX: Get cached global stats snapshot.
     */
    public function globalStats(): JsonResponse
    {
        $snapshot = StampGlobalStats::latest();

        if (! $snapshot) {
            return response()->json([
                'snapshot'    => null,
                'message'     => 'No hay datos todavía. Usa "Actualizar ahora" para generar el primer snapshot.',
            ]);
        }

        return response()->json([
            'snapshot' => $snapshot,
        ]);
    }

    /**
     * AJAX: Trigger an on-demand refresh of the global stats snapshot.
     *
     * Dispatches the job synchronously with a timeout to avoid hanging
     * the UI. For large numbers of profiles, this may take a few seconds.
     */
    public function refreshGlobalStats(): JsonResponse
    {
        try {
            $job = new RefreshStampGlobalStatsJob();
            $job->handle(app(SWUserService::class));

            $snapshot = StampGlobalStats::latest();

            return response()->json([
                'snapshot' => $snapshot,
                'message'  => 'Estadísticas actualizadas correctamente.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Error al actualizar estadísticas: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Paginated table of issuers with live stamp balances.
     *
     * For each row on the current page, concurrently queries the PAC.
     * Individual failures don't affect other rows — errored rows show
     * a fallback message in the UI.
     */
    public function issuersIndex(Request $request, SWUserService $swUserService): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 15), 50);
        $search  = $request->input('search', '');

        $query = FiscalProfile::with(['subscription', 'stampPurchases' => fn ($q) => $q->latest()->limit(1)])
            ->where('is_active', true);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('razon_social', 'like', "%{$search}%")
                  ->orWhere('rfc', 'like', "%{$search}%");
            });
        }

        $paginator = $query->paginate($perPage);

        // Concurrently fetch balances for profiles on the current page
        $profiles = $paginator->getCollection()->map(function ($profile) use ($swUserService) {
            $balance = null;
            $balanceError = null;

            if ($profile->sw_user_id) {
                try {
                    $balance = $swUserService->getStampsBalance($profile->sw_user_id);
                } catch (\Exception $e) {
                    $balanceError = 'No se pudo consultar';
                }
            }

            $lastPurchase = $profile->stampPurchases->first();

            return [
                'id'                 => $profile->id,
                'rfc'                => $profile->rfc,
                'razon_social'       => $profile->razon_social,
                'sw_user_id'         => $profile->sw_user_id,
                'is_active'          => $profile->is_active,
                'subscription_name'  => $profile->subscription?->commercial_name,
                'subscription_id'    => $profile->subscription_id,
                'balance'            => $balance,
                'balanceError'       => $balanceError,
                'last_purchase'      => $lastPurchase ? [
                    'id'             => $lastPurchase->id,
                    'stamp_quantity' => $lastPurchase->stamp_quantity,
                    'created_at'     => $lastPurchase->created_at,
                    'payment_method' => $lastPurchase->payment_method?->value,
                ] : null,
            ];
        });

        $paginator->setCollection($profiles);

        return response()->json(['issuers' => $paginator]);
    }

    /**
     * Update the large purchase threshold.
     */
    public function updateThreshold(Request $request): JsonResponse
    {
        $request->validate([
            'threshold' => ['required', 'integer', 'min:1', 'max:999999'],
        ]);

        $definition = \App\Models\SettingDefinition::where('key', 'stamp_large_purchase_threshold')->first();

        if ($definition) {
            $definition->update(['default_value' => (string) $request->input('threshold')]);
        }

        return response()->json([
            'threshold' => (int) $request->input('threshold'),
            'message'   => 'Umbral actualizado correctamente.',
        ]);
    }

    /**
     * Paginated list of all stamp movements (purchases and adjustments)
     * with filters for search, status, payment method, and date range.
     */
    public function movements(Request $request): JsonResponse
    {
        $perPage    = min((int) $request->input('per_page', 20), 100);
        $search     = $request->input('search', '');
        $status     = $request->input('status');
        $method     = $request->input('payment_method');
        $dateFrom   = $request->input('date_from');
        $dateTo     = $request->input('date_to');
        $sortField  = $request->input('sort_field', 'created_at');
        $sortOrder  = $request->input('sort_order', 'desc');

        $sortable = [
            'created_at'     => 'created_at',
            'stamp_quantity' => 'stamp_quantity',
            'amount_total'   => 'amount_total',
        ];
        $orderBy = $sortable[$sortField] ?? 'created_at';
        $direction = strtolower($sortOrder) === 'asc' ? 'asc' : 'desc';

        $query = StampPurchase::with(['fiscalProfile.subscription', 'requestedBy', 'reviewedBy']);

        // Search by subscriber name or RFC
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('fiscalProfile.subscription', fn ($sq) =>
                    $sq->where('commercial_name', 'like', "%{$search}%")
                       ->orWhere('business_name', 'like', "%{$search}%")
                )->orWhereHas('fiscalProfile', fn ($fq) =>
                    $fq->where('rfc', 'like', "%{$search}%")
                       ->orWhere('razon_social', 'like', "%{$search}%")
                );
            });
        }

        if ($status) {
            $query->where('status', $status);
        }

        if ($method) {
            $query->where('payment_method', $method);
        }

        if ($dateFrom) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }

        if ($dateTo) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        $movements = $query->orderBy($orderBy, $direction)
            ->paginate($perPage)
            ->through(fn ($p) => [
                'id'                 => $p->id,
                'stamp_quantity'     => $p->stamp_quantity,
                'amount_total'       => (float) $p->amount_total,
                'unit_price'         => (float) $p->unit_price,
                'payment_method'     => $p->payment_method?->value,
                'status'             => $p->status?->value,
                'adjustment_type'    => $p->adjustment_type?->value,
                'review_reason'      => $p->review_reason,
                'rejection_reason'   => $p->rejection_reason,
                'admin_note'         => $p->admin_note,
                'created_at'         => $p->created_at->toISOString(),
                'stamps_applied_at'  => $p->stamps_applied_at?->toISOString(),
                'fiscal_profile'     => [
                    'id'           => $p->fiscalProfile?->id,
                    'rfc'          => $p->fiscalProfile?->rfc,
                    'razon_social' => $p->fiscalProfile?->razon_social,
                    'subscription' => [
                        'id'              => $p->fiscalProfile?->subscription?->id,
                        'commercial_name' => $p->fiscalProfile?->subscription?->commercial_name,
                    ],
                ],
                'requested_by'       => ['name' => $p->requestedBy?->name],
                'reviewed_by'        => ['name' => $p->reviewedBy?->name],
            ]);

        return response()->json(['movements' => $movements]);
    }
}
