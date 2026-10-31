<?php

namespace App\Http\Requests\FinancialReports;

use Illuminate\Foundation\Http\FormRequest;

class ExportFinancialReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('financial_reports.access');
    }

    public function rules(): array
    {
        return [
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ];
    }

    public function messages(): array
    {
        return [
            'start_date.required' => 'Selecciona la fecha inicial del reporte.',
            'start_date.date_format' => 'La fecha inicial debe tener el formato AAAA-MM-DD.',
            'end_date.required' => 'Selecciona la fecha final del reporte.',
            'end_date.date_format' => 'La fecha final debe tener el formato AAAA-MM-DD.',
            'end_date.after_or_equal' => 'La fecha final debe ser igual o posterior a la fecha inicial.',
        ];
    }
}
