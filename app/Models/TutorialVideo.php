<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * Tutorial video shown inside the tutorial modal of a module.
 *
 * Videos are grouped by `module` (key from config/tutorials.php) and rendered
 * under their `section` (free-text title, e.g. "Pagos y complementos").
 * Each video plays either from an external URL (YouTube/Vimeo) or from a file
 * uploaded to the public disk — at most one of the two is set.
 */
class TutorialVideo extends Model
{
    protected $table = 'tutorial_videos';

    protected $fillable = [
        'module',
        'section',
        'title',
        'description',
        'duration',
        'url',
        'file_path',
        'sort_order',
        'is_active',
    ];

    protected $appends = ['file_url'];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active'  => 'boolean',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Scope to only videos that should be visible to subscribers.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to the videos of one module (key from config/tutorials.php).
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
     * Public URL of the uploaded file (null when the video uses an external URL).
     */
    public function getFileUrlAttribute(): ?string
    {
        if (! $this->file_path) {
            return null;
        }

        /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
        $disk = Storage::disk('public');

        return $disk->url($this->file_path);
    }
}
