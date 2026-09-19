<?php

namespace App\Http\Requests\Api\V1\ServiceOrders;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Deleting a service order (and its linked sale). The app asks for an explicit
 * confirmation before sending this.
 */
class DestroyServiceOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('services.orders.delete') ?? false;
    }

    public function rules(): array
    {
        return [];
    }
}
