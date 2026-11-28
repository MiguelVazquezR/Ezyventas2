<?php

namespace App\Services\SmartCheckout;

use App\Models\SmartPosSetting;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Reads and writes the smart POS preferences of a subscription.
 *
 * Two rules shape this service:
 *
 * 1. **Everything is off by default.** No row means "scale and smart checkout
 *    disabled"; the fallback object is never persisted.
 * 2. **A pending migration must not break the POS.** On an installation where
 *    `smartpos_settings` does not exist yet, every read logs a warning and
 *    returns the disabled defaults instead of throwing (see forSubscription()).
 */
class SmartPosSettingsService
{
    /**
     * Units the bulk scale can weigh. Compared normalised (trim + lowercase),
     * because `products.measure_unit` is free text: "Kg", "kg ", "KGS" all mean
     * the same. Matches decision D3 (Kg / Grs) plus the usual abbreviations.
     */
    public const WEIGHT_UNITS = ['kg', 'kgs', 'g', 'gr', 'grs'];

    /** Readings averaged before a weight is considered stable. */
    public const DEFAULT_STABILITY_READINGS = 5;

    /** Allowed deviation, in grams, between consecutive readings. */
    public const DEFAULT_STABILITY_TOLERANCE_G = 3;

    /** Unit the scale reports. */
    public const DEFAULT_SCALE_UNIT = 'kg';

    /** Driver used until a profile configures Web Serial. */
    public const DEFAULT_SCALE_DRIVER = 'simulated';

    /** Days the smart checkout keeps its evidence (Phase 2). */
    public const DEFAULT_RETENTION_DAYS = 30;

    /*
    |--------------------------------------------------------------------------
    | MODO CAJA INTELIGENTE (Fase 2)
    |--------------------------------------------------------------------------
    */

    /** Minimum confidence for a detection to be painted green. */
    public const DEFAULT_GREEN_MIN = 0.85;

    /** Margin over the runner-up a green detection needs. */
    public const DEFAULT_GREEN_MARGIN = 0.08;

    /** Minimum confidence for a detection to be painted yellow. */
    public const DEFAULT_YELLOW_MIN = 0.65;

    /**
     * Cameras a tenant allows in the same recognition cycle (1-4).
     *
     * Stored and validated, but the current engine still analyses **all** the
     * configured cameras per cycle: this is the lever for the latency mitigation
     * described in `modelos.md` §9 (round-robin one camera per cycle) if the real
     * measurement on the client's hardware goes over one second.
     */
    public const DEFAULT_MAX_CAMERAS_PER_CYCLE = 2;

    /** Version reported by the simulated engine. */
    public const DEFAULT_MODEL_VERSION = 'mock';

    /**
     * True once a read found the table missing. Keeps a single request from
     * hammering the database (and the log) with the same failing query.
     */
    private bool $tableUnavailable = false;

    /*
    |--------------------------------------------------------------------------
    | LECTURA
    |--------------------------------------------------------------------------
    */

    /**
     * Preferences of the subscription, or the disabled defaults when there is no
     * row yet (or the table is not migrated on this installation).
     */
    public function forSubscription(int $subscriptionId): SmartPosSetting
    {
        if ($subscriptionId <= 0) {
            return $this->defaults(0);
        }

        if ($this->tableUnavailable) {
            return $this->defaults($subscriptionId);
        }

        try {
            $settings = SmartPosSetting::query()
                ->forSubscription($subscriptionId)
                ->first();
        } catch (QueryException $exception) {
            // Deployment where the migration is pending: the POS must keep
            // working with the features turned off.
            $this->tableUnavailable = true;

            Log::warning('No se pudo leer la configuración de Caja Inteligente; se usan los valores por defecto (todo apagado).', [
                'subscription_id' => $subscriptionId,
                'error' => $exception->getMessage(),
            ]);

            return $this->defaults($subscriptionId);
        }

        return $settings ?? $this->defaults($subscriptionId);
    }

    /**
     * Whether the bulk scale is enabled for the business of this user.
     */
    public function scaleEnabledFor(?User $user): bool
    {
        return $this->flagFor($user, 'scale_enabled');
    }

    /**
     * Whether the smart checkout is enabled for the business of this user.
     */
    public function smartCheckoutEnabledFor(?User $user): bool
    {
        return $this->flagFor($user, 'smart_checkout_enabled');
    }

    /**
     * Both switches of the module for the business of this user.
     *
     * The POS panel needs them to render the on/off switch (and to know whether
     * the switch must be shown at all), so they are read together.
     *
     * @return array{scale: bool, smart_checkout: bool}
     */
    public function flagsFor(?User $user): array
    {
        $subscriptionId = $this->subscriptionIdFor($user);

        if ($subscriptionId === null) {
            return ['scale' => false, 'smart_checkout' => false];
        }

        return $this->flagsForSubscription($subscriptionId);
    }

    /**
     * @return array{scale: bool, smart_checkout: bool}
     */
    public function flagsForSubscription(int $subscriptionId): array
    {
        $settings = $this->forSubscription($subscriptionId);

        return [
            'scale' => (bool) $settings->scale_enabled,
            'smart_checkout' => (bool) $settings->smart_checkout_enabled,
        ];
    }

    /**
     * Scale settings of the user's business, always complete: stored values are
     * merged over the defaults so the frontend never has to guess a threshold.
     *
     * @return array<string, mixed>
     */
    public function scaleSettingsFor(?User $user): array
    {
        $subscriptionId = $this->subscriptionIdFor($user);

        if ($subscriptionId === null) {
            return $this->defaultScaleSettings();
        }

        return $this->scaleSettingsForSubscription($subscriptionId);
    }

    /**
     * @return array<string, mixed>
     */
    public function scaleSettingsForSubscription(int $subscriptionId): array
    {
        return $this->normalizeScaleSettings(
            $this->forSubscription($subscriptionId)->scale_settings,
        );
    }

    /**
     * Smart checkout settings of the user's business, always complete: stored
     * values are merged over the defaults so the frontend never has to guess a
     * threshold.
     *
     * @return array<string, mixed>
     */
    public function smartCheckoutSettingsFor(?User $user): array
    {
        $subscriptionId = $this->subscriptionIdFor($user);

        if ($subscriptionId === null) {
            return $this->defaultSmartCheckoutSettings();
        }

        return $this->smartCheckoutSettingsForSubscription($subscriptionId);
    }

    /**
     * @return array<string, mixed>
     */
    public function smartCheckoutSettingsForSubscription(int $subscriptionId): array
    {
        return $this->normalizeSmartCheckoutSettings(
            $this->forSubscription($subscriptionId)->smart_checkout_settings,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | ESCRITURA
    |--------------------------------------------------------------------------
    */

    /**
     * Persists the scale settings of one subscription.
     *
     * The subscription comes from the authenticated user (never from the
     * payload), so a tenant can only ever write its own row.
     *
     * @param  array<string, mixed>  $data  Either the settings themselves or `['scale_settings' => [...]]`.
     */
    public function updateScale(int $subscriptionId, array $data): SmartPosSetting
    {
        if ($subscriptionId <= 0) {
            throw new InvalidArgumentException('Se requiere una suscripción válida para guardar la configuración de la báscula.');
        }

        // Never trust a subscription id coming from the payload.
        unset($data['subscription_id']);

        $settingsData = is_array($data['scale_settings'] ?? null)
            ? $data['scale_settings']
            : $data;

        $attributes = [
            'scale_settings' => $this->normalizeScaleSettings($settingsData),
        ];

        // Optional: lets the same call switch the feature on/off once the
        // business has enabled it from the settings panel.
        if (array_key_exists('scale_enabled', $data)) {
            $attributes['scale_enabled'] = (bool) $data['scale_enabled'];
        }

        return SmartPosSetting::query()->updateOrCreate(
            ['subscription_id' => $subscriptionId],
            $attributes,
        );
    }

    /**
     * Persists the smart checkout settings of one subscription.
     *
     * Same contract as updateScale(): only the `smart_checkout_settings` column
     * is written. The on/off switch (`smart_checkout_enabled`) is never touched
     * here, because enabling the mode is a separate action (updateFlags()).
     *
     * The subscription comes from the authenticated user (never from the
     * payload), so a tenant can only ever write its own row.
     *
     * @param  array<string, mixed>  $data  Either the settings themselves or `['smart_checkout_settings' => [...]]`.
     */
    public function updateSmartCheckout(int $subscriptionId, array $data): SmartPosSetting
    {
        if ($subscriptionId <= 0) {
            throw new InvalidArgumentException('Se requiere una suscripción válida para guardar la configuración de la Caja Inteligente.');
        }

        // Never trust a subscription id coming from the payload.
        unset($data['subscription_id']);

        $settingsData = is_array($data['smart_checkout_settings'] ?? null)
            ? $data['smart_checkout_settings']
            : $data;

        return SmartPosSetting::query()->updateOrCreate(
            ['subscription_id' => $subscriptionId],
            [
                'smart_checkout_settings' => $this->normalizeSmartCheckoutSettings($settingsData),
            ],
        );
    }

    /**
     * Turns the two switches on/off ("activar o desactivar en cualquier momento
     * desde el módulo Punto de venta", decision D2).
     *
     * Only the flags are written: `scale_settings` and the smart checkout
     * settings are preserved, and a row that does not exist yet is created with
     * the rest of the columns on their defaults.
     *
     * @param  array{scale_enabled?: bool, smart_checkout_enabled?: bool}  $flags
     */
    public function updateFlags(int $subscriptionId, array $flags): SmartPosSetting
    {
        if ($subscriptionId <= 0) {
            throw new InvalidArgumentException('Se requiere una suscripción válida para guardar las preferencias.');
        }

        // Never trust a subscription id coming from the payload.
        unset($flags['subscription_id']);

        $attributes = [];

        if (array_key_exists('scale_enabled', $flags)) {
            $attributes['scale_enabled'] = (bool) $flags['scale_enabled'];
        }

        if (array_key_exists('smart_checkout_enabled', $flags)) {
            $attributes['smart_checkout_enabled'] = (bool) $flags['smart_checkout_enabled'];
        }

        return SmartPosSetting::query()
            ->updateOrCreate(
                ['subscription_id' => $subscriptionId],
                $attributes,
            )
            // Read back the row so the response also carries the column defaults
            // when the preferences are stored for the first time.
            ->refresh();
    }

    /*
    |--------------------------------------------------------------------------
    | DEFAULTS / NORMALIZACIÓN
    |--------------------------------------------------------------------------
    */

    /**
     * Scale settings every tenant has before configuring anything:
     *
     * ```json
     * {
     *   "active_profile_id": null,
     *   "profiles": [],
     *   "stability": { "readings": 5, "tolerance_g": 3 },
     *   "unit": "kg",
     *   "driver": "simulated"
     * }
     * ```
     *
     * @return array<string, mixed>
     */
    public function defaultScaleSettings(): array
    {
        return [
            'active_profile_id' => null,
            'profiles' => [],
            'stability' => [
                'readings' => self::DEFAULT_STABILITY_READINGS,
                'tolerance_g' => self::DEFAULT_STABILITY_TOLERANCE_G,
            ],
            'unit' => self::DEFAULT_SCALE_UNIT,
            'driver' => self::DEFAULT_SCALE_DRIVER,
        ];
    }

    /**
     * Completes (and orders) whatever is stored so the API contract never
     * changes shape, even if an old row lacks one of the keys.
     *
     * @param  array<string, mixed>|null  $stored
     * @return array<string, mixed>
     */
    public function normalizeScaleSettings(?array $stored): array
    {
        $defaults = $this->defaultScaleSettings();
        $stored = $stored ?? [];

        $storedStability = is_array($stored['stability'] ?? null) ? $stored['stability'] : [];
        $storedProfiles = is_array($stored['profiles'] ?? null) ? $stored['profiles'] : [];

        return [
            'active_profile_id' => $stored['active_profile_id'] ?? $defaults['active_profile_id'],
            'profiles' => array_values($storedProfiles),
            'stability' => [
                'readings' => (int) ($storedStability['readings'] ?? $defaults['stability']['readings']),
                'tolerance_g' => (int) ($storedStability['tolerance_g'] ?? $defaults['stability']['tolerance_g']),
            ],
            'unit' => $stored['unit'] ?? $defaults['unit'],
            'driver' => $stored['driver'] ?? $defaults['driver'],
        ];
    }

    /**
     * Smart checkout settings every tenant has before configuring anything:
     *
     * ```json
     * {
     *   "thresholds": { "green_min": 0.85, "green_margin": 0.08, "yellow_min": 0.65 },
     *   "auto_accept": true,
     *   "cameras": [],
     *   "max_cameras_per_cycle": 2,
     *   "save_crops": true,
     *   "model_version": "mock"
     * }
     * ```
     *
     * @return array<string, mixed>
     */
    public function defaultSmartCheckoutSettings(): array
    {
        return [
            'thresholds' => [
                'green_min' => self::DEFAULT_GREEN_MIN,
                'green_margin' => self::DEFAULT_GREEN_MARGIN,
                'yellow_min' => self::DEFAULT_YELLOW_MIN,
            ],
            // A green detection is added to the cart without cashier action.
            'auto_accept' => true,
            // Cameras of the terminal (Phase 3: [{id, label, mode, resolution}]).
            'cameras' => [],
            'max_cameras_per_cycle' => self::DEFAULT_MAX_CAMERAS_PER_CYCLE,
            'save_crops' => true,
            'model_version' => self::DEFAULT_MODEL_VERSION,
        ];
    }

    /**
     * Completes (and orders) whatever is stored so the API contract never
     * changes shape, even if an old row lacks one of the keys.
     *
     * @param  array<string, mixed>|null  $stored
     * @return array<string, mixed>
     */
    public function normalizeSmartCheckoutSettings(?array $stored): array
    {
        $defaults = $this->defaultSmartCheckoutSettings();
        $stored = $stored ?? [];

        $storedThresholds = is_array($stored['thresholds'] ?? null) ? $stored['thresholds'] : [];
        $storedCameras = is_array($stored['cameras'] ?? null) ? $stored['cameras'] : [];

        return [
            'thresholds' => [
                'green_min' => (float) ($storedThresholds['green_min'] ?? $defaults['thresholds']['green_min']),
                'green_margin' => (float) ($storedThresholds['green_margin'] ?? $defaults['thresholds']['green_margin']),
                'yellow_min' => (float) ($storedThresholds['yellow_min'] ?? $defaults['thresholds']['yellow_min']),
            ],
            'auto_accept' => (bool) ($stored['auto_accept'] ?? $defaults['auto_accept']),
            'cameras' => array_values($storedCameras),
            'max_cameras_per_cycle' => (int) ($stored['max_cameras_per_cycle'] ?? $defaults['max_cameras_per_cycle']),
            'save_crops' => (bool) ($stored['save_crops'] ?? $defaults['save_crops']),
            'model_version' => (string) ($stored['model_version'] ?? $defaults['model_version']),
        ];
    }

    /**
     * A non-persisted settings object with both flags off.
     */
    private function defaults(int $subscriptionId): SmartPosSetting
    {
        $settings = new SmartPosSetting([
            'smart_checkout_enabled' => false,
            'scale_enabled' => false,
            'retention_days' => self::DEFAULT_RETENTION_DAYS,
            'save_crops' => true,
        ]);

        if ($subscriptionId > 0) {
            $settings->subscription_id = $subscriptionId;
        }

        $settings->scale_settings = $this->defaultScaleSettings();
        $settings->smart_checkout_settings = $this->defaultSmartCheckoutSettings();

        return $settings;
    }

    /**
     * Business of the user: always resolved from the authenticated user, never
     * from the request.
     */
    private function subscriptionIdFor(?User $user): ?int
    {
        $subscriptionId = $user?->branch?->subscription_id;

        return $subscriptionId ? (int) $subscriptionId : null;
    }

    private function flagFor(?User $user, string $flag): bool
    {
        $subscriptionId = $this->subscriptionIdFor($user);

        if ($subscriptionId === null) {
            return false;
        }

        return (bool) $this->forSubscription($subscriptionId)->getAttribute($flag);
    }

    /*
    |--------------------------------------------------------------------------
    | UNIDADES
    |--------------------------------------------------------------------------
    */

    /**
     * Whether a `products.measure_unit` value is a weight unit (case/space
     * insensitive), i.e. whether the product can be weighed on the scale.
     */
    public static function isWeightUnit(?string $unit): bool
    {
        if ($unit === null) {
            return false;
        }

        return in_array(strtolower(trim($unit)), self::WEIGHT_UNITS, true);
    }
}
