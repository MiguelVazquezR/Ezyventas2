<?php

namespace App\Http\Controllers;

use App\Models\ReleaseNote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ReleaseNoteController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | MÉTODOS PARA ADMINISTRADORES (SUPER ADMIN)
    |--------------------------------------------------------------------------
    */

    public function adminIndex(Request $request): Response
    {
        $query = ReleaseNote::query();

        if ($request->has('search') && $request->input('search')) {
            $searchTerm = $request->input('search');
            $query->where(function ($q) use ($searchTerm) {
                $q->where('title', 'LIKE', "%{$searchTerm}%")
                  ->orWhere('version', 'LIKE', "%{$searchTerm}%");
            });
        }

        $sortField = $request->input('sortField', 'created_at');
        $sortOrder = $request->input('sortOrder', 'desc');
        $query->orderBy($sortField, $sortOrder);

        $notes = $query->paginate($request->input('rows', 20))->withQueryString();

        return Inertia::render('ReleaseNote/Index', [
            'notes' => $notes,
            'filters' => $request->only(['search', 'sortField', 'sortOrder']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('ReleaseNote/Create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'version' => 'nullable|string|max:50',
            'excerpt' => 'nullable|string|max:500',
            'content' => 'required|string',
            'is_published' => 'boolean',
            'media.*' => 'nullable|file|mimes:jpg,jpeg,png,gif,mp4,webm|max:20480', // Máx 20MB
        ]);

        if ($validated['is_published'] ?? false) {
            $validated['published_at'] = now();
        }

        $note = ReleaseNote::create($validated);

        // Subir nuevos archivos con Spatie MediaLibrary
        if ($request->hasFile('media')) {
            foreach ($request->file('media') as $file) {
                $note->addMedia($file)->toMediaCollection('gallery');
            }
        }

        return redirect()->route('admin.release-notes.index')
            ->with('success', 'Novedad creada exitosamente.');
    }

    public function edit(ReleaseNote $releaseNote): Response
    {
        // CARGA CLAVE: Le decimos a Laravel que incluya los archivos adjuntos
        $releaseNote->load('media');

        return Inertia::render('ReleaseNote/Edit', [
            'note' => $releaseNote,
        ]);
    }

    public function update(Request $request, ReleaseNote $releaseNote)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'version' => 'nullable|string|max:50',
            'excerpt' => 'nullable|string|max:500',
            'content' => 'required|string',
            'is_published' => 'boolean',
            'deleted_media' => 'nullable|array',
            'media.*' => 'nullable|file|mimes:jpg,jpeg,png,gif,mp4,webm|max:20480',
        ]);

        // Si cambia el estado de publicación
        if (($validated['is_published'] ?? false) && !$releaseNote->is_published) {
            $validated['published_at'] = now();
        } elseif (!($validated['is_published'] ?? false)) {
            $validated['published_at'] = null;
        }

        $releaseNote->update($validated);

        // 1. Eliminar archivos que el usuario borró en la vista
        // CORRECCIÓN: Instanciamos los modelos Media para que Spatie dispare el evento "deleting"
        // y elimine físicamente el archivo del disco (evitando archivos huérfanos).
        if ($request->filled('deleted_media')) {
            $mediaToDelete = $releaseNote->media()->whereIn('id', $request->input('deleted_media'))->get();
            foreach ($mediaToDelete as $media) {
                $media->delete(); 
            }
        }

        // 2. Subir nuevos archivos agregados
        if ($request->hasFile('media')) {
            foreach ($request->file('media') as $file) {
                $releaseNote->addMedia($file)->toMediaCollection('gallery');
            }
        }

        return redirect()->route('admin.release-notes.index')
            ->with('success', 'Novedad actualizada correctamente.');
    }

    public function destroy(ReleaseNote $releaseNote)
    {
        // Spatie MediaLibrary elimina automáticamente los archivos físicos si el modelo es eliminado.
        $releaseNote->delete();

        return redirect()->back()->with('success', 'Novedad eliminada de forma permanente.');
    }

    public function togglePublish(ReleaseNote $releaseNote)
    {
        $isPublished = !$releaseNote->is_published;
        
        $releaseNote->update([
            'is_published' => $isPublished,
            'published_at' => $isPublished ? now() : null,
        ]);

        $status = $isPublished ? 'publicada' : 'ocultada';
        return redirect()->back()->with('success', "La novedad ha sido {$status} con éxito.");
    }


    /*
    |--------------------------------------------------------------------------
    | MÉTODOS PARA SUSCRIPTORES (CLIENTES)
    |--------------------------------------------------------------------------
    */

    public function index(Request $request): Response|JsonResponse
    {
        $user = Auth::user();
        
        $readIds = $user->readReleaseNotes()->pluck('release_notes.id')->toArray();

        $notes = ReleaseNote::published()
            ->with('media') 
            ->paginate(10)
            ->through(function ($note) use ($readIds) {
                return [
                    'id' => $note->id,
                    'version' => $note->version,
                    'title' => $note->title,
                    'excerpt' => $note->excerpt,
                    'content' => $note->content, 
                    'published_at' => $note->published_at,
                    'is_read' => in_array($note->id, $readIds),
                    'media' => $note->getMedia('gallery')->map(function ($media) {
                        return [
                            'id' => $media->id,
                            'url' => $media->getUrl(),
                            'mime_type' => $media->mime_type, 
                            'name' => $media->name,
                        ];
                    }),
                ];
            });

        if ($request->wantsJson()) {
            return response()->json($notes);
        }

        return Inertia::render('ReleaseNotes/UserIndex', [
            'notes' => $notes
        ]);
    }

    public function markAsRead(ReleaseNote $releaseNote): JsonResponse
    {
        $user = Auth::user();

        $user->readReleaseNotes()->syncWithoutDetaching([
            $releaseNote->id => ['read_at' => now()]
        ]);

        return response()->json(['success' => true]);
    }

    public function markAllAsRead(): JsonResponse
    {
        $user = Auth::user();
        
        $unreadNotes = ReleaseNote::published()
            ->whereNotIn('id', $user->readReleaseNotes()->pluck('release_notes.id'))
            ->pluck('id');

        $syncData = [];
        foreach ($unreadNotes as $id) {
            $syncData[$id] = ['read_at' => now()];
        }

        if (!empty($syncData)) {
            $user->readReleaseNotes()->syncWithoutDetaching($syncData);
        }

        return response()->json(['success' => true]);
    }

    public function show(ReleaseNote $releaseNote): Response
    {
        // Seguridad: Si alguien intenta entrar a una URL de una novedad no publicada, arrojamos 404
        if (!$releaseNote->is_published) {
            abort(404);
        }

        $releaseNote->load('media');

        // Por si acaso accedieron directamente a la URL, la marcamos como leída
        $user = Auth::user();
        $user->readReleaseNotes()->syncWithoutDetaching([
            $releaseNote->id => ['read_at' => now()]
        ]);

        return Inertia::render('ReleaseNote/Show', [
            'note' => [
                'id' => $releaseNote->id,
                'version' => $releaseNote->version,
                'title' => $releaseNote->title,
                'content' => $releaseNote->content, // Enviamos el content completo
                'published_at' => $releaseNote->published_at,
                'media' => $releaseNote->getMedia('gallery')->map(function ($media) {
                    return [
                        'id' => $media->id,
                        'url' => $media->getUrl(),
                        'mime_type' => $media->mime_type,
                        'name' => $media->name,
                    ];
                }),
            ]
        ]);
    }
}