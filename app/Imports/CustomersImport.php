<?php

namespace App\Imports;

use App\Models\Customer;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class CustomersImport implements ToModel, WithHeadingRow
{
    public function model(array $row)
    {
        return new Customer([
            'name' => $row['nombre'],
            'company_name' => $row['empresa'],
            'email' => $row['email'],
            'phone' => $row['telefono'],
            'tax_id' => $row['rfc'],
            'credit_limit' => $row['limite_de_credito'] ?? 0,
            'branch_id' => Auth::user()->branch_id,
        ]);
    }
}