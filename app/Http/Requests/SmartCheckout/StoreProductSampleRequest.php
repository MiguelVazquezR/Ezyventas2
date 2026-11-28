<?php

namespace App\Http\Requests\SmartCheckout;

use App\Models\SmartPosProductSample;
use App\Services\SmartCheckout\SmartCheckoutGalleryService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * One sample of the smart checkout vector gallery (Phase 3).
 *
 * Body:
 *
 * ```json
 * {
 *   "product_id": 5,
 *   "model_version": "mock",
 *   "dim": 384,
 *   "vector": [0.01, -0.02, 0.03, "… 384 valores …"],
 *   "source": "catalog_image",
 *   "quality": 0.91,
 *   "crop": "<base64 JPEG>"
 * }
 * ```
 *
 * - `vector` accepts either the array of numbers or the base64 of the
 *   little-endian float32 buffer (1536 bytes for `dim = 384`).
 * - `dim` is optional and defaults to 384; 128 to 1024 are accepted.
 * - `source` is one of SmartPosProductSample::SOURCES.
 * - `crop` is optional: a JPEG of at most 224x224 px and 80 KB.
 *
 * This request validates the **shape** of the payload (types, ranges, enum,
 * sizes). The checks that need the bytes — exact length of the vector, finite
 * components, L2 normalisation, JPEG sniffing, the product belonging to the
 * cashier's branch — live in SmartCheckoutGalleryService::storeSample(), which
 * raises the same kind of ValidationException and ends up in the same 422 JSON.
 * That keeps one single implementation of each rule.
 *
 * Like the rest of the module it carries no permission of its own: `authorize()`
 * only requires an authenticated user and the route already requires `pos.access`.
 * The tenant and the branch always come from the authenticated user, never from
 * this payload.
 */
class StoreProductSampleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * `dim` is optional in the contract but always known from here on: defaulting
     * it once means the service, the response and the tests never handle a null.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->has('dim') || $this->input('dim') === null || $this->input('dim') === '') {
            $this->merge(['dim' => SmartCheckoutGalleryService::DIM_DEFAULT]);
        }
    }

    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', 'min:1'],
            'model_version' => ['required', 'string', 'max:64'],

            'dim' => [
                'required',
                'integer',
                'min:'.SmartCheckoutGalleryService::DIM_MIN,
                'max:'.SmartCheckoutGalleryService::DIM_MAX,
            ],

            // Either the list of floats or the base64 of the binary buffer. The
            // components are validated by the service so every vector error is
            // reported under the same `vector` key.
            'vector' => [
                'required',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_array($value) && ! is_string($value)) {
                        $fail('El vector debe ser una lista de números o su representación en base64.');
                    }
                },
            ],

            'source' => ['required', 'string', Rule::in(SmartPosProductSample::SOURCES)],

            'quality' => ['nullable', 'numeric', 'between:0,1'],

            // Cheap ceiling before the body is decoded; the real MIME, size and
            // dimensions are checked on the bytes by the service.
            'crop' => ['nullable', 'string', 'max:'.SmartCheckoutGalleryService::CROP_MAX_ENCODED_LENGTH],
        ];
    }

    /**
     * Spanish names for the JSON fields the panel shows when a request fails.
     */
    public function attributes(): array
    {
        return [
            'product_id' => 'producto',
            'model_version' => 'versión del modelo',
            'dim' => 'dimensión del vector',
            'vector' => 'vector',
            'source' => 'origen de la muestra',
            'quality' => 'calidad de la muestra',
            'crop' => 'recorte',
        ];
    }

    public function messages(): array
    {
        return [
            'product_id.required' => 'El producto es obligatorio.',
            'product_id.integer' => 'El producto debe ser un identificador numérico.',
            'product_id.min' => 'El producto debe ser un identificador válido.',

            'model_version.required' => 'La versión del modelo es obligatoria.',
            'model_version.string' => 'La versión del modelo debe ser texto.',
            'model_version.max' => 'La versión del modelo no puede superar 64 caracteres.',

            'dim.required' => 'La dimensión del vector es obligatoria.',
            'dim.integer' => 'La dimensión del vector debe ser un número entero.',
            'dim.min' => 'La dimensión del vector debe ser al menos '.SmartCheckoutGalleryService::DIM_MIN.'.',
            'dim.max' => 'La dimensión del vector no puede superar '.SmartCheckoutGalleryService::DIM_MAX.'.',

            'vector.required' => 'El vector es obligatorio.',

            'source.required' => 'El origen de la muestra es obligatorio.',
            'source.in' => 'El origen de la muestra debe ser uno de: '.implode(', ', SmartPosProductSample::SOURCES).'.',

            'quality.numeric' => 'La calidad de la muestra debe ser un número entre 0 y 1.',
            'quality.between' => 'La calidad de la muestra debe estar entre 0 y 1.',

            'crop.string' => 'El recorte debe enviarse como una cadena en base64.',
            'crop.max' => 'El recorte no puede superar el tamaño máximo permitido (80 KB).',
        ];
    }

    /**
     * The app renders every error outside /api/* as an Inertia page/redirect
     * (bootstrap/app.php + ApiExceptionRenderer), but this endpoint has a JSON
     * contract: the panel needs the 422 with the field errors.
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message' => $validator->errors()->first(),
            'errors' => $validator->errors(),
        ], 422));
    }
}
