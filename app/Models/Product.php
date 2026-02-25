<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Product extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia, LogsActivity;

    protected $fillable = [
        'name',
        'description',
        'sku',
        'selling_price',
        'price_tiers',
        'cost_price',
        'category_id',
        'provider_id',
        'brand_id',
        'branch_id', // Sucursal "Propietaria/Creadora"
        'global_product_id',
        'measure_unit',
        'currency',
        'show_online',
        'online_price',
        'slug',
        'delivery_days',
        'tags',
        'is_featured',
        'is_on_sale',
        'sale_price',
        'sale_start_date',
        'sale_end_date',
        'weight',
        'length',
        'width',
        'height',
        'requires_shipping',
        'view_count',
        'purchase_count',
    ];

    protected function casts(): array
    {
        return [
            'selling_price' => 'decimal:2',
            'price_tiers' => 'array',
            'cost_price' => 'decimal:2',
            'online_price' => 'decimal:2',
            'sale_price' => 'decimal:2',
            'show_online' => 'boolean',
            'is_featured' => 'boolean',
            'is_on_sale' => 'boolean',
            'requires_shipping' => 'boolean',
            'sale_start_date' => 'datetime',
            'sale_end_date' => 'datetime',
            'tags' => 'array',
            'weight' => 'decimal:2',
            'length' => 'decimal:2',
            'width' => 'decimal:2',
            'height' => 'decimal:2',
        ];
    }

    // protected $appends = ['available_stock'];

    // /**
    //  * Obtiene el stock disponible para la venta (físico - reservado).
    //  */
    // protected function availableStock(): Attribute
    // {
    //     return Attribute::make(
    //         get: fn() => $this->current_stock - $this->reserved_stock,
    //     );
    // }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'name',
                'description',
                'sku',
                'selling_price',
                'price_tiers',
                'cost_price',
                'category_id',
                'brand_id',
                'provider_id',
                'show_online',
                'online_price'
            ])
            ->setDescriptionForEvent(fn(string $eventName) => "El producto ha sido {$this->translateEventName($eventName)}")
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    private function translateEventName(string $eventName): string
    {
        $translations = [
            'created' => 'creado',
            'updated' => 'actualizado',
            'deleted' => 'eliminado',
        ];
        return $translations[$eventName] ?? $eventName;
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('product-general-images')->withResponsiveImages();
        $this->addMediaCollection('product-variant-images')->withResponsiveImages();
    }

    /* RELACIONES */

    public function promotionRules(): MorphToMany
    {
        return $this->morphToMany(Promotion::class, 'itemable', 'promotion_rules');
    }

    public function promotionEffects(): MorphToMany
    {
        return $this->morphToMany(Promotion::class, 'itemable', 'promotion_effects');
    }

    /**
     * Accesor para obtener ambas promociones (reglas y efectos) combinadas.
     * Se accederá mágicamente como $product->promotions
     */
    public function getPromotionsAttribute()
    {
        return $this->promotionRules->merge($this->promotionEffects)->unique('id')->values();
    }

    public function globalProduct(): BelongsTo
    {
        return $this->belongsTo(GlobalProduct::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(ProductReview::class);
    }

    public function transactionItems(): MorphMany
    {
        return $this->morphMany(TransactionItem::class, 'itemable');
    }

    public function productAttributes(): HasMany
    {
        return $this->hasMany(ProductAttribute::class);
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'branch_product')
            ->using(BranchProduct::class)
            ->withPivot([
                'current_stock',
                'reserved_stock',
                'min_stock',
                'max_stock',
                'location'
            ])
            ->withTimestamps();
    }
}