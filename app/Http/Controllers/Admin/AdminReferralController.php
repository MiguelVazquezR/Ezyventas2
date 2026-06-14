<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateReferralSettingsRequest;
use App\Models\ReferralSettings;
use App\Models\ReferralUsage;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class AdminReferralController extends Controller
{
    public function index(): Response
    {
        $usages = ReferralUsage::with([
            'referralCode.user:id,name,branch_id',
            'referralCode.user.branch.subscription:id,commercial_name',
            'referredSubscription:id,commercial_name',
            'payment:id,amount,payment_method,created_at',
        ])->latest()->paginate(20);

        return Inertia::render('Admin/Referral/Index', [
            'usages' => $usages,
        ]);
    }

    public function markPaid(ReferralUsage $referralUsage): RedirectResponse
    {
        $referralUsage->update([
            'reward_status' => 'paid',
            'reward_paid_at' => now(),
        ]);

        return redirect()->back()->with('success', 'Premio marcado como pagado.');
    }

    public function settings(): Response
    {
        return Inertia::render('Admin/Referral/Settings', [
            'settings' => ReferralSettings::firstOrCreate([], [
                'referred_discount_pct' => 15.00,
                'referrer_reward_pct' => 50.00,
                'referrer_ongoing_discount_pct' => 10.00,
            ]),
        ]);
    }

    public function updateSettings(UpdateReferralSettingsRequest $request): RedirectResponse
    {
        $settings = ReferralSettings::firstOrCreate([]);
        $settings->update($request->validated());

        return redirect()->back()->with('success', 'Configuración de referidos actualizada.');
    }
}
