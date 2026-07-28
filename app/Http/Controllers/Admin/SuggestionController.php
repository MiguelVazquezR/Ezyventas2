<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Suggestion;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SuggestionController extends Controller
{
    public function index(Request $request): Response
    {
        $query = Suggestion::query()
            ->with(['user', 'branch.subscription']);

        // Filtro por búsqueda
        if ($request->filled('search')) {
            $searchTerm = $request->input('search');
            $query->where(function ($q) use ($searchTerm) {
                $q->where('title', 'LIKE', "%{$searchTerm}%")
                  ->orWhere('description', 'LIKE', "%{$searchTerm}%");
            });
        }

        // Filtro por status
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        // Filtro por categoría
        if ($request->filled('category')) {
            $query->where('category', $request->input('category'));
        }

        // Filtro por prioridad
        if ($request->filled('priority')) {
            $query->where('priority', $request->input('priority'));
        }

        // Ordenamiento
        $sortField = $request->input('sortField', 'created_at');
        $sortOrder = $request->input('sortOrder', 'desc');
        $query->orderBy($sortField, $sortOrder);

        $suggestions = $query->paginate($request->input('rows', 20))->withQueryString();

        // Transform para agregar datos planos del usuario/suscripción/sucursal
        $suggestions->through(function ($suggestion) {
            $suggestion->user_name = $suggestion->user?->name;
            $suggestion->user_email = $suggestion->user?->email;
            $suggestion->branch_name = $suggestion->branch?->name;
            $suggestion->subscription_name = $suggestion->branch?->subscription?->commercial_name
                ?? $suggestion->branch?->subscription?->business_name;

            return $suggestion;
        });

        return Inertia::render('Admin/Suggestions/Index', [
            'suggestions' => $suggestions,
            'filters'     => $request->only(['search', 'status', 'category', 'priority', 'sortField', 'sortOrder']),
        ]);
    }

    public function updateStatus(Request $request, Suggestion $suggestion)
    {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:pending,reviewed,planned,implemented,declined'],
        ]);

        $suggestion->update(['status' => $validated['status']]);

        return back()->with('success', 'Estado actualizado correctamente.');
    }

    public function updatePriority(Request $request, Suggestion $suggestion)
    {
        $validated = $request->validate([
            'priority' => ['required', 'string', 'in:low,medium,high'],
        ]);

        $suggestion->update(['priority' => $validated['priority']]);

        return back()->with('success', 'Prioridad actualizada correctamente.');
    }

    public function updateAdminNotes(Request $request, Suggestion $suggestion)
    {
        $validated = $request->validate([
            'admin_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $suggestion->update(['admin_notes' => $validated['admin_notes']]);

        return back()->with('success', 'Notas guardadas correctamente.');
    }
}