<?php

namespace App\Actions\Invoices;

use App\Models\Invoices\FiscalProfile;
use App\Services\SW\SWUserService;
use Illuminate\Http\Client\ConnectionException;

/**
 * CreateFiscalSubaccountAction
 *
 * Thin orchestrator that links a FiscalProfile to a SW Sapien
 * sub-user account so the RFC can start issuing CFDI invoices.
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
    public function execute(FiscalProfile $profile, string $email, string $password): array
    {
        if ($profile->hasSwSubaccount()) {
            return [
                'success' => false,
                'message' => 'Este perfil fiscal ya tiene una subcuenta vinculada en el PAC.',
            ];
        }

        try {
            $this->swUserService->createSubaccountForProfile($profile, $email, $password);

            return [
                'success'    => true,
                'message'    => 'Subcuenta creada exitosamente en el PAC.',
                'sw_user_id' => $profile->sw_user_id,
            ];
        } catch (ConnectionException $e) {
            return [
                'success' => false,
                'message' => 'No se pudo conectar con el PAC. Verifica tu conexión a internet e inténtalo de nuevo.',
            ];
        } catch (\RuntimeException $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }
}
