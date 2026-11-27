<?php

namespace App\Actions\HelpCenter;

use App\Models\HelpArticle;

class DeleteHelpArticleAction
{
    public function execute(HelpArticle $article): void
    {
        $article->delete();
    }
}
