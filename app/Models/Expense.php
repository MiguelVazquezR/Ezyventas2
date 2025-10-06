<?php

namespace App\Models;

use App\Enums\ExpenseStatus;
use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Expense extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'folio',
        'user_id',
        'branch_id',
        'amount',
        'expense_category_id',
        'expense_date',
        'status',
        'description',
        'payment_method',
        'bank_account_id',
        'session_cash_movement_id', // <-- AÑADIR
    ];

    protected function casts(): array
    {
        return [
            'status' => ExpenseStatus::class,
            'amount' => 'decimal:2',
            'expense_date' => 'date',
            'payment_method' => PaymentMethod::class,
        ];
    }
    
    // Configuración para el historial de actividad
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['amount', 'expense_category_id', 'expense_date', 'status', 'description'])
            ->setDescriptionForEvent(fn(string $eventName) => "El gasto ha sido {$this->translateEventName($eventName)}")
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    private function translateEventName(string $eventName): string
    {
        return ['created' => 'creado', 'updated' => 'actualizado', 'deleted' => 'eliminado'][$eventName] ?? $eventName;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function subscription(): HasOneThrough
    {
        return $this->hasOneThrough(Subscription::class, Branch::class, 'id', 'id', 'branch_id', 'subscription_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function sessionCashMovement(): BelongsTo
    {
        return $this->belongsTo(SessionCashMovement::class);
    }
}