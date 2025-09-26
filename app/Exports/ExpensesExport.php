<?php

namespace App\Exports;

use App\Models\Expense;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class ExpensesExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize
{
    /**
    * @return \Illuminate\Support\Collection
    */
    public function collection()
    {
        $subscriptionId = Auth::user()->branch->subscription_id;
        
        return Expense::whereHas('branch.subscription', function ($query) use ($subscriptionId) {
            $query->where('id', $subscriptionId);
        })
        ->with(['category', 'user', 'branch'])
        ->get();
    }

    public function headings(): array
    {
        return [
            'ID',
            'Folio',
            'Fecha',
            'Descripción',
            'Monto',
            'Categoría',
            'Estatus',
            'Registrado Por',
            'Sucursal',
        ];
    }

    public function map($expense): array
    {
        return [
            $expense->id,
            $expense->folio,
            $expense->expense_date->format('Y-m-d'),
            $expense->description,
            $expense->amount,
            $expense->category->name ?? 'N/A',
            $expense->status->value,
            $expense->user->name ?? 'N/A',
            $expense->branch->name ?? 'N/A',
        ];
    }
}