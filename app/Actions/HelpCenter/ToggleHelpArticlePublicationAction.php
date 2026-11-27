<?php

namespace App\Actions\HelpCenter;

use App\Models\HelpArticle;

class ToggleHelpArticlePublicationAction
{
    public function execute(HelpArticle $article): HelpArticle
    {
        $article->update([
            'is_published' => ! $article->is_published,
            'published_at' => $article->is_published ? null : now(),
        ]);

        return $article;
    }
}
