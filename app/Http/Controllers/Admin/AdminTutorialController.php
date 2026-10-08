<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreTutorialVideoRequest;
use App\Http\Requests\Admin\UpdateTutorialVideoRequest;
use App\Models\TutorialVideo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin panel for the video tutorials shown in every module ("Tutoriales").
 *
 * Videos are grouped by module (key from config/tutorials.php) → section;
 * each module only displays its own videos in the tutorial modal.
 */
class AdminTutorialController extends Controller
{
    public function index(): Response
    {
        $moduleOptions = collect(config('tutorials.modules'))
            ->map(fn ($label, $key) => ['key' => $key, 'label' => $label])
            ->values();

        return Inertia::render('Admin/Tutorials/Index', [
            'videos'        => TutorialVideo::query()->ordered()->get(),
            'moduleOptions' => $moduleOptions,
            'uploadLimitKb' => (int) config('tutorials.max_upload_kb'),
        ]);
    }

    public function store(StoreTutorialVideoRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $video = new TutorialVideo([
            'module'      => $data['module'],
            'section'     => $data['section'],
            'title'       => $data['title'],
            'description' => $data['description'] ?? null,
            'duration'    => $data['duration'] ?? null,
            'is_active'   => true,
            'sort_order'  => $this->nextSortOrder($data['module'], $data['section']),
        ]);

        $this->applySource($video, $request, $data);

        $video->save();

        return back()->with('success', 'Video agregado correctamente.');
    }

    public function update(UpdateTutorialVideoRequest $request, TutorialVideo $tutorial): RedirectResponse
    {
        $data = $request->validated();

        $tutorial->fill([
            'module'      => $data['module'],
            'section'     => $data['section'],
            'title'       => $data['title'],
            'description' => $data['description'] ?? null,
            'duration'    => $data['duration'] ?? null,
        ]);

        // Moving the video to another module/section sends it to the end of
        // the destination group.
        if ($tutorial->isDirty('module') || $tutorial->isDirty('section')) {
            $tutorial->sort_order = $this->nextSortOrder($data['module'], $data['section']);
        }

        $this->applySource($tutorial, $request, $data);

        $tutorial->save();

        return back()->with('success', 'Video actualizado correctamente.');
    }

    public function destroy(TutorialVideo $tutorial): RedirectResponse
    {
        $this->deleteFile($tutorial);
        $tutorial->delete();

        return back()->with('success', 'Video eliminado correctamente.');
    }

    public function toggleActive(TutorialVideo $tutorial): RedirectResponse
    {
        $tutorial->update(['is_active' => ! $tutorial->is_active]);

        return back()->with(
            'success',
            $tutorial->is_active ? 'Video activado.' : 'Video desactivado.',
        );
    }

    /**
     * Move a video one position up or down inside its section.
     */
    public function move(Request $request, TutorialVideo $tutorial): RedirectResponse
    {
        $direction = $request->input('direction') === 'up' ? 'up' : 'down';

        $siblings = TutorialVideo::query()
            ->where('module', $tutorial->module)
            ->where('section', $tutorial->section)
            ->ordered()
            ->get();

        $index = $siblings->search(fn (TutorialVideo $item) => $item->id === $tutorial->id);

        if ($index === false) {
            return back();
        }

        $target = $direction === 'up' ? $index - 1 : $index + 1;

        if ($target < 0 || $target >= $siblings->count()) {
            return back();
        }

        $reordered = $siblings->all();
        [$reordered[$index], $reordered[$target]] = [$reordered[$target], $reordered[$index]];

        foreach ($reordered as $position => $item) {
            $item->update(['sort_order' => $position + 1]);
        }

        return back()->with('success', 'Orden actualizado.');
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Store the chosen source on the model: an external link (YouTube/Vimeo)
     * or an uploaded file. Replacing a source removes the previous file.
     */
    private function applySource(TutorialVideo $video, Request $request, array $data): void
    {
        if (($data['source_type'] ?? null) === 'file') {
            if ($request->hasFile('file')) {
                $this->deleteFile($video);

                $video->file_path = $request->file('file')->store('tutorials', 'public');
            }

            $video->url = null;

            return;
        }

        // External link — drop any previously uploaded file.
        $this->deleteFile($video);

        $video->file_path = null;
        $video->url = $data['url'] ?? null;
    }

    private function deleteFile(TutorialVideo $video): void
    {
        if ($video->file_path) {
            Storage::disk('public')->delete($video->file_path);
        }
    }

    private function nextSortOrder(string $module, string $section): int
    {
        return (int) TutorialVideo::query()
            ->where('module', $module)
            ->where('section', $section)
            ->max('sort_order') + 1;
    }
}
