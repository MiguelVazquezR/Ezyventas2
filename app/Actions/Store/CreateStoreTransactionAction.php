<?php

namespace App\Actions\Store;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\TransactionChannel;
use App\Enums\TransactionStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Product;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

/**
 * Creates a Transaction record linked to an online store Order
 * so the sale appears in sales history, reports, and analytics.
 */
class CreateStoreTransactionAction
{
    /**
     * Execute the action inside the same DB transaction as the Order creation.
     */
    public function execute(Order $order): Transaction
    {
        $branch = Branch::where('subscription_id', $order->subscription_id)
            ->first();

        if (! $branch) {
            throw new \RuntimeException('No branch found for subscription #' . $order->subscription_id);
        }

        $isMercadoPago = $order->payment_method === 'mercadopago';
        $isCash = $order->payment_method === 'cash';

        // For Mercado Pago, transaction stays pending until payment confirmed.
        // For cash, assume paid at delivery (pending until delivered).
        $transactionStatus = $isMercadoPago
            ? TransactionStatus::PENDING
            : TransactionStatus::COMPLETED;

        $transaction = Transaction::create([
            'branch_id'              => $branch->id,
            'folio'                  => Transaction::generateFolio($branch->id),
            'transactionable_id'     => $order->id,
            'transactionable_type'   => Order::class,
            'customer_id'            => null,
            'contact_info'           => [
                'name'  => $order->customer_name,
                'phone' => $order->customer_phone,
                'email' => $order->customer_email,
            ],
            'user_id'                => null,
            'cash_register_session_id' => null,
            'status'                 => $transactionStatus,
            'channel'                => TransactionChannel::ONLINE_STORE,
            'subtotal'               => $order->subtotal,
            'shipping_cost'          => $order->delivery_fee ?? 0,
            'total_discount'         => 0,
            'total_tax'              => 0,
            'currency'               => 'MXN',
            'shipping_address'       => $order->delivery_address,
            'notes'                  => "Pedido en línea #{$order->formatted_order_number}. " . ($order->customer_notes ?? ''),
            'status_changed_at'      => now(),
        ]);

        foreach ($order->items as $orderItem) {
            $transaction->items()->create([
                'itemable_id'    => $orderItem->product_id,
                'itemable_type'  => Product::class,
                'description'    => $orderItem->product_name,
                'quantity'       => $orderItem->quantity,
                'unit_price'     => $orderItem->unit_price,
                'discount_amount'=> 0,
                'line_total'     => $orderItem->subtotal,
            ]);

            // Deduct stock from the branch for this online store sale.
            // For MercadoPago orders, stock is deducted only when payment is confirmed.
            // For cash orders, stock is deducted immediately (paid on delivery).
            if (! $isMercadoPago) {
                $product = Product::find($orderItem->product_id);
                if ($product) {
                    $product->deductStock(
                        $branch->id,
                        $orderItem->quantity,
                        null,
                        "Venta por tienda en línea — pedido #{$order->formatted_order_number}"
                    );
                }
            }
        }

        // Payment record — status depends on method
        if ($isCash) {
            $transaction->payments()->create([
                'amount'          => $order->total,
                'payment_method'  => PaymentMethod::CASH->value,
                'status'          => PaymentStatus::COMPLETED->value,
                'notes'           => "Pago en efectivo — pedido en línea #{$order->formatted_order_number}",
            ]);
        } elseif ($isMercadoPago) {
            $transaction->payments()->create([
                'amount'          => $order->total,
                'payment_method'  => PaymentMethod::CARD->value,
                'status'          => PaymentStatus::PROCESSING->value,
                'notes'           => "Pago con Mercado Pago (pendiente) — pedido en línea #{$order->formatted_order_number}",
            ]);
        }

        $order->update(['transaction_id' => $transaction->id]);

        return $transaction;
    }
}
