<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Product extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia, LogsActivity;

    protected $fillable = [
        'name', 'description', 'sku', 'selling_price', 'price_tiers', 'cost_price',
        'category_id', 'provider_id', 'brand_id', 'branch_id', 'global_product_id',
        'measure_unit', 'currency', 'show_online', 'online_price', 'show_in_pos',
        'slug', 'delivery_days', 'tags', 'is_featured', 'is_on_sale', 'sale_price',
        'sale_start_date', 'sale_end_date', 'weight', 'length', 'width', 'height',
        'requires_shipping', 'view_count', 'purchase_count',
    ];

   protected function casts(): array
{
        return [
            'selling_price' => 'decimal:2',
            'price_tiers' => 'array', // <-- ESTA LÍNEA ES LA CLAVE
            'cost_price' => 'decimal:2',
            'online_price' => 'decimal:2',
            'sale_price' => 'decimal:2',
            'show_in_pos' => 'boolean',
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

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'sku', 'selling_price', 'show_in_pos'])
            ->setDescriptionForEvent(fn(string $eventName) => "El producto ha sido {$this->translateEventName($eventName)}")
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    private function translateEventName(string $eventName): string
    {
        return match($eventName) {
            'created' => 'creado',
            'updated' => 'actualizado',
            'deleted' => 'eliminado',
            default => $eventName,
        };
    }

    /*
    |--------------------------------------------------------------------------
    | LÓGICA DE NEGOCIO: GESTIÓN DE STOCK (REFACTOR)
    |--------------------------------------------------------------------------
    */

    /**
     * Punto de entrada principal. Determina si aplica el stock a sí mismo o a sus componentes (Kit).
     */
    public function processStockChange(int $branchId, float $quantity, string $action, ?User $user, string $logNote): void
    {
        $this->loadMissing('components.componentable');
        
        $isComposite = $this->components && $this->components->isNotEmpty();

        if ($isComposite) {
            foreach ($this->components as $component) {
                // Cantidad total a descontar/reservar del componente
                $qtyToProcess = $quantity * $component->quantity;
                // Polimorfismo: Llama al mismo método, ya sea en un Product simple o ProductAttribute
                if($component->componentable) {
                    $component->componentable->applyDirectStockChange($branchId, $qtyToProcess, $action, $user, $logNote . " (Componente)");
                }
            }
        } else {
            // Producto Simple (Sin Kit)
            $this->applyDirectStockChange($branchId, $quantity, $action, $user, $logNote);
        }
    }

    /**
     * Aplica el cambio físico en la BD a la tabla pivote de sucursales.
     */
    public function applyDirectStockChange(int $branchId, float $quantity, string $action, ?User $user, string $logNote): void
    {
        $query = DB::table('branch_product')
            ->where('product_id', $this->id)
            ->where('branch_id', $branchId);

        $qtyChangedLog = 0;

        switch ($action) {
            case 'reserve':
                $query->increment('reserved_stock', $quantity);
                $qtyChangedLog = $quantity;
                break;
            case 'deduct': // Venta directa
                $query->decrement('current_stock', $quantity);
                $qtyChangedLog = -$quantity;
                break;
            case 'restock': // Devoluciones
                $query->increment('current_stock', $quantity);
                $qtyChangedLog = $quantity;
                break;
            case 'finalize_reserve': // Cuando el apartado se liquida
                $query->decrement('reserved_stock', $quantity);
                $query->decrement('current_stock', $quantity);
                $qtyChangedLog = -$quantity;
                break;
            case 'release_reserve': // Cancelar apartado
                $query->decrement('reserved_stock', $quantity);
                $qtyChangedLog = -$quantity; // Log registra que la reserva bajó
                break;
        }

        if ($qtyChangedLog != 0) {
            activity()->performedOn($this)
                ->causedBy($user)
                ->event('stock_update')
                ->withProperties(['quantity_changed' => $qtyChangedLog])
                ->log($logNote);
        }
    }

    // Wrappers semánticos para el Servicio de Transacciones
    public function reserveStock(int $branchId, float $qty, ?User $user, string $note) { $this->processStockChange($branchId, $qty, 'reserve', $user, $note); }
    public function deductStock(int $branchId, float $qty, ?User $user, string $note) { $this->processStockChange($branchId, $qty, 'deduct', $user, $note); }
    public function restock(int $branchId, float $qty, ?User $user, string $note) { $this->processStockChange($branchId, $qty, 'restock', $user, $note); }
    public function finalizeLayawayStock(int $branchId, float $qty, ?User $user, string $note) { $this->processStockChange($branchId, $qty, 'finalize_reserve', $user, $note); }
    public function releaseLayawayStock(int $branchId, float $qty, ?User $user, string $note) { $this->processStockChange($branchId, $qty, 'release_reserve', $user, $note); }


    /* -----------------------------------------------------------------
     | RELACIONES
     | ----------------------------------------------------------------- */
    
    public function promotionRules(): MorphToMany { return $this->morphToMany(Promotion::class, 'itemable', 'promotion_rules'); }
    public function promotionEffects(): MorphToMany { return $this->morphToMany(Promotion::class, 'itemable', 'promotion_effects'); }
    public function getPromotionsAttribute() { return $this->promotionRules->merge($this->promotionEffects)->unique('id')->values(); }
    public function globalProduct(): BelongsTo { return $this->belongsTo(GlobalProduct::class); }
    public function category(): BelongsTo { return $this->belongsTo(Category::class); }
    public function provider(): BelongsTo { return $this->belongsTo(Provider::class); }
    public function brand(): BelongsTo { return $this->belongsTo(Brand::class); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function reviews(): HasMany { return $this->hasMany(ProductReview::class); }
    public function transactionItems(): MorphMany { return $this->morphMany(TransactionItem::class, 'itemable'); }
    public function productAttributes(): HasMany { return $this->hasMany(ProductAttribute::class); }
    
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'branch_product')
            ->using(BranchProduct::class)
            ->withPivot(['current_stock', 'reserved_stock', 'min_stock', 'max_stock', 'location'])
            ->withTimestamps();
    }

    public function components(): HasMany
    {
        return $this->hasMany(ProductComponent::class, 'composite_product_id');
    }

    /* -----------------------------------------------------------------
     | LOGICA DE NEGOCIO Y ACCESORES (REFACTORIZADA)
     | ----------------------------------------------------------------- */

    /**
     * Extrae el stock de la sucursal indicada desde la tabla pivote 
     * y lo asigna como atributos dinámicos del modelo.
     */
    public function loadStockForBranch(int $branchId): self
    {
        $branchPivot = $this->branches->where('id', $branchId)->first()?->pivot;

        $this->current_stock = $branchPivot ? $branchPivot->current_stock : 0;
        $this->reserved_stock = $branchPivot ? $branchPivot->reserved_stock : 0;
        $this->available_stock = max(0, $this->current_stock - $this->reserved_stock);
        $this->min_stock = $branchPivot ? $branchPivot->min_stock : null;
        $this->max_stock = $branchPivot ? $branchPivot->max_stock : null;
        $this->location = $branchPivot ? $branchPivot->location : null;

        // Propagar a variantes si están cargadas
        if ($this->relationLoaded('productAttributes')) {
            $this->productAttributes->each(fn($variant) => $variant->loadStockForBranch($branchId));
        }

        return $this;
    }

    /**
     * Accesor para entregar los componentes (Kits/Combos) formateados
     * listos para ser consumidos por el Frontend (Vue).
     */
    public function getFormattedComponentsAttribute(): \Illuminate\Support\Collection
    {
        return $this->components->map(function ($component) {
            $itemable = $component->componentable;

            if ($component->componentable_type === ProductAttribute::class) {
                // Es una variante
                $parent = $itemable->product;
                $name = $parent->name . ' - ' . implode(' ', array_values($itemable->attributes ?? []));
                $sku = $itemable->sku_suffix ?: $parent->sku;
                $price = $parent->selling_price + $itemable->selling_price_modifier;
            } else {
                // Es un producto simple
                $name = $itemable->name;
                $sku = $itemable->sku;
                $price = $itemable->selling_price;
            }

            return [
                'id' => $component->componentable_id,
                'type' => $component->componentable_type,
                'name' => $name,
                'sku' => $sku,
                'price' => (float) $price,
                'quantity' => (float) $component->quantity,
            ];
        });
    }

    /**
     * Sincroniza los elementos que componen un producto Kit/Composite.
     */
    public function syncComponents(array $compositeItems = []): void
    {
        $this->components()->delete();
        
        if (empty($compositeItems)) {
            return;
        }

        $componentsData = array_map(fn($item) => [
            'componentable_id' => $item['id'],
            'componentable_type' => $item['type'],
            'quantity' => $item['quantity'],
        ], $compositeItems);

        $this->components()->createMany($componentsData);
    }
}