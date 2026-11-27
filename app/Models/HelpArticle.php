<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Documentation article shown in the help center ("Centro de ayuda").
 *
 * Articles are grouped by the same module keys used by the video tutorials
 * (config/tutorials.php), so one module page displays its written guides and
 * its videos together.
 */
class HelpArticle extends Model
{
    protected $table = 'help_center_articles';

    protected $fillable = [
        'module',
        'title',
        'excerpt',
        'content',
        'sort_order',
        'is_published',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'sort_order'   => 'integer',
            'is_published' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Scope to only articles visible to subscribers.
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true)
            ->where(function (Builder $query) {
                $query->whereNull('published_at')
                    ->orWhere('published_at', '<=', now());
            });
    }

    /**
     * Scope to the articles of one module (key from config/tutorials.php).
     */
    public function scopeForModule(Builder $query, string $module): Builder
    {
        return $query->where('module', $module);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors
    |--------------------------------------------------------------------------
    */

    /**
     * Human readable module label (config/tutorials.php).
     */
    public function getModuleLabelAttribute(): string
    {
        return config("tutorials.modules.{$this->module}", $this->module);
    }
}
