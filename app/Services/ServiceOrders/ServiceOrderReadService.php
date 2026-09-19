<?php

namespace App\Services\ServiceOrders;

use App\Models\CustomFieldDefinition;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\ServiceVariant;
use App\Models\Transaction;
use App\Services\BankAccounts\BankAccountQueryService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Collection;

/**
 * Read model of the service orders of a branch: the work list the technician
 * uses on the phone plus the full detail of one order.
 */
class ServiceOrderReadService
{
    /**
     * Supported sort fields, mapped to real columns to avoid SQL injection.
     */
    private const SORT_COLUMNS = [
        'received_at' => 'service_orders.received_at',
        'promised_at' => 'service_orders.promised_at',
        'folio' => 'service_orders.folio',
        'final_total' => 'service_orders.final_total',
    ];

    public function __construct(private readonly BankAccountQueryService $bankAccounts) {}

    /**
     * Service orders of the branch.
     *
     * @param  array<string, mixed>  $filters
     */
    public function queryForBranch(int $branchId, array $filters = []): Builder
    {
        $query = ServiceOrder::query()
            ->where('branch_id', $branchId)
            ->with('transaction.payments');

        if (!empty($filters['search'])) {
            $search = $filters['search'];

            $query->where(function (Builder $q) use ($search) {
                $q->where('customer_name', 'like', "%{$search}%")
                    ->orWhere('item_description', 'like', "%{$search}%")
                    ->orWhere('folio', 'like', "%{$search}%");
            });
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['updated_since'])) {
            $query->where('updated_at', '>=', Carbon::parse($filters['updated_since']));
        }

        return $query->orderBy(
            self::SORT_COLUMNS[$filters['sort_field'] ?? 'received_at'] ?? self::SORT_COLUMNS['received_at'],
            ($filters['sort_order'] ?? 'desc') === 'asc' ? 'asc' : 'desc'
        );
    }

    /**
     * Finds one service order of the branch with its full detail.
     */
    public function findForBranch(int $serviceOrderId, int $branchId): ?ServiceOrder
    {
        return ServiceOrder::query()
            ->where('branch_id', $branchId)
            ->with([
                'branch:id,name,subscription_id',
                'user:id,name',
                'customer:id,name,phone,email,balance',
                'items.itemable' => function (MorphTo $morphTo) {
                    $morphTo->morphWith([
                        Product::class => [],
                        Service::class => [],
                        ServiceVariant::class => [],
                    ]);
                },
                'media',
                'transaction.payments.bankAccount',
            ])
            ->find($serviceOrderId);
    }

    /**
     * Compact payload of the work list.
     *
     * @return array<string, mixed>
     */
    public function listPayload(ServiceOrder $serviceOrder): array
    {
        $totalPaid = (float) ($serviceOrder->transaction?->total_paid ?? 0);
        $finalTotal = (float) $serviceOrder->final_total;

        return [
            'id' => $serviceOrder->id,
            'folio' => $serviceOrder->folio,
            'customer_name' => $serviceOrder->customer_name,
            'customer_phone' => $serviceOrder->customer_phone,
            'item_description' => $serviceOrder->item_description,
            'status' => $serviceOrder->status instanceof \BackedEnum ? $serviceOrder->status->value : $serviceOrder->status,
            'technician_name' => $serviceOrder->technician_name,
            'received_at' => $serviceOrder->received_at?->toIso8601String(),
            'promised_at' => $serviceOrder->promised_at?->toIso8601String(),
            'subtotal' => (string) $serviceOrder->subtotal,
            'discount_amount' => (string) $serviceOrder->discount_amount,
            'final_total' => (string) $serviceOrder->final_total,
            'total_paid' => $totalPaid,
            'amount_due' => max(0, $finalTotal - $totalPaid),
            'has_transaction' => $serviceOrder->transaction !== null,
            'created_at' => $serviceOrder->created_at?->toIso8601String(),
        ];
    }

    /**
     * Full payload: contact data, items, evidence photos, linked sale and history.
     *
     * @return array<string, mixed>
     */
    public function detailPayload(ServiceOrder $serviceOrder): array
    {
        return array_merge($this->listPayload($serviceOrder), [
            'customer' => $serviceOrder->customer ? [
                'id' => $serviceOrder->customer->id,
                'name' => $serviceOrder->customer->name,
                'phone' => $serviceOrder->customer->phone,
                'email' => $serviceOrder->customer->email,
                'balance' => (string) $serviceOrder->customer->balance,
            ] : null,
            'customer_email' => $serviceOrder->customer_email,
            'customer_address' => $serviceOrder->customer_address,
            'reported_problems' => $serviceOrder->reported_problems,
            'technician_diagnosis' => $serviceOrder->technician_diagnosis,
            'technician_commission_type' => $serviceOrder->technician_commission_type,
            'technician_commission_value' => (string) $serviceOrder->technician_commission_value,
            'discount_type' => $serviceOrder->discount_type,
            'discount_value' => (string) $serviceOrder->discount_value,
            'custom_fields' => $serviceOrder->custom_fields,
            'custom_field_definitions' => $this->customFieldDefinitions($serviceOrder),
            'items' => $this->itemsPayload($serviceOrder),
            'media' => $this->mediaPayload($serviceOrder),
            'transaction' => $this->transactionPayload($serviceOrder->transaction),
            'activities' => $this->activitiesPayload($serviceOrder),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function itemsPayload(ServiceOrder $serviceOrder): array
    {
        return $serviceOrder->items->map(fn ($item) => [
            'id' => $item->id,
            // Older orders may not store a description: fall back to the item name.
            'description' => $item->description ?: $item->itemable?->name,
            'itemable_type' => $item->itemable_type,
            'itemable_id' => $item->itemable_id,
            'quantity' => (float) $item->quantity,
            'unit_price' => (string) $item->unit_price,
            'line_total' => (string) $item->line_total,
        ])->values()->all();
    }

    /**
     * Evidence photos grouped by collection.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function mediaPayload(ServiceOrder $serviceOrder): array
    {
        return [
            'initial_service_order_evidence' => $this->mapMedia($serviceOrder->getMedia('initial-service-order-evidence')),
            'closing_service_order_evidence' => $this->mapMedia($serviceOrder->getMedia('closing-service-order-evidence')),
        ];
    }

    /**
     * @param  Collection<int, \Spatie\MediaLibrary\MediaCollections\Models\Media>  $media
     * @return array<int, array<string, mixed>>
     */
    private function mapMedia(Collection $media): array
    {
        return $media->map(fn ($file) => [
            'id' => $file->id,
            'file_name' => $file->file_name,
            'original_url' => $file->getUrl(),
            // The collections only register responsive images, so the thumbnail
            // falls back to the original file while no "thumb" conversion exists.
            'thumb_url' => $file->hasGeneratedConversion('thumb') ? $file->getUrl('thumb') : $file->getUrl(),
            'size' => $file->size,
        ])->values()->all();
    }

    /**
     * Linked sale with its payments, or null for old orders without a sale.
     *
     * @return array<string, mixed>|null
     */
    private function transactionPayload(?Transaction $transaction): ?array
    {
        if (!$transaction) {
            return null;
        }

        return [
            'id' => $transaction->id,
            'folio' => $transaction->folio,
            'status' => $transaction->status instanceof \BackedEnum ? $transaction->status->value : $transaction->status,
            'total' => (float) $transaction->total,
            'total_paid' => (float) $transaction->total_paid,
            'remaining_due' => (float) $transaction->remaining_due,
            'payments' => $transaction->payments->map(fn ($payment) => [
                'id' => $payment->id,
                'amount' => (string) $payment->amount,
                'payment_method' => $payment->payment_method instanceof \BackedEnum
                    ? $payment->payment_method->value
                    : $payment->payment_method,
                'payment_date' => $payment->payment_date?->toIso8601String(),
                'bank_account' => $payment->bankAccount ? $this->bankAccounts->payload($payment->bankAccount) : null,
            ])->values()->all(),
        ];
    }

    /**
     * Last 20 history events of the order.
     *
     * @return array<int, array<string, mixed>>
     */
    private function activitiesPayload(ServiceOrder $serviceOrder): array
    {
        return $serviceOrder->activities()
            ->with('causer:id,name')
            // Ordered by id so entries created in the same second keep a
            // deterministic order (newest first).
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn ($activity) => [
                'id' => $activity->id,
                'description' => $activity->description,
                'event' => $activity->event,
                'causer' => $activity->causer ? [
                    'id' => $activity->causer->id,
                    'name' => $activity->causer->name,
                ] : null,
                'created_at' => $activity->created_at?->toIso8601String(),
            ])->values()->all();
    }

    /**
     * Definitions the app needs to render the custom fields of the order.
     *
     * @return array<int, array<string, mixed>>
     */
    private function customFieldDefinitions(ServiceOrder $serviceOrder): array
    {
        $subscriptionId = $serviceOrder->branch?->subscription_id;

        if (!$subscriptionId) {
            return [];
        }

        return CustomFieldDefinition::where('subscription_id', $subscriptionId)
            ->where('module', 'service_orders')
            ->orderBy('name')
            ->get(['id', 'key', 'name', 'type', 'options', 'is_required'])
            ->map(fn (CustomFieldDefinition $definition) => [
                'key' => $definition->key,
                'name' => $definition->name,
                'type' => $definition->type,
                'options' => $definition->options,
                'is_required' => (bool) $definition->is_required,
            ])->values()->all();
    }
}
