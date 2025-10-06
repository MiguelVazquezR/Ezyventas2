<?php

namespace App\Http\Controllers;

use App\Enums\TemplateContextType;
use App\Enums\TemplateType;
use App\Models\PrintTemplate;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PrintTemplateController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:settings.templates.access', only: ['index']),
            new Middleware('can:settings.templates.create', only: ['create', 'store']),
            new Middleware('can:settings.templates.edit', only: ['edit', 'update']),
            new Middleware('can:settings.templates.delete', only: ['destroy']),
        ];
    }

    /**
     * --- AÑADIDO: Función auxiliar para obtener datos del límite de plantillas. ---
     */
    private function getTemplateLimitData()
    {
        $subscription = Auth::user()->branch->subscription;
        $currentVersion = $subscription->versions()->latest('start_date')->first();
        $limit = -1; // -1 significa ilimitado
        if ($currentVersion) {
            $limitItem = $currentVersion->items()->where('item_key', 'limit_print_templates')->first();
            if ($limitItem) {
                $limit = $limitItem->quantity;
            }
        }
        $usage = $subscription->printTemplates()->count();
        return ['limit' => $limit, 'usage' => $usage];
    }

    public function index(): Response
    {
        $subscription = Auth::user()->branch->subscription;

        $templates = PrintTemplate::where('subscription_id', $subscription->id)
            ->with('branches:id,name')
            ->latest()
            ->get();

        // --- AÑADIDO: Se pasan los datos del límite a la vista ---
        $limitData = $this->getTemplateLimitData();

        return Inertia::render('Template/Index', [
            'templates' => $templates,
            'templateLimit' => $limitData['limit'],
            'templateUsage' => $limitData['usage'],
        ]);
    }

    public function create(Request $request): Response
    {
        $type = $request->query('type', 'ticket_venta');
        $subscription = Auth::user()->branch->subscription;

        // --- AÑADIDO: Se pasan los datos del límite a la vista ---
        $limitData = $this->getTemplateLimitData();

        $view = match ($type) {
            'etiqueta' => 'Template/CreateLabel',
            default => 'Template/CreateTicket',
        };

        return Inertia::render($view, [
            'branches' => $subscription->branches()->get(['id', 'name']),
            'templateImages' => $subscription->getMedia('template-images')->map(fn ($media) => ['id' => $media->id, 'url' => $media->getUrl(), 'name' => $media->name]),
            'templateLimit' => $limitData['limit'], // <-- Nuevo
            'templateUsage' => $limitData['usage'],   // <-- Nuevo
        ]);
    }

    public function store(Request $request)
    {
        // --- AÑADIDO: Validación del límite de plantillas ---
        $limitData = $this->getTemplateLimitData();
        if ($limitData['limit'] !== -1 && $limitData['usage'] >= $limitData['limit']) {
            throw ValidationException::withMessages([
                'limit' => 'Has alcanzado el límite de plantillas de tu plan.'
            ]);
        }

        $subscription = Auth::user()->branch->subscription;
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => ['required', Rule::in(array_column(TemplateType::cases(), 'value'))],
            'content' => 'required|array',
            'content.config' => 'required|array',
            'content.elements' => 'required|array',
            'branch_ids' => 'required|array|min:1',
            'branch_ids.*' => ['required', Rule::in($subscription->branches->pluck('id'))],
        ]);

        DB::transaction(function () use ($validated, $subscription) {
            $template = $subscription->printTemplates()->create([
                'name' => $validated['name'],
                'type' => $validated['type'],
                'content' => $validated['content'],
                'context_type' => $this->determineContextType($validated['content']['elements'] ?? []),
            ]);
            $template->branches()->attach($validated['branch_ids']);
        });

        return redirect()->route('print-templates.index')->with('success', 'Plantilla creada con éxito.');
    }
    
    // ... (El resto de los métodos se mantienen igual)

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
                'context_type' => $this->determineContextType($validated['content']['elements'] ?? []),
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

        $templateImages = $subscription->getMedia('template-images')->map(fn ($media) => [
            'id' => $media->id,
            'url' => $media->getUrl(),
            'name' => $media->name,
        ]);

        return Inertia::render($view, [
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

    public function storeMedia(Request $request)
    {
        $request->validate([
            'image' => ['required', 'image', 'max:1024'],
        ]);

        $subscription = Auth::user()->branch->subscription;

        $media = $subscription->addMediaFromRequest('image')
            ->toMediaCollection('template-images');

        return response()->json([
            'id' => $media->id,
            'url' => $media->getUrl(),
            'name' => $media->name,
        ]);
    }

    private function determineContextType(array $elements): string
    {
        $contentString = json_encode($elements);

        if (str_contains($contentString, '{{orden.')) {
            return TemplateContextType::SERVICE_ORDER->value;
        }
        if (str_contains($contentString, '{{folio') || str_contains($contentString, '{{cliente.')) {
            return TemplateContextType::TRANSACTION->value;
        }
        if (str_contains($contentString, '{{producto.')) {
            return TemplateContextType::PRODUCT->value;
        }

        return TemplateContextType::GENERAL->value;
    }
}