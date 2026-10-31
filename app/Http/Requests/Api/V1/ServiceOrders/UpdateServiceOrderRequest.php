<?php

namespace App\Http\Requests\Api\V1\ServiceOrders;

use App\Models\Branch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Service order edited from the phone (multipart).
 *
 * Same rules as the web form: the action syncs the items (adjusting stock only
 * by the difference), updates the linked sale and the customer debt, and keeps
 * or removes the evidence photos.
 */
class UpdateServiceOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('services.orders.edit') ?? false;
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['nullable', 'integer', $this->customerOfSubscriptionRule()],
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:20'],
            'customer_address' => ['nullable', 'array'],
            'customer_address.street' => ['nullable', 'string', 'max:255'],
            'customer_address.city' => ['nullable', 'string', 'max:255'],
            'item_description' => ['required', 'string', 'max:255'],
            'reported_problems' => ['required', 'string'],
            'technician_diagnosis' => ['nullable', 'string', 'max:1000'],
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
            'deleted_media_ids' => ['nullable', 'array'],
            'deleted_media_ids.*' => ['integer', 'exists:media,id'],
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }

    public function messages(): array
    {
        return [
            'customer_id.exists' => 'El cliente seleccionado no pertenece a tu suscripción.',
            'customer_name.required' => 'Escribe el nombre del cliente.',
            'item_description.required' => 'Describe el equipo de la orden.',
            'reported_problems.required' => 'Anota las fallas que reporta el cliente.',
            'technician_name.required_if' => 'Escribe el nombre del técnico asignado.',
            'initial_evidence_images.max' => 'Puedes adjuntar como máximo 5 fotos.',
            'initial_evidence_images.*.image' => 'Cada archivo debe ser una imagen.',
            'initial_evidence_images.*.max' => 'Cada imagen debe pesar menos de 2 MB.',
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
            'deleted_media_ids' => 'evidencias por eliminar',
            'items' => 'conceptos',
        ];
    }

    /**
     * Order data for the action (photos, deletions and key are passed apart).
     *
     * @return array<string, mixed>
     */
    public function orderData(): array
    {
        return $this->safe()->except(['initial_evidence_images', 'deleted_media_ids', 'client_uuid']);
    }

    private function customerOfSubscriptionRule(): object
    {
        $branchIds = Branch::where('subscription_id', $this->user()?->branch?->subscription_id)->pluck('id');

        return Rule::exists('customers', 'id')->whereIn('branch_id', $branchIds);
    }
}
