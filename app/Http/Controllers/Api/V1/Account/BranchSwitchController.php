<?php

namespace App\Http\Controllers\Api\V1\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Account\SwitchBranchRequest;
use App\Models\Branch;
use App\Services\Auth\UserAccessContextService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Branch selector of the mobile topbar.
 *
 * Switching the branch applies to every device of the user (it is stored on the
 * user), so the app must refresh its local cache afterwards.
 */
class BranchSwitchController extends Controller
{
    public function __construct(private readonly UserAccessContextService $accessContext) {}

    public function update(SwitchBranchRequest $request, int $branchId): JsonResponse
    {
        $user = $request->user();
        $branch = Branch::find($branchId);

        if (!$branch) {
            throw new NotFoundHttpException('Recurso no encontrado.');
        }

        // Nobody crosses subscriptions, except the platform support user (id 1).
        if ($user->id !== 1 && (int) $user->branch?->subscription_id !== (int) $branch->subscription_id) {
            return response()->json([
                'code' => 'branch_out_of_scope',
                'message' => 'No tienes permiso para cambiar a esta sucursal.',
            ], 403);
        }

        $user->update(['branch_id' => $branch->id]);

        return response()->json([
            'branch' => ['id' => $branch->id, 'name' => $branch->name],
            'message' => "Cambiado a la sucursal: {$branch->name}",
            'context' => $this->accessContext->build($user->fresh(['branch.subscription'])),
        ]);
    }
}
