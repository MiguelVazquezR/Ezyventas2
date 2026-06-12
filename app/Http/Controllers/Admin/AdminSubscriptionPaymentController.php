<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Subscription\ApproveSubscriptionPaymentAction;
use App\Actions\Subscription\RejectSubscriptionPaymentAction;
use App\Enums\SubscriptionPaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\SubscriptionPayment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class AdminSubscriptionPaymentController extends Controller
{
    public function index()
    {
        $pendingPayments = SubscriptionPayment::with([
            'subscriptionVersion.subscription.branches'
        ])
            ->where('payment_method', 'transferencia')
            ->where('status', SubscriptionPaymentStatus::PENDING)
            ->latest()
            ->get();

        return Inertia::render('Admin/Payment/Index', [
            'pendingPayments' => $pendingPayments,
        ]);
    }

    public function show(SubscriptionPayment $payment)
    {
        $payment->load([
            'subscriptionVersion.subscription:id,commercial_name',
            'subscriptionVersion.items',
            'media'
        ]);

        $pendingVersion = $payment->subscriptionVersion;
        $subscription = $pendingVersion->subscription;

        $previousVersion = $subscription->versions()
            ->where('id', '!=', $pendingVersion->id)
            ->latest('id')
            ->with('items')
            ->first();

        $previousItemsMap = $previousVersion ? $previousVersion->items->keyBy('item_key') : collect();

        $processedItems = $pendingVersion->items->map(function ($newItem) use ($previousItemsMap) {
            $previousItem = $previousItemsMap->get($newItem->item_key);
            $previousQuantity = $previousItem ? $previousItem->quantity : 0;
            $newQuantity = $newItem->quantity;
            $status = 'unchanged';

            if (!$previousItem) {
                $status = 'new';
            } elseif ($newQuantity > $previousQuantity) {
                $status = 'upgraded';
            } elseif ($newQuantity < $previousQuantity && $previousQuantity > 0) {
                $status = 'downgraded';
            }

            return [
                'name' => $newItem->name,
                'quantity' => $newQuantity,
                'billing_period' => $newItem->billing_period,
                'unit_price' => $newItem->unit_price,
                'status' => $status,
                'previous_quantity' => $previousQuantity,
            ];
        });

        $proofUrl = $payment->hasMedia('proof_of_payment') 
            ? $payment->getFirstMedia('proof_of_payment')->getUrl() 
            : null;

        return Inertia::render('Admin/Payment/Show', [
            'payment' => $payment,
            'proofUrl' => $proofUrl,
            'processedItems' => $processedItems,
        ]);
    }

    public function approve(SubscriptionPayment $payment, ApproveSubscriptionPaymentAction $action)
    {
        if ($payment->status !== SubscriptionPaymentStatus::PENDING || $payment->payment_method !== 'transferencia') {
            return redirect()->route('admin.payments.index')->with('error', 'Este pago no se puede aprobar.');
        }

        try {
            $action->execute($payment);
            return redirect()->route('admin.payments.index')->with('success', 'Pago aprobado y suscripción activada.');
        } catch (\Exception $e) {
            Log::error("Error al aprobar pago: " . $e->getMessage());
            return redirect()->route('admin.payments.index')->with('error', 'Error al aprobar el pago: ' . $e->getMessage());
        }
    }

    public function reject(Request $request, SubscriptionPayment $payment, RejectSubscriptionPaymentAction $action)
    {
        $validated = $request->validate([
            'rejection_reason' => 'required|string|max:500',
        ]);

        if ($payment->status !== SubscriptionPaymentStatus::PENDING || $payment->payment_method !== 'transferencia') {
            return redirect()->route('admin.payments.index')->with('error', 'Este pago no se puede rechazar.');
        }

        try {
            $action->execute($payment, $validated['rejection_reason']);
            return redirect()->route('admin.payments.index')->with('success', 'Pago rechazado exitosamente.');
        } catch (\Exception $e) {
            Log::error("Error al rechazar pago: " . $e->getMessage());
            return redirect()->route('admin.payments.index')->with('error', 'Error al rechazar el pago: ' . $e->getMessage());
        }
    }
}