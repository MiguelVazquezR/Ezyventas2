<?php

namespace App\Actions\Billing;

use App\Models\Billing\FiscalProfile;
use App\Models\Billing\PacAccount;
use App\Services\SW\SWUserService;
use Illuminate\Http\Client\ConnectionException;

/**
 * CreateFiscalSubaccountAction
 *
 * Thin orchestrator that provisions a dealer subaccount in SW Sapien
 * for a PacAccount record and links a FiscalProfile to it.
 *
 * Failures (network errors, duplicate RFC, PAC validation) are
 * caught and returned as a structured result — the caller (controller
 * or route closure) maps it to an HTTP JSON response.
 */
class CreateFiscalSubaccountAction
{
    public function __construct(
        private readonly SWUserService $swUserService,
    ) {}

    /**
     * Execute the subaccount creation.
     *
     * @return array{success: bool, message: string, sw_user_id?: string}
     */
    public function execute(
        PacAccount $account,
        FiscalProfile $profile,
        string $email,
        string $password,
    ): array {
        if ($account->isActive() && $account->sw_user_id) {
            return [
                'success' => false,
                'message' => 'Esta cuenta ya está vinculada con el Proveedor de timbrado. Contacta con soporte.',
            ];
        }

        try {
            $this->swUserService->createSubaccountForAccount($account, $profile, $email, $password);

            return [
                'success'    => true,
                'message'    => 'Se completó exitosamente la vinculación fiscal.',
                'sw_user_id' => $account->sw_user_id,
            ];
        } catch (ConnectionException $e) {
            return [
                'success' => false,
                'message' => 'No se pudo conectar. Verifica tu conexión a internet e inténtalo de nuevo.',
            ];
        } catch (\RuntimeException $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }
}
