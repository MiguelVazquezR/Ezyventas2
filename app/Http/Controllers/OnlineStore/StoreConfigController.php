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
            'storeUrl'     => $storeUrl,
            'mpConnected'    => $storeConfig->isMpConnected(),
            'mpUserId'       => $storeConfig->mp_user_id,
            'mpTestMode'     => $storeConfig->isMpTestMode(),
            'mpAccountInfo'  => $storeConfig->mpAccountInfo(),
        ]);
    }

    public function update(UpdateStoreConfigRequest $request): \Illuminate\Http\RedirectResponse
    {
        $user = Auth::user();
        $subscription = $user->branch->subscription;

        $storeConfig = StoreConfig::where('subscription_id', $subscription->id)->firstOrFail();

        $storeConfig->update($request->safe()->except([
            'logo', 'remove_logo', 'banners', 'remove_banners', 'removed_banner_ids',
            'prep_days', 'prep_hours', 'prep_minutes',
            'restock_days', 'restock_hours', 'restock_minutes',
        ]));

        // Handle logo removal
        if ($request->boolean('remove_logo')) {
            $storeConfig->clearMediaCollection('store-logo');
            $storeConfig->update(['logo_url' => null]);
        }

        // Handle logo upload
        if ($request->hasFile('logo')) {
            $storeConfig->clearMediaCollection('store-logo');
            $storeConfig->addMediaFromRequest('logo')->toMediaCollection('store-logo');
            $storeConfig->update(['logo_url' => $storeConfig->getFirstMediaUrl('store-logo')]);
        }

        // Handle specific banner removal by media IDs
        $removedIds = $request->input('removed_banner_ids', []);
        if (!empty($removedIds)) {
            $storeConfig->media()->whereIn('id', $removedIds)->get()->each->delete();
        }

        // Handle banner uploads (only new files, never re-upload existing ones)
        if ($request->hasFile('banners')) {
            foreach ($request->file('banners') as $banner) {
                $storeConfig->addMedia($banner)->toMediaCollection('store-banners');
            }
        }

        return back()->with('success', 'Configuración de tienda actualizada correctamente.');
    }

    /**
     * Check if a slug is available for the current subscription.
     */
    public function checkSlug(Request $request): \Illuminate\Http\JsonResponse
    {
        $request->validate([
            'slug' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9-]+$/'],
        ]);

        $user = Auth::user();
        $subscriptionId = $user->branch->subscription_id;

        $existing = StoreConfig::where('slug', $request->slug)
            ->when($subscriptionId, fn($q) => $q->where('subscription_id', '!=', $subscriptionId))
            ->exists();

        return response()->json([
            'available' => !$existing,
        ]);
    }
}
