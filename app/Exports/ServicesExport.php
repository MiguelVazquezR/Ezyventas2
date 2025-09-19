<?php

namespace App\Exports;

use App\Models\Service;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class ServicesExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize
{
    public function collection()
    {
        $subscriptionId = Auth::user()->branch->subscription_id;
        return Service::whereHas('branch.subscription', function ($query) use ($subscriptionId) {
            $query->where('id', $subscriptionId);
        })->with('category')->get();
    }

    public function headings(): array
    {
        return [
            'Nombre',
            'Descripción',
            'Categoría',
            'Precio Base',
            'Duración Estimada',
            'Visible en Tienda',
        ];
    }

    public function map($service): array
    {
        return [
            $service->name,
            $service->description,
            $service->category->name ?? 'N/A',
            $service->base_price,
            $service->duration_estimate,
            $service->show_online ? 'Si' : 'No',
        ];
    }
}