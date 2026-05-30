<?php

namespace App\Http\Controllers\OnlineStore;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class OrderController extends Controller
{
    public function index(Request $request): Response
    {
        $user = Auth::user();
        $subscriptionId = $user->branch->subscription_id;

        $statusFilter = $request->input('status');

        $query = Order::with(['items', 'statusLogs'])
            ->where('subscription_id', $subscriptionId)
            ->when($statusFilter, fn($q) => $q->where('status', $statusFilter))
            ->latest();

        $orders = $query->paginate(20)->withQueryString();

        // Count by status for tabs
        $counts = Order::where('subscription_id', $subscriptionId)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        return Inertia::render('OnlineStore/Orders/Index', [
            'orders' => $orders,
            'filters' => $request->only(['status']),
            'statuses' => OrderStatus::cases(),
            'counts' => $counts,
        ]);
    }

    public function show(Order $order): Response
    {
        $this->authorizeOrder($order);

        $order->load(['items', 'statusLogs.user', 'storeConfig']);

        return Inertia::render('OnlineStore/Orders/Show', [
            'order' => $order,
            'allowedTransitions' => $order->status->allowedTransitions(),
        ]);
    }

    public function updateStatus(Request $request, Order $order): RedirectResponse
    {
        $this->authorizeOrder($order);

        $validated = $request->validate([
            'status' => ['required', Rule::enum(OrderStatus::class)],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $newStatus = OrderStatus::from($validated['status']);
        $oldStatus = $order->status;

        // Validate transition is allowed
        if (!in_array($newStatus, $oldStatus->allowedTransitions())) {
            return back()->with('error', 'Invalid status transition.');
        }

        $order->update(['status' => $newStatus]);
        $order->logStatusChange($oldStatus, $newStatus, $validated['note'] ?? null, Auth::id());

        return back()->with('success', "Order status changed to '{$newStatus->label()}'.");
    }

    private function authorizeOrder(Order $order): void
    {
        $user = Auth::user();
        if ($order->subscription_id !== $user->branch->subscription_id) {
            abort(403);
        }
    }
}
