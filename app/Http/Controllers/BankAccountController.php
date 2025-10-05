<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;

class BankAccountController extends Controller
{
    /**
     * Obtiene las cuentas bancarias asignadas a la sucursal del usuario actual.
     */
    public function getForBranch()
    {
        $branchId = Auth::user()->branch_id;
        $bankAccounts = BankAccount::whereHas('branches', function ($query) use ($branchId) {
            $query->where('branch_id', $branchId);
        })->get();

        return response()->json($bankAccounts);
    }
    
     public function store(Request $request)
    {
        $subscription = Auth::user()->branch->subscription;
        $branchIds = $subscription->branches->pluck('id')->toArray();

        $validated = $request->validate([
            'bank_name' => 'required|string|max:255',
            'owner_name' => 'required|string|max:255',
            'account_name' => 'required|string|max:255',
            'account_number' => 'nullable|string|max:255',
            'card_number' => 'nullable|string|max:255',
            'clabe' => 'nullable|string|max:255',
            'branches' => 'required|array|min:1',
            'branches.*.id' => ['required', Rule::in($branchIds)],
            'branches.*.is_favorite' => 'required|boolean',
        ], [
            'branches.required' => 'Debes asignar la cuenta al menos a una sucursal.',
        ]);
        
        DB::transaction(function () use ($validated, $subscription) {
            $bankAccount = $subscription->bankAccounts()->create(
                collect($validated)->except(['branches'])->all()
            );
            
            $branchesToSync = collect($validated['branches'])->mapWithKeys(function ($branch) {
                return [$branch['id'] => ['is_favorite' => $branch['is_favorite']]];
            });

            $bankAccount->branches()->attach($branchesToSync);
        });

        return redirect()->back()->with('success', 'Cuenta bancaria creada con éxito.');
    }

    public function update(Request $request, BankAccount $bankAccount)
    {
        if ($bankAccount->subscription_id !== Auth::user()->branch->subscription_id) {
            abort(403);
        }
        
        $branchIds = $bankAccount->subscription->branches->pluck('id')->toArray();

        $validated = $request->validate([
            'bank_name' => 'required|string|max:255',
            'owner_name' => 'required|string|max:255',
            'account_name' => 'required|string|max:255',
            'account_number' => 'nullable|string|max:255',
            'card_number' => 'nullable|string|max:255',
            'clabe' => 'nullable|string|max:255',
            'branches' => 'required|array|min:1',
            'branches.*.id' => ['required', Rule::in($branchIds)],
            'branches.*.is_favorite' => 'required|boolean',
        ], [
            'branches.required' => 'Debes asignar la cuenta al menos a una sucursal.',
        ]);
        
        DB::transaction(function () use ($validated, $bankAccount) {
            $bankAccount->update(
                collect($validated)->except(['branches'])->all()
            );

            $branchesToSync = collect($validated['branches'])->mapWithKeys(function ($branch) {
                return [$branch['id'] => ['is_favorite' => $branch['is_favorite']]];
            });

            $bankAccount->branches()->sync($branchesToSync);
        });

        return redirect()->back()->with('success', 'Cuenta bancaria actualizada con éxito.');
    }

    public function destroy(BankAccount $bankAccount)
    {
        if ($bankAccount->subscription_id !== Auth::user()->branch->subscription_id) {
            abort(403);
        }
        
        $bankAccount->delete();

        return redirect()->back()->with('success', 'Cuenta bancaria eliminada con éxito.');
    }
}