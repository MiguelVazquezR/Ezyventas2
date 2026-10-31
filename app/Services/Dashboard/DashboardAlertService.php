<?php

namespace App\Services\Dashboard;

use App\Enums\TransactionStatus;
use App\Models\Transaction;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Alerts of the home screen of the mobile app: layaways and credit sales about
 * to expire, and orders waiting to be delivered.
 *
 * The default window (three days) is the same one used by the notification bell
 * of the web app, so every counter of the app agrees with the web dashboard.
 */
class DashboardAlertService
{
    public const DEFAULT_WINDOW_DAYS = 3;

    /**
     * Counter of the "Ventas por vencer" tile.
     */
    public function expiringLayawaysCount(int $branchId, int $days = self::DEFAULT_WINDOW_DAYS): int
    {
        return $this->expiringLayawaysQuery($branchId, $days)->count();
    }

    /**
     * Counter of the "Pedidos por entregar" tile.
     */
    public function upcomingDeliveriesCount(int $branchId, int $days = self::DEFAULT_WINDOW_DAYS): int
    {
        return $this->upcomingDeliveriesQuery($branchId, $days)->count();
    }

    /**
     * Rows behind the "Ventas por vencer" tile, closest expiration first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function expiringLayaways(int $branchId, int $days = self::DEFAULT_WINDOW_DAYS): Collection
    {
        return $this->expiringLayawaysQuery($branchId, $days)
            ->with('customer:id,name,phone')
            ->withSum('payments', 'amount')
            ->orderBy('layaway_expiration_date')
            ->get()
            ->map(function (Transaction $transaction) {
                $paid = $this->paidAmount($transaction);
                $total = (float) $transaction->total;
                $daysRemaining = $this->daysRemaining($transaction->layaway_expiration_date);

                return [
                    'id' => $transaction->id,
                    'folio' => $transaction->folio,
                    'type' => $transaction->status === TransactionStatus::ON_LAYAWAY ? 'apartado' : 'credito',
                    'status' => $transaction->status->value,
                    'customer_id' => $transaction->customer_id,
                    'customer_name' => $transaction->customer?->name ?? 'Público en general',
                    'customer_phone' => $transaction->customer?->phone,
                    'total_amount' => $this->money($total),
                    'total_paid' => $this->money($paid),
                    'pending_amount' => $this->money(max(0, $total - $paid)),
                    'expiration_date' => $transaction->layaway_expiration_date?->toDateString(),
                    'days_remaining' => $daysRemaining,
                    'is_overdue' => $daysRemaining < 0,
                ];
            })
            ->values();
    }

    /**
     * Rows behind the "Pedidos por entregar" tile, closest delivery first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function upcomingDeliveries(int $branchId, int $days = self::DEFAULT_WINDOW_DAYS): Collection
    {
        return $this->upcomingDeliveriesQuery($branchId, $days)
            ->with('customer:id,name,phone')
            ->withSum('payments', 'amount')
            ->orderBy('delivery_date')
            ->get()
            ->map(function (Transaction $transaction) {
                $contact = $transaction->contact_info ?? [];
                $paid = $this->paidAmount($transaction);
                $total = (float) $transaction->total;
                $daysRemaining = $this->daysRemaining($transaction->delivery_date);

                return [
                    'id' => $transaction->id,
                    'folio' => $transaction->folio,
                    'status' => $transaction->status->value,
                    'customer_id' => $transaction->customer_id,
                    'customer_name' => $transaction->customer?->name ?? ($contact['name'] ?? 'Cliente invitado'),
                    'customer_phone' => $transaction->customer?->phone ?? ($contact['phone'] ?? null),
                    'shipping_address' => $transaction->shipping_address,
                    'notes' => $transaction->notes,
                    'total_amount' => $this->money($total),
                    'total_paid' => $this->money($paid),
                    'pending_amount' => $this->money(max(0, $total - $paid)),
                    'delivery_date' => $transaction->delivery_date?->toISOString(),
                    'days_remaining' => $daysRemaining,
                    'is_today' => $transaction->delivery_date?->isToday() ?? false,
                    'is_overdue' => $daysRemaining < 0,
                ];
            })
            ->values();
    }

    /**
     * @return Builder<Transaction>
     */
    private function expiringLayawaysQuery(int $branchId, int $days): Builder
    {
        return Transaction::query()
            ->where('branch_id', $branchId)
            ->whereIn('status', [TransactionStatus::ON_LAYAWAY, TransactionStatus::PENDING])
            ->whereNotNull('layaway_expiration_date')
            ->whereDate('layaway_expiration_date', '<=', now()->addDays($days)->toDateString());
    }

    /**
     * @return Builder<Transaction>
     */
    private function upcomingDeliveriesQuery(int $branchId, int $days): Builder
    {
        return Transaction::query()
            ->where('branch_id', $branchId)
            ->where('status', TransactionStatus::TO_DELIVER)
            ->whereNotNull('delivery_date')
            ->whereDate('delivery_date', '<=', now()->addDays($days)->toDateString());
    }

    /**
     * Amount already collected for the sale. `payments_sum_amount` comes from
     * `withSum('payments', 'amount')`, so the whole list costs one extra query.
     */
    private function paidAmount(Transaction $transaction): float
    {
        return (float) ($transaction->payments_sum_amount ?? 0);
    }

    /**
     * Calendar days from today to the given moment (negative when overdue).
     */
    private function daysRemaining(?CarbonInterface $date): int
    {
        if ($date === null) {
            return 0;
        }

        return (int) now()->startOfDay()->diffInDays($date->copy()->startOfDay(), false);
    }

    private function money(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }
}
