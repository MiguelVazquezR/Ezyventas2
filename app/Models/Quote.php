<?php

namespace App\Models;

use App\Enums\QuoteStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Quote extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'folio',
        'branch_id',
        'user_id',
        'customer_id',
        'transaction_id',
        'parent_quote_id',
        'expiry_date',
        'status',
        'subtotal',
        'total_discount',
        'total_tax',
        'tax_type',
        'tax_rate',
        'shipping_cost',
        'total_amount',
        'notes',
        'version_number',
        'custom_fields',
        'recipient_name',
        'recipient_email',
        'recipient_phone',
        'shipping_address',
        'status_changed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => QuoteStatus::class,
            'expiry_date' => 'date',
            'status_changed_at' => 'datetime',
            'subtotal' => 'decimal:2',
            'total_discount' => 'decimal:2',
            'total_tax' => 'decimal:2',
            'shipping_cost' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'custom_fields' => 'array',
            'shipping_address' => 'array',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'folio',
                'status',
                'expiry_date',
                'subtotal',
                'total_discount',
                'total_tax',
                'shipping_cost',
                'total_amount',
                'notes',
                'recipient_name',
                'recipient_email',
                'recipient_phone',
                'shipping_address',
                'custom_fields'
            ])
            ->setDescriptionForEvent(fn(string $eventName) => "La cotización ha sido {$this->translateEventName($eventName)}")
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    private function translateEventName(string $eventName): string
    {
        return ['created' => 'creada', 'updated' => 'actualizada', 'deleted' => 'eliminada'][$eventName] ?? $eventName;
    }

    /*
    |--------------------------------------------------------------------------
    | LÓGICA DE NEGOCIO Y HELPERS (REFACTOR)
    |--------------------------------------------------------------------------
    */

    /**
     * Sincroniza los items de la cotización.
     */
    public function syncItems(array $itemsData): void
    {
        $this->items()->delete(); 
        foreach ($itemsData as $itemData) {
            $this->items()->create($itemData);
        }
    }

    /**
     * Deduce el stock de todos los items de la cotización (ej. al convertir a venta).
     */
    public function deductStockForSale(?User $user = null): void
    {
        $note = "Conversión de Cotización a Venta #{$this->folio}";
        foreach ($this->items as $item) {
            if ($item->itemable && method_exists($item->itemable, 'deductStock')) {
                $item->itemable->deductStock($this->branch_id, $item->quantity, $user, $note);
            }
        }
    }

    /**
     * Retorna el stock de todos los items (ej. si se cancela la venta originada).
     */
    public function returnStockFromCancelledSale(?User $user = null): void
    {
        $note = "Cancelación de Venta derivada de Cotización #{$this->folio}";
        foreach ($this->items as $item) {
            if ($item->itemable && method_exists($item->itemable, 'restock')) {
                $item->itemable->restock($this->branch_id, $item->quantity, $user, $note);
            }
        }
    }

    /**
     * Crea una nueva versión de esta cotización (Duplicado).
     */
    public function createNewVersion(): self
    {
        $newVersionNumber = ($this->versions()->max('version_number') ?? $this->version_number) + 1;

        $replicatedQuote = $this->replicate()->fill([
            'parent_quote_id' => $this->parent_quote_id ?? $this->id,
            'version_number' => $newVersionNumber,
            'status' => QuoteStatus::DRAFT,
            'folio' => $this->folio . '-V' . $newVersionNumber,
        ]);
        $replicatedQuote->save();

        foreach ($this->items as $item) {
            $replicatedQuote->items()->create($item->toArray());
        }

        return $replicatedQuote;
    }

    /**
     * Genera el siguiente folio consecutivo para una cotización nueva.
     */
    public static function generateFolio(int $branchId): string
    {
        $lastQuote = self::where('branch_id', $branchId)
            ->whereNull('parent_quote_id') // Ignorar sub-versiones (ej. COT-001-V2) para calcular el número
            ->orderByRaw('CAST(SUBSTRING(folio, 5) AS UNSIGNED) DESC') // Toma el número después de 'COT-'
            ->first();
        
        $nextFolioNumber = $lastQuote ? ((int) substr($lastQuote->folio, 4)) + 1 : 1;
        
        // Rellenar con ceros a la izquierda (ej. COT-001, COT-002)
        return 'COT-' . str_pad($nextFolioNumber, 3, '0', STR_PAD_LEFT);
    }


    /*
    |--------------------------------------------------------------------------
    | RELACIONES
    |--------------------------------------------------------------------------
    */

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Quote::class, 'parent_quote_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(Quote::class, 'parent_quote_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuoteItem::class);
    }
}