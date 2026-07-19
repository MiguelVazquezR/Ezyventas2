<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Billing\ApproveStampPurchaseAction;
use App\Actions\Billing\CreateManualStampAdjustmentAction;
use App\Actions\Billing\RejectStampPurchaseAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ApproveStampPurchaseRequest;
use App\Http\Requests\Admin\RejectStampPurchaseRequest;
use App\Http\Requests\Admin\StoreManualStampAdjustmentRequest;
use App\Jobs\Billing\ApplyStampsToPacJob;
use App\Models\Billing\FiscalProfile;
use App\Models\Billing\StampPurchase;
use App\Services\SW\SWUserService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class AdminStampPurchaseController extends Controller
{
    /**
     * Bandeja de revisión: list all stamp purchases awaiting review (bank transfers).
     */
    public function index(): Response
    {
        $purchases = StampPurchase::with([
                'fiscalProfile.subscription',
                'requestedBy',
            ])
            ->awaitingReview()
            ->latest()
            ->paginate(25);

        return Inertia::render('Admin/Stamps/Index', [
            'purchases' => $purchases,
        ]);
    }

    /**
     * Show a single stamp purchase detail (for viewing proof file).
     */
    public function show(StampPurchase $purchase): Response
    {
        $purchase->load(['fiscalProfile.subscription', 'requestedBy', 'reviewedBy']);

        return Inertia::render('Admin/Stamps/Show', [
            'purchase' => $purchase,
        ]);
    }

    /**
     * Approve a stamp purchase and dispatch the PAC application job.
     */
    public function approve(
        ApproveStampPurchaseRequest $request,
        StampPurchase $purchase,
        ApproveStampPurchaseAction $approveAction,
    ): RedirectResponse {
        $approveAction->execute($purchase, Auth::id());

        return back()->with('success', 'Compra aprobada. Los timbres se están acreditando al perfil fiscal.');
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
     * but whose PAC call failed.
     */
    public function retry(StampPurchase $purchase): RedirectResponse
    {
        // Only retry if approved but not yet applied
        if ($purchase->status->value !== 'approved') {
            return back()->with('error', 'Solo se puede reintentar compras en estado aprobado.');
        }

        if ($purchase->isStampsApplied()) {
            return back()->with('info', 'Los timbres ya fueron aplicados.');
        }

        ApplyStampsToPacJob::dispatch($purchase->id);

        return back()->with('success', 'Reintentando aplicar timbres al PAC. Los cambios se reflejarán en breve.');
    }

    /**
     * Create a manual adjustment (add or remove stamps) for a fiscal profile.
     */
    public function manualAdjustment(
        StoreManualStampAdjustmentRequest $request,
        CreateManualStampAdjustmentAction $adjustmentAction,
    ): RedirectResponse {
        $user = Auth::user();

        $data = $request->validated();
        $data['requested_by_user_id'] = $user->id;

        $purchase = $adjustmentAction->execute($data);

        $action = $data['adjustment_type'] === 'remove' ? 'retiraron' : 'agregaron';

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
}
