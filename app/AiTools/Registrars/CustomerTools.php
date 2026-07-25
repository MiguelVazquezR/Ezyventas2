<?php

namespace App\AiTools\Registrars;

use App\Models\Customer;
use App\Services\CustomerReportService;
use Carbon\Carbon;
use Illuminate\Contracts\Auth\Authenticatable;
use Prism\Prism\Tool;

class CustomerTools implements ToolRegistrar
{
    public function definitions(Authenticatable $user): array
    {
        $branchId = $user->branch_id;

        return [
            [
                'permission' => 'customers.access',
                'category'   => 'customers',
                'tool'       => (new Tool)->as('search_customers')
                    ->for('Buscar clientes por nombre, email o teléfono')
                    ->withStringParameter('query', 'Nombre parcial, email o teléfono a buscar')
                    ->using(function (string $query) use ($branchId) {
                        $customers = Customer::query()
                            ->where('branch_id', $branchId)
                            ->where(function ($q) use ($query) {
                                $q->where('name', 'LIKE', "%{$query}%")
                                  ->orWhere('email', 'LIKE', "%{$query}%")
                                  ->orWhere('phone', 'LIKE', "%{$query}%");
                            })
                            ->limit(15)
                            ->get(['id', 'name', 'email', 'phone', 'balance', 'credit_limit']);
                        return json_encode($customers, JSON_PRETTY_PRINT);
                    }),
            ],

            [
                'permission' => 'customers.see_financial_info',
                'category'   => 'customers',
                'tool'       => (new Tool)->as('customer_purchase_history')
                    ->for('Obtener el historial de compras recientes de un cliente específico')
                    ->withStringParameter('customer_query', 'Nombre, email o teléfono del cliente a buscar')
                    ->using(function (string $customer_query) use ($branchId) {
                        $customer = Customer::where('branch_id', $branchId)
                            ->where(fn ($q) => $q->where('name', 'like', "%{$customer_query}%")
                                ->orWhere('email', 'like', "%{$customer_query}%")
                                ->orWhere('phone', 'like', "%{$customer_query}%"))
                            ->firstOrFail();
                        $result = app(CustomerReportService::class)->getPurchaseHistory($branchId, $customer->id);
                        return json_encode($result, JSON_PRETTY_PRINT);
                    }),
            ],

            [
                'permission' => 'customers.see_financial_info',
                'category'   => 'customers',
                'tool'       => (new Tool)->as('customer_account_statement')
                    ->for('Obtener el estado de cuenta y movimientos de saldo de un cliente')
                    ->withStringParameter('customer_query', 'Nombre, email o teléfono del cliente a buscar')
                    ->using(function (string $customer_query) use ($branchId) {
                        $customer = Customer::where('branch_id', $branchId)
                            ->where(fn ($q) => $q->where('name', 'like', "%{$customer_query}%")
                                ->orWhere('email', 'like', "%{$customer_query}%")
                                ->orWhere('phone', 'like', "%{$customer_query}%"))
                            ->firstOrFail();
                        $result = app(CustomerReportService::class)->getAccountStatement($branchId, $customer->id);
                        return json_encode($result, JSON_PRETTY_PRINT);
                    }),
            ],

            [
                'permission' => 'customers.see_financial_info',
                'category'   => 'customers',
                'tool'       => (new Tool)->as('top_customers')
                    ->for('Obtener el ranking de los clientes con mayor gasto en un período')
                    ->withStringParameter('start_date', 'Fecha inicial en formato YYYY-MM-DD')
                    ->withStringParameter('end_date', 'Fecha final en formato YYYY-MM-DD')
                    ->withNumberParameter('limit', 'Cantidad máxima de clientes (por defecto 10)')
                    ->using(function (string $start_date, string $end_date, ?int $limit = 10) use ($branchId) {
                        $result = app(CustomerReportService::class)->getTopCustomers(
                            $branchId,
                            Carbon::parse($start_date),
                            Carbon::parse($end_date),
                            $limit ?? 10,
                        );
                        return json_encode($result, JSON_PRETTY_PRINT);
                    }),
            ],

            [
                'permission' => 'customers.see_financial_info',
                'category'   => 'customers',
                'tool'       => (new Tool)->as('customer_recency')
                    ->for('Listar clientes que no han comprado en los últimos N días, o que compraron recientemente, para identificar clientes inactivos')
                    ->withNumberParameter('days', 'Días de inactividad o ventana reciente, ej. 30, 60, 90')
                    ->withStringParameter('direction', '"inactive" (no han comprado en N días) o "recent" (compraron dentro de N días). Por defecto "inactive"')
                    ->withNumberParameter('limit', 'Cantidad máxima de clientes (por defecto 20, máximo 50)')
                    ->using(function (int $days, ?string $direction = 'inactive', ?int $limit = 20) use ($branchId) {
                        $result = app(CustomerReportService::class)->getCustomerRecency(
                            $branchId,
                            $days,
                            $direction ?? 'inactive',
                            min($limit ?? 20, 50),
                        );
                        return json_encode($result, JSON_PRETTY_PRINT);
                    }),
            ],

            // ════════════════ CRUD ════════════════
            [
                'permission' => 'customers.create',
                'category'   => 'customers (crear/editar)',
                'tool'       => (new Tool)->as('create_customer')
                    ->for('Crear un nuevo cliente. REQUIERE modo escritura activado.')
                    ->withStringParameter('name', 'Nombre completo del cliente')
                    ->withStringParameter('email', 'Email del cliente (opcional)')
                    ->withStringParameter('phone', 'Teléfono del cliente (opcional)')
                    ->withStringParameter('notes', 'Notas adicionales (opcional)')
                    ->using(function (string $name, ?string $email = null, ?string $phone = null, ?string $notes = null) use ($branchId) {
                        $gate = app(\App\AiTools\WriteModeGate::class);
                        if (! $gate->isEnabled()) {
                            return json_encode(['error' => $gate->rejectionMessage()]);
                        }

                        $customer = Customer::create([
                            'branch_id' => $branchId,
                            'name'      => $name,
                            'email'     => $email,
                            'phone'     => $phone,
                            'notes'     => $notes,
                            'balance'   => 0,
                        ]);

                        return json_encode([
                            'message' => 'Cliente creado exitosamente.',
                            'customer' => [
                                'id'    => $customer->id,
                                'name'  => $customer->name,
                                'email' => $customer->email,
                                'phone' => $customer->phone,
                            ],
                        ], JSON_PRETTY_PRINT);
                    }),
            ],

            [
                'permission' => 'customers.edit',
                'category'   => 'customers (crear/editar)',
                'tool'       => (new Tool)->as('update_customer')
                    ->for('Actualizar los datos de un cliente existente. REQUIERE modo escritura activado.')
                    ->withNumberParameter('customer_id', 'ID del cliente a actualizar')
                    ->withStringParameter('name', 'Nuevo nombre (opcional)')
                    ->withStringParameter('email', 'Nuevo email (opcional)')
                    ->withStringParameter('phone', 'Nuevo teléfono (opcional)')
                    ->withStringParameter('notes', 'Nuevas notas (opcional)')
                    ->using(function (int $customer_id, ?string $name = null, ?string $email = null, ?string $phone = null, ?string $notes = null) use ($branchId) {
                        $gate = app(\App\AiTools\WriteModeGate::class);
                        if (! $gate->isEnabled()) {
                            return json_encode(['error' => $gate->rejectionMessage()]);
                        }

                        $data = array_filter([
                            'name'  => $name,
                            'email' => $email,
                            'phone' => $phone,
                            'notes' => $notes,
                        ], fn ($v) => $v !== null);

                        if (empty($data)) {
                            return json_encode(['error' => 'Debes proporcionar al menos un campo para actualizar.']);
                        }

                        $customer = Customer::where('branch_id', $branchId)->findOrFail($customer_id);
                        $customer->update($data);

                        return json_encode([
                            'message' => 'Cliente actualizado exitosamente.',
                            'customer' => [
                                'id'    => $customer->id,
                                'name'  => $customer->name,
                                'email' => $customer->email,
                                'phone' => $customer->phone,
                            ],
                        ], JSON_PRETTY_PRINT);
                    }),
            ],
        ];
    }
}