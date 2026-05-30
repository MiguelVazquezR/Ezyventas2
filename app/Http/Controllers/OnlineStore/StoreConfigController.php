<?php

namespace App\Http\Controllers\OnlineStore;

use App\Http\Controllers\Controller;
use App\Http\Requests\OnlineStore\UpdateStoreConfigRequest;
use App\Models\StoreConfig;
use App\Services\TiendaUrlService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class StoreConfigController extends Controller
{
    public function __construct(
        private readonly TiendaUrlService $urlService,
    ) {}

    public function show(Request $request): Response
    {
        $user = Auth::user();
        $subscription = $user->branch->subscription;

        $storeConfig = StoreConfig::firstOrCreate(
            ['subscription_id' => $subscription->id],
            [
                'slug' => $subscription->slug ?? \Illuminate\Support\Str::slug($subscription->business_name),
                'store_name' => $subscription->business_name,
                'is_active' => false,
            ]
        );

        $storeUrl = $storeConfig->is_active ? $this->urlService->storeUrl($storeConfig) : null;

        return Inertia::render('OnlineStore/Config', [
            'storeConfig' => $storeConfig,
            'storeUrl' => $storeUrl,
        ]);
    }

    public function update(UpdateStoreConfigRequest $request): \Illuminate\Http\RedirectResponse
    {
        $user = Auth::user();
        $subscription = $user->branch->subscription;

        $storeConfig = StoreConfig::where('subscription_id', $subscription->id)->firstOrFail();

        $storeConfig->update($request->validated());

        // Handle logo upload
        if ($request->hasFile('logo')) {
            $storeConfig->clearMediaCollection('store-logo');
            $storeConfig->addMediaFromRequest('logo')->toMediaCollection('store-logo');
            $storeConfig->update(['logo_url' => $storeConfig->getFirstMediaUrl('store-logo')]);
        }

        return back()->with('success', 'Store configuration updated successfully.');
    }
}
