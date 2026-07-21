<?php

namespace App\Http\Controllers\Billing;

use App\Actions\Billing\CreateStampPurchaseAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\StampQuoteRequest;
use App\Http\Requests\Billing\StoreStampPurchaseRequest;
use App\Models\Billing\FiscalProfile;
use App\Services\Billing\StampPurchaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class StampPurchaseController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:stamps.purchase'),
        ];
    }

    /**
     * Quote the price for a given stamp quantity.
     * Called via fetch from the frontend for real-time price display.
     */
    public function quote(StampQuoteRequest $request, StampPurchaseService $stampPurchaseService): JsonResponse
    {
        $pricing = $stampPurchaseService->calculatePrice($request->integer('quantity'));

        return response()->json([
            'quantity'            => $request->integer('quantity'),
            'unit_price'          => $pricing['unit_price'],
            'amount_total'        => $pricing['amount_total'],
            'pricing_tier_label'  => $pricing['pricing_tier_label'],
            'base_unit_price'     => $pricing['base_unit_price'],
            'savings_amount'      => $pricing['savings_amount'],
            'savings_percentage'  => $pricing['savings_percentage'],
        ]);
    }

    /**
     * Store a new stamp purchase (Mercado Pago or bank transfer).
     */
    public function store(
        StoreStampPurchaseRequest $request,
        CreateStampPurchaseAction $createStampPurchaseAction,
        FiscalProfile $fiscalProfile,
    ): RedirectResponse {
        $user    = Auth::user();
        $data    = $request->validated();
        $data['fiscal_profile_id']    = $fiscalProfile->id;
        $data['requested_by_user_id'] = $user->id;

        // Handle proof file upload for bank transfers
        if ($request->hasFile('proof_file')) {
            $path = $request->file('proof_file')->store('stamp-purchase-proofs');
            $data['proof_file_path'] = $path;
        }

        try {
            $result = $createStampPurchaseAction->execute($data);
        } catch (\RuntimeException $e) {
            return redirect()->route('billing.fiscal-profiles.show', $fiscalProfile)
                ->with('error', $e->getMessage());
        }

        // For MercadoPago, redirect to the MP checkout
        if ($data['payment_method'] === 'mercadopago' && ! empty($result['mp_preference']['init_point'])) {
            return Inertia::location($result['mp_preference']['init_point']);
        }

        return redirect()->route('billing.fiscal-profiles.show', $fiscalProfile)
            ->with('success', 'Tu comprobante ha sido enviado. Un administrador lo revisará pronto.');
    }

    /**
     * Return from Mercado Pago checkout.
     * Handles both success and failure/cancellation.
     */
    public function return(FiscalProfile $fiscalProfile): RedirectResponse
    {
        $status = request()->query('status');
        $purchaseId = request()->query('preference_id');

        if ($status === 'approved') {
            return redirect()->route('billing.fiscal-profiles.show', $fiscalProfile)
                ->with('success', 'Pago aprobado. Los timbres se acreditarán en breve.');
        }

        if ($status === 'rejected') {
            return redirect()->route('billing.fiscal-profiles.show', $fiscalProfile)
                ->with('error', 'El pago fue rechazado. Intenta de nuevo.');
        }

        return redirect()->route('billing.fiscal-profiles.show', $fiscalProfile)
            ->with('warning', 'El proceso de pago fue cancelado o está pendiente.');
    }
}
