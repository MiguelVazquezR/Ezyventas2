<?php

namespace App\Http\Requests\Api\V1\ServiceOrders;

use App\Models\Branch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Service order created from the phone (multipart: evidence photos included).
 *
 * Same rules as the web order form, so both clients create the same order:
 * the folio, the linked sale, the customer debt and the stock deduction all
 * come from CreateServiceOrderAction.
 */
class StoreServiceOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('services.orders.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['nullable', 'integer', $this->customerOfSubscriptionRule()],
            'create_customer' => ['required', 'boolean'],
            'credit_limit' => ['required_if:create_customer,true', 'nullable', 'numeric', 'min:0'],
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:20'],
            'customer_address' => ['nullable', 'array'],
            'customer_address.street' => ['nullable', 'string', 'max:255'],
            'customer_address.city' => ['nullable', 'string', 'max:255'],
            'item_description' => ['required', 'string', 'max:255'],
            'reported_problems' => ['required', 'string'],
            'promised_at' => ['nullable', 'date'],
            'assign_technician' => ['required', 'boolean'],
            'technician_name' => ['required_if:assign_technician,true', 'nullable', 'string', 'max:255'],
            'technician_commission_type' => ['required_if:assign_technician,true', 'nullable', Rule::in(['percentage', 'fixed'])],
            'technician_commission_value' => ['required_if:assign_technician,true', 'nullable', 'numeric', 'min:0'],
            'custom_fields' => ['nullable', 'array'],
            'items' => ['nullable', 'array'],
            'items.*.itemable_id' => ['nullable'],
            'items.*.itemable_type' => ['nullable', 'string'],
            'items.*.description' => ['required', 'string'],
            'items.*.quantity' => ['required', 'numeric', 'min:0'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.line_total' => ['required', 'numeric', 'min:0'],
            'subtotal' => ['required', 'numeric', 'min:0'],
            'discount_type' => ['required', Rule::in(['fixed', 'percentage'])],
            'discount_value' => ['nullable', 'numeric', 'min:0'],
            'discount_amount' => ['required', 'numeric', 'min:0'],
            'final_total' => ['required', 'numeric', 'min:0'],
            'initial_evidence_images' => ['nullable', 'array', 'max:5'],
            'initial_evidence_images.*' => ['image', 'max:2048'],
            // Handled by the open session guard of the controller.
            'cash_register_session_id' => ['required', 'integer', 'exists:cash_register_sessions,id,status,abierta'],
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }

    public function messages(): array
    {
        return [
            'customer_id.exists' => 'El cliente seleccionado no pertenece a tu suscripción.',
            'customer_name.required' => 'Escribe el nombre del cliente.',
            'item_description.required' => 'Describe el equipo que recibes.',
            'reported_problems.required' => 'Anota las fallas que reporta el cliente.',
            'technician_name.required_if' => 'Escribe el nombre del técnico asignado.',
            'technician_commission_type.required_if' => 'Indica si la comisión del técnico es porcentaje o monto fijo.',
            'technician_commission_value.required_if' => 'Escribe el valor de la comisión del técnico.',
            'initial_evidence_images.max' => 'Puedes adjuntar como máximo 5 fotos.',
            'initial_evidence_images.*.image' => 'Cada archivo debe ser una imagen.',
            'initial_evidence_images.*.max' => 'Cada imagen debe pesar menos de 2 MB.',
            'cash_register_session_id.exists' => 'Necesitas una sesión de caja abierta para crear la orden.',
            'client_uuid.uuid' => 'El identificador de la operación no es válido.',
        ];
    }

    public function attributes(): array
    {
        return [
            'customer_name' => 'nombre del cliente',
            'item_description' => 'descripción del equipo',
            'reported_problems' => 'fallas reportadas',
            'initial_evidence_images' => 'evidencias',
            'items' => 'conceptos',
        ];
    }

    /**
     * Order data for the action (evidence photos and idempotency key excluded).
     *
     * @return array<string, mixed>
     */
    public function orderData(): array
    {
        return $this->safe()->except(['initial_evidence_images', 'client_uuid']);
    }

    /**
     * The web order form offers the customers of the whole subscription, so the
     * app must not be able to attach a customer from another business.
     */
    private function customerOfSubscriptionRule(): object
    {
        $branchIds = Branch::where('subscription_id', $this->user()?->branch?->subscription_id)->pluck('id');

        return Rule::exists('customers', 'id')->whereIn('branch_id', $branchIds);
    }
}
