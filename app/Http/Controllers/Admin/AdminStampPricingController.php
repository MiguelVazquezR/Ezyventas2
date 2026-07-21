<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreStampPricingTierRequest;
use App\Http\Requests\Admin\UpdateStampPricingTierRequest;
use App\Models\Billing\StampPricingTier;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class AdminStampPricingController extends Controller
{
    /**
     * List all pricing tiers, ordered by min_quantity.
     */
    public function index(): Response
    {
        $tiers = StampPricingTier::orderBy('min_quantity')->get();

        // Build preview table: what 4 reference quantities would cost
        $previewQuantities = [10, 100, 500, 1000];
        $preview = collect($previewQuantities)->map(fn ($qty) => [
            'quantity'    => $qty,
            'unit_price'  => StampPricingTier::findForQuantity($qty)?->unit_price ?? 0,
            'total'       => round($qty * (StampPricingTier::findForQuantity($qty)?->unit_price ?? 0), 2),
            'tier_label'  => StampPricingTier::findForQuantity($qty)?->label ?? 'Sin tramo',
        ]);

        return Inertia::render('Admin/Stamps/Pricing', [
            'tiers'   => $tiers,
            'preview' => $preview,
        ]);
    }

    /**
     * Store a new pricing tier.
     */
    public function store(StoreStampPricingTierRequest $request): RedirectResponse
    {
        StampPricingTier::create($request->validated());

        return back()->with('success', 'Tramo de precio creado correctamente.');
    }

    /**
     * Update an existing pricing tier.
     */
    public function update(UpdateStampPricingTierRequest $request, StampPricingTier $tier): RedirectResponse
    {
        $tier->update($request->validated());

        return back()->with('success', 'Tramo de precio actualizado correctamente.');
    }

    /**
     * Delete a pricing tier.
     */
    public function destroy(StampPricingTier $tier): RedirectResponse
    {
        $tier->delete();

        return back()->with('success', 'Tramo de precio eliminado correctamente.');
    }
}
