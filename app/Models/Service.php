<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Service extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia, LogsActivity;

    protected $fillable = [
        'category_id',
        'branch_id', // Se mantiene como la sucursal "Creadora/Dueña"
        'name',
        'description',
        'slug',
        'base_price',
        'duration_estimate',
        'show_online',
    ];

    protected $casts = [
        'base_price' => 'decimal:2',
        'show_online' => 'boolean',
    ];

    // Configuración para el historial
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'description', 'category_id', 'base_price', 'duration_estimate', 'show_online'])
            ->setDescriptionForEvent(fn(string $eventName) => "El servicio ha sido {$this->translateEventName($eventName)}")
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    private function translateEventName(string $eventName): string
    {
        return ['created' => 'creado', 'updated' => 'actualizado', 'deleted' => 'eliminado'][$eventName] ?? $eventName;
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Sucursal dueña original del servicio
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * NUEVA RELACIÓN: Sucursales donde el servicio está disponible.
     */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'branch_service');
    }

    /**
     * Obtiene todas las veces que este servicio ha sido un item en una orden.
     */
    public function orderItems(): MorphMany
    {
        return $this->morphMany(ServiceOrderItem::class, 'itemable');
    }

    /**
     * Obtiene las variantes de este servicio (ej. por modelo, calidad, cilindraje).
     */
    public function variants()
    {
        return $this->hasMany(ServiceVariant::class);
    }
}