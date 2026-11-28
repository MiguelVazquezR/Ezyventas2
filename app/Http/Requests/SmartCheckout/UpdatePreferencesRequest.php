<?php

namespace App\Http\Requests\SmartCheckout;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * On/off switches of the "Modo Caja Inteligente" module, changed from the Point
 * of Sale itself (decision D2: the business can enable or disable them at any
 * time from the POS).
 *
 * Body (at least one of the two):
 *
 * ```json
 * { "scale_enabled": true, "smart_checkout_enabled": false }
 * ```
 *
 * These features carry no permission of their own: every user who can open the
 * Point of Sale (`pos.access`, enforced by the route middleware) can use and
 * configure them. The route and this request deliberately check nothing else.
 */
class UpdatePreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            // `required_without` is an implicit rule, so it also answers 422 on an
            // empty body (a `sometimes` here would skip it). Only the first flag
            // carries it, to avoid duplicating the error.
            'scale_enabled' => ['boolean', 'required_without:smart_checkout_enabled'],
            'smart_checkout_enabled' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'scale_enabled.required_without' => 'Indica al menos una preferencia: báscula o Modo Caja Inteligente.',
            'scale_enabled.boolean' => 'La preferencia de la báscula debe ser verdadera o falsa.',
            'smart_checkout_enabled.boolean' => 'La preferencia del Modo Caja Inteligente debe ser verdadera o falsa.',
        ];
    }

    /**
     * The app renders every error outside /api/* as an Inertia page/redirect
     * (bootstrap/app.php + ApiExceptionRenderer), but this endpoint has a JSON
     * contract: the POS panel needs the 422 with the field errors.
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message' => $validator->errors()->first(),
            'errors' => $validator->errors(),
        ], 422));
    }
}
