<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Billing\ApproveStampPurchaseAction;
use App\Actions\Billing\CreateManualStampAdjustmentAction;
use App\Actions\Billing\RejectStampPurchaseAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ApproveStampPurchaseRequest;
use App\Http\Requests\Admin\RejectStampPurchaseRequest;
use App\Http\Requests\Admin\StoreManualStampAdjustmentRequest;
use App\Models\Billing\FiscalProfile;
use App\Models\Billing\Invoice;
use App\Models\Billing\StampPurchase;
use App\Services\Billing\StampPurchaseService;
use App\Services\SW\SWUserService;
use App\Enums\StampPaymentMethod;
use App\Enums\StampPurchaseStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class AdminStampPurchaseController extends Controller
{
    /**
     * Bandeja de revisión: list all stamp purchases awaiting review (bank transfers).
     */
    public function index(Request $request): Response
    {
        $perPage = (int) $request->integer('rows', 25) ?: 25;
        $sortField = $request->input('sortField', 'created_at');
        $sortOrder = $request->input('sortOrder', 'desc');

        // Map sortField to actual DB columns
        $sortable = [
            'stamp_quantity' => 'stamp_quantity',
            'amount_total'   => 'amount_total',
            'created_at'     => 'created_at',
        ];
        $orderBy = $sortable[$sortField] ?? 'created_at';
        $direction = strtolower($sortOrder) === 'asc' ? 'asc' : 'desc';

        $purchases = StampPurchase::with([
                'fiscalProfile.subscription',
                'requestedBy',
            ])
            ->awaitingReview()
            ->orderBy($orderBy, $direction)
            ->paginate($perPage);

        return Inertia::render('Admin/Stamps/ReviewQueue', [
            'purchases' => $purchases,
        ]);
    }

    /**
     * Approve a stamp purchase and dispatch the PAC application job.
     */
    public function approve(
        ApproveStampPurchaseRequest $request,
        StampPurchase $purchase,
        ApproveStampPurchaseAction $approveAction,
        StampPurchaseService $stampPurchaseService,
    ): RedirectResponse {
        // Guard: don't allow re-approval of already-approved purchases.
        if ($purchase->status === StampPurchaseStatus::APPROVED || $purchase->isStampsApplied()) {
            return back()->with('info', 'Esta compra ya fue aprobada.');
        }

        // Check master account balance BEFORE approving — block if insufficient.
        // The PAC will reject the assignment if the dealer lacks stamps, so we
        // prevent the approval upfront rather than letting the async job fail.
        $balanceCheck = $stampPurchaseService->checkMasterBalance($purchase->stamp_quantity);

        if (! $balanceCheck['sufficient']) {
            return back()->with(
                'error',
                "No puedes aprobar esta compra. Tu cuenta maestra tiene {$balanceCheck['stampsBalance']} timbres disponibles "
                . "y esta compra requiere {$purchase->stamp_quantity}. Recarga tu cuenta maestra en el portal de SW "
                . "antes de aprobar."
            );
        }

        try {
            $approveAction->execute($purchase, Auth::id());
        } catch (\RuntimeException $e) {
            return back()->with(
                'error',
                'No se pudieron acreditar los timbres al perfil fiscal. ' . $e->getMessage()
            );
        }

        return back()->with('success', 'Compra aprobada. Los timbres se acreditaron al perfil fiscal.');
    }

    /**
     * Reject a stamp purchase with a mandatory reason.
     */
    public function reject(
        RejectStampPurchaseRequest $request,
        StampPurchase $purchase,
        RejectStampPurchaseAction $rejectAction,
    ): RedirectResponse {
        $rejectAction->execute(
            $purchase,
            Auth::id(),
            $request->validated('rejection_reason'),
        );

        return back()->with('success', 'Compra rechazada correctamente.');
    }

    /**
     * Retry applying stamps to PAC for a purchase that was approved
     * but whose PAC call failed (approved or failed status).
     */
    public function retry(
        StampPurchase $purchase,
        StampPurchaseService $stampPurchaseService,
    ): RedirectResponse {
        $allowedStatuses = [StampPurchaseStatus::APPROVED, StampPurchaseStatus::FAILED];

        if (! in_array($purchase->status, $allowedStatuses, true)) {
            return back()->with('error', 'Solo se puede reintentar compras en estado aprobado o fallido.');
        }

        if ($purchase->isStampsApplied()) {
            return back()->with('info', 'Los timbres ya fueron aplicados.');
        }

        try {
            $stampPurchaseService->applyStampsToPac($purchase);
        } catch (\RuntimeException $e) {
            return back()->with(
                'error',
                'No se pudieron aplicar los timbres al perfil fiscal. ' . $e->getMessage()
            );
        }

        return back()->with('success', 'Timbers aplicados exitosamente al perfil fiscal.');
    }

    /**
     * Create a manual adjustment or process a stamp purchase for a fiscal profile.
     *
     * Two modes:
     *  - 'manual'   : add/remove stamps directly (existing behavior).
     *  - 'purchase' : sell stamps with payment proof, pricing-tier calculation,
     *                 file upload, and master-balance deduction.
     */
    public function manualAdjustment(
        StoreManualStampAdjustmentRequest $request,
        CreateManualStampAdjustmentAction $adjustmentAction,
        StampPurchaseService $stampPurchaseService,
    ): RedirectResponse {
        $user = Auth::user();
        $data = $request->validated();
        $mode = $data['mode'] ?? 'manual';
        $stampQuantity = (int) $data['stamp_quantity'];

        // ── Mode: purchase ──────────────────────────────────────
        if ($mode === 'purchase') {
            // Check master balance
            $balanceCheck = $stampPurchaseService->checkMasterBalance($stampQuantity, false);

            if (! $balanceCheck['sufficient']) {
                return back()->with(
                    'error',
                    "Tu cuenta maestra tiene {$balanceCheck['stampsBalance']} timbres disponibles "
                    . "y esta operación requiere {$stampQuantity}. Recarga tu cuenta maestra en el portal de SW "
                    . "antes de intentar de nuevo."
                );
            }
            // Recalculate price server-side for security
            $price = $stampPurchaseService->calculatePrice($stampQuantity);

            if (! $price['pricing_tier_id']) {
                return back()->with('error', 'No hay un tramo de precio configurado para esta cantidad.');
            }

            // Store the proof file
            $proofPath = null;
            if ($request->hasFile('proof_file')) {
                $proofPath = $request->file('proof_file')
                    ->store('comprobantes', 'public');
            }

            $purchase = StampPurchase::create([
                'fiscal_profile_id'    => $data['fiscal_profile_id'],
                'requested_by_user_id' => $user->id,
                'stamp_quantity'       => $stampQuantity,
                'unit_price'           => $price['unit_price'],
                'amount_total'         => $price['amount_total'],
                'pricing_tier_id'      => $price['pricing_tier_id'],
                'payment_method'       => StampPaymentMethod::BANK_TRANSFER,
                'status'               => StampPurchaseStatus::APPROVED,
                'admin_note'           => 'Compra de timbres ingresada por administrador',
                'proof_file_path'      => $proofPath,
                'proof_uploaded_at'    => now(),
                'reviewed_by_user_id'  => $user->id,
                'reviewed_at'          => now(),
            ]);

            // Apply stamps to PAC synchronously so the admin sees immediate results.
            try {
                $stampPurchaseService->applyStampsToPac($purchase);
            } catch (\RuntimeException $e) {
                return back()->with(
                    'error',
                    'No se pudieron acreditar los timbres al perfil fiscal. ' . $e->getMessage()
                );
            }

            return back()->with(
                'success',
                "Compra de {$stampQuantity} timbres registrada exitosamente por "
                . '$' . number_format($price['amount_total'], 2) . ' MXN. '
                . 'Los timbres se acreditaron al perfil fiscal.'
            );
        }

        // ── Mode: manual (existing behavior) ────────────────────
        $data['requested_by_user_id'] = $user->id;
        $isRemoval = ($data['adjustment_type'] ?? 'add') === 'remove';
        $stampQuantity = (int) $data['stamp_quantity'];

        // Block "add" adjustments if master balance is insufficient.
        if (! $isRemoval) {
            $balanceCheck = $stampPurchaseService->checkMasterBalance($stampQuantity, false);

            if (! $balanceCheck['sufficient']) {
                return back()->with(
                    'error',
                    "Tu cuenta maestra tiene {$balanceCheck['stampsBalance']} timbres disponibles "
                    . "y este ajuste requiere {$stampQuantity}. Recarga tu cuenta maestra en el portal de SW "
                    . "antes de intentar de nuevo."
                );
            }
        }

        try {
            $purchase = $adjustmentAction->execute($data);
        } catch (\RuntimeException $e) {
            return back()->with(
                'error',
                'No se pudieron aplicar los timbres al perfil fiscal. ' . $e->getMessage()
            );
        }

        $action = $isRemoval ? 'retiraron' : 'agregaron';

        return back()->with(
            'success',
            "Se {$action} {$purchase->stamp_quantity} timbres al perfil fiscal. Los cambios se reflejarán en breve."
        );
    }

    /**
     * Get live stamp balance for a fiscal profile (AJAX).
     */
    public function balance(FiscalProfile $fiscalProfile, SWUserService $swUserService): \Illuminate\Http\JsonResponse
    {
        if (! $fiscalProfile->sw_user_id) {
            return response()->json(['error' => 'Este perfil no tiene subcuenta PAC.'], 400);
        }

        try {
            $balance = $swUserService->getStampsBalance($fiscalProfile->sw_user_id);

            return response()->json(['balance' => $balance]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'No se pudo consultar el saldo en este momento.'], 502);
        }
    }

    /**
     * Show the stamp movement history for a single fiscal profile.
     *
     * Combines StampPurchase records (acquisitions & adjustments) with
     * stamped invoices (consumption) into a unified chronological list
     * with running balance anchored to the PAC live balance.
     */
    public function history(
        Request $request,
        FiscalProfile $fiscalProfile,
        SWUserService $swUserService,
    ): Response {
        $fiscalProfile->load('subscription');

        $startDate = $request->input('start_date');
        $endDate   = $request->input('end_date');

        // ── Get the live PAC balance as the anchor ──────────────
        $currentBalance = null;
        $balanceError   = null;

        if ($fiscalProfile->sw_user_id) {
            try {
                $pacData        = $swUserService->getStampsBalance($fiscalProfile->sw_user_id);
                $currentBalance = (int) ($pacData['stampsBalance'] ?? 0);
            } catch (\Exception $e) {
                $balanceError = 'No se pudo consultar el saldo actual del PAC.';
            }
        }

        // ── Build ALL movements (unfiltered) to compute the initial balance ──
        $allPurchases = $fiscalProfile->stampPurchases()
            ->orderBy('created_at')
            ->get();

        $firstPurchaseId = $allPurchases->first()?->id;

        // ── Stamped invoices (consumption) — all, for anchor calculation ──
        $allInvoices = $fiscalProfile->invoices()
            ->whereNotNull('fecha_timbrado')
            ->orderBy('fecha_timbrado')
            ->get();

        // Total net change from ALL movements
        $totalEntradas = 0;
        $totalSalidas  = 0;

        foreach ($allPurchases as $p) {
            $isAdd    = $p->isManualAdjustment() && $p->adjustment_type?->value === 'add';
            $isRemove = $p->isManualAdjustment() && $p->adjustment_type?->value === 'remove';
            $totalEntradas += $isAdd ? $p->stamp_quantity : ($p->isManualAdjustment() ? 0 : $p->stamp_quantity);
            $totalSalidas  += $isRemove ? $p->stamp_quantity : 0;
        }

        $totalSalidas += $allInvoices->count(); // each stamped invoice = 1 stamp consumed

        // Initial balance = current PAC balance - total net change
        $initialBalance = $currentBalance !== null
            ? max(0, $currentBalance - $totalEntradas + $totalSalidas)
            : 0;

        // ── Filter purchases by date range ──────────────────────
        $purchases = $allPurchases
            ->filter(function (StampPurchase $p) use ($startDate, $endDate) {
                if ($startDate && $p->created_at->lt($startDate.' 00:00:00')) return false;
                if ($endDate   && $p->created_at->gt($endDate.' 23:59:59'))   return false;
                return true;
            })
            ->values()
            ->map(function (StampPurchase $p) use ($firstPurchaseId) {
                $isAdd    = $p->isManualAdjustment() && $p->adjustment_type?->value === 'add';
                $isRemove = $p->isManualAdjustment() && $p->adjustment_type?->value === 'remove';
                $isFirst  = $p->id === $firstPurchaseId;

                $description = match (true) {
                    $isFirst                => 'Depósito por apertura de cuenta',
                    $p->isMercadoPago()     => 'Compra por Mercado Pago',
                    $p->isBankTransfer()    => 'Compra por transferencia bancaria',
                    $isAdd                  => 'Adición manual',
                    $isRemove               => 'Retiro manual',
                    default                 => 'Movimiento de timbres',
                };

                if ($p->admin_note && ! $isFirst) {
                    $description .= " — {$p->admin_note}";
                }

                return [
                    'date'        => $p->created_at->format('Y-m-d\TH:i:s'),
                    'description' => $description,
                    'entrada'     => $isAdd ? $p->stamp_quantity : ($p->isManualAdjustment() ? 0 : $p->stamp_quantity),
                    'salida'      => $isRemove ? $p->stamp_quantity : 0,
                    'type'        => 'purchase',
                ];
            });

        // ── Filter invoices by date range ───────────────────────
        $invoices = $allInvoices
            ->filter(function (Invoice $inv) use ($startDate, $endDate) {
                if ($startDate && $inv->fecha_timbrado->lt($startDate.' 00:00:00')) return false;
                if ($endDate   && $inv->fecha_timbrado->gt($endDate.' 23:59:59'))   return false;
                return true;
            })
            ->values()
            ->map(function (Invoice $inv) {
                $folio = $inv->series && $inv->folio ? "{$inv->series}{$inv->folio}" : null;

                return [
                    'date'        => $inv->fecha_timbrado->format('Y-m-d\TH:i:s'),
                    'description' => $folio
                        ? "Timbrado de factura {$folio}"
                        : 'Timbrado de factura',
                    'entrada'     => 0,
                    'salida'      => 1,
                    'type'        => 'invoice',
                ];
            });

        // ── Merge & sort chronologically (oldest → newest) ─────
        $movements = collect()
            ->merge($purchases)
            ->merge($invoices)
            ->sortBy('date')
            ->values();

        // ── Compute running balance anchored to PAC ────────────
        $running = $initialBalance;
        $movements = $movements->map(function (array $m) use (&$running) {
            $running += $m['entrada'] - $m['salida'];
            $m['saldo'] = $running;
            return $m;
        });

        // Fiscal profiles list for the Adjust Stamp modal
        $allProfiles = FiscalProfile::with('subscription')
            ->active()
            ->get()
            ->map(fn ($p) => [
                'id'                 => $p->id,
                'rfc'                => $p->rfc,
                'razon_social'       => $p->razon_social,
                'email'              => $p->email,
                'subscription_name'  => $p->subscription?->business_name,
                'subscription_email' => $p->subscription?->contact_email,
            ])
            ->values();

        // Pricing tiers for the modal
        $tiers = \App\Models\Billing\StampPricingTier::orderBy('min_quantity')->get();

        return Inertia::render('Admin/Stamps/SubaccountHistory', [
            'fiscalProfile'  => $fiscalProfile,
            'fiscalProfiles' => $allProfiles,
            'tiers'          => $tiers,
            'movements'      => $movements,
            'currentBalance' => $currentBalance,
            'balanceError'   => $balanceError,
            'filters'        => [
                'start_date' => $startDate,
                'end_date'   => $endDate,
            ],
        ]);
    }
}
