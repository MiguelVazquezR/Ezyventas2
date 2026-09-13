<?php

namespace App\Actions\Billing;

use App\Models\Billing\AcceptRejectResponse;
use App\Models\Billing\FiscalProfile;
use App\Services\Billing\SWSapienService;
use Illuminate\Support\Facades\Log;

class AcceptRejectInvoiceAction
{
    /**
     * SAT/PAC folio code that confirms the response was registered.
     * Any other code means the PAC processed the call, but the SAT did NOT
     * register the acceptance/rejection (no pending request, wrong receiver
     * RFC, already answered, etc.).
     */
    private const REGISTERED_FOLIO_CODE = '1000';

    public function __construct(
        private readonly SWSapienService $swService,
    ) {}

    /**
     * Accept or reject a CFDI cancelation request via SW Sapien and persist
     * the response (both successful and failed attempts) in the local history.
     *
     * SW answers HTTP 200 + status success even when the response was NOT
     * registered at the SAT (e.g. no pending cancelation request, receiver RFC
     * mismatch, already answered). Only folio code 1000 confirms the answer was
     * actually registered, so anything else is persisted and reported as error.
     *
     * @return array{status: string, message: string, data: array}
     *
     * @throws \RuntimeException When SW rejects the request or the SAT does not
     *                          register the response.
     */
    public function execute(FiscalProfile $profile, string $uuid, string $action, int $branchId): array
    {
        try {
            $responseData = $this->swService->acceptReject($profile, $uuid, $action);
        } catch (\RuntimeException $e) {
            AcceptRejectResponse::create([
                'branch_id'         => $branchId,
                'fiscal_profile_id' => $profile->id,
                'rfc'               => $profile->rfc,
                'uuid'              => $uuid,
                'action'            => $action,
                'status'            => 'error',
                'message'           => $e->getMessage(),
                'responded_at'      => now(),
            ]);

            Log::warning('CFDI cancelation accept/reject failed', [
                'fiscal_profile_id' => $profile->id,
                'rfc'               => $profile->rfc,
                'uuid'              => $uuid,
                'action'            => $action,
                'error'             => $e->getMessage(),
            ]);

            throw $e;
        }

        $folios = $responseData['folios'] ?? [];
        $folio = $folios[0] ?? [];
        $folioCode = isset($folio['estatusUUID']) ? (string) $folio['estatusUUID'] : null;

        // The SAT result for this UUID lives in the folio code — do not trust
        // the transport-level success alone, or the user would be told that a
        // response was registered when it was actually rejected by the SAT
        // (1001 no pending request, 1002 already answered, 1003 RFC mismatch).
        if ($folioCode !== self::REGISTERED_FOLIO_CODE) {
            $message = $folioCode
                ? $this->swService->translateAcceptRejectCode($folioCode)
                : 'No se pudo confirmar que el SAT haya registrado la respuesta (el PAC no devolvió el detalle de folios). Verifica el estatus de la solicitud antes de intentarlo de nuevo.';

            AcceptRejectResponse::create([
                'branch_id'         => $branchId,
                'fiscal_profile_id' => $profile->id,
                'rfc'               => $profile->rfc,
                'uuid'              => $uuid,
                'action'            => $action,
                'status'            => 'error',
                'acuse'             => $responseData['acuse'] ?? null,
                'estatus_uuid'      => $folioCode,
                'respuesta'         => $folio['respuesta'] ?? null,
                'message'           => $message,
                'message_detail'    => $folio ? json_encode($folio, JSON_UNESCAPED_UNICODE) : null,
                'responded_at'      => now(),
            ]);

            Log::warning('CFDI cancelation accept/reject not registered by SAT', [
                'fiscal_profile_id' => $profile->id,
                'rfc'               => $profile->rfc,
                'uuid'              => $uuid,
                'action'            => $action,
                'folio_code'        => $folioCode,
            ]);

            throw new \RuntimeException($message);
        }

        AcceptRejectResponse::create([
            'branch_id'          => $branchId,
            'fiscal_profile_id'  => $profile->id,
            'rfc'                => $profile->rfc,
            'uuid'               => $uuid,
            'action'             => $action,
            'status'             => 'success',
            'acuse'              => $responseData['acuse'] ?? null,
            'estatus_uuid'       => $folioCode,
            'respuesta'          => $folio['respuesta'] ?? null,
            'responded_at'       => now(),
        ]);

        Log::info('CFDI cancelation accept/reject sent', [
            'fiscal_profile_id' => $profile->id,
            'rfc'               => $profile->rfc,
            'uuid'              => $uuid,
            'action'            => $action,
        ]);

        return [
            'status'  => 'success',
            'message' => $action === 'Aceptacion'
                ? 'Cancelación aceptada correctamente ante el SAT.'
                : 'Cancelación rechazada correctamente. La factura sigue vigente.',
            'data'    => $responseData,
        ];
    }
}
