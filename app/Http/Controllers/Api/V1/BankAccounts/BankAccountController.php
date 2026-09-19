<?php

namespace App\Http\Controllers\Api\V1\BankAccounts;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\BankAccounts\IndexBankAccountRequest;
use App\Services\BankAccounts\BankAccountQueryService;
use Illuminate\Http\JsonResponse;

/**
 * Bank accounts the user can declare when opening the cash register.
 */
class BankAccountController extends Controller
{
    public function __construct(private readonly BankAccountQueryService $bankAccounts) {}

    public function index(IndexBankAccountRequest $request): JsonResponse
    {
        $accounts = $this->bankAccounts->forUser($request->user());

        return response()->json($this->bankAccounts->payloadCollection($accounts));
    }
}
