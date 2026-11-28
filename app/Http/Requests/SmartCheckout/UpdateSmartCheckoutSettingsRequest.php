<?php

namespace App\Http\Requests\SmartCheckout;

use App\Services\SmartCheckout\SmartPosSettingsService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Validator as LaravelValidator;

/**
 * Configuration of the "Modo Caja Inteligente" (Phase 2). The payload is exactly
 * the `smartpos_settings.smart_checkout_settings` JSON contract:
 *
 * ```json
 * {
 *   "thresholds": { "green_min": 0.85, "green_margin": 0.08, "yellow_min": 0.65 },
 *   "auto_accept": true,
 *   "cameras": [{ "id": "cam-1", "label": "Cámara mostrador",
 *                 "mode": "zone", "resolution": "1280x720" }],
 *   "max_cameras_per_cycle": 2,
 *   "save_crops": true,
 *   "model_version": "mock"
 * }
 * ```
 *
 * A PUT replaces the whole settings object (missing keys fall back to the
 * defaults), which is the same contract the scale settings already use.
 *
 * Like the rest of the module, it carries no permission of its own: `authorize()`
 * only requires an authenticated user, and the route already requires
 * `pos.access`. Tenant isolation always comes from the authenticated user.
 */
class UpdateSmartCheckoutSettingsRequest extends FormRequest
{
    /** A cash terminal never needs more cameras than this. */
    public const MAX_CAMERAS = 4;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'thresholds' => ['sometimes', 'array'],
            'thresholds.green_min' => ['sometimes', 'numeric', 'between:0,1'],
            'thresholds.green_margin' => ['sometimes', 'numeric', 'between:0,1'],
            'thresholds.yellow_min' => ['sometimes', 'numeric', 'between:0,1'],

            'auto_accept' => ['sometimes', 'boolean'],

            // Cameras of the terminal (Phase 3 wires the video; the contract
            // exists from Phase 2 so the frontend can already store them).
            'cameras' => ['sometimes', 'array', 'max:'.self::MAX_CAMERAS],
            'cameras.*.id' => ['required', 'string', 'max:64', 'distinct'],
            'cameras.*.label' => ['required', 'string', 'max:120'],
            'cameras.*.mode' => ['required', 'string', 'in:zone,overlap'],
            'cameras.*.resolution' => ['sometimes', 'string', 'max:16'],

            'max_cameras_per_cycle' => ['sometimes', 'integer', 'between:1,'.self::MAX_CAMERAS],
            'save_crops' => ['sometimes', 'boolean'],
            'model_version' => ['sometimes', 'string', 'max:64'],
        ];
    }

    /**
     * The yellow band can never start above the green one. Missing values are
     * compared against the defaults, because a partial PUT is completed with
     * them (SmartPosSettingsService::normalizeSmartCheckoutSettings()).
     */
    public function withValidator(LaravelValidator $validator): void
    {
        $validator->after(function (LaravelValidator $validator): void {
            $defaults = app(SmartPosSettingsService::class)->defaultSmartCheckoutSettings();

            $greenMin = $this->input('thresholds.green_min', $defaults['thresholds']['green_min']);
            $yellowMin = $this->input('thresholds.yellow_min', $defaults['thresholds']['yellow_min']);

            if (is_numeric($greenMin) && is_numeric($yellowMin) && (float) $yellowMin > (float) $greenMin) {
                $validator->errors()->add(
                    'thresholds.yellow_min',
                    'El umbral amarillo debe ser menor o igual que el umbral verde.',
                );
            }
        });
    }

    /**
     * Spanish names for the JSON fields the settings panel shows.
     */
    public function attributes(): array
    {
        return [
            'thresholds' => 'umbrales',
            'thresholds.green_min' => 'umbral verde',
            'thresholds.green_margin' => 'margen verde',
            'thresholds.yellow_min' => 'umbral amarillo',
            'auto_accept' => 'agregado automático',
            'cameras' => 'cámaras',
            'cameras.*.id' => 'identificador de la cámara',
            'cameras.*.label' => 'nombre de la cámara',
            'cameras.*.mode' => 'modo de la cámara',
            'cameras.*.resolution' => 'resolución de la cámara',
            'max_cameras_per_cycle' => 'cámaras por ciclo',
            'save_crops' => 'guardar recortes',
            'model_version' => 'versión del modelo',
        ];
    }

    public function messages(): array
    {
        return [
            'thresholds.array' => 'Los umbrales deben enviarse como un objeto.',
            'thresholds.green_min.numeric' => 'El umbral verde debe ser un número entre 0 y 1.',
            'thresholds.green_min.between' => 'El umbral verde debe estar entre 0 y 1.',
            'thresholds.green_margin.numeric' => 'El margen verde debe ser un número entre 0 y 1.',
            'thresholds.green_margin.between' => 'El margen verde debe estar entre 0 y 1.',
            'thresholds.yellow_min.numeric' => 'El umbral amarillo debe ser un número entre 0 y 1.',
            'thresholds.yellow_min.between' => 'El umbral amarillo debe estar entre 0 y 1.',
            'auto_accept.boolean' => 'El agregado automático debe ser verdadero o falso.',
            'cameras.array' => 'Las cámaras deben enviarse como una lista.',
            'cameras.max' => 'No se pueden configurar más de '.self::MAX_CAMERAS.' cámaras.',
            'cameras.*.id.required' => 'Cada cámara necesita un identificador.',
            'cameras.*.id.distinct' => 'Los identificadores de las cámaras no pueden repetirse.',
            'cameras.*.label.required' => 'Cada cámara necesita un nombre.',
            'cameras.*.mode.required' => 'Cada cámara necesita un modo de detección.',
            'cameras.*.mode.in' => 'El modo de la cámara debe ser "zone" o "overlap".',
            'max_cameras_per_cycle.integer' => 'Las cámaras por ciclo deben ser un número entero.',
            'max_cameras_per_cycle.between' => 'Las cámaras por ciclo deben estar entre 1 y '.self::MAX_CAMERAS.'.',
            'save_crops.boolean' => 'La preferencia de guardar recortes debe ser verdadera o falsa.',
            'model_version.string' => 'La versión del modelo debe ser texto.',
            'model_version.max' => 'La versión del modelo no puede superar 64 caracteres.',
        ];
    }

    /**
     * The app renders every error outside /api/* as an Inertia page/redirect
     * (bootstrap/app.php + ApiExceptionRenderer), but this endpoint has a JSON
     * contract: the settings panel needs the 422 with the field errors.
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message' => $validator->errors()->first(),
            'errors' => $validator->errors(),
        ], 422));
    }
}
