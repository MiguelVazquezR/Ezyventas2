<?php

namespace App\Http\Controllers\Subscription;

use App\Actions\Referral\GenerateReferralCodeAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Subscription\UpdateReferrerBankAccountRequest;
use App\Models\ReferralSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ReferralController extends Controller
{
    public function index(): Response
    {
        $subscription = Auth::user()->branch->subscription;

        return Inertia::render('Subscription/Referral/Index', [
            'referralCode'   => $subscription->referralCode,
            'referrals'      => $subscription->referralUsagesAsReferrer()
                                    ->with(['referredSubscription:id,commercial_name', 'payment'])
                                    ->latest()
                                    ->get(),
            'pendingRewards' => (float) $subscription->referralUsagesAsReferrer()
                                    ->where('reward_status', 'pending')
                                    ->sum('reward_amount'),
            'totalEarned'    => (float) $subscription->referralUsagesAsReferrer()
                                    ->where('reward_status', 'paid')
                                    ->sum('reward_amount'),
            'bankAccount'    => $subscription->referrerBankAccount,
            'settings'       => ReferralSettings::first(),
        ]);
    }

    public function getCode(GenerateReferralCodeAction $action): JsonResponse
    {
        $subscription = Auth::user()->branch->subscription;
        $code = $action->execute($subscription);

        return response()->json(['code' => $code->code]);
    }

    public function saveBankAccount(UpdateReferrerBankAccountRequest $request): RedirectResponse
    {
        $subscription = Auth::user()->branch->subscription;

        $subscription->referrerBankAccount()->updateOrCreate(
            ['subscription_id' => $subscription->id],
            $request->validated()
        );

        return redirect()->back()->with('success', 'Cuenta bancaria guardada correctamente.');
    }
}
