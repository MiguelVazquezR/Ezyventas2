<?php

namespace App\Observers\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\StampPaymentMethod;
use App\Models\Billing\Invoice;
use App\Models\Billing\StampMovement;
use App\Models\Billing\StampPurchase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * StampMovementObserver
 *
 * Automatically records stamp movements when:
 * - A StampPurchase reaches status = stamps_applied (entry)
 * - An Invoice is certified (exit, 1 stamp per invoice)
 *
 * Registered for both StampPurchase and Invoice models.
 */
class StampMovementObserver
{
    /**
     * Handle the "created" event for both StampPurchase and Invoice.
     */
    public function created(Model $model): void
    {
        if ($model instanceof StampPurchase) {
            if ($model->status->value === 'stamps_applied' && ! $this->movementExists($model)) {
                $this->recordEntryFromPurchase($model);
            }
        } elseif ($model instanceof Invoice) {
            if ($model->status === InvoiceStatus::CERTIFIED && ! $this->movementExists($model)) {
                $this->recordExitFromInvoice($model);
            }
        }
    }

    /**
     * Handle the "updated" event for both StampPurchase and Invoice.
     */
    public function updated(Model $model): void
    {
        if ($model instanceof StampPurchase) {
            if ($model->isDirty('status') && $model->status->value === 'stamps_applied') {
                $this->handlePurchaseApplied($model);
            }
        } elseif ($model instanceof Invoice) {
            if ($model->isDirty('status') && $model->status === InvoiceStatus::CERTIFIED && ! $this->movementExists($model)) {
                $this->recordExitFromInvoice($model);
            }
        }
    }

    /**
     * Check if a movement already exists for the given reference model.
     */
    private function movementExists(Model $model): bool
    {
        return StampMovement::where('reference_type', get_class($model))
            ->where('reference_id', $model->getKey())
            ->exists();
    }

    /*
    |--------------------------------------------------------------------------
    | Internal helpers
    |--------------------------------------------------------------------------
    */

    private function recordEntryFromPurchase(StampPurchase $purchase): void
    {
        // Build a human-readable description
        $description = $this->buildPurchaseDescription($purchase);

        $quantity = $purchase->adjustment_type === 'remove'
            ? $purchase->stamp_quantity  // will be treated as negative below
            : $purchase->stamp_quantity;

        $type = $purchase->adjustment_type === 'remove' ? 'exit' : 'entry';

        $balanceAfter = $this->calculateNextBalance(
            $purchase->fiscal_profile_id,
            $type === 'entry' ? $quantity : -$quantity,
        );

        $metadata = [
            'payment_method'  => $purchase->payment_method?->value,
            'status'          => $purchase->status?->value,
            'amount_total'    => (float) $purchase->amount_total,
            'unit_price'      => (float) $purchase->unit_price,
            'admin_note'      => $purchase->admin_note,
            'adjustment_type' => $purchase->adjustment_type?->value,
        ];

        StampMovement::create([
            'fiscal_profile_id' => $purchase->fiscal_profile_id,
            'type'              => $type,
            'description'       => $description,
            'quantity'          => $quantity,
            'balance_after'     => max($balanceAfter, 0),
            'reference_type'    => StampPurchase::class,
            'reference_id'      => $purchase->id,
            'metadata'          => $metadata,
        ]);
    }

    private function recordExitFromInvoice(Invoice $invoice): void
    {
        $folio = $invoice->series && $invoice->folio
            ? "{$invoice->series}-{$invoice->folio}"
            : "Folio #{$invoice->id}";

        $description = "Timbrado de factura {$folio}";

        $balanceAfter = $this->calculateNextBalance(
            $invoice->fiscal_profile_id,
            -1, // Each certified invoice consumes 1 stamp
        );

        $metadata = [
            'invoice_id'      => $invoice->id,
            'series'          => $invoice->series,
            'folio'           => $invoice->folio,
            'uuid'            => $invoice->uuid,
            'total'           => (float) $invoice->total,
            'fecha_timbrado'  => $invoice->fecha_timbrado?->toISOString(),
        ];

        StampMovement::create([
            'fiscal_profile_id' => $invoice->fiscal_profile_id,
            'type'              => 'exit',
            'description'       => $description,
            'quantity'          => 1,
            'balance_after'     => max($balanceAfter, 0),
            'reference_type'    => Invoice::class,
            'reference_id'      => $invoice->id,
            'metadata'          => $metadata,
        ]);
    }

    /**
     * Build a human-readable description in Spanish for a StampPurchase.
     */
    private function buildPurchaseDescription(StampPurchase $purchase): string
    {
        // Manual adjustments (superadmin)
        if ($purchase->payment_method === StampPaymentMethod::MANUAL_ADJUSTMENT) {
            if ($purchase->adjustment_type?->value === 'remove') {
                return $purchase->admin_note
                    ? "Ajuste manual: {$purchase->admin_note}"
                    : 'Ajuste manual (eliminación de timbres)';
            }

            if ($purchase->admin_note) {
                return $purchase->admin_note;
            }

            return 'Ajuste manual (carga de timbres)';
        }

        // Mercado Pago or bank transfer purchases
        $methodLabels = [
            'mercadopago'    => 'Mercado Pago',
            'bank_transfer'  => 'Transferencia bancaria',
        ];

        $method = $methodLabels[$purchase->payment_method?->value] ?? $purchase->payment_method?->value;

        $statusLabels = [
            'pending'         => 'Pendiente',
            'awaiting_review' => 'En revisión',
            'approved'        => 'Aprobado',
            'rejected'        => 'Rechazado',
            'failed'          => 'Fallido',
            'stamps_applied'  => 'Acreditado',
        ];

        $status = $statusLabels[$purchase->status?->value] ?? $purchase->status?->value;

        return "Compra de timbres por {$method} ({$status})";
    }

    /**
     * Calculate the running balance after a movement.
     * Uses the last known balance_after for this fiscal profile.
     */
    private function calculateNextBalance(int $fiscalProfileId, int $delta): int
    {
        $last = StampMovement::where('fiscal_profile_id', $fiscalProfileId)
            ->latest('id')
            ->value('balance_after');

        return ($last ?? 0) + $delta;
    }

    /**
     * Handle a StampPurchase reaching stamps_applied.
     *
     * If a pending movement already exists (bank_transfer flow), update it
     * with the correct balance_after and recalculate subsequent movements.
     * Otherwise, create a new movement entry.
     */
    private function handlePurchaseApplied(StampPurchase $purchase): void
    {
        $existing = StampMovement::where('reference_type', StampPurchase::class)
            ->where('reference_id', $purchase->id)
            ->first();

        if ($existing && ($existing->metadata['status'] ?? null) === 'pending') {
            // Transition pending → applied: update balance_after and metadata
            $delta = $purchase->stamp_quantity;
            $newBalance = ($existing->balance_after ?? 0) + $delta;

            $existing->update([
                'description'   => $this->buildPurchaseDescription($purchase),
                'balance_after' => $newBalance,
                'metadata'      => array_merge($existing->metadata ?? [], [
                    'status' => 'stamps_applied',
                ]),
            ]);

            // Bump all subsequent movements' balance_after by the same delta
            $this->bumpSubsequentBalances($purchase->fiscal_profile_id, $existing->id, $delta);

            return;
        }

        // No pending movement exists — create a fresh one (MP, manual adjustments, etc.)
        if (! $this->movementExists($purchase)) {
            $this->recordEntryFromPurchase($purchase);
        }
    }

    /**
     * Add $delta to balance_after for all movements after $movementId.
     */
    private function bumpSubsequentBalances(int $fiscalProfileId, int $movementId, int $delta): void
    {
        StampMovement::where('fiscal_profile_id', $fiscalProfileId)
            ->where('id', '>', $movementId)
            ->update(['balance_after' => DB::raw("balance_after + {$delta}")]);
    }
}
