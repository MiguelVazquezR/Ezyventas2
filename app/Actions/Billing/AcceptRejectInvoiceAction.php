<?php

namespace App\Actions\Billing;

use App\Models\Billing\AcceptRejectResponse;
use App\Models\Billing\FiscalProfile;
use App\Services\Billing\SWSapienService;
use Illuminate\Support\Facades\Log;

class AcceptRejectInvoiceAction
{
    public function __construct(
        private readonly SWSapienService $swService,
    ) {}

    /**
     * Accept or reject a CFDI cancelation request via SW Sapien and persist
     * the response (both successful and failed attempts) in the local history.
     *
     * @return array{status: string, message: string, data: array}
     *
     * @throws \RuntimeException When SW rejects the request.
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

        AcceptRejectResponse::create([
            'branch_id'          => $branchId,
            'fiscal_profile_id'  => $profile->id,
            'rfc'                => $profile->rfc,
            'uuid'               => $uuid,
            'action'             => $action,
            'status'             => 'success',
            'acuse'              => $responseData['acuse'] ?? null,
            'estatus_uuid'       => $folios[0]['estatusUUID'] ?? null,
            'respuesta'          => $folios[0]['respuesta'] ?? null,
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
