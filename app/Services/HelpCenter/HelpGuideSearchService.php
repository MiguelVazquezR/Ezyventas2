<?php

namespace App\Services\HelpCenter;

use App\Models\HelpArticle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Keyword search across the written guides of the help center ("Centro de ayuda").
 *
 * The AI agent uses it to answer "how do I do this process?" questions with a
 * direct link to the matching guide.
 */
class HelpGuideSearchService
{
    /**
     * Accent map used for accent-insensitive comparisons. Every replacement keeps
     * the original string length, so match positions stay valid.
     */
    private const ACCENTS = [
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a',
        'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
        'ñ' => 'n', 'ç' => 'c',
    ];

    /**
     * Very common words that add noise to the keyword search.
     */
    private const STOP_WORDS = [
        'de', 'del', 'la', 'las', 'el', 'los', 'un', 'una', 'unos', 'unas',
        'que', 'como', 'para', 'por', 'con', 'sin', 'al', 'y', 'o', 'en',
        'se', 'su', 'sus', 'mi', 'mis', 'tu', 'tus', 'es', 'son', 'esta',
        'este', 'esto', 'estas', 'estos', 'lo', 'le', 'les', 'me', 'te',
        'nos', 'mas', 'pero', 'si', 'no', 'ya', 'muy', 'todo', 'toda',
        'todos', 'todas', 'hacer', 'hago', 'puedo', 'puede', 'quiero',
        'necesito', 'ayuda', 'favor', 'donde', 'cuando', 'cual', 'cuales',
        'sobre', 'entre', 'desde', 'hasta', 'hay', 'ser', 'estar', 'tengo',
        'tiene', 'fue', 'era', 'the', 'and', 'how', 'help',
    ];

    /**
     * Find the published guides that best match the user's question.
     *
     * @return array{
     *     message: string,
     *     help_center_url: string,
     *     guides: array<int, array<string, mixed>>,
     *     related_modules: array<int, array<string, string>>
     * }
     */
    public function search(string $query, ?string $module = null, int $limit = 5): array
    {
        $moduleKey = $this->resolveModuleKey($module);
        $tokens = $this->tokens($query);
        $keywords = array_column($tokens, 'normalized');

        $matches = $keywords === []
            ? collect()
            : $this->rank($this->prefilter($moduleKey, $tokens), $keywords);

        if ($matches->isEmpty() && $keywords !== []) {
            // Accent-insensitive fallback: SQL LIKE cannot guarantee accent
            // matching on every driver (e.g. SQLite), so rescore every guide of
            // the module in PHP before reporting "no results".
            $matches = $this->rank($this->moduleGuides($moduleKey), $keywords);
        }

        $guides = $matches
            ->take($limit)
            ->map(fn (array $row) => $this->presentGuide($row['article'], $keywords))
            ->values()
            ->all();

        return [
            'message' => $guides === []
                ? 'No encontré una guía del centro de ayuda para esa consulta. Ofrece al usuario el centro de ayuda completo para que explore por módulo.'
                : 'Guías del centro de ayuda que coinciden con la consulta.',
            'help_center_url' => route('help-center.index'),
            'guides' => $guides,
            'related_modules' => $this->relatedModules($guides),
        ];
    }

    /**
     * Every help center module that currently has at least one published guide.
     *
     * @return array<int, array<string, mixed>>
     */
    public function modules(): array
    {
        $counts = HelpArticle::query()
            ->published()
            ->selectRaw('module, count(*) as total')
            ->groupBy('module')
            ->pluck('total', 'module');

        return collect(config('tutorials.modules', []))
            ->filter(fn (string $label, string $key) => (int) ($counts[$key] ?? 0) > 0)
            ->map(fn (string $label, string $key) => [
                'key' => $key,
                'label' => $label,
                'description' => config("help_center.descriptions.{$key}"),
                'guides_count' => (int) $counts[$key],
                'url' => route('help-center.show', $key),
            ])
            ->values()
            ->all();
    }

    /*
    |--------------------------------------------------------------------------
    | Matching
    |--------------------------------------------------------------------------
    */

    /**
     * Narrow the candidate rows with SQL LIKE before scoring them in PHP.
     *
     * @param  array<int, array{raw: string, normalized: string}>  $tokens
     * @return Collection<int, HelpArticle>
     */
    private function prefilter(?string $module, array $tokens): Collection
    {
        return HelpArticle::query()
            ->published()
            ->when($module, fn (Builder $query) => $query->forModule($module))
            ->where(function (Builder $query) use ($tokens) {
                foreach ($tokens as $token) {
                    $like = '%' . $this->escapeLike($token['raw']) . '%';

                    $query->orWhere('title', 'like', $like)
                        ->orWhere('excerpt', 'like', $like)
                        ->orWhere('content', 'like', $like);
                }
            })
            ->ordered()
            ->get();
    }

    /**
     * @return Collection<int, HelpArticle>
     */
    private function moduleGuides(?string $module): Collection
    {
        return HelpArticle::query()
            ->published()
            ->when($module, fn (Builder $query) => $query->forModule($module))
            ->ordered()
            ->get();
    }

    /**
     * Score every candidate and keep only the ones with at least one match.
     *
     * When two guides score the same, the shorter title wins because it is more
     * specific (e.g. "Cómo crear una factura" over a long multi-topic guide).
     *
     * @param  Collection<int, HelpArticle>  $articles
     * @param  array<int, string>  $keywords
     * @return Collection<int, array{article: HelpArticle, score: int, title_length: int}>
     */
    private function rank(Collection $articles, array $keywords): Collection
    {
        return $articles
            ->map(fn (HelpArticle $article) => [
                'article' => $article,
                'score' => $this->score($article, $keywords),
                'title_length' => mb_strlen($article->title),
            ])
            ->filter(fn (array $row) => $row['score'] > 0)
            ->sortBy([
                ['score', 'desc'],
                ['title_length', 'asc'],
            ])
            ->values();
    }

    /**
     * Title matches weigh more than the summary, and the summary more than the
     * body of the guide.
     *
     * @param  array<int, string>  $keywords
     */
    private function score(HelpArticle $article, array $keywords): int
    {
        $title = $this->normalize($article->title);
        $excerpt = $this->normalize((string) $article->excerpt);
        $content = $this->normalize($this->plainText((string) $article->content));

        $score = 0;

        foreach ($keywords as $keyword) {
            $score += str_contains($title, $keyword) ? 6 : 0;
            $score += str_contains($excerpt, $keyword) ? 3 : 0;
            $score += str_contains($content, $keyword) ? 1 : 0;
        }

        return $score;
    }

    /**
     * Shape one guide for the AI agent, including the deep link to its anchor.
     *
     * @param  array<int, string>  $keywords
     * @return array<string, mixed>
     */
    private function presentGuide(HelpArticle $article, array $keywords): array
    {
        return [
            'title' => $article->title,
            'module' => $article->module,
            'module_label' => $article->module_label,
            'excerpt' => $article->excerpt,
            'snippet' => $this->snippet($article, $keywords),
            'url' => route('help-center.show', $article->module) . '#guide-' . $article->id,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $guides
     * @return array<int, array<string, string>>
     */
    private function relatedModules(array $guides): array
    {
        return collect($guides)
            ->unique('module')
            ->map(fn (array $guide) => [
                'key' => $guide['module'],
                'label' => $guide['module_label'],
                'url' => route('help-center.show', $guide['module']),
            ])
            ->values()
            ->all();
    }

    /*
    |--------------------------------------------------------------------------
    | Text helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Split the question into meaningful keywords.
     *
     * @return array<int, array{raw: string, normalized: string}>
     */
    private function tokens(string $query): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $tokens = [];

        foreach ($words as $word) {
            $normalized = trim(strtr($word, self::ACCENTS));

            if (mb_strlen($normalized) < 3 || in_array($normalized, self::STOP_WORDS, true)) {
                continue;
            }

            // Keyed by the normalized form to drop duplicates and accented variants.
            // Words of 6+ letters match by prefix, so "facturacion" also finds
            // "facturar"/"facturación" and "productos" finds "producto".
            $tokens[$normalized] = [
                'raw' => mb_substr($word, 0, 6),
                'normalized' => mb_strlen($normalized) >= 6 ? mb_substr($normalized, 0, 6) : $normalized,
            ];
        }

        return array_values($tokens);
    }

    /**
     * Lowercase and strip accents so comparisons ignore both.
     */
    private function normalize(string $value): string
    {
        $value = strtr(mb_strtolower(strip_tags($value)), self::ACCENTS);

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    /**
     * Lowercase and strip accents without collapsing whitespace, keeping the
     * original string length so the index of a match can be reused.
     */
    private function comparisonText(string $value): string
    {
        return strtr(mb_strtolower($value), self::ACCENTS);
    }

    /**
     * Convert the guide HTML into readable plain text.
     */
    private function plainText(string $html): string
    {
        $text = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', $html) ?? $html;
        $text = preg_replace('/<br\s*\/?>/i', ' ', $text) ?? $text;
        $text = preg_replace('/<\/(p|li|h[1-6]|div)>/i', ' ', $text) ?? $text;

        return html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Short plain-text fragment around the first keyword found in the guide body.
     *
     * @param  array<int, string>  $keywords
     */
    private function snippet(HelpArticle $article, array $keywords, int $length = 180): ?string
    {
        $plain = trim($this->plainText((string) $article->content));

        if ($plain === '') {
            return $article->excerpt;
        }

        $haystack = $this->comparisonText($plain);
        $position = null;

        foreach ($keywords as $keyword) {
            $found = mb_strpos($haystack, $keyword);

            if ($found !== false && ($position === null || $found < $position)) {
                $position = $found;
            }
        }

        $start = max(0, ($position ?? 0) - 60);
        $excerpt = trim(preg_replace('/\s+/u', ' ', mb_substr($plain, $start, $length)) ?? '');

        $prefix = $start > 0 ? '… ' : '';
        $suffix = ($start + $length) < mb_strlen($plain) ? ' …' : '';

        return $prefix . $excerpt . $suffix;
    }

    /**
     * Accept both the module key ("billing") and its label ("Facturación").
     */
    private function resolveModuleKey(?string $module): ?string
    {
        if ($module === null || trim($module) === '') {
            return null;
        }

        $labels = config('tutorials.modules', []);
        $candidate = trim($module);

        if (array_key_exists($candidate, $labels)) {
            return $candidate;
        }

        $normalized = $this->normalize($candidate);

        foreach ($labels as $key => $label) {
            if ($this->normalize($label) === $normalized) {
                return $key;
            }
        }

        return $candidate;
    }

    /**
     * Escape LIKE wildcards so a keyword is matched literally.
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}

