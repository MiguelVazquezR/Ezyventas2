<?php

namespace App\Http\Requests\Api\V1\ServiceOrders;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Full detail of one service order.
 */
class ShowServiceOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('services.orders.see_details') ?? false;
    }

    public function rules(): array
    {
        return [];
    }
}
