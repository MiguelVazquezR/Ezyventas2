<?php

namespace App\Models;

use App\Traits\HasSubscription;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Illuminate\Support\Str;

class Service extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia, LogsActivity, HasSubscription;

    protected $fillable = [
        'category_id',
        'branch_id', // Se mantiene como la sucursal "Creadora/Dueña"
        'name',
        'description',
        'slug',
        'sat_product_code',
        'sat_unit_code',
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

    /**
     * Genera un slug único para el servicio respetando los límites de la suscripción.
     */
    public static function generateUniqueSlug(string $name, int $subscriptionId, ?int $ignoreId = null): string
    {
        $baseSlug = Str::slug($name);
        $slug = $baseSlug;
        $counter = 1;
        
        $query = static::whereHas('branch', fn($q) => $q->where('subscription_id', $subscriptionId));
        
        if ($ignoreId) {
            $query->where('id', '!=', $ignoreId);
        }

        while ($query->clone()->where('slug', $slug)->exists()) {
            $slug = $baseSlug . '-' . $counter++;
        }
        
        return $slug;
    }

    /**
     * Sincroniza, actualiza y elimina las variantes del servicio.
     */
    public function syncVariants(array $variantsData): void
    {
        if (empty($variantsData)) {
            $this->variants()->delete();
            return;
        }

        $existingVariantIds = [];
        $newVariantsToInsert = [];
        
        foreach ($variantsData as $variantData) {
            if (!empty($variantData['id'])) {
                $variant = $this->variants()->find($variantData['id']);
                if ($variant) {
                    $variant->update([
                        'name' => $variantData['name'],
                        'price' => $variantData['price'],
                        'duration_estimate' => $variantData['duration_estimate'] ?? null,
                    ]);
                    $existingVariantIds[] = $variant->id;
                }
            } else {
                $newVariantsToInsert[] = [
                    'service_id' => $this->id,
                    'name' => $variantData['name'],
                    'price' => $variantData['price'],
                    'duration_estimate' => $variantData['duration_estimate'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        if (!empty($newVariantsToInsert)) {
            \App\Models\ServiceVariant::insert($newVariantsToInsert);
            $newInsertedIds = $this->variants()
                ->where('created_at', '>=', now()->subSeconds(5))
                ->pluck('id')->toArray();
            $existingVariantIds = array_merge($existingVariantIds, $newInsertedIds);
        }

        if (!empty($existingVariantIds)) {
            $this->variants()->whereNotIn('id', $existingVariantIds)->delete();
        } else {
            $this->variants()->delete();
        }
    }
}