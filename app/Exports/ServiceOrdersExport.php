<?php

namespace App\Exports;

use App\Models\ServiceOrder;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class ServiceOrdersExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize
{
    public function collection()
    {
        $subscriptionId = Auth::user()->branch->subscription_id;
        return ServiceOrder::whereHas('branch.subscription', function ($query) use ($subscriptionId) {
            $query->where('id', $subscriptionId);
        })->with('branch')->get();
    }

    public function headings(): array
    {
        return [
            'ID',
            'Cliente',
            'Teléfono',
            'Email',
            'Equipo',
            'Falla Reportada',
            'Diagnóstico Técnico',
            'Estatus',
            'Fecha de Recepción',
            'Fecha Promesa',
            'Total Final',
            'Técnico Asignado',
            'Sucursal',
        ];
    }

    public function map($order): array
    {
        return [
            $order->id,
            $order->customer_name,
            $order->customer_phone,
            $order->customer_email,
            $order->item_description,
            $order->reported_problems,
            $order->technician_diagnosis,
            $order->status->value,
            $order->received_at->format('Y-m-d H:i'),
            $order->promised_at ? $order->promised_at->format('Y-m-d H:i') : 'N/A',
            $order->final_total,
            $order->technician_name,
            $order->branch->name,
        ];
    }
}