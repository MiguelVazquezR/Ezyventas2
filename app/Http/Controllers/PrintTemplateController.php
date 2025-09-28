<?php

namespace App\Http\Controllers;

use App\Enums\TemplateType;
use App\Models\PrintTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PrintTemplateController extends Controller
{
    public function index(): Response
    {
        $subscription = Auth::user()->branch->subscription;

        $templates = PrintTemplate::where('subscription_id', $subscription->id)
            ->with('branches:id,name')
            ->latest()
            ->get();

        return Inertia::render('Template/Index', [
            'templates' => $templates,
        ]);
    }

    public function create(Request $request): Response
    {
        $type = $request->query('type', 'ticket_venta'); // Por defecto, crea un ticket
        $subscription = Auth::user()->branch->subscription;

        $view = match ($type) {
            'etiqueta' => 'Template/CreateLabel',
            default => 'Template/CreateTicket',
        };

        return Inertia::render($view, [
            'branches' => $subscription->branches()->get(['id', 'name']),
            'templateImages' => $subscription->getMedia('template-images')->map(fn($media) => ['id' => $media->id, 'url' => $media->getUrl(), 'name' => $media->name]),
        ]);
    }

    public function store(Request $request)
    {
        $subscription = Auth::user()->branch->subscription;

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => ['required', Rule::in(array_column(TemplateType::cases(), 'value'))],
            'content' => 'required|array',
            'content.config' => 'required|array',
            'content.elements' => 'required|array', // Se valida el array de elementos visuales
            'branch_ids' => 'required|array|min:1',
            'branch_ids.*' => ['required', Rule::in($subscription->branches->pluck('id'))],
        ]);

        DB::transaction(function () use ($validated, $subscription) {
            $template = $subscription->printTemplates()->create([
                'name' => $validated['name'],
                'type' => $validated['type'],
                'content' => $validated['content'], // Se guarda el objeto con config y elements
            ]);
            $template->branches()->attach($validated['branch_ids']);
        });

        return redirect()->route('print-templates.index')->with('success', 'Plantilla creada con éxito.');
    }

    public function update(Request $request, PrintTemplate $printTemplate)
    {
        if ($printTemplate->subscription_id !== Auth::user()->branch->subscription_id) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => ['required', Rule::in(array_column(TemplateType::cases(), 'value'))],
            'content' => 'required|array',
            'content.config' => 'required|array',
            'content.elements' => 'required|array',
            'branch_ids' => 'required|array|min:1',
            'branch_ids.*' => ['required', Rule::in($printTemplate->subscription->branches->pluck('id'))],
        ]);

        DB::transaction(function () use ($validated, $printTemplate) {
            $printTemplate->update([
                'name' => $validated['name'],
                'type' => $validated['type'],
                'content' => $validated['content'],
            ]);
            $printTemplate->branches()->sync($validated['branch_ids']);
        });

        return redirect()->route('print-templates.index')->with('success', 'Plantilla actualizada con éxito.');
    }

    public function edit(PrintTemplate $printTemplate): Response
    {
        if ($printTemplate->subscription_id !== Auth::user()->branch->subscription_id) {
            abort(403);
        }

        $view = match ($printTemplate->type->value) {
            'etiqueta' => 'Template/EditLabel',
            default => 'Template/EditTicket',
        };

        $subscription = Auth::user()->branch->subscription;
        $printTemplate->load('branches:id,name');

        // Se obtienen las imágenes existentes para la galería
        $templateImages = $subscription->getMedia('template-images')->map(fn($media) => [
            'id' => $media->id,
            'url' => $media->getUrl(),
            'name' => $media->name,
        ]);

        return Inertia::render('Template/Edit', [
            'template' => $printTemplate,
            'branches' => $subscription->branches()->get(['id', 'name']),
            'templateImages' => $templateImages,
        ]);
    }

    public function destroy(PrintTemplate $printTemplate)
    {
        if ($printTemplate->subscription_id !== Auth::user()->branch->subscription_id) {
            abort(403);
        }

        $printTemplate->delete();

        return redirect()->back()->with('success', 'Plantilla eliminada con éxito.');
    }

    /**
     * Almacena una imagen para usar en las plantillas y devuelve su URL.
     */
    public function storeMedia(Request $request)
    {
        $request->validate([
            'image' => ['required', 'image', 'max:1024'],
        ]);

        $subscription = Auth::user()->branch->subscription;

        $media = $subscription->addMediaFromRequest('image')
            ->toMediaCollection('template-images');

        // Se devuelve el objeto completo de la nueva imagen
        return response()->json([
            'id' => $media->id,
            'url' => $media->getUrl(),
            'name' => $media->name,
        ]);
    }
}
