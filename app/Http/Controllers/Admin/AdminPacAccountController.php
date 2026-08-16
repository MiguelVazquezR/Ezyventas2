<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Billing\ActivatePacAccountAction;
use App\Enums\PacAccountStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ActivatePacAccountRequest;
use App\Models\Billing\PacAccount;
use App\Services\SW\SWUserService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/**
 * AdminPacAccountController
 *
 * Superadmin panel to manage PAC accounts — mainly to coordinate the
 * manual provisioning of external "normal" accounts with the reseller
 * (Conectia) and to activate them once the credentials arrive.
 */
class AdminPacAccountController extends Controller
{
    /**
     * List PAC accounts with their subscription and fiscal profiles.
     */
    public function index(Request $request): Response
    {
        $statusFilter = $request->input('status');

        $query = PacAccount::with([
            'subscription',
            'fiscalProfiles',
            'requestedBy',
            'activatedBy',
        ])->latest('id');

        if ($statusFilter && in_array($statusFilter, ['pending_request', 'pending_activation', 'active', 'inactive'], true)) {
            $query->where('status', $statusFilter);
        }

        $accounts = $query->paginate($request->integer('rows', 25) ?: 25)
            ->withQueryString();

        return Inertia::render('Admin/PacAccounts/Index', [
            'accounts' => $accounts,
            'filters'  => ['status' => $statusFilter],
            'statuses' => [
                ['value' => 'pending_request',    'label' => 'Pendiente de solicitud'],
                ['value' => 'pending_activation', 'label' => 'Pendiente de activación'],
                ['value' => 'active',             'label' => 'Activa'],
                ['value' => 'inactive',           'label' => 'Inactiva'],
            ],
        ]);
    }

    /**
     * Activate a "normal" PAC account with the reseller's credentials.
     *
     * The credentials are validated against the PAC before persisting.
     */
    public function activate(
        ActivatePacAccountRequest $request,
        PacAccount $pacAccount,
        ActivatePacAccountAction $action,
    ): RedirectResponse {
        if (! $pacAccount->isShared()) {
            return back()->with('error', 'Esta cuenta no es de tipo compartida y no se activa de esta forma.');
        }

        $result = $action->execute(
            $pacAccount,
            $request->validated('login_email'),
            $request->validated('password'),
            Auth::id(),
        );

        if (! $result['success']) {
            return back()->with('error', $result['message']);
        }

        $pacAccount->update([
            'is_shared' => $request->boolean('is_shared', $pacAccount->is_shared),
        ]);

        Log::info('PAC account activated by superadmin', [
            'pac_account_id' => $pacAccount->id,
            'sw_user_id'     => $result['sw_user_id'] ?? null,
            'by_user_id'     => Auth::id(),
        ]);

        return back()->with('success', $result['message']);
    }

    /**
     * Update the credentials of an account without changing its status.
     *
     * Needed when the reseller changes the account password externally.
     */
    public function updateCredentials(
        ActivatePacAccountRequest $request,
        PacAccount $pacAccount,
        SWUserService $swUserService,
    ): RedirectResponse {
        if ($pacAccount->isSubaccount()) {
            return back()->with('error', 'Las credenciales de las subcuentas se administran en el PAC.');
        }

        try {
            $swUserService->activateSharedAccount(
                $pacAccount,
                $request->validated('login_email'),
                $request->validated('password'),
                Auth::id(),
            );

            $pacAccount->update([
                'is_shared' => $request->boolean('is_shared', $pacAccount->is_shared),
            ]);
        } catch (\RuntimeException $e) {
            return back()->with('error', 'No se pudieron actualizar las credenciales: ' . $e->getMessage());
        }

        return back()->with('success', 'Credenciales actualizadas y validadas contra el Proveedor de timbrado.');
    }

    /**
     * Save the admin coordination notes (bitácora with the reseller).
     */
    public function updateNotes(Request $request, PacAccount $pacAccount): RedirectResponse
    {
        $validated = $request->validate([
            'admin_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $pacAccount->update([
            'admin_notes' => $validated['admin_notes'] ?? null,
        ]);

        return back()->with('success', 'Notas guardadas correctamente.');
    }

    /**
     * Mark an account as inactive (no PAC call — for normal accounts the
     * deactivation is coordinated with the reseller).
     */
    public function deactivate(PacAccount $pacAccount): RedirectResponse
    {
        if ($pacAccount->isSubaccount()) {
            return back()->with('error', 'Las subcuentas se desactivan desde el perfil fiscal.');
        }

        $pacAccount->update([
            'status'     => PacAccountStatus::INACTIVE,
            'admin_notes' => ($pacAccount->admin_notes ?? '') . PHP_EOL
                . '[' . now()->toDateTimeString() . '] Desactivada por admin.',
        ]);

        return back()->with('success', 'Cuenta marcada como inactiva.');
    }
}
