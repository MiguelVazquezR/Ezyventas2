<?php

namespace App\Actions\HelpCenter;

use App\Models\HelpArticle;

class CreateHelpArticleAction
{
    public function execute(array $data): HelpArticle
    {
        $data['is_published'] = (bool) ($data['is_published'] ?? false);
        $data['published_at'] = $data['is_published'] ? now() : null;
        $data['sort_order']   = $this->nextSortOrder($data['module']);

        return HelpArticle::create($data);
    }

    private function nextSortOrder(string $module): int
    {
        return (int) HelpArticle::query()
            ->forModule($module)
            ->max('sort_order') + 1;
    }
}
