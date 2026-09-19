<?php

namespace App\Http\Requests\Api\V1\Transactions;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Sale detail, including items and payments.
 */
class ShowTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('transactions.see_details') ?? false;
    }

    public function rules(): array
    {
        return [];
    }
}
