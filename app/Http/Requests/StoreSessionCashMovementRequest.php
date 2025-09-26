<?php

namespace App\Http\Requests;

use App\Enums\SessionCashMovementType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSessionCashMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(SessionCashMovementType::class)],
            'amount' => 'required|numeric|min:0.01',
            'description' => 'required|string|max:255',
        ];
    }
}
