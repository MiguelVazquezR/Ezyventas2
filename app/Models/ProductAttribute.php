<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;

class ProductAttribute extends Model
{
    use HasFactory;

    protected $table = 'product_attributes';

    protected $fillable = [
        'product_id',
        'attributes', 
        'selling_price_modifier', 
        'sku_suffix',
        'global_product_id'
    ];

    protected $casts = [
        'attributes' => 'array',
        'selling_price_modifier' => 'decimal:2',
    ];

    /*
    |--------------------------------------------------------------------------
    | LÓGICA DE NEGOCIO: GESTIÓN DE STOCK (REFACTOR)
    |--------------------------------------------------------------------------
    */

    /**
     * Las variantes no tienen componentes hijos, por lo que el "process" redirige directo al "apply".
     * Interfaz compatible con Product.php para el polimorfismo.
     */
    public function processStockChange(int $branchId, float $quantity, string $action, ?User $user, string $logNote, ?array $extraContext = null): void
    {
        $this->applyDirectStockChange($branchId, $quantity, $action, $user, $logNote, $extraContext);
    }

    /**
     * Actualiza el stock de la variante y refleja la suma total en el producto padre.
     */
    public function applyDirectStockChange(int $branchId, float $quantity, string $action, ?User $user, string $logNote, ?array $extraContext = null): void
    {
        $variantQuery = DB::table('branch_product_attribute')->where('product_attribute_id', $this->id)->where('branch_id', $branchId);
        $parentQuery = DB::table('branch_product')->where('product_id', $this->product_id)->where('branch_id', $branchId);

        // Determine which stock field is being modified
        $stockField = match ($action) {
            'reserve', 'release_reserve' => 'reserved_stock',
            default => 'current_stock',
        };

        // Capture "before" values from variant's pivot
        $beforeRecord = DB::table('branch_product_attribute')
            ->where('product_attribute_id', $this->id)
            ->where('branch_id', $branchId)
            ->first();
        $stockBefore = $beforeRecord ? (float) $beforeRecord->{$stockField} : 0;
        $currentBefore = $beforeRecord ? (float) $beforeRecord->current_stock : 0;
        $reservedBefore = $beforeRecord ? (float) $beforeRecord->reserved_stock : 0;
        $availableBefore = max(0, $currentBefore - $reservedBefore);
        
        $qtyChangedLog = 0;

        switch ($action) {
            case 'reserve':
                $variantQuery->increment('reserved_stock', $quantity);
                $parentQuery->increment('reserved_stock', $quantity);
                $qtyChangedLog = $quantity;
                break;
            case 'deduct':
                $variantQuery->decrement('current_stock', $quantity);
                $parentQuery->decrement('current_stock', $quantity);
                $qtyChangedLog = -$quantity;
                break;
            case 'restock':
                $variantQuery->increment('current_stock', $quantity);
                $parentQuery->increment('current_stock', $quantity);
                $qtyChangedLog = $quantity;
                break;
            case 'finalize_reserve':
                $variantQuery->decrement('reserved_stock', $quantity);
                $variantQuery->decrement('current_stock', $quantity);
                $parentQuery->decrement('reserved_stock', $quantity);
                $parentQuery->decrement('current_stock', $quantity);
                $qtyChangedLog = -$quantity;
                break;
            case 'release_reserve':
                $variantQuery->decrement('reserved_stock', $quantity);
                $parentQuery->decrement('reserved_stock', $quantity);
                $qtyChangedLog = -$quantity;
                break;
        }

        // Capture "after" values from variant's pivot
        $afterRecord = DB::table('branch_product_attribute')
            ->where('product_attribute_id', $this->id)
            ->where('branch_id', $branchId)
            ->first();
        $stockAfter = $afterRecord ? (float) $afterRecord->{$stockField} : 0;
        $currentAfter = $afterRecord ? (float) $afterRecord->current_stock : 0;
        $reservedAfter = $afterRecord ? (float) $afterRecord->reserved_stock : 0;
        $availableAfter = max(0, $currentAfter - $reservedAfter);

        if ($qtyChangedLog != 0) {
            $properties = [
                'quantity_changed' => $qtyChangedLog,
                'stock_before' => $stockBefore,
                'stock_after' => $stockAfter,
                'stock_field' => $stockField,
                'current_before' => $currentBefore,
                'current_after' => $currentAfter,
                'reserved_before' => $reservedBefore,
                'reserved_after' => $reservedAfter,
                'available_before' => $availableBefore,
                'available_after' => $availableAfter,
            ];

            if (!empty($extraContext)) {
                $properties = array_merge($properties, $extraContext);
            }

            // El log lo guardamos en el padre para que sea visible en la vista Show.vue
            $parentProduct = $this->product ?? Product::find($this->product_id);
            if ($parentProduct) {
                activity()->performedOn($parentProduct)
                    ->causedBy($user)
                    ->event('stock_update')
                    ->withProperties($properties)
                    ->log($logNote . " [Variante: " . implode(' ', $this->attributes ?? []) . "]");
            }
        }
    }

    // =========================================================================
    // NUEVO: WRAPPERS SEMÁNTICOS (Igual que en Product.php)
    // =========================================================================
    public function reserveStock(int $branchId, float $qty, ?User $user, string $note, ?array $extraContext = null) { $this->processStockChange($branchId, $qty, 'reserve', $user, $note, $extraContext); }
    public function deductStock(int $branchId, float $qty, ?User $user, string $note, ?array $extraContext = null) { $this->processStockChange($branchId, $qty, 'deduct', $user, $note, $extraContext); }
    public function restock(int $branchId, float $qty, ?User $user, string $note, ?array $extraContext = null) { $this->processStockChange($branchId, $qty, 'restock', $user, $note, $extraContext); }
    public function finalizeLayawayStock(int $branchId, float $qty, ?User $user, string $note, ?array $extraContext = null) { $this->processStockChange($branchId, $qty, 'finalize_reserve', $user, $note, $extraContext); }
    public function releaseLayawayStock(int $branchId, float $qty, ?User $user, string $note, ?array $extraContext = null) { $this->processStockChange($branchId, $qty, 'release_reserve', $user, $note, $extraContext); }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'branch_product_attribute')
            ->using(BranchProductAttribute::class)
            ->withPivot([
                'current_stock',
                'reserved_stock',
                'min_stock',
                'max_stock',
                'location'
            ])
            ->withTimestamps();
    }

    /**
     * Extrae el stock de la sucursal indicada desde la tabla pivote 
     * y lo asigna como atributos dinámicos del modelo.
     */
    public function loadStockForBranch(int $branchId): self
    {
        $vPivot = $this->branches->where('id', $branchId)->first()?->pivot;
        
        $this->current_stock = $vPivot ? $vPivot->current_stock : 0;
        $this->reserved_stock = $vPivot ? $vPivot->reserved_stock : 0;
        $this->available_stock = max(0, $this->current_stock - $this->reserved_stock);
        $this->min_stock = $vPivot ? $vPivot->min_stock : null;
        $this->max_stock = $vPivot ? $vPivot->max_stock : null;
        $this->location = $vPivot ? $vPivot->location : null;
        $this->sku = $this->sku_suffix;

        return $this;
    }

    /**
     * Actualiza el modificador de precio basándose en un precio final deseado.
     */
    public function updatePriceFromTotal(float $newTotalPrice): void
    {
        // Se asume que la relación 'product' está cargada o se cargará.
        $basePrice = $this->product->selling_price;
        $newModifier = $newTotalPrice - $basePrice;
        
        $this->update(['selling_price_modifier' => $newModifier]);
    }
}