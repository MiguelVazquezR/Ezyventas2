<?php

namespace App\Http\Controllers\Subscription;

use App\Actions\Referral\ExpireStaleTrialReferralsAction;
use App\Actions\Referral\GenerateReferralCodeAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Subscription\UpdateReferrerBankAccountRequest;
use App\Models\ReferralCode;
use App\Models\ReferralSettings;
use App\Models\ReferralUsage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ReferralController extends Controller
{
    public function index(): Response
    {
        $user = Auth::user();
        $subscription = $user->branch->subscription;

        // Pasar a 'expired' los referidos en prueba cuya prueba terminó sin pago.
        $referralCodeId = $user->referralCode?->id;
        if ($referralCodeId) {
            app(ExpireStaleTrialReferralsAction::class)->execute([$referralCodeId]);
        }

        // Calcular costo mensual actual de la suscripción
        $subscriptionCost = $subscription->getCurrentMonthlyCost();
        $referrerActiveDiscountPct = $subscription->getReferrerActiveDiscountPct();

        $referrals = $user->referralUsagesAsReferrer()
                            ->with([
                                'referredSubscription' => fn($q) => $q->select('id', 'commercial_name')
                                    ->withCount(['versions as active_versions_count' => fn($v) =>
                                        $v->where('end_date', '>=', now()->startOfDay())
                                    ])
                                    ->with(['versions' => fn($v) =>
                                        $v->select('id', 'subscription_id', 'end_date')
                                          ->latest('end_date')
                                          ->limit(1)
                                    ]),
                                'payment',
                            ])
                            ->latest()
                            ->get()
                            ->map(fn($r) => [
                                ...$r->toArray(),
                                'referred_subscription_active' => $r->referredSubscription && $r->referredSubscription->active_versions_count > 0,
                                // Fecha en que termina la prueba (para el estado "De prueba").
                                'trial_ends_at' => $r->reward_status === 'trial' && $r->referredSubscription
                                    ? $r->referredSubscription->versions->first()?->end_date
                                    : null,
                            ]);

        // Solo cuentan como "activos" los referidos que ya hicieron su primer
        // pago (pending/paid) y siguen con suscripción activa.
        $activeReferralsCount = $referrals
            ->whereIn('reward_status', [ReferralUsage::STATUS_PENDING, ReferralUsage::STATUS_PAID])
            ->where('referred_subscription_active', true)
            ->count();

        return Inertia::render('Subscription/Referral/Index', [
            'referralCode'              => $user->referralCode,
            'referrals'                 => $referrals,
            'pendingRewards'            => (float) $user->referralUsagesAsReferrer()
                                            ->where('reward_status', 'pending')
                                            ->sum('reward_amount'),
            'totalEarned'               => (float) $user->referralUsagesAsReferrer()
                                            ->where('reward_status', 'paid')
                                            ->sum('reward_amount'),
            'bankAccount'               => $user->referrerBankAccount,
            'settings'                  => ReferralSettings::first(),
            'activeReferralsCount'      => $activeReferralsCount,
            'subscriptionCost'          => (float) round($subscriptionCost, 2),
            'referrerActiveDiscountPct' => (float) $referrerActiveDiscountPct,
        ]);
    }

    public function getCode(GenerateReferralCodeAction $action): JsonResponse
    {
        $user = Auth::user();

        // Solo el dueño puede generar código
        if ($user->roles()->exists()) {
            return response()->json(['code' => null], 403);
        }

        $code = $action->execute($user);

        return response()->json(['code' => $code->code]);
    }

    public function saveBankAccount(UpdateReferrerBankAccountRequest $request): RedirectResponse
    {
        $user = Auth::user();

        $user->referrerBankAccount()->updateOrCreate(
            ['user_id' => $user->id],
            $request->validated()
        );

        return redirect()->back()->with('success', 'Cuenta bancaria guardada correctamente.');
    }

    /**
     * Marca todos los referidos del usuario como vistos (seen_at).
     */
    public function markSeen(): JsonResponse
    {
        $user = Auth::user();

        $user->referralUsagesAsReferrer()
            ->whereNull('seen_at')
            ->update(['seen_at' => now()]);

        return response()->json(['success' => true]);
    }

    /**
     * Valida en tiempo real si un código de referido es válido para usarse.
     */
    public function validateCode(Request $request): JsonResponse
    {
        $code = trim($request->input('code', ''));

        if (empty($code) || strlen($code) < 6) {
            return response()->json(['valid' => false, 'message' => 'El código es demasiado corto.']);
        }

        $referral = ReferralCode::where('code', $code)->first();

        if (!$referral) {
            return response()->json(['valid' => false, 'message' => 'Este código no existe.']);
        }

        if (!$referral->is_active) {
            return response()->json(['valid' => false, 'message' => 'Este código ya no está activo.']);
        }

        $user = Auth::user();
        $subscription = $user->branch->subscription;

        if ($referral->user->branch->subscription_id === $subscription->id) {
            return response()->json(['valid' => false, 'message' => 'No puedes usar tu propio código.']);
        }

        // Solo aplica en el primer pago
        if ($subscription->versions()->count() > 1) {
            return response()->json(['valid' => false, 'message' => 'El código de referido solo aplica en el primer pago.']);
        }

        // Ya fue referido antes
        if ($subscription->referralUsageAsReferred()->exists()) {
            return response()->json(['valid' => false, 'message' => 'Esta suscripción ya usó un código de referido.']);
        }

        $settings = ReferralSettings::firstOrCreate([], [
            'referred_discount_pct' => 15.00,
            'referrer_reward_pct' => 50.00,
            'referrer_ongoing_discount_pct' => 10.00,
        ]);

        return response()->json([
            'valid' => true,
            'message' => '¡Código válido! Obtendrás un ' . $settings->referred_discount_pct . '% de descuento en este pago.',
            'discount_pct' => (float) $settings->referred_discount_pct,
        ]);
    }
}
