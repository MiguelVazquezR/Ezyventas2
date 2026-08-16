<?php

namespace App\Services\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\StampPaymentMethod;
use App\Models\Billing\FiscalProfile;
use App\Models\Billing\Invoice;
use App\Models\Billing\StampMovement;
use App\Models\Billing\StampPurchase;
use Illuminate\Support\Facades\DB;

/**
 * StampMovementService
 *
 * Encapsulates the logic for recording stamp movements and backfilling
 * historical data from existing StampPurchase and Invoice records.
 */
class StampMovementService
{
    /**
     * Grant the welcome (gift) stamps for a new fiscal profile.
     *
     * Idempotent: a profile only receives the gift once. Gift movements are
     * flagged with metadata.source = 'gift' so they can be excluded from the
     * PAC reconciliation (they do not exist in the real account balance —
     * they are local wallet stamps so the RFC can test the module).
     *
     * @return StampMovement|null Null when the profile already has the gift
     *                            or when the quantity is not positive.
     */
    public function grantWelcomeStamps(FiscalProfile $profile, int $quantity = 5): ?StampMovement
    {
        if ($quantity <= 0) {
            return null;
        }

        // Idempotent — one gift per profile, ever.
        $alreadyGranted = StampMovement::where('fiscal_profile_id', $profile->id)
            ->where('metadata->source', 'gift')
            ->exists();

        if ($alreadyGranted) {
            return null;
        }

        $lastBalance = StampMovement::where('fiscal_profile_id', $profile->id)
            ->latest('id')
            ->value('balance_after') ?? 0;

        return StampMovement::create([
            'fiscal_profile_id' => $profile->id,
            'type'              => 'entry',
            'description'       => 'Timbres de regalo de bienvenida',
            'quantity'          => $quantity,
            'balance_after'     => $lastBalance + $quantity,
            'metadata'          => ['source' => 'gift'],
        ]);
    }

    /**
     * Backfill stamp_movements for all fiscal profiles.
     * Idempotent — skips profiles that already have movements.
     */
    public function backfillAll(): int
    {
        $count = 0;

        FiscalProfile::chunk(50, function ($profiles) use (&$count) {
            foreach ($profiles as $profile) {
                $count += $this->backfillForProfile($profile);
            }
        });

        return $count;
    }

    /**
     * Backfill movements for a single fiscal profile.
     * Returns the number of movements created.
     */
    public function backfillForProfile(FiscalProfile $profile): int
    {
        // Skip if already backfilled
        if (StampMovement::where('fiscal_profile_id', $profile->id)->exists()) {
            return 0;
        }

        $created = 0;

        DB::transaction(function () use ($profile, &$created) {
            // Build chronological events list
            $events = [];

            // 1. Stamp purchases with stamps_applied status (entries)
            $purchases = StampPurchase::where('fiscal_profile_id', $profile->id)
                ->where('status', 'stamps_applied')
                ->get();

            foreach ($purchases as $purchase) {
                $date = $purchase->stamps_applied_at ?? $purchase->created_at;
                $events[] = [
                    'date'     => $date,
                    'type'     => 'purchase',
                    'model'    => $purchase,
                ];
            }

            // 2. Certified invoices (exits — 1 stamp per invoice)
            $invoices = Invoice::where('fiscal_profile_id', $profile->id)
                ->where('status', InvoiceStatus::CERTIFIED)
                ->whereNotNull('fecha_timbrado')
                ->get();

            foreach ($invoices as $invoice) {
                $events[] = [
                    'date'     => $invoice->fecha_timbrado,
                    'type'     => 'invoice',
                    'model'    => $invoice,
                ];
            }

            // Sort by date
            usort($events, fn ($a, $b) => $a['date']->timestamp <=> $b['date']->timestamp);

            $runningBalance = 0;

            foreach ($events as $event) {
                $model = $event['model'];

                if ($event['type'] === 'purchase') {
                    /** @var StampPurchase $model */
                    $qty = $model->stamp_quantity;

                    // adjustment_type is cast to StampAdjustmentType — compare
                    // the enum value, never the enum instance, against the string.
                    $movementType = $model->adjustment_type?->value === 'remove' ? 'exit' : 'entry';

                    if ($movementType === 'exit') {
                        $runningBalance -= $qty;
                    } else {
                        $runningBalance += $qty;
                    }

                    $runningBalance = max($runningBalance, 0);

                    $description = $this->buildPurchaseDescription($model);

                    StampMovement::create([
                        'fiscal_profile_id' => $profile->id,
                        'type'              => $movementType,
                        'description'       => $description,
                        'quantity'          => $qty,
                        'balance_after'     => $runningBalance,
                        'reference_type'    => StampPurchase::class,
                        'reference_id'      => $model->id,
                        'metadata'          => [
                            'payment_method'  => $model->payment_method?->value,
                            'status'          => $model->status?->value,
                            'amount_total'    => (float) $model->amount_total,
                            'unit_price'      => (float) $model->unit_price,
                            'admin_note'      => $model->admin_note,
                            'adjustment_type' => $model->adjustment_type?->value,
                        ],
                        'created_at'        => $event['date'],
                        'updated_at'        => $event['date'],
                    ]);

                    $created++;
                } else {
                    /** @var Invoice $model */
                    $folio = $model->series && $model->folio
                        ? "{$model->series}-{$model->folio}"
                        : "Folio #{$model->id}";

                    $runningBalance -= 1;
                    $runningBalance = max($runningBalance, 0);

                    StampMovement::create([
                        'fiscal_profile_id' => $profile->id,
                        'type'              => 'exit',
                        'description'       => "Timbrado de factura {$folio}",
                        'quantity'          => 1,
                        'balance_after'     => $runningBalance,
                        'reference_type'    => Invoice::class,
                        'reference_id'      => $model->id,
                        'metadata'          => [
                            'invoice_id'      => $model->id,
                            'series'          => $model->series,
                            'folio'           => $model->folio,
                            'uuid'            => $model->uuid,
                            'total'           => (float) $model->total,
                            'fecha_timbrado'  => $model->fecha_timbrado?->toISOString(),
                        ],
                        'created_at'        => $event['date'],
                        'updated_at'        => $event['date'],
                    ]);

                    $created++;
                }
            }
        });

        return $created;
    }

    /**
     * Build a human-readable description in Spanish for a StampPurchase.
     */
    private function buildPurchaseDescription(StampPurchase $purchase): string
    {
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
}
