<?php

namespace App\Http\Requests\FinancialReports;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FinancialReportIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('financial_reports.access');
    }

    public function rules(): array
    {
        return [
            'start_date' => ['nullable', 'required_with:end_date', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'required_with:start_date', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'range' => ['nullable', Rule::in(['day', 'week', 'month', 'year', 'custom'])],
        ];
    }

    public function messages(): array
    {
        return [
            'start_date.date_format' => 'La fecha inicial debe tener el formato AAAA-MM-DD.',
            'start_date.required_with' => 'Selecciona una fecha inicial y una fecha final.',
            'end_date.date_format' => 'La fecha final debe tener el formato AAAA-MM-DD.',
            'end_date.required_with' => 'Selecciona una fecha inicial y una fecha final.',
            'end_date.after_or_equal' => 'La fecha final debe ser igual o posterior a la fecha inicial.',
            'range.in' => 'El periodo seleccionado no es válido.',
        ];
    }
}
