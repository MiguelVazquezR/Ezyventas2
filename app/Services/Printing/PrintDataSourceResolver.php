<?php

namespace App\Services\Printing;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ServiceOrder;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Resolves the record a print template is rendered against.
 *
 * Every source is scoped to the subscription of the user: neither the web nor
 * the app may render another business's documents.
 *
 * Shared by the web PrintController and the mobile print endpoints.
 */
class PrintDataSourceResolver
{
    /**
     * Source types accepted by the print endpoints.
     *
     * @var array<int, string>
     */
    public const SOURCE_TYPES = [
        'pos',
        'transaction',
        'service_order',
        'product',
        'customer',
        'order',
        'general',
    ];

    public function resolve(string $type, int $id, User $user): Model
    {
        $subscriptionId = $user->branch?->subscription_id;

        $source = match ($type) {
            'customer' => Customer::query()
                ->where('id', $id)
                ->where(fn ($query) => $query
                    ->whereHas('branch', fn ($branch) => $branch->where('subscription_id', $subscriptionId))
                    ->orWhereNull('branch_id'))
                ->first(),
            'transaction', 'pos', 'general', 'order' => Transaction::with(['customer', 'items.itemable'])->find($id),
            'product' => Product::find($id),
            'service_order' => ServiceOrder::find($id),
            default => null,
        };

        if (!$source || !$this->belongsToSubscription($source, $subscriptionId)) {
            throw new NotFoundHttpException('Recurso no encontrado.');
        }

        return $source;
    }

    private function belongsToSubscription(Model $source, ?int $subscriptionId): bool
    {
        if ($source instanceof Customer) {
            return $source->branch_id === null
                || (int) $source->branch?->subscription_id === $subscriptionId;
        }

        return (int) $source->branch?->subscription_id === $subscriptionId;
    }
}
