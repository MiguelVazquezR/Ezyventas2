<?php

namespace App\Http\Controllers;

use App\Enums\ServiceOrderStatus;
use App\Http\Requests\StoreServiceOrderRequest;
use App\Http\Requests\UpdateServiceOrderRequest;
use App\Models\Customer;
use App\Models\CustomFieldDefinition;
use App\Models\ServiceOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ServiceOrderController extends Controller
{
    public function index(Request $request): Response
    {
        $user = Auth::user();
        $subscriptionId = $user->branch->subscription_id;

        $query = ServiceOrder::query()
            ->whereHas('branch.subscription', function ($q) use ($subscriptionId) {
                $q->where('id', $subscriptionId);
            })
            ->with('branch:id,name');

        if ($request->has('search')) {
            $searchTerm = $request->input('search');
            $query->where(function ($q) use ($searchTerm) {
                $q->where('customer_name', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('item_description', 'LIKE', "%{$searchTerm}%");
            });
        }

        $sortField = $request->input('sortField', 'received_at');
        $sortOrder = $request->input('sortOrder', 'desc');
        $query->orderBy($sortField, $sortOrder);

        $serviceOrders = $query->paginate($request->input('rows', 20))->withQueryString();

        return Inertia::render('ServiceOrder/Index', [
            'serviceOrders' => $serviceOrders,
            'filters' => $request->only(['search', 'sortField', 'sortOrder']),
        ]);
    }

    public function show(ServiceOrder $serviceOrder): Response
    {
        $serviceOrder->load(['branch', 'user', 'items.itemable', 'activities.causer']);

        $translations = config('log_translations.ServiceOrder', []);

        $formattedActivities = $serviceOrder->activities->map(function ($activity) use ($translations) {
            $changes = ['before' => [], 'after' => []];
            if (isset($activity->properties['old'])) {
                foreach ($activity->properties['old'] as $key => $value) {
                    $changes['before'][($translations[$key] ?? $key)] = $value;
                }
            }
            if (isset($activity->properties['attributes'])) {
                foreach ($activity->properties['attributes'] as $key => $value) {
                    $changes['after'][($translations[$key] ?? $key)] = $value;
                }
            }
            return [
                'id' => $activity->id,
                'description' => $activity->description,
                'event' => $activity->event,
                'causer' => $activity->causer ? $activity->causer->name : 'Sistema',
                'timestamp' => $activity->created_at->diffForHumans(),
                'changes' => $changes,
            ];
        });

        return Inertia::render('ServiceOrder/Show', [
            'serviceOrder' => $serviceOrder,
            'activities' => $formattedActivities,
        ]);
    }

    public function create(): Response
    {
        $user = Auth::user();
        $subscriptionId = $user->branch->subscription_id;

        $customFields = CustomFieldDefinition::where('subscription_id', $subscriptionId)
            ->where('module', 'service_orders')
            ->get();

        // Pasar la lista de clientes a la vista
        $customers = Customer::whereHas('branch.subscription', function ($q) use ($subscriptionId) {
            $q->where('id', $subscriptionId);
        })->get(['id', 'name', 'phone']);

        return Inertia::render('ServiceOrder/Create', [
            'customFieldDefinitions' => $customFields,
            'customers' => $customers,
        ]);
    }

    public function store(StoreServiceOrderRequest $request)
    {
        ServiceOrder::create(array_merge($request->validated(), [
            'user_id' => Auth::id(),
            'branch_id' => Auth::user()->branch_id,
            'status' => ServiceOrderStatus::PENDING,
        ]));
        return redirect()->route('service-orders.index')->with('success', 'Orden de servicio creada.');
    }

    public function edit(ServiceOrder $serviceOrder): Response
    {
        $user = Auth::user();
        $subscriptionId = $user->branch->subscription_id;

        $customFields = CustomFieldDefinition::where('subscription_id', $subscriptionId)
            ->where('module', 'service_orders')
            ->get();

        $customers = Customer::whereHas('branch.subscription', function ($q) use ($subscriptionId) {
            $q->where('id', $subscriptionId);
        })->get(['id', 'name', 'phone']);

        return Inertia::render('ServiceOrder/Edit', [
            'serviceOrder' => $serviceOrder,
            'customFieldDefinitions' => $customFields,
            'customers' => $customers,
        ]);
    }

    public function update(UpdateServiceOrderRequest $request, ServiceOrder $serviceOrder)
    {
        $serviceOrder->update($request->validated());
        return redirect()->route('service-orders.index')->with('success', 'Orden de servicio actualizada.');
    }

    public function updateStatus(Request $request, ServiceOrder $serviceOrder)
    {
        $validated = $request->validate([
            'status' => ['required', Rule::enum(ServiceOrderStatus::class)],
        ]);

        $oldStatus = $serviceOrder->status->value;
        $newStatus = $validated['status'];

        $serviceOrder->update(['status' => $newStatus]);

        activity()
            ->performedOn($serviceOrder)
            ->causedBy(auth()->user())
            ->event('status_changed') // Evento personalizado para el historial
            ->withProperties(['old_status' => $oldStatus, 'new_status' => $newStatus])
            ->log("El estatus cambió de '{$oldStatus}' a '{$newStatus}'.");

        return redirect()->back()->with('success', 'Estatus de la orden actualizado.');
    }

    public function destroy(ServiceOrder $serviceOrder)
    {
        $serviceOrder->delete();
        return redirect()->route('service-orders.index')->with('success', 'Orden de servicio eliminada.');
    }

    public function batchDestroy(Request $request)
    {
        $request->validate(['ids' => 'required|array']);
        ServiceOrder::whereIn('id', $request->input('ids'))->delete();
        return redirect()->route('service-orders.index')->with('success', 'Órdenes seleccionadas eliminadas.');
    }
}
