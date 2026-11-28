<?php

namespace App\Http\Requests\SmartCheckout;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Scale configuration of the business (profiles, framing, reading pattern and
 * stability thresholds). The payload is exactly the `smartpos_settings
 * .scale_settings` JSON contract:
 *
 * ```json
 * {
 *   "active_profile_id": "string|null",
 *   "profiles": [{ "id": "...", "name": "...", "baudRate": 9600, "dataBits": 8,
 *                  "stopBits": 1, "parity": "none", "terminator": "lf",
 *                  "pattern": "/.../", "weightGroup": 1, "unitGroup": 2,
 *                  "stableGroup": 3, "unit": "kg", "notes": "..." }],
 *   "stability": { "readings": 5, "tolerance_g": 3 },
 *   "driver": "simulated|manual|webserial"
 * }
 * ```
 *
 * A profile is the serial framing of one scale model plus the regular expression
 * the driver uses to pull the weight out of each line the device sends.
 */
class UpdateScaleSettingsRequest extends FormRequest
{
    /** A scale never needs more than a handful of saved models. */
    public const MAX_PROFILES = 10;

    /** Highest baud rate a serial scale uses. */
    public const MAX_BAUD_RATE = 115200;

    /**
     * The scale carries no permission of its own: every user who can open the
     * Point of Sale (`pos.access`, enforced by the route middleware) can
     * configure it. Tenant isolation comes from the authenticated user, never
     * from the payload.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'active_profile_id' => ['nullable', 'string', 'max:64'],

            'profiles' => ['present', 'array', 'max:'.self::MAX_PROFILES],
            'profiles.*.id' => ['required', 'string', 'max:64', 'distinct'],
            'profiles.*.name' => ['required', 'string', 'max:120'],
            'profiles.*.baudRate' => ['required', 'integer', 'between:1200,'.self::MAX_BAUD_RATE],
            'profiles.*.dataBits' => ['required', 'integer', 'in:7,8'],
            'profiles.*.stopBits' => ['required', 'integer', 'in:1,2'],
            'profiles.*.parity' => ['required', 'string', 'in:none,even,odd'],
            'profiles.*.terminator' => ['required', 'string', 'in:lf,cr,crlf'],
            'profiles.*.pattern' => [
                'required',
                'string',
                'max:500',
                // The driver feeds this to preg_match(), so it has to compile.
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || @preg_match($value, '') === false) {
                        $fail('El campo :attribute debe ser una expresión regular válida.');
                    }
                },
            ],
            'profiles.*.weightGroup' => ['nullable', 'integer', 'between:0,20'],
            'profiles.*.unitGroup' => ['nullable', 'integer', 'between:0,20'],
            'profiles.*.stableGroup' => ['nullable', 'integer', 'between:0,20'],
            'profiles.*.unit' => ['required', 'string', 'in:kg,g'],
            'profiles.*.notes' => ['nullable', 'string', 'max:255'],

            'stability' => ['sometimes', 'array'],
            'stability.readings' => ['sometimes', 'integer', 'between:1,50'],
            'stability.tolerance_g' => ['sometimes', 'numeric', 'between:0,1000'],

            'unit' => ['sometimes', 'string', 'in:kg,g'],
            'driver' => ['sometimes', 'string', 'in:simulated,manual,webserial'],
        ];
    }

    /**
     * Spanish names for the JSON fields the cashier sees in the panel.
     */
    public function attributes(): array
    {
        return [
            'active_profile_id' => 'perfil activo',
            'profiles' => 'perfiles',
            'profiles.*.id' => 'identificador del perfil',
            'profiles.*.name' => 'nombre del perfil',
            'profiles.*.baudRate' => 'velocidad (baudios)',
            'profiles.*.dataBits' => 'bits de datos',
            'profiles.*.stopBits' => 'bits de parada',
            'profiles.*.parity' => 'paridad',
            'profiles.*.terminator' => 'terminador de línea',
            'profiles.*.pattern' => 'patrón de lectura',
            'profiles.*.weightGroup' => 'grupo del peso',
            'profiles.*.unitGroup' => 'grupo de la unidad',
            'profiles.*.stableGroup' => 'grupo de estabilidad',
            'profiles.*.unit' => 'unidad',
            'profiles.*.notes' => 'notas',
            'stability.readings' => 'lecturas de estabilidad',
            'stability.tolerance_g' => 'tolerancia en gramos',
            'unit' => 'unidad de la báscula',
            'driver' => 'controlador',
        ];
    }

    /**
     * The app renders every error outside /api/* as an Inertia page/redirect
     * (bootstrap/app.php + ApiExceptionRenderer), but this endpoint has a JSON
     * contract: the scale panel needs the 422 with the field errors.
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message' => $validator->errors()->first(),
            'errors' => $validator->errors(),
        ], 422));
    }
}
