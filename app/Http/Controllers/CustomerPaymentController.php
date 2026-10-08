<?php

namespace App\Http\Controllers;

use App\Enums\PaymentMethod;
use App\Models\Customer;
use App\Services\TransactionPaymentService;
use App\Services\WhatsAppTicketService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Exception;

class CustomerPaymentController extends Controller
{
    // Inyectar los servicios
    public function __construct(
        protected TransactionPaymentService $transactionPaymentService,
        protected WhatsAppTicketService $whatsAppTicketService,
    ) {}

    /**
     * Almacena uno o más pagos (abonos) de un cliente y los aplica a sus deudas pendientes.
     */
    public function store(Request $request, Customer $customer)
    {
        $validated = $request->validate([
            'payments' => 'required|array|min:1',
            'payments.*.amount' => 'required|numeric|min:0.01',
            'payments.*.method' => ['required', Rule::in(array_column(PaymentMethod::cases(), 'value'))],
            'payments.*.bank_account_id' => 'nullable|exists:bank_accounts,id',
            'notes' => 'nullable|string|max:255',
            'cash_register_session_id' => 'required|exists:cash_register_sessions,id,status,abierta',
        ]);

        $user = Auth::user();
        $sessionId = (int) $validated['cash_register_session_id'];

        try {
            // 2. DELEGAR LÓGICA AL SERVICIO (devuelve el resumen del abono aplicado)
            $summary = $this->transactionPaymentService->applyPaymentToCustomerBalance(
                $customer,
                $validated,
                $sessionId,
                $user
            );

            // 3. Construir el ticket de abono para WhatsApp
            $summary['payment_method'] = $this->whatsAppTicketService->formatPaymentBreakdown($validated['payments']);
            $payload = $this->whatsAppTicketService->buildGeneralAbonoPayload($customer, $summary);

            return redirect()->back()
                ->with('success', 'Abono registrado correctamente.')
                ->with('print_data', [
                    'type' => 'abono',
                    'payload' => $payload,
                    'customer_phone' => $customer->phone ?: null,
                    'customer_id' => $customer->id,
                ]);
        } catch (Exception $e) {
            return redirect()->back()->with('error', 'Error al procesar el abono: ' . $e->getMessage());
        }
    }
}
