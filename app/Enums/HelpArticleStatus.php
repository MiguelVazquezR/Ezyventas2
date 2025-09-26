<?php

namespace App\Enums;

enum HelpArticleStatus: string
{
    case PUBLISHED = 'published';
    case DRAFT = 'draft';
}