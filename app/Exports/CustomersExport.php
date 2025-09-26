<?php

namespace App\Exports;

use App\Models\Customer;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class CustomersExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize
{
    public function collection()
    {
        $subscriptionId = Auth::user()->branch->subscription_id;
        return Customer::whereHas('branch.subscription', function ($query) use ($subscriptionId) {
            $query->where('id', $subscriptionId);
        })->get();
    }

    public function headings(): array
    {
        return [
            'Nombre', 'Empresa', 'Email', 'Teléfono', 'RFC', 'Límite de Crédito'
        ];
    }

    public function map($customer): array
    {
        return [
            $customer->name,
            $customer->company_name,
            $customer->email,
            $customer->phone,
            $customer->tax_id,
            $customer->credit_limit,
        ];
    }
}