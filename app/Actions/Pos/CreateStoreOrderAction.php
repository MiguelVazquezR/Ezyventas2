<?php

namespace App\Actions\Pos;

use App\Models\Customer;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionPaymentService;

/**
 * Creates an order (pedido) with reserved stock and a pending delivery.
 *
 * Shared by the web POS drawer and the mobile app: both send the same payload
 * and both get the order normalized the same way (contact type and phone).
 */
class CreateStoreOrderAction
{
    public function __construct(private readonly TransactionPaymentService $payments) {}

    /**
     * @param  array<string, mixed>  $data  contact_info, cartItems, delivery_date, totals and customer id
     */
    public function execute(array $data, User $user): Transaction
    {
        $customer = $this->resolveCustomer($data);

        $data['customer_id'] = $data['customer_id'] ?? $data['customerId'] ?? $customer?->id;
        $data['contact_info'] = $this->normalizeContactInfo($data['contact_info'] ?? [], $customer);

        return $this->payments->handleNewOrder($user, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveCustomer(array $data): ?Customer
    {
        $customerId = $data['customer_id'] ?? $data['customerId'] ?? null;

        return $customerId ? Customer::find($customerId) : null;
    }

    /**
     * Order contact: 'comanda' (restaurant mode) or 'pedido' (retail). When the
     * contact has no phone, the customer phone is inherited.
     *
     * @param  array<string, mixed>  $contactInfo
     * @return array<string, mixed>
     */
    private function normalizeContactInfo(array $contactInfo, ?Customer $customer): array
    {
        $contactInfo['type'] = ($contactInfo['type'] ?? null) === 'comanda' ? 'comanda' : 'pedido';

        if (empty($contactInfo['phone'] ?? null) && $customer?->phone) {
            $contactInfo['phone'] = $customer->phone;
        }

        return $contactInfo;
    }
}
