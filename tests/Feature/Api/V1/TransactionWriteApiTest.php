<?php

namespace Tests\Feature\Api\V1;

use App\Enums\CashRegisterSessionStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\SessionCashMovementType;
use App\Enums\TransactionChannel;
use App\Enums\TransactionStatus;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Api\V1\Concerns\BuildsMobileApiContext;
use Tests\Feature\Api\V1\Concerns\BuildsPosApiContext;
use Tests\TestCase;

/**
 * Covers the money endpoints of the mobile API phase 3: payments (abonos),
 * cancellation and refund of a sale.
 */
class TransactionWriteApiTest extends TestCase
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

    /**
     * Sale of 300 with two units of the product of the branch.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function sale(array $overrides = [], float $paid = 300): Transaction
    {
        $transaction = Transaction::factory()->create(array_merge([
            'branch_id' => $this->branch->id,
            'user_id' => $this->owner->id,
            'customer_id' => $this->customer->id,
            'cash_register_session_id' => $this->cashRegisterSession->id,
            'status' => TransactionStatus::COMPLETED,
            'channel' => TransactionChannel::POS,
            'subtotal' => 300,
            'total_discount' => 0,
            'total_tax' => 0,
            'shipping_cost' => 0,
            'invoiced' => false,
            'created_at' => now()->subDay(),
        ], $overrides));

        TransactionItem::create([
            'transaction_id' => $transaction->id,
            'itemable_type' => Product::class,
            'itemable_id' => $this->product->id,
            'description' => $this->product->name,
            'quantity' => 2,
            'unit_price' => 150,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'line_total' => 300,
        ]);

        if ($paid > 0) {
            Payment::factory()->create([
                'transaction_id' => $transaction->id,
                'cash_register_session_id' => $this->cashRegisterSession->id,
                'amount' => $paid,
                'payment_method' => PaymentMethod::CASH,
                'status' => PaymentStatus::COMPLETED,
            ]);
        }

        return $transaction;
    }

    #[Test]
    public function it_registers_a_payment_and_returns_the_receipt(): void
    {
        $transaction = $this->sale(['status' => TransactionStatus::PENDING], paid: 100);

        $response = $this->withToken($this->token)
            ->postJson('/api/v1/transactions/' . $transaction->id . '/payments', [
                'cash_register_session_id' => $this->cashRegisterSession->id,
                'use_balance' => false,
                'payments' => [
                    ['amount' => 200, 'method' => 'tarjeta', 'bank_account_id' => $this->bankAccount->id, 'notes' => 'Voucher 1234'],
                ],
            ]);

        $response->assertOk()
            ->assertJsonPath('transaction.total_paid', 300)
            ->assertJsonPath('transaction.remaining_due', 0)
            ->assertJsonPath('transaction.is_paid', true)
            ->assertJsonPath('print.type', 'abono')
            ->assertJsonPath('print.payload.kind', 'abono')
            ->assertJsonPath('print.payload.folio', $transaction->folio)
            ->assertJsonPath('print.payload.abonado', '$200.00 MXN')
            ->assertJsonPath('print.payload.remainingDue', '$0.00 MXN')
            ->assertJsonPath('print.payload.liquidated', true)
            ->assertJsonPath('print.customer_phone', '4771112233')
            ->assertJsonPath('print.customer_id', $this->customer->id);

        $this->assertDatabaseHas('payments', [
            'transaction_id' => $transaction->id,
            'amount' => 200,
            'payment_method' => 'tarjeta',
            'bank_account_id' => $this->bankAccount->id,
        ]);
        // The bank account received the money of the card payment.
        $this->assertEquals(5200, (float) $this->bankAccount->fresh()->balance);
        $this->assertEquals(TransactionStatus::COMPLETED, $transaction->fresh()->status);
    }

    #[Test]
    public function it_rejects_a_payment_without_methods_or_balance(): void
    {
        $transaction = $this->sale(['status' => TransactionStatus::PENDING], paid: 100);

        $this->withToken($this->token)
            ->postJson('/api/v1/transactions/' . $transaction->id . '/payments', [
                'cash_register_session_id' => $this->cashRegisterSession->id,
                'use_balance' => false,
                'payments' => [],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'payments' => 'Debe proporcionar al menos un método de pago o usar el saldo a favor.',
            ]);

        $this->withToken($this->token)
            ->postJson('/api/v1/transactions/' . $transaction->id . '/payments', [
                'cash_register_session_id' => $this->cashRegisterSession->id,
                'payments' => [['amount' => 200, 'method' => 'tarjeta', 'bank_account_id' => null]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'payments.0.bank_account_id' => 'Selecciona la cuenta destino para los pagos con tarjeta o transferencia.',
            ]);
    }

    #[Test]
    public function it_pays_with_the_customer_balance(): void
    {
        $customerWithBalance = Customer::factory()->create([
            'branch_id' => $this->branch->id,
            'balance' => 500,
        ]);

        $transaction = $this->sale(
            ['customer_id' => $customerWithBalance->id, 'status' => TransactionStatus::PENDING],
            paid: 100
        );

        $response = $this->withToken($this->token)
            ->postJson('/api/v1/transactions/' . $transaction->id . '/payments', [
                'cash_register_session_id' => $this->cashRegisterSession->id,
                'use_balance' => true,
                'payments' => [],
            ]);

        $response->assertOk()
            ->assertJsonPath('transaction.total_paid', 300)
            ->assertJsonPath('transaction.remaining_due', 0);

        // The balance covered the missing 200 and kept the rest for later.
        $this->assertEquals(300, (float) $customerWithBalance->fresh()->balance);
        $this->assertDatabaseHas('payments', [
            'transaction_id' => $transaction->id,
            'amount' => 200,
            'payment_method' => 'saldo',
        ]);
    }

    #[Test]
    public function it_requires_an_open_session_to_register_a_payment(): void
    {
        $transaction = $this->sale(['status' => TransactionStatus::PENDING], paid: 100);
        $this->cashRegisterSession->update(['status' => CashRegisterSessionStatus::CLOSED]);

        $this->withToken($this->token)
            ->postJson('/api/v1/transactions/' . $transaction->id . '/payments', [
                'cash_register_session_id' => $this->cashRegisterSession->id,
                'payments' => [['amount' => 200, 'method' => 'efectivo', 'bank_account_id' => null]],
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'session_required')
            ->assertJsonPath('message', 'Necesitas una sesión de caja abierta para registrar abonos.');
    }

    #[Test]
    public function it_cancels_a_sale_with_penalty_and_returns_the_stock(): void
    {
        $transaction = $this->sale();

        $response = $this->withToken($this->token)
            ->postJson('/api/v1/transactions/' . $transaction->id . '/cancel', [
                'action' => 'penalty',
            ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Transacción cancelada con penalización (dinero retenido).')
            ->assertJsonPath('transaction.status', 'cancelado');

        // The money stays in the till and the stock goes back to the shelf.
        $this->assertEquals(22, $this->productStock()->current_stock);
        $this->assertEquals(0, (float) $this->customer->fresh()->balance);
    }

    #[Test]
    public function it_refunds_cash_from_the_open_session(): void
    {
        $transaction = $this->sale();

        $response = $this->withToken($this->token)
            ->postJson('/api/v1/transactions/' . $transaction->id . '/cancel', [
                'action' => 'refund',
                'refund_method' => 'cash',
            ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Transacción reembolsada en efectivo.')
            ->assertJsonPath('transaction.status', 'reembolsado');

        $this->assertDatabaseHas('session_cash_movements', [
            'cash_register_session_id' => $this->cashRegisterSession->id,
            'type' => SessionCashMovementType::OUTFLOW->value,
            'amount' => 300,
        ]);
        $this->assertEquals(22, $this->productStock()->current_stock);
    }

    #[Test]
    public function it_requires_an_open_session_to_refund_cash(): void
    {
        $transaction = $this->sale();
        $this->cashRegisterSession->update(['status' => CashRegisterSessionStatus::CLOSED]);

        $this->withToken($this->token)
            ->postJson('/api/v1/transactions/' . $transaction->id . '/cancel', [
                'action' => 'refund',
                'refund_method' => 'cash',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Se requiere una sesión de caja activa para devolver efectivo.');

        // Nothing changed: the sale is still completed.
        $this->assertEquals(TransactionStatus::COMPLETED, $transaction->fresh()->status);
    }

    #[Test]
    public function it_refunds_to_the_customer_balance(): void
    {
        $transaction = $this->sale();

        $this->withToken($this->token)
            ->postJson('/api/v1/transactions/' . $transaction->id . '/refund', [
                'refund_method' => 'balance',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Transacción reembolsada al saldo del cliente.')
            ->assertJsonPath('transaction.status', 'reembolsado');

        $this->assertEquals(300, (float) $this->customer->fresh()->balance);
    }

    #[Test]
    public function it_refunds_by_transfer_to_the_bank_account(): void
    {
        $transaction = $this->sale();

        $this->withToken($this->token)
            ->postJson('/api/v1/transactions/' . $transaction->id . '/refund', [
                'refund_method' => 'transfer',
                'bank_account_id' => $this->bankAccount->id,
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Transacción reembolsada por transferencia bancaria.');

        // The account is charged and a negative payment keeps the statement straight.
        $this->assertEquals(4700, (float) $this->bankAccount->fresh()->balance);
        $this->assertDatabaseHas('payments', [
            'transaction_id' => $transaction->id,
            'amount' => -300,
            'payment_method' => 'transferencia',
        ]);
    }

    #[Test]
    public function it_rejects_cancelling_an_already_cancelled_sale(): void
    {
        $transaction = $this->sale(['status' => TransactionStatus::CANCELLED]);

        $this->withToken($this->token)
            ->postJson('/api/v1/transactions/' . $transaction->id . '/cancel', ['action' => 'penalty'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'La venta ya se encuentra cancelada o reembolsada.');

        $this->withToken($this->token)
            ->postJson('/api/v1/transactions/' . $transaction->id . '/refund', ['refund_method' => 'cash'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'La venta ya se encuentra cancelada o reembolsada.');
    }

    #[Test]
    public function it_requires_the_money_permissions(): void
    {
        $transaction = $this->sale(['status' => TransactionStatus::PENDING], paid: 100);
        $employee = $this->employeeUser(['pos.access']);
        $token = $this->tokenFor($employee);

        $this->withToken($token)
            ->postJson('/api/v1/transactions/' . $transaction->id . '/payments', [
                'cash_register_session_id' => $this->cashRegisterSession->id,
                'payments' => [['amount' => 200, 'method' => 'efectivo', 'bank_account_id' => null]],
            ])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Tu usuario no tiene permiso para esta acción.');

        $this->withToken($token)
            ->postJson('/api/v1/transactions/' . $transaction->id . '/cancel', ['action' => 'penalty'])
            ->assertStatus(403);

        $this->withToken($token)
            ->postJson('/api/v1/transactions/' . $transaction->id . '/refund', ['refund_method' => 'cash'])
            ->assertStatus(403);
    }

    #[Test]
    public function it_edits_a_payment_and_reconciles_the_bank_account(): void
    {
        $transaction = $this->sale(['status' => TransactionStatus::COMPLETED], paid: 0);

        $payment = Payment::factory()->create([
            'transaction_id' => $transaction->id,
            'cash_register_session_id' => $this->cashRegisterSession->id,
            'amount' => 200,
            'payment_method' => PaymentMethod::CARD,
            'status' => PaymentStatus::COMPLETED,
            'bank_account_id' => $this->bankAccount->id,
        ]);
        // The account already holds the money of the card payment.
        $this->bankAccount->update(['balance' => 5200]);

        $response = $this->withToken($this->token)
            ->putJson('/api/v1/transactions/' . $transaction->id . '/payments/' . $payment->id, [
                'amount' => 150,
                'payment_method' => 'tarjeta',
                'bank_account_id' => $this->bankAccount->id,
                'notes' => 'Voucher corregido',
            ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Pago actualizado correctamente.')
            ->assertJsonPath('payment.amount', 150)
            ->assertJsonPath('payment.notes', 'Voucher corregido')
            ->assertJsonPath('transaction.total_paid', 150);

        // 200 reverted and 150 applied.
        $this->assertEquals(5150, (float) $this->bankAccount->fresh()->balance);
        $this->assertEquals(150, (float) $payment->fresh()->amount);

        // Moving the payment to cash gives the money back to the account.
        $this->withToken($this->token)
            ->putJson('/api/v1/transactions/' . $transaction->id . '/payments/' . $payment->id, [
                'amount' => 150,
                'payment_method' => 'efectivo',
                'bank_account_id' => null,
            ])
            ->assertOk()
            ->assertJsonPath('payment.bank_account_id', null);

        $this->assertEquals(5000, (float) $this->bankAccount->fresh()->balance);
    }

    #[Test]
    public function it_requires_the_bank_account_when_editing_to_card_or_transfer(): void
    {
        $transaction = $this->sale(['status' => TransactionStatus::COMPLETED], paid: 0);
        $payment = Payment::factory()->create([
            'transaction_id' => $transaction->id,
            'amount' => 300,
            'payment_method' => PaymentMethod::CASH,
            'status' => PaymentStatus::COMPLETED,
        ]);

        $this->withToken($this->token)
            ->putJson('/api/v1/transactions/' . $transaction->id . '/payments/' . $payment->id, [
                'amount' => 300,
                'payment_method' => 'transferencia',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'bank_account_id' => 'Selecciona la cuenta destino para los pagos con tarjeta o transferencia.',
            ]);
    }

    #[Test]
    public function it_deletes_a_payment_and_reverts_its_effects(): void
    {
        $transaction = $this->sale(['status' => TransactionStatus::COMPLETED], paid: 0);

        $payment = Payment::factory()->create([
            'transaction_id' => $transaction->id,
            'cash_register_session_id' => $this->cashRegisterSession->id,
            'amount' => 300,
            'payment_method' => PaymentMethod::CARD,
            'status' => PaymentStatus::COMPLETED,
            'bank_account_id' => $this->bankAccount->id,
        ]);
        $this->bankAccount->update(['balance' => 5300]);

        $this->withToken($this->token)
            ->deleteJson('/api/v1/transactions/' . $transaction->id . '/payments/' . $payment->id)
            ->assertNoContent();

        $this->assertDatabaseMissing('payments', ['id' => $payment->id]);
        $this->assertEquals(5000, (float) $this->bankAccount->fresh()->balance);
        // The sale is no longer settled.
        $this->assertEquals(TransactionStatus::PENDING, $transaction->fresh()->status);
        $this->assertEquals(0, (float) $transaction->fresh()->total_paid);
    }

    #[Test]
    public function it_hides_payments_of_another_sale(): void
    {
        $transaction = $this->sale(['status' => TransactionStatus::COMPLETED], paid: 0);
        $otherSale = $this->sale(['status' => TransactionStatus::COMPLETED], paid: 0);
        $otherPayment = Payment::factory()->create([
            'transaction_id' => $otherSale->id,
            'amount' => 100,
            'payment_method' => PaymentMethod::CASH,
            'status' => PaymentStatus::COMPLETED,
        ]);

        $this->withToken($this->token)
            ->deleteJson('/api/v1/transactions/' . $transaction->id . '/payments/' . $otherPayment->id)
            ->assertStatus(404)
            ->assertJsonPath('message', 'Recurso no encontrado.');

        $this->assertDatabaseHas('payments', ['id' => $otherPayment->id]);
    }

    #[Test]
    public function it_requires_the_edit_payment_permission(): void
    {
        $transaction = $this->sale(['status' => TransactionStatus::COMPLETED], paid: 0);
        $payment = Payment::factory()->create([
            'transaction_id' => $transaction->id,
            'amount' => 300,
            'payment_method' => PaymentMethod::CASH,
            'status' => PaymentStatus::COMPLETED,
        ]);

        $employee = $this->employeeUser(['transactions.add_payment']);
        $token = $this->tokenFor($employee);

        $this->withToken($token)
            ->putJson('/api/v1/transactions/' . $transaction->id . '/payments/' . $payment->id, [
                'amount' => 250,
                'payment_method' => 'efectivo',
            ])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Tu usuario no tiene permiso para esta acción.');

        $this->withToken($token)
            ->deleteJson('/api/v1/transactions/' . $transaction->id . '/payments/' . $payment->id)
            ->assertStatus(403);
    }

    #[Test]
    public function it_requires_a_token(): void
    {
        $transaction = $this->sale();

        $this->postJson('/api/v1/transactions/' . $transaction->id . '/cancel', ['action' => 'penalty'])
            ->assertStatus(401)
            ->assertJsonPath('message', 'No autenticado.');

        $this->putJson('/api/v1/transactions/' . $transaction->id . '/payments/1', [
            'amount' => 100,
            'payment_method' => 'efectivo',
        ])->assertStatus(401);
    }
}
