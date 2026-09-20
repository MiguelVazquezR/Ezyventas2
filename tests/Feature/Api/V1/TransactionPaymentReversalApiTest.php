<?php

namespace Tests\Feature\Api\V1;

use App\Enums\TransactionStatus;
use App\Models\Payment;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Api\V1\Concerns\BuildsMobileApiContext;
use Tests\Feature\Api\V1\Concerns\BuildsPosApiContext;
use Tests\TestCase;

/**
 * The customer balance must survive the whole money cycle of a layaway
 * (hallazgo 8 of the mobile handover): create a layaway, pay it, edit the
 * payment, delete it, pay again and cancel with a cash refund.
 *
 * The debt posted by the sale is adjusted by every payment, so editing or
 * deleting one has to revert exactly the movement it wrote.
 */
class TransactionPaymentReversalApiTest extends TestCase
{
    use RefreshDatabase;
    use BuildsMobileApiContext;
    use BuildsPosApiContext;

    private User $owner;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpMobileApiContext();

        $this->owner = $this->ownerUser();
        $this->token = $this->tokenFor($this->owner);
        $this->setUpPosApiContext($this->owner);
    }

    #[Test]
    public function it_keeps_the_customer_balance_balanced_through_the_whole_layaway_cycle(): void
    {
        $layaway = $this->layaway(1.00);

        // The layaway posted the debt of the sale minus the first payment.
        $this->assertCustomerBalance(-139.00);

        $payment = $layaway->payments()->firstOrFail();

        $this->editPayment($layaway, $payment, 1.50);
        $this->assertCustomerBalance(-138.50);

        $this->deletePayment($layaway, $payment);
        $this->assertCustomerBalance(-140.00);
        $this->assertTotals($layaway, paid: 0.00, due: 140.00);

        $this->addPayment($layaway, 2.00);
        $this->assertCustomerBalance(-138.00);

        // Cancelling with a cash refund forgives the pending debt: back to zero.
        $this->withToken($this->token)
            ->postJson("/api/v1/transactions/{$layaway->id}/cancel", [
                'action' => 'refund',
                'refund_method' => 'cash',
            ])
            ->assertOk();

        $this->assertCustomerBalance(0.00);
        $this->assertSame(TransactionStatus::REFUNDED, $layaway->fresh()->status);
        // El saldo pendiente de una venta anulada se corrige en el punto A2.
        $this->assertEqualsWithDelta(2.00, (float) $layaway->fresh()->total_paid, 0.001);
    }

    #[Test]
    public function it_does_not_double_revert_a_payment_made_with_the_customer_balance(): void
    {
        $layaway = $this->layaway(0.00);

        // The debt of the layaway (140) is now part of the balance.
        $this->assertCustomerBalance(-140.00);

        // The customer buys credit before paying the layaway with it.
        $this->customer->update(['balance' => 500]);

        $this->withToken($this->token)
            ->postJson("/api/v1/transactions/{$layaway->id}/payments", [
                'cash_register_session_id' => $this->cashRegisterSession->id,
                'use_balance' => true,
                'payments' => [],
            ])
            ->assertOk();

        $this->assertCustomerBalance(360.00);

        $payment = $layaway->payments()->firstOrFail();
        $this->assertSame('saldo', $payment->payment_method->value);

        $this->deletePayment($layaway, $payment);

        // The balance used by the payment comes back exactly once.
        $this->assertCustomerBalance(500.00);
        $this->assertTotals($layaway, paid: 0.00, due: 140.00);
    }

    /**
     * Layaway of 140 with a first cash payment (0 = nothing paid yet).
     */
    private function layaway(float $firstPayment): Transaction
    {
        $response = $this->withToken($this->token)
            ->postJson('/api/v1/pos/layaway', [
                'cash_register_session_id' => $this->cashRegisterSession->id,
                'customerId' => $this->customer->id,
                'cartItems' => [
                    $this->cartItem(['quantity' => 1, 'unit_price' => 140, 'discount' => 0]),
                ],
                'subtotal' => 140,
                'total_discount' => 0,
                'total' => 140,
                'use_balance' => false,
                'layaway_expiration_date' => now()->addWeek()->toDateString(),
                'payments' => $firstPayment > 0
                    ? [['amount' => $firstPayment, 'method' => 'efectivo', 'bank_account_id' => null]]
                    : [],
            ]);

        $response->assertCreated();

        return Transaction::findOrFail($response->json('transaction.id'));
    }

    private function addPayment(Transaction $transaction, float $amount): void
    {
        $this->withToken($this->token)
            ->postJson("/api/v1/transactions/{$transaction->id}/payments", [
                'cash_register_session_id' => $this->cashRegisterSession->id,
                'use_balance' => false,
                'payments' => [['amount' => $amount, 'method' => 'efectivo', 'bank_account_id' => null]],
            ])
            ->assertOk();
    }

    private function editPayment(Transaction $transaction, Payment $payment, float $amount): void
    {
        $this->withToken($this->token)
            ->putJson("/api/v1/transactions/{$transaction->id}/payments/{$payment->id}", [
                'amount' => $amount,
                'payment_method' => 'efectivo',
                'bank_account_id' => null,
            ])
            ->assertOk();
    }

    private function deletePayment(Transaction $transaction, Payment $payment): void
    {
        $this->withToken($this->token)
            ->deleteJson("/api/v1/transactions/{$transaction->id}/payments/{$payment->id}")
            ->assertNoContent();
    }

    private function assertCustomerBalance(float $expected): void
    {
        $this->assertEqualsWithDelta(
            $expected,
            (float) $this->customer->fresh()->balance,
            0.001,
            'El saldo del cliente quedó desfasado.'
        );
    }

    private function assertTotals(Transaction $transaction, float $paid, float $due): void
    {
        $this->assertEqualsWithDelta($paid, (float) $transaction->fresh()->total_paid, 0.001);
        $this->assertEqualsWithDelta($due, (float) $transaction->fresh()->remaining_due, 0.001);
    }
}
