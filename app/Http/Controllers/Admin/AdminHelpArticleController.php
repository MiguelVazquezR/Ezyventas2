<?php

namespace App\Http\Controllers\Admin;

use App\Actions\HelpCenter\CreateHelpArticleAction;
use App\Actions\HelpCenter\DeleteHelpArticleAction;
use App\Actions\HelpCenter\MoveHelpArticleAction;
use App\Actions\HelpCenter\ToggleHelpArticlePublicationAction;
use App\Actions\HelpCenter\UpdateHelpArticleAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\HelpCenter\MoveHelpArticleRequest;
use App\Http\Requests\HelpCenter\StoreHelpArticleRequest;
use App\Http\Requests\HelpCenter\UpdateHelpArticleRequest;
use App\Models\HelpArticle;
use App\Models\TutorialVideo;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin panel for the written guides of the help center ("Centro de ayuda").
 *
 * Guides are grouped by module (key from config/tutorials.php); each module
 * page in the help center displays its guides plus its videos (managed in
 * Admin → Tutoriales).
 */
class AdminHelpArticleController extends Controller
{
    public function index(): Response
    {
        $moduleOptions = collect(config('tutorials.modules'))
            ->map(fn ($label, $key) => ['key' => $key, 'label' => $label])
            ->values();

        return Inertia::render('Admin/HelpCenter/Index', [
            'articles'      => HelpArticle::query()->orderBy('module')->ordered()->get(),
            'moduleOptions' => $moduleOptions,
            'videoCounts'   => TutorialVideo::query()
                ->selectRaw('module, count(*) as total')
                ->groupBy('module')
                ->pluck('total', 'module'),
        ]);
    }

    public function store(
        StoreHelpArticleRequest $request,
        CreateHelpArticleAction $action,
    ): RedirectResponse {
        $action->execute($request->validated());

        return back()->with('success', 'Guía creada correctamente.');
    }

    public function update(
        UpdateHelpArticleRequest $request,
        HelpArticle $helpArticle,
        UpdateHelpArticleAction $action,
    ): RedirectResponse {
        $action->execute($helpArticle, $request->validated());

        return back()->with('success', 'Guía actualizada correctamente.');
    }

    public function destroy(
        HelpArticle $helpArticle,
        DeleteHelpArticleAction $action,
    ): RedirectResponse {
        $action->execute($helpArticle);

        return back()->with('success', 'Guía eliminada correctamente.');
    }

    public function togglePublish(
        HelpArticle $helpArticle,
        ToggleHelpArticlePublicationAction $action,
    ): RedirectResponse {
        $article = $action->execute($helpArticle);

        return back()->with(
            'success',
            $article->is_published ? 'Guía publicada.' : 'Guía ocultada.',
        );
    }

    /**
     * Move an article one position up or down inside its module.
     */
    public function move(
        MoveHelpArticleRequest $request,
        HelpArticle $helpArticle,
        MoveHelpArticleAction $action,
    ): RedirectResponse {
        $action->execute($helpArticle, $request->validated('direction'));

        return back()->with('success', 'Orden actualizado.');
    }
}
