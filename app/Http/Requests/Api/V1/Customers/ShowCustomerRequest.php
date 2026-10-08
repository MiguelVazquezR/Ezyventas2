<?php

namespace App\Http\Requests\Api\V1\Customers;

use App\Http\Requests\Api\V1\Concerns\AuthorizesAnyPermission;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Customer profile with layaways and balance movements.
 */
class ShowCustomerRequest extends FormRequest
{
    use AuthorizesAnyPermission;

    public function authorize(): bool
    {
        return $this->canAnyPermission(['pos.access', 'customers.access', 'customers.see_details']);
    }

    public function rules(): array
    {
        return [];
    }
}
