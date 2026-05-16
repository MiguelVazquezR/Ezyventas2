<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlanItem;
use App\Http\Requests\Admin\StorePlanItemRequest;
use App\Http\Requests\Admin\UpdatePlanItemRequest;
use App\Actions\Admin\PlanItems\CreatePlanItemAction;
use App\Actions\Admin\PlanItems\UpdatePlanItemAction;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PlanItemController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $filters = $request->only(['search', 'sortField', 'sortOrder']);

        $planItems = PlanItem::query()
            ->search($filters['search'] ?? null)
            ->when($filters['sortField'] ?? null, function ($query, $sortField) use ($filters) {
                $query->orderBy($sortField, $filters['sortOrder'] === 'asc' ? 'asc' : 'desc');
            }, function ($query) {
                // Default sort
                $query->latest('id');
            })
            ->paginate($request->input('rows', 20))
            ->withQueryString();

        return Inertia::render('Admin/PlanItems/Index', [
            'planItems' => $planItems,
            'filters'   => $filters,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return Inertia::render('Admin/PlanItems/Create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StorePlanItemRequest $request, CreatePlanItemAction $action)
    {
        // El controlador solo delega los datos validados a la acción de negocio
        $action->execute($request->validated());

        return redirect()
            ->route('admin.plan-items.index')
            ->with('success', 'El ítem del plan ha sido creado exitosamente.');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(PlanItem $planItem)
    {
        return Inertia::render('Admin/PlanItems/Edit', [
            'planItem' => $planItem
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdatePlanItemRequest $request, PlanItem $planItem, UpdatePlanItemAction $action)
    {
        // El controlador inyecta la acción y delega la responsabilidad
        $action->execute($planItem, $request->validated());

        return redirect()
            ->route('admin.plan-items.index')
            ->with('success', 'El ítem del plan ha sido actualizado correctamente.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(PlanItem $planItem)
    {
        // Lógica de eliminación simple. Podría ir a una Action si implicara
        // verificar relaciones (ej. que ninguna suscripción lo esté usando).
        $planItem->delete();

        return redirect()
            ->back()
            ->with('success', 'El ítem ha sido eliminado del sistema.');
    }
}