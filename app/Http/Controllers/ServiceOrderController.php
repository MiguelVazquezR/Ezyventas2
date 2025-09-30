<?php

namespace App\Http\Controllers;

use App\Enums\CustomerBalanceMovementType;
use App\Enums\PaymentMethod;
use App\Enums\ServiceOrderStatus;
use App\Enums\TemplateContextType;
use App\Enums\TemplateType;
use App\Enums\TransactionChannel;
use App\Enums\TransactionStatus;
use App\Http\Requests\StoreServiceOrderRequest;
use App\Http\Requests\UpdateServiceOrderRequest;
use App\Models\Customer;
use App\Models\CustomFieldDefinition;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceOrder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Exception;

class ServiceOrderController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:services.orders.access', only: ['index']),
            new Middleware('can:services.orders.create', only: ['create', 'store']),
            new Middleware('can:services.orders.see_details', only: ['show']),
            new Middleware('can:services.orders.edit', only: ['edit', 'update']),
            new Middleware('can:services.orders.delete', only: ['destroy', 'batchDestroy']),
            new Middleware('can:services.orders.change_status', only: ['updateStatus']),
            new Middleware('can:services.orders.store_payment', only: ['storePayment']), // <-- Añadir permiso si es necesario
        ];
    }

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

        $availableTemplates = $user->branch->printTemplates()
            ->whereIn('type', [TemplateType::SALE_TICKET, TemplateType::LABEL])
            ->whereIn('context_type', [TemplateContextType::SERVICE_ORDER, TemplateContextType::GENERAL])
            ->get();

        return Inertia::render('ServiceOrder/Index', [
            'serviceOrders' => $serviceOrders,
            'filters' => $request->only(['search', 'sortField', 'sortOrder']),
            'availableTemplates' => $availableTemplates,
        ]);
    }

    public function show(ServiceOrder $serviceOrder): Response
    {
        $serviceOrder->load(['branch', 'user', 'customer', 'items.itemable', 'activities.causer', 'media', 'transaction.payments']);

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

        $availableTemplates = Auth::user()->branch->printTemplates()
            ->whereIn('type', [TemplateType::SALE_TICKET, TemplateType::LABEL])
            ->whereIn('context_type', [TemplateContextType::SERVICE_ORDER, TemplateContextType::GENERAL])
            ->get();

        return Inertia::render('ServiceOrder/Show', [
            'serviceOrder' => $serviceOrder,
            'activities' => $formattedActivities,
            'availableTemplates' => $availableTemplates,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('ServiceOrder/Create', $this->getFormData());
    }

    public function store(StoreServiceOrderRequest $request)
    {
        $newServiceOrder = null;

        DB::transaction(function () use ($request, &$newServiceOrder) {
            $user = Auth::user();
            $validated = $request->validated();
            $customer = null;

            if (! empty($validated['customer_id'])) {
                $customer = Customer::find($validated['customer_id']);
            } else {
                $customer = Customer::firstOrCreate(
                    [
                        'branch_id' => $user->branch_id,
                        'name' => $validated['customer_name'],
                        'phone' => $validated['customer_phone'] ?? null,
                    ],
                    [
                        'email' => $validated['customer_email'] ?? null,
                        'address' => $validated['customer_address'] ?? null,
                        'credit_limit' => 5000,
                        'balance' => 0,
                    ]
                );
            }

            $serviceOrder = ServiceOrder::create(array_merge($validated, [
                'user_id' => $user->id,
                'branch_id' => $user->branch_id,
                'customer_id' => $customer->id,
                'status' => ServiceOrderStatus::PENDING,
            ]));

            $transaction = $serviceOrder->transaction()->create([
                'folio' => 'TR-' . time(),
                'customer_id' => $customer->id,
                'branch_id' => $user->branch_id,
                'user_id' => $user->id,
                'subtotal' => $serviceOrder->final_total,
                'total_discount' => 0,
                'total_tax' => 0,
                'channel' => TransactionChannel::SERVICE_ORDER,
                'status' => TransactionStatus::PENDING,
            ]);

            foreach ($validated['items'] ?? [] as $item) {
                if (isset($item['itemable_id']) && $item['itemable_id'] == 0) {
                    unset($item['itemable_id'], $item['itemable_type']);
                }
                $serviceOrder->items()->create($item);
            }

            if ($request->hasFile('initial_evidence_images')) {
                foreach ($request->file('initial_evidence_images') as $file) {
                    $serviceOrder->addMedia($file)->toMediaCollection('initial-service-order-evidence');
                }
            }

            $newServiceOrder = $serviceOrder;
        });

        return redirect()->route('service-orders.show', $newServiceOrder->id)
            ->with('success', 'Orden de servicio creada.')
            ->with('show_payment_modal', true);
    }

    public function edit(ServiceOrder $serviceOrder): Response
    {
        $serviceOrder->load('items.itemable');
        return Inertia::render('ServiceOrder/Edit', array_merge($this->getFormData(), ['serviceOrder' => $serviceOrder]));
    }

    public function update(UpdateServiceOrderRequest $request, ServiceOrder $serviceOrder)
    {
        DB::transaction(function () use ($request, $serviceOrder) {
            $validated = $request->validated();
            $serviceOrder->update($validated);

            $serviceOrder->items()->delete();
            foreach ($validated['items'] as $item) {
                if (isset($item['itemable_id']) && $item['itemable_id'] == 0) {
                    unset($item['itemable_id'], $item['itemable_type']);
                }
                $serviceOrder->items()->create($item);
            }

            if ($request->input('deleted_media_ids')) {
                $serviceOrder->media()->whereIn('id', $request->input('deleted_media_ids'))->delete();
            }

            if ($request->hasFile('initial_evidence_images')) {
                foreach ($request->file('initial_evidence_images') as $file) {
                    $serviceOrder->addMedia($file)->toMediaCollection('initial-service-order-evidence');
                }
            }
        });

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
            ->event('status_changed')
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

    private function getFormData(): array
    {
        $user = Auth::user();
        $subscriptionId = $user->branch->subscription_id;

        return [
            'customers' => Customer::whereHas('branch.subscription', fn($q) => $q->where('id', $subscriptionId))->get(),
            'products' => Product::where('branch_id', $user->branch_id)->with('productAttributes')->get(),
            'services' => Service::where('branch_id', $user->branch_id)->get(['id', 'name', 'base_price']),
            'customFieldDefinitions' => CustomFieldDefinition::where('subscription_id', $subscriptionId)->where('module', 'service_orders')->get(),
        ];
    }
}
