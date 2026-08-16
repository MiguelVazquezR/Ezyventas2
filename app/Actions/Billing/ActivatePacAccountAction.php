<?php

namespace App\Actions\Billing;

use App\Models\Billing\PacAccount;
use App\Services\SW\SWUserService;
use Illuminate\Http\Client\ConnectionException;

/**
 * ActivatePacAccountAction
 *
 * Thin orchestrator that validates a "normal" PAC account against the
 * PAC using the credentials provided by the reseller. If the PAC accepts
 * them, the account becomes active; otherwise nothing is persisted.
 *
 * Failures are caught and returned as a structured result so the admin
 * panel can show a clear message.
 */
class ActivatePacAccountAction
{
    public function __construct(
        private readonly SWUserService $swUserService,
    ) {}

    /**
     * Execute the activation.
     *
     * @return array{success: bool, message: string, sw_user_id?: string}
     */
    public function execute(
        PacAccount $account,
        string $email,
        string $password,
        ?int $activatedByUserId = null,
    ): array {
        try {
            $this->swUserService->activateSharedAccount($account, $email, $password, $activatedByUserId);

            return [
                'success'    => true,
                'message'    => 'Cuenta activada correctamente. Los perfiles vinculados ya pueden subir sus certificados CSD.',
                'sw_user_id' => $account->sw_user_id,
            ];
        } catch (ConnectionException $e) {
            return [
                'success' => false,
                'message' => 'No se pudo conectar con el Proveedor de timbrado. Verifica tu conexión e inténtalo de nuevo.',
            ];
        } catch (\RuntimeException $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }
}
