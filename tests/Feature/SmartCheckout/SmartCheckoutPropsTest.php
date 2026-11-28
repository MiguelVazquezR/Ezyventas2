<?php

namespace Tests\Feature\SmartCheckout;

use App\Models\SmartPosSetting;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * `smartCheckout` prop of the Point of Sale page.
 *
 * The POS panel needs one single query for the whole module: the prop reuses the
 * `smartpos_settings` row that already feeds `scale`, so enabling the mode never
 * adds a second read to `GET /pos`.
 */
class SmartCheckoutPropsTest extends SmartCheckoutTestCase
{
    #[Test]
    public function the_pos_page_reports_the_mode_disabled_and_no_settings(): void
    {
        $this->get(route('pos.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('smartCheckout.enabled', false)
                ->where('smartCheckout.settings', null)
                // The scale prop is untouched by Phase 2.
                ->where('scale.enabled', false)
                ->where('scale.settings', null)
                ->where('scale.preferences.smart_checkout', false)
            );
    }

    #[Test]
    public function the_pos_page_exposes_the_complete_defaults_when_the_mode_is_enabled(): void
    {
        $this->enableSmartCheckout();

        $this->get(route('pos.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('smartCheckout.enabled', true)
                ->where('smartCheckout.settings.thresholds.green_min', 0.85)
                ->where('smartCheckout.settings.thresholds.green_margin', 0.08)
                ->where('smartCheckout.settings.thresholds.yellow_min', 0.65)
                ->where('smartCheckout.settings.auto_accept', true)
                ->where('smartCheckout.settings.cameras', [])
                ->where('smartCheckout.settings.max_cameras_per_cycle', 2)
                ->where('smartCheckout.settings.save_crops', true)
                ->where('smartCheckout.settings.model_version', 'mock')
                // Scale stays independently off and keeps its own contract.
                ->where('scale.enabled', false)
                ->where('scale.settings', null)
                ->where('scale.preferences.smart_checkout', true)
            );
    }

    #[Test]
    public function a_partially_stored_object_is_completed_with_the_defaults(): void
    {
        SmartPosSetting::create([
            'subscription_id' => $this->branch->subscription_id,
            'smart_checkout_enabled' => true,
            'smart_checkout_settings' => [
                'thresholds' => ['green_min' => 0.92],
                'auto_accept' => false,
            ],
        ]);

        $this->get(route('pos.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('smartCheckout.enabled', true)
                ->where('smartCheckout.settings.thresholds.green_min', 0.92)
                // Keys the stored object does not mention fall back to defaults.
                ->where('smartCheckout.settings.thresholds.green_margin', 0.08)
                ->where('smartCheckout.settings.thresholds.yellow_min', 0.65)
                ->where('smartCheckout.settings.auto_accept', false)
                ->where('smartCheckout.settings.model_version', 'mock')
            );
    }
}
