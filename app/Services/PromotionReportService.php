<?php

namespace App\Services;

use App\Models\Promotion;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class PromotionReportService
{
    public function getActivePromotions(int $branchId): array
    {
        $subscriptionId = DB::table('branches')
            ->where('id', $branchId)
            ->value('subscription_id');

        if (! $subscriptionId) {
            return [];
        }

        return Promotion::where('subscription_id', $subscriptionId)
            ->where('is_active', true)
            ->whereDate('start_date', '<=', now())
            ->where(function ($q) {
                $q->whereNull('end_date')
                  ->orWhereDate('end_date', '>=', now());
            })
            ->with(['rules', 'effects'])
            ->get()
            ->map(function ($promo) {
                return [
                    'id'          => $promo->id,
                    'name'        => $promo->name,
                    'type'        => $promo->type->value,
                    'start_date'  => $promo->start_date?->toDateString(),
                    'end_date'    => $promo->end_date?->toDateString(),
                    'priority'    => $promo->priority,
                    'usage_limit' => $promo->usage_limit,
                    'rules_count' => $promo->rules->count(),
                    'effects_count'=> $promo->effects->count(),
                ];
            })
            ->toArray();
    }

    public function getUsageStats(int $promotionId, Carbon $start, Carbon $end, int $branchId): array
    {
        $promotion = Promotion::with(['transactions' => function ($q) use ($start, $end) {
            $q->whereBetween('transactions.created_at', [$start, $end]);
        }])->findOrFail($promotionId);

        $usageCount = $promotion->transactions->count();
        $totalDiscount = $promotion->transactions->sum('pivot.discount_applied');

        return [
            'promotion_id'    => $promotion->id,
            'promotion_name'  => $promotion->name,
            'times_applied'   => $usageCount,
            'total_discount'  => (float) $totalDiscount,
            'period'          => [
                'start' => $start->toDateString(),
                'end'   => $end->toDateString(),
            ],
        ];
    }
}
