<?php

namespace App\Http\Requests;

use App\Enums\ExpenseStatus;
use App\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'folio' => ['nullable', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'expense_category_id' => ['required', 'exists:expense_categories,id'],
            'expense_date' => ['required', 'date'],
            'status' => ['required', Rule::enum(ExpenseStatus::class)],
            'take_from_cash_register' => 'boolean',
            'description' => ['nullable', 'string'],
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'bank_account_id' => [
                'nullable',
                // La cuenta es requerida si el método es tarjeta o transferencia.
                Rule::requiredIf(function () {
                    return in_array($this->payment_method, [PaymentMethod::CARD->value, PaymentMethod::TRANSFER->value]);
                }),
                // Valida que el ID de la cuenta exista en la base de datos.
                'exists:bank_accounts,id',
            ],
        ];
    }
}

