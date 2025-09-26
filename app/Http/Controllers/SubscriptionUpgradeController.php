<?php

namespace App\Http\Controllers;

use App\Enums\BillingPeriod;
use App\Enums\InvoiceStatus;
use App\Models\PlanItem;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionVersion;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SubscriptionUpgradeController extends Controller
{
    /**
     * Muestra la página para mejorar la suscripción.
     */
    public function show(): Response
    {
        $user = Auth::user();
        if ($user->roles()->exists()) abort(403);

        $subscription = $user->branch->subscription;
        $currentVersion = $subscription->versions()->with('items')->latest()->first();
        
        // CAMBIO: Se envían TODOS los items del plan activos, no solo los que el usuario no tiene.
        $allPlanItems = PlanItem::where('is_active', true)->get();

        return Inertia::render('Subscription/Upgrade', [
            'subscription' => $subscription,
            'currentVersion' => $currentVersion,
            'allPlanItems' => $allPlanItems,
        ]);
    }

    /**
     * Procesa la mejora de la suscripción.
     */
    public function store(Request $request)
    {
        $user = Auth::user();
        if ($user->roles()->exists()) abort(403);

        $validated = $request->validate([
            'added_items' => 'required|array|min:1',
            'added_items.*.key' => 'required|exists:plan_items,key',
            'added_items.*.quantity' => 'required|integer|min:1',
        ]);

        $subscription = $user->branch->subscription;
        $currentVersion = $subscription->versions()->with('items')->latest()->first();

        $endDate = Carbon::parse($currentVersion->end_date);
        $remainingDays = now()->diffInDaysFiltered(fn(Carbon $date) => !$date->isWeekend(), $endDate);
        if ($remainingDays <= 0) {
            return redirect()->back()->with('error', 'No se puede mejorar un plan que está a punto de expirar.');
        }
        
        $totalProratedCost = 0;
        $newItemsData = [];
        $updatedItemsData = [];

        foreach ($validated['added_items'] as $item) {
            $planItem = PlanItem::where('key', $item['key'])->firstOrFail();
            $annualPrice = $planItem->monthly_price * 10;
            
            $unitAnnualPrice = $annualPrice;
            if ($planItem->type->value === 'limit' && $planItem->meta['quantity'] > 0) {
                $unitAnnualPrice = $annualPrice / $planItem->meta['quantity'];
            }
            
            $dailyPrice = $unitAnnualPrice / 260; 
            $proratedCost = $dailyPrice * $remainingDays * $item['quantity'];
            $totalProratedCost += $proratedCost;
            
            $existingItem = $currentVersion->items->firstWhere('item_key', $planItem->key);

            if ($planItem->type->value === 'module') {
                 $newItemsData[] = [ 'item_key' => $planItem->key, 'item_type' => $planItem->type->value, 'name' => $planItem->name, 'quantity' => 1, 'unit_price' => $annualPrice, 'billing_period' => BillingPeriod::ANNUALLY, ];
            } elseif ($planItem->type->value === 'limit') {
                $newQuantity = ($existingItem ? $existingItem->quantity : 0) + $item['quantity'];
                 $updatedItemsData[$planItem->key] = [ 'item_key' => $planItem->key, 'item_type' => $planItem->type->value, 'name' => $planItem->name, 'quantity' => $newQuantity, 'unit_price' => 0, 'billing_period' => BillingPeriod::ANNUALLY, ];
            }
        }

        DB::transaction(function () use ($subscription, $currentVersion, $newItemsData, $updatedItemsData, $totalProratedCost) {
            $newVersion = SubscriptionVersion::create(['subscription_id' => $subscription->id, 'start_date' => now(), 'end_date' => $currentVersion->end_date ]);
            foreach ($currentVersion->items as $item) {
                if (array_key_exists($item->item_key, $updatedItemsData)) {
                    $newVersion->items()->create($updatedItemsData[$item->item_key]);
                    unset($updatedItemsData[$item->item_key]);
                } else {
                    $newVersion->items()->create($item->only(['item_key', 'item_type', 'name', 'quantity', 'unit_price', 'billing_period']));
                }
            }
            foreach($updatedItemsData as $data) { $newVersion->items()->create($data); }
            foreach ($newItemsData as $data) { $newVersion->items()->create($data); }
            if ($totalProratedCost > 0) {
                SubscriptionPayment::create([ 'subscription_version_id' => $newVersion->id, 'amount' => $totalProratedCost, 'payment_method' => 'mejora', 'invoiced' => false, 'invoice_status' => InvoiceStatus::NOT_REQUESTED, ]);
            }
        });

        return redirect()->route('subscription.show')->with('success', '¡Tu plan ha sido mejorado con éxito!');
    }
}

