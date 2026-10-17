<?php

namespace App\Actions\HelpCenter;

use App\Models\HelpArticle;

class MoveHelpArticleAction
{
    /**
     * Move an article one position up or down inside its module.
     */
    public function execute(HelpArticle $article, string $direction): void
    {
        $siblings = HelpArticle::query()
            ->forModule($article->module)
            ->ordered()
            ->get();

        $index = $siblings->search(fn (HelpArticle $item) => $item->id === $article->id);

        if ($index === false) {
            return;
        }

        $target = $direction === 'up' ? $index - 1 : $index + 1;

        if ($target < 0 || $target >= $siblings->count()) {
            return;
        }

        $reordered = $siblings->all();
        [$reordered[$index], $reordered[$target]] = [$reordered[$target], $reordered[$index]];

        foreach ($reordered as $position => $item) {
            $item->update(['sort_order' => $position + 1]);
        }
    }
}
