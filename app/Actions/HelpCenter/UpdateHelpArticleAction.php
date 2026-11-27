<?php

namespace App\Actions\HelpCenter;

use App\Models\HelpArticle;

class UpdateHelpArticleAction
{
    public function execute(HelpArticle $article, array $data): HelpArticle
    {
        $isPublished = (bool) ($data['is_published'] ?? false);

        $data['is_published'] = $isPublished;

        if ($isPublished && ! $article->is_published) {
            $data['published_at'] = now();
        } elseif (! $isPublished) {
            $data['published_at'] = null;
        }

        // Moving the article to another module sends it to the end of the
        // destination group.
        if ($data['module'] !== $article->module) {
            $data['sort_order'] = $this->nextSortOrder($data['module']);
        }

        $article->update($data);

        return $article;
    }

    private function nextSortOrder(string $module): int
    {
        return (int) HelpArticle::query()
            ->forModule($module)
            ->max('sort_order') + 1;
    }
}
