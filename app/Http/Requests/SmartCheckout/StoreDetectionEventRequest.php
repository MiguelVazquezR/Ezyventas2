<?php

namespace App\Http\Requests\SmartCheckout;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * One metric event of the "Modo Caja Inteligente" (Phase 2).
 *
 * Body (`analysis`, a recognition cycle):
 *
 * ```json
 * {
 *   "session_id": "6f6c2b1e-6a1a-4d8e-9b0a-4b8f3e2f9c11",
 *   "event_type": "analysis",
 *   "objects": 3,
 *   "auto_accepted": 2,
 *   "confirmed": 1,
 *   "corrected": 0,
 *   "unknown": 0,
 *   "latency_ms": 148,
 *   "model_version": "mock",
 *   "occurred_at": "2026-11-27T13:45:00-06:00"
 * }
 * ```
 *
 * Body (`sale`, a sale closed with the mode active):
 *
 * ```json
 * {
 *   "session_id": "6f6c2b1e-6a1a-4d8e-9b0a-4b8f3e2f9c11",
 *   "event_type": "sale",
 *   "sale_id": 1432,
 *   "duration_seconds": 96
 * }
 * ```
 *
 * The payload **never** carries the tenant or the branch: both are resolved from
 * the authenticated user. A `sale_id` of another branch is rejected by
 * SmartCheckoutAnalyticsService (422), because `exists` alone cannot tell which
 * tenant the sale belongs to.
 *
 * The event has no permission of its own: every user who can open the Point of
 * Sale (`pos.access`, enforced by the route middleware) can register events.
 */
class StoreDetectionEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'session_id' => ['required', 'uuid'],
            'event_type' => ['required', 'string', 'in:analysis,sale'],

            // Aggregate counters of one analysis.
            'objects' => ['sometimes', 'integer', 'min:0', 'max:255'],
            'auto_accepted' => ['sometimes', 'integer', 'min:0', 'max:255'],
            'confirmed' => ['sometimes', 'integer', 'min:0', 'max:255'],
            'corrected' => ['sometimes', 'integer', 'min:0', 'max:255'],
            'unknown' => ['sometimes', 'integer', 'min:0', 'max:255'],

            'latency_ms' => ['nullable', 'integer', 'min:0', 'max:60000'],
            'model_version' => ['nullable', 'string', 'max:64'],
            'sale_id' => ['nullable', 'integer', 'exists:transactions,id'],
            'duration_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'occurred_at' => ['nullable', 'date'],
        ];
    }

    /**
     * Spanish names for the JSON fields the cashier never writes by hand, but
     * that the panel shows when a request is rejected.
     */
    public function attributes(): array
    {
        return [
            'session_id' => 'sesión de caja',
            'event_type' => 'tipo de evento',
            'objects' => 'objetos detectados',
            'auto_accepted' => 'agregados automáticamente',
            'confirmed' => 'confirmados',
            'corrected' => 'corregidos',
            'unknown' => 'no reconocidos',
            'latency_ms' => 'latencia del análisis',
            'model_version' => 'versión del modelo',
            'sale_id' => 'venta',
            'duration_seconds' => 'duración de la venta',
            'occurred_at' => 'fecha del evento',
        ];
    }

    public function messages(): array
    {
        return [
            'session_id.required' => 'La sesión de caja es obligatoria.',
            'session_id.uuid' => 'El identificador de la sesión de caja debe ser un UUID válido.',
            'event_type.required' => 'El tipo de evento es obligatorio.',
            'event_type.in' => 'El tipo de evento debe ser "analysis" o "sale".',
            'objects.integer' => 'Los objetos detectados deben ser un número entero.',
            'objects.min' => 'Los objetos detectados no pueden ser negativos.',
            'objects.max' => 'Los objetos detectados no pueden superar 255.',
            'auto_accepted.min' => 'Los agregados automáticamente no pueden ser negativos.',
            'confirmed.min' => 'Los confirmados no pueden ser negativos.',
            'corrected.min' => 'Los corregidos no pueden ser negativos.',
            'unknown.min' => 'Los no reconocidos no pueden ser negativos.',
            'latency_ms.integer' => 'La latencia debe ser un número entero de milisegundos.',
            'latency_ms.min' => 'La latencia no puede ser negativa.',
            'latency_ms.max' => 'La latencia no puede superar 60000 ms.',
            'model_version.max' => 'La versión del modelo no puede superar 64 caracteres.',
            'sale_id.exists' => 'La venta indicada no existe.',
            'duration_seconds.integer' => 'La duración de la venta debe ser un número entero de segundos.',
            'duration_seconds.min' => 'La duración de la venta no puede ser negativa.',
            'duration_seconds.max' => 'La duración de la venta no puede superar 86400 segundos.',
            'occurred_at.date' => 'La fecha del evento no es válida.',
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
