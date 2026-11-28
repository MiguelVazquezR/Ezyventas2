<?php

namespace Tests\Feature\SmartCheckout;

use App\Models\SmartPosSetting;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;

/**
 * Configuration endpoints of the "Modo Caja Inteligente" (Phase 2).
 *
 * The business preference (`smart_checkout_enabled`) is checked on every
 * request: while it is off both endpoints answer 403. When it is on the defaults
 * are always complete and a PUT only writes `smart_checkout_settings`, never the
 * scale configuration nor the on/off flags.
 */
class SmartCheckoutSettingsTest extends SmartCheckoutTestCase
{
    #[Test]
    public function it_returns_403_while_the_mode_is_disabled(): void
    {
        $this->getJson(route('pos.smart-checkout.settings'))
            ->assertForbidden()
            ->assertJsonPath('message', 'El Modo Caja Inteligente no está habilitado para este negocio.');

        $this->putJson(route('pos.smart-checkout.settings.update'), $this->validSmartCheckoutPayload())
            ->assertForbidden()
            ->assertJsonPath('message', 'El Modo Caja Inteligente no está habilitado para este negocio.');

        // Nothing was created from the rejected writes.
        $this->assertDatabaseCount('smartpos_settings', 0);
    }

    #[Test]
    public function it_returns_the_complete_defaults_when_the_mode_is_enabled(): void
    {
        $this->enableSmartCheckout();

        $this->getJson(route('pos.smart-checkout.settings'))
            ->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('settings.thresholds.green_min', 0.85)
            ->assertJsonPath('settings.thresholds.green_margin', 0.08)
            ->assertJsonPath('settings.thresholds.yellow_min', 0.65)
            ->assertJsonPath('settings.auto_accept', true)
            ->assertJsonPath('settings.cameras', [])
            ->assertJsonPath('settings.max_cameras_per_cycle', 2)
            ->assertJsonPath('settings.save_crops', true)
            ->assertJsonPath('settings.model_version', 'mock');
    }

    #[Test]
    public function it_saves_the_settings_without_touching_the_scale_or_the_flags(): void
    {
        $scaleSettings = [
            'active_profile_id' => 'bascula-1',
            'profiles' => [['id' => 'bascula-1', 'name' => 'Báscula mostrador']],
            'stability' => ['readings' => 7, 'tolerance_g' => 5],
            'unit' => 'kg',
            'driver' => 'manual',
        ];

        SmartPosSetting::create([
            'subscription_id' => $this->branch->subscription_id,
            'smart_checkout_enabled' => true,
            'scale_enabled' => true,
            'scale_settings' => $scaleSettings,
        ]);

        $this->putJson(route('pos.smart-checkout.settings.update'), $this->validSmartCheckoutPayload())
            ->assertOk()
            ->assertJsonPath('message', 'Configuración del Modo Caja Inteligente guardada.')
            ->assertJsonPath('settings.thresholds.green_min', 0.9)
            ->assertJsonPath('settings.thresholds.green_margin', 0.05)
            ->assertJsonPath('settings.thresholds.yellow_min', 0.7)
            ->assertJsonPath('settings.auto_accept', false)
            ->assertJsonPath('settings.cameras.0.id', 'cam-1')
            ->assertJsonPath('settings.cameras.0.mode', 'zone')
            ->assertJsonPath('settings.cameras.0.resolution', '1280x720')
            ->assertJsonPath('settings.max_cameras_per_cycle', 1)
            ->assertJsonPath('settings.save_crops', false)
            ->assertJsonPath('settings.model_version', 'mock-v2');

        $row = SmartPosSetting::where('subscription_id', $this->branch->subscription_id)->firstOrFail();

        $this->assertTrue($row->smart_checkout_enabled);
        $this->assertTrue($row->scale_enabled);

        // The scale configuration is byte-for-byte the one it had before.
        $this->assertEquals($scaleSettings, $row->scale_settings);

        // The defaults a stored object does not mention are completed again.
        $this->assertSame(0.9, $row->smart_checkout_settings['thresholds']['green_min']);
        $this->assertSame(1, $row->smart_checkout_settings['max_cameras_per_cycle']);
    }

    #[Test]
    public function it_validates_the_thresholds_the_automatic_acceptance_and_the_cameras(): void
    {
        $this->enableSmartCheckout();

        $invalidPayloads = [
            'green_min above 1' => [
                ['thresholds' => ['green_min' => 1.5]],
                'thresholds.green_min',
            ],
            'green_min below 0' => [
                ['thresholds' => ['green_min' => -0.2]],
                'thresholds.green_min',
            ],
            'green_margin not numeric' => [
                ['thresholds' => ['green_margin' => 'alto']],
                'thresholds.green_margin',
            ],
            'yellow_min below 0' => [
                ['thresholds' => ['yellow_min' => -0.1]],
                'thresholds.yellow_min',
            ],
            'auto_accept not boolean' => [
                ['auto_accept' => 'quizá'],
                'auto_accept',
            ],
            'camera mode not supported' => [
                ['cameras' => [['id' => 'cam-1', 'label' => 'Mostrador', 'mode' => 'laser']]],
                'cameras.0.mode',
            ],
            'more than four cameras' => [
                ['cameras' => $this->cameras(5)],
                'cameras',
            ],
        ];

        foreach ($invalidPayloads as $case => [$payload, $errorKey]) {
            $this->putJson(route('pos.smart-checkout.settings.update'), $payload)
                ->assertStatus(422)
                ->assertJsonValidationErrors($errorKey);
        }

        // The row keeps its previous settings (null: nothing was written yet).
        $row = SmartPosSetting::where('subscription_id', $this->branch->subscription_id)->firstOrFail();
        $this->assertNull($row->smart_checkout_settings);
    }

    #[Test]
    public function it_rejects_a_yellow_threshold_above_the_green_one(): void
    {
        $this->enableSmartCheckout();

        $this->putJson(route('pos.smart-checkout.settings.update'), [
            'thresholds' => ['green_min' => 0.8, 'yellow_min' => 0.9],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['thresholds.yellow_min'])
            ->assertJsonPath('message', 'El umbral amarillo debe ser menor o igual que el umbral verde.');

        // A partial update also cannot place the yellow band above the default
        // green threshold.
        $this->putJson(route('pos.smart-checkout.settings.update'), [
            'thresholds' => ['yellow_min' => 0.95],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['thresholds.yellow_min']);
    }

    #[Test]
    public function it_never_reads_or_writes_the_settings_of_another_subscription(): void
    {
        $this->enableSmartCheckout();

        $this->putJson(route('pos.smart-checkout.settings.update'), [
            'thresholds' => ['green_min' => 0.95],
            'model_version' => 'tenant-a',
        ])->assertOk();

        $otherBranch = $this->makeTenant();
        $this->enableSmartCheckout($otherBranch->subscription_id);
        $otherUser = User::factory()->create(['branch_id' => $otherBranch->id]);

        // B still sees the defaults...
        $this->actingAs($otherUser)
            ->getJson(route('pos.smart-checkout.settings'))
            ->assertOk()
            ->assertJsonPath('settings.thresholds.green_min', 0.85)
            ->assertJsonPath('settings.model_version', 'mock');

        // ...and its write goes to its own row only.
        $this->actingAs($otherUser)
            ->putJson(route('pos.smart-checkout.settings.update'), ['model_version' => 'tenant-b'])
            ->assertOk()
            ->assertJsonPath('settings.model_version', 'tenant-b');

        $this->assertSame(
            'tenant-a',
            SmartPosSetting::where('subscription_id', $this->branch->subscription_id)->firstOrFail()
                ->smart_checkout_settings['model_version'],
        );

        $this->assertSame(
            'tenant-b',
            SmartPosSetting::where('subscription_id', $otherBranch->subscription_id)->firstOrFail()
                ->smart_checkout_settings['model_version'],
        );
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function cameras(int $count): array
    {
        $cameras = [];

        for ($index = 1; $index <= $count; $index++) {
            $cameras[] = [
                'id' => 'cam-'.$index,
                'label' => 'Cámara '.$index,
                'mode' => $index % 2 === 0 ? 'overlap' : 'zone',
            ];
        }

        return $cameras;
    }
}
