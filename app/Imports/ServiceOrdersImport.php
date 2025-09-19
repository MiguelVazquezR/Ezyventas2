<?php

namespace App\Imports;

use App\Enums\ServiceOrderStatus;
use App\Models\ServiceOrder;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class ServiceOrdersImport implements ToModel, WithHeadingRow
{
    public function model(array $row)
    {
        return new ServiceOrder([
            'customer_name' => $row['cliente'],
            'customer_phone' => $row['telefono'],
            'customer_email' => $row['email'],
            'item_description' => $row['equipo'],
            'reported_problems' => $row['falla_reportada'],
            'technician_diagnosis' => $row['diagnostico_tecnico'],
            'status' => ServiceOrderStatus::tryFrom(strtolower($row['estatus'])) ?? ServiceOrderStatus::PENDING,
            'received_at' => Carbon::parse($row['fecha_de_recepcion']),
            'promised_at' => isset($row['fecha_promesa']) ? Carbon::parse($row['fecha_promesa']) : null,
            'final_total' => $row['total_final'] ?? null,
            'technician_name' => $row['tecnico_asignado'],
            'user_id' => Auth::id(),
            'branch_id' => Auth::user()->branch_id,
        ]);
    }
}