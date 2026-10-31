<?php

namespace App\Http\Controllers\Api\V1\CashRegisters;

use App\Enums\CashRegisterSessionStatus;
use App\Enums\TemplateContextType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CashRegisters\CashRegisterSessionReceiptRequest;
use App\Http\Requests\Api\V1\CashRegisters\CloseCashRegisterSessionRequest;
use App\Http\Requests\Api\V1\CashRegisters\CurrentSessionRequest;
use App\Http\Requests\Api\V1\CashRegisters\JoinCashRegisterSessionRequest;
use App\Http\Requests\Api\V1\CashRegisters\OpenCashRegisterSessionRequest;
use App\Http\Requests\Api\V1\CashRegisters\RejoinOrStartCashRegisterSessionRequest;
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use App\Models\PrintTemplate;
use App\Models\User;
use App\Services\CashRegisters\CashRegisterSessionLifecycleService;
use App\Services\CashRegisters\CashRegisterSessionQueryService;
use App\Services\PrintEncoderService;
use App\Services\Printing\CashRegisterCutTemplate;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Cash register state of the authenticated user: current shift, opening a new
 * shift and joining the shift opened by a teammate.
 */
class CashRegisterSessionController extends Controller
{
    public function __construct(
        private readonly CashRegisterSessionQueryService $sessions,
        private readonly CashRegisterSessionLifecycleService $lifecycle,
    ) {}

    public function current(CurrentSessionRequest $request): JsonResponse
    {
        return response()->json($this->sessions->currentPayload($request->user()));
    }

    public function store(OpenCashRegisterSessionRequest $request): JsonResponse
    {
        $user = $request->user();

        $cashRegister = CashRegister::where('branch_id', $user->branch_id)
            ->find($request->validated('cash_register_id'));

        if (!$cashRegister) {
            throw new NotFoundHttpException('Recurso no encontrado.');
        }

        if ($user->getActiveCashRegisterSession()) {
            return response()->json([
                'code' => 'session_already_open',
                'message' => 'Ya tienes una sesión de caja activa.',
            ], 422);
        }

        if ($activeSession = $this->lifecycle->openSessionOn($cashRegister)) {
            return response()->json([
                'code' => 'cash_register_in_use',
                'message' => 'Parece que otro usuario abrió caja antes que tú. Puedes unirte a la sesión.',
                'session_id' => $activeSession->id,
                'cash_register' => ['id' => $cashRegister->id, 'name' => $cashRegister->name],
                'opened_by' => $activeSession->opener ? [
                    'id' => $activeSession->opener->id,
                    'name' => $activeSession->opener->name,
                ] : null,
            ], 409);
        }

        if (!$cashRegister->is_active) {
            return response()->json([
                'code' => 'no_cash_register_available',
                'message' => 'No hay terminales disponibles en esta sucursal.',
            ], 422);
        }

        $session = $this->lifecycle->open(
            $cashRegister,
            $user,
            $request->openingCashBalance(),
            $request->declaredBankAccounts()
        );

        return response()->json([
            'active_session' => $this->sessions->sessionPayload($session),
            'message' => 'La caja ha sido abierta con éxito.',
        ], 201);
    }

    public function join(JoinCashRegisterSessionRequest $request, int $cashRegisterSessionId): JsonResponse
    {
        $session = $this->findSessionOrFail($cashRegisterSessionId, (int) $request->user()->branch_id);

        if ($session->status !== CashRegisterSessionStatus::OPEN) {
            return response()->json([
                'code' => 'session_not_open',
                'message' => 'Esa sesión de caja ya fue cerrada.',
            ], 409);
        }

        $this->lifecycle->join($session, $request->user());

        return response()->json([
            'active_session' => $this->sessions->sessionPayload($session->refresh()),
            'message' => 'Te has unido a la sesión de caja.',
        ]);
    }

    public function leave(JoinCashRegisterSessionRequest $request, int $cashRegisterSessionId): JsonResponse
    {
        $session = $this->findSessionOrFail($cashRegisterSessionId, (int) $request->user()->branch_id);

        $this->lifecycle->leave($session, $request->user());

        return response()->json(['message' => 'Has salido de la sesión de caja.']);
    }

    public function rejoinOrStart(RejoinOrStartCashRegisterSessionRequest $request): JsonResponse
    {
        $user = $request->user();

        if ($user->getActiveCashRegisterSession()) {
            return response()->json([
                'code' => 'session_already_open',
                'message' => 'Ya tienes una sesión activa.',
            ], 422);
        }

        $cashRegister = CashRegister::where('branch_id', $user->branch_id)
            ->find($request->validated('cash_register_id'));

        if (!$cashRegister) {
            throw new NotFoundHttpException('Recurso no encontrado.');
        }

        $opener = User::find($request->validated('original_opener_id'));

        if (!$opener) {
            throw new NotFoundHttpException('Recurso no encontrado.');
        }

        $session = $this->lifecycle->rejoinOrStart($cashRegister, $user, $opener);

        return response()->json([
            'active_session' => $this->sessions->sessionPayload($session),
            'message' => 'Te has unido a la nueva sesión.',
        ]);
    }

    public function summary(CurrentSessionRequest $request, int $cashRegisterSessionId): JsonResponse
    {
        $session = $this->findSessionOrFail($cashRegisterSessionId, (int) $request->user()->branch_id);

        return response()->json($this->sessions->summaryPayload($session, $request->user()));
    }

    /**
     * The cut ready to reprint (closed shifts included): the figures of the
     * shift, the template that was used and the print operations.
     *
     * Needed from the phone because the cut of a shift closed days ago cannot be
     * rebuilt by the client (B5).
     */
    public function receipt(CashRegisterSessionReceiptRequest $request, int $cashRegisterSessionId): JsonResponse
    {
        $session = $this->findSessionOrFail($cashRegisterSessionId, (int) $request->user()->branch_id);
        $template = $this->cutTemplate($request);

        $encoded = PrintEncoderService::encodeWithReport($template, $session);

        return response()->json([
            'session' => [
                'id' => $session->id,
                'status' => $session->status instanceof \BackedEnum ? $session->status->value : $session->status,
                'opened_at' => $session->opened_at?->toIso8601String(),
                'closed_at' => $session->closed_at?->toIso8601String(),
                'cash_register' => $session->cashRegister?->name,
            ],
            'summary' => $this->sessions->summaryPayload($session, $request->user()),
            'template' => [
                'id' => $template->exists ? $template->id : null,
                'name' => $template->name,
                'builtin' => !$template->exists,
            ],
            'operations' => $encoded['operations'],
            'unsupported_operations' => $encoded['unsupported_operations'],
            'warnings' => $encoded['warnings'],
            'paperWidth' => $template->content['config']['paperWidth'] ?? '80mm',
            'feedLines' => $template->content['config']['feedLines'] ?? 0,
        ]);
    }

    /**
     * Template of the cut: the one the client asks for, else the cut template of
     * the business, else the built-in one.
     */
    private function cutTemplate(CashRegisterSessionReceiptRequest $request): PrintTemplate
    {
        $subscriptionId = $request->user()->branch?->subscription_id;

        if ($templateId = (int) $request->validated('template_id')) {
            $template = PrintTemplate::where('subscription_id', $subscriptionId)->find($templateId);

            if (!$template) {
                throw new NotFoundHttpException('Recurso no encontrado.');
            }

            return $template;
        }

        return PrintTemplate::where('subscription_id', $subscriptionId)
            ->where('context_type', TemplateContextType::CASH_REGISTER->value)
            ->orderByDesc('is_default')
            ->latest('id')
            ->first()
            ?? CashRegisterCutTemplate::make();
    }

    public function close(CloseCashRegisterSessionRequest $request, int $cashRegisterSessionId): JsonResponse
    {
        $user = $request->user();
        $session = $this->findSessionOrFail($cashRegisterSessionId, (int) $user->branch_id);

        if (!$this->isParticipant($session, (int) $user->id)) {
            return response()->json([
                'code' => 'not_session_participant',
                'message' => 'No participas en esta sesión de caja.',
            ], 403);
        }

        if ($session->status !== CashRegisterSessionStatus::OPEN) {
            return response()->json([
                'code' => 'session_not_open',
                'message' => 'Esa sesión de caja ya fue cerrada.',
            ], 422);
        }

        $this->lifecycle->close(
            $session,
            (float) $request->validated('closing_cash_balance'),
            $request->validated('notes'),
            $user
        );

        $session->refresh();

        return response()->json([
            'session' => [
                'id' => $session->id,
                'status' => 'cerrada',
                'closed_at' => $session->closed_at?->toIso8601String(),
                'calculated_cash_total' => (float) $session->calculated_cash_total,
                'closing_cash_balance' => (float) $session->closing_cash_balance,
                'cash_difference' => (float) $session->cash_difference,
            ],
            'summary' => $this->sessions->summaryPayload($session, $user),
            'message' => 'Corte de caja realizado con éxito.',
        ]);
    }

    private function findSessionOrFail(int $cashRegisterSessionId, int $branchId): CashRegisterSession
    {
        $session = CashRegisterSession::with(['cashRegister:id,name,branch_id', 'opener:id,name', 'users:id,name'])
            ->find($cashRegisterSessionId);

        if (!$session || (int) $session->cashRegister?->branch_id !== $branchId) {
            throw new NotFoundHttpException('Recurso no encontrado.');
        }

        return $session;
    }

    private function isParticipant(CashRegisterSession $session, int $userId): bool
    {
        return $session->users()->where('users.id', $userId)->exists();
    }
}
