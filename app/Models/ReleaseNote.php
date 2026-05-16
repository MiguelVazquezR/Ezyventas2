<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class ReleaseNote extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia;

    protected $fillable = [
        'version',
        'title',
        'excerpt',
        'content',
        'is_published',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    /**
     * Scope para obtener solo las novedades publicadas y ordenadas por la más reciente.
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true)
            ->where('published_at', '<=', now())
            ->orderBy('published_at', 'desc');
    }

    /**
     * Usuarios que han marcado esta novedad como leída.
     */
    public function readers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'release_note_user')
            ->withPivot('read_at');
    }

    /**
     * Registra las colecciones de media de Spatie.
     */
    public function registerMediaCollections(): void
    {
        // Colección principal para las imágenes, videos o gifs de esta novedad
        $this->addMediaCollection('gallery');
    }
}
