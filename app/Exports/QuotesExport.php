<?php

namespace App\Exports;

use App\Models\Quote;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class QuotesExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize
{
    public function collection()
    {
        $subscriptionId = Auth::user()->branch->subscription_id;
        return Quote::whereHas('branch.subscription', function ($query) use ($subscriptionId) {
            $query->where('id', $subscriptionId);
        })->with('customer')->get();
    }

    public function headings(): array
    {
        return ['Folio', 'Cliente', 'Fecha de Expiración', 'Estatus', 'Monto Total'];
    }

    public function map($quote): array
    {
        return [
            $quote->folio,
            $quote->customer->name ?? 'N/A',
            $quote->expiry_date ? $quote->expiry_date->format('Y-m-d') : 'N/A',
            $quote->status->value,
            $quote->total_amount,
        ];
    }
}