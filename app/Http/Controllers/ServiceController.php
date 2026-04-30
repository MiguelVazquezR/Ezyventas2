<?php

namespace App\Http\Controllers;

use App\Actions\Service\StoreServiceAction;
use App\Actions\Service\UpdateServiceAction;
use App\Http\Requests\StoreServiceRequest;
use App\Http\Requests\UpdateServiceRequest;
use App\Models\Category;
use App\Models\Service;
use App\Models\Branch;
use App\Services\ActivityLogService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ServiceController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:services.catalog.access', only: ['index']),
            new Middleware('can:services.catalog.create', only: ['create', 'store']),
            new Middleware('can:services.catalog.see_details', only: ['show']),
            new Middleware('can:services.catalog.edit', only: ['edit', 'update']),
            new Middleware('can:services.catalog.delete', only: ['destroy', 'batchDestroy']),
        ];
    }

    public function index(Request $request): Response
    {
        $user = Auth::user();
        $subscription = $user->branch->subscription;

        $query = Service::query()
            ->join('categories', 'services.category_id', '=', 'categories.id')
            ->whereHas('branches', fn ($q) => $q->where('branches.id', $user->branch_id))
            ->with([
                'category:id,name', 
                'variants:id,service_id,name,price,duration_estimate', 
                'branches:id,name'
            ])
            ->select('services.*');

        if ($request->has('search')) {
            $query->where('services.name', 'LIKE', "%{$request->input('search')}%");
        }

        $sortField = $request->input('sortField', 'created_at');
        $sortColumn = $sortField === 'category.name' ? 'categories.name' : "services.{$sortField}";
        $query->orderBy($sortColumn, $request->input('sortOrder', 'desc'));

        return Inertia::render('Service/Index', [
            'services' => $query->paginate($request->input('rows', 20))->withQueryString(),
            'filters' => $request->only(['search', 'sortField', 'sortOrder']),
            'serviceLimitReached' => $subscription->hasReachedServiceLimit(), 
        ]);
    }

    public function create(): Response
    {
        $user = Auth::user();
        $subscriptionId = $user->branch->subscription_id;

        return Inertia::render('Service/Create', [
            'categories' => Category::where('subscription_id', $subscriptionId)->where('type', 'service')->get(['id', 'name']),
            'branches' => Branch::where('subscription_id', $subscriptionId)->get(['id', 'name']),
            'current_branch_id' => $user->branch_id,
            'serviceLimitReached' => $user->branch->subscription->hasReachedServiceLimit(), 
        ]);
    }

    public function store(StoreServiceRequest $request, StoreServiceAction $action)
    {
        $validatedData = $request->validated();
        $subscription = Auth::user()->branch->subscription;
        
        $newItemsCount = 1 + (!empty($validatedData['variants']) ? count($validatedData['variants']) : 0);

        if ($subscription->hasReachedServiceLimit($newItemsCount)) {
            return redirect()->back()->with('error', 'Esta acción excede tu límite de servicios. Mejora tu suscripción.');
        }

        $action->execute($validatedData, Auth::user(), $request->file('image'));

        return redirect()->route('services.index')->with('success', 'Servicio creado con éxito.');
    }

    public function edit(Service $service): Response
    {
        $user = Auth::user();
        $subscriptionId = $user->branch->subscription_id;
        
        $service->load(['media', 'variants', 'branches:id']); 
        
        return Inertia::render('Service/Edit', [
            'service' => $service,
            'categories' => Category::where('subscription_id', $subscriptionId)->where('type', 'service')->get(['id', 'name']),
            'branches' => Branch::where('subscription_id', $subscriptionId)->get(['id', 'name']),
        ]);
    }

    public function update(UpdateServiceRequest $request, Service $service, UpdateServiceAction $action)
    {
        $validatedData = $request->validated();
        $subscription = Auth::user()->branch->subscription;

        if (!empty($validatedData['has_variants']) && !empty($validatedData['variants'])) {
            $newVariantsCount = collect($validatedData['variants'])->filter(fn($v) => empty($v['id']))->count();
            
            if ($newVariantsCount > 0 && $subscription->hasReachedServiceLimit($newVariantsCount)) {
                return redirect()->back()->with('error', 'No puedes agregar estas variantes porque excedes el límite de servicios de tu plan.');
            }
        }

        $action->execute($service, $validatedData, Auth::user(), $request->file('image'));

        return redirect()->route('services.index')->with('success', 'Servicio actualizado con éxito.');
    }

    public function show(Request $request, Service $service, ActivityLogService $activityLogService): Response
    {
        $service->load(['category', 'media', 'variants', 'branches']);

        return Inertia::render('Service/Show', [
            'service' => $service,
            'activities' => $activityLogService->getFormattedActivities($service, $request, 'Service'),
        ]);
    }

    public function destroy(Service $service)
    {
        $service->delete();
        return redirect()->route('services.index')->with('success', 'Servicio eliminado con éxito.');
    }

    public function batchDestroy(Request $request)
    {
        $request->validate(['ids' => 'required|array']);
        Service::whereIn('id', $request->input('ids'))->delete();
        return redirect()->route('services.index')->with('success', 'Servicios seleccionados eliminados.');
    }
}