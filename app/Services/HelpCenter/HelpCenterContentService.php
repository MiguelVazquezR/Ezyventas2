<?php

namespace App\Services\HelpCenter;

use App\Models\HelpArticle;
use App\Models\TutorialVideo;
use Illuminate\Support\Collection;

/**
 * Builds the contents of the subscriber-facing help center: every module with
 * its published articles and its active videos.
 */
class HelpCenterContentService
{
    /**
     * Modules that currently have at least one guide or one video.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function modules(): Collection
    {
        $labels = config('tutorials.modules', []);
        $descriptions = config('help_center.descriptions', []);
        $icons = config('help_center.icons', []);

        $articles = HelpArticle::query()->published()->ordered()->get()->groupBy('module');
        $videos = TutorialVideo::query()->active()->ordered()->get()->groupBy('module');

        return collect($labels)
            ->map(function (string $label, string $key) use ($articles, $videos, $descriptions, $icons) {
                $moduleArticles = $articles->get($key, collect());
                $moduleVideos = $videos->get($key, collect());

                if ($moduleArticles->isEmpty() && $moduleVideos->isEmpty()) {
                    return null;
                }

                return [
                    'key'            => $key,
                    'label'          => $label,
                    'description'    => $descriptions[$key] ?? null,
                    'icon'           => $icons[$key] ?? 'pi-book',
                    'articles_count' => $moduleArticles->count(),
                    'videos_count'   => $moduleVideos->count(),
                    'articles'       => $moduleArticles
                        ->map(fn (HelpArticle $article) => [
                            'id'      => $article->id,
                            'title'   => $article->title,
                            'excerpt' => $article->excerpt,
                        ])
                        ->values(),
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * Contents of one module: published guides + active videos by section.
     *
     * @return array<string, mixed>|null
     */
    public function module(string $module): ?array
    {
        $labels = config('tutorials.modules', []);

        if (! array_key_exists($module, $labels)) {
            return null;
        }

        $articles = HelpArticle::query()
            ->forModule($module)
            ->published()
            ->ordered()
            ->get();

        $sections = TutorialVideo::query()
            ->forModule($module)
            ->active()
            ->ordered()
            ->get()
            ->groupBy('section')
            ->map(fn (Collection $items, string $section) => [
                'title'  => $section,
                'videos' => $items->map(fn (TutorialVideo $video) => [
                    'id'          => $video->id,
                    'title'       => $video->title,
                    'description' => $video->description,
                    'duration'    => $video->duration,
                    'url'         => $video->url,
                    'file_url'    => $video->file_url,
                ])->values(),
            ])
            ->values();

        return [
            'key'            => $module,
            'label'          => $labels[$module],
            'description'    => config("help_center.descriptions.{$module}"),
            'icon'           => config("help_center.icons.{$module}", 'pi-book'),
            'articles'       => $articles->map(fn (HelpArticle $article) => [
                'id'         => $article->id,
                'title'      => $article->title,
                'excerpt'    => $article->excerpt,
                'content'    => $article->content,
                'updated_at' => $article->updated_at?->toIso8601String(),
            ])->values(),
            'videos'         => $sections,
            'articles_count' => $articles->count(),
            'videos_count'   => $sections->sum(fn (array $section) => $section['videos']->count()),
        ];
    }
}
