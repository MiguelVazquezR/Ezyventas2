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

        $validated = $request->validate([
            'bank_name' => 'required|string|max:255',
            'owner_name' => 'required|string|max:255',
            'account_name' => 'required|string|max:255',
            'account_number' => 'nullable|string|max:255',
            'card_number' => 'nullable|string|max:255',
            'clabe' => 'nullable|string|max:255',
            'branch_ids' => 'required|array|min:1',
            'branch_ids.*.id' => ['required', Rule::in($subscription->branches->pluck('id'))],
            'branch_ids.*.is_favorite' => 'required|boolean',
        ]);
        
        DB::transaction(function () use ($validated, $subscription) {
            $bankAccount = $subscription->bankAccounts()->create([
                'bank_name' => $validated['bank_name'],
                'owner_name' => $validated['owner_name'],
                'account_name' => $validated['account_name'],
                'account_number' => $validated['account_number'],
                'card_number' => $validated['card_number'],
                'clabe' => $validated['clabe'],
            ]);
            
            $branchesToSync = collect($validated['branch_ids'])->mapWithKeys(function ($branch) {
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

        $validated = $request->validate([
            'bank_name' => 'required|string|max:255',
            'owner_name' => 'required|string|max:255',
            'account_name' => 'required|string|max:255',
            'account_number' => 'nullable|string|max:255',
            'card_number' => 'nullable|string|max:255',
            'clabe' => 'nullable|string|max:255',
            'branch_ids' => 'required|array|min:1',
            'branch_ids.*.id' => ['required', Rule::in($bankAccount->subscription->branches->pluck('id'))],
            'branch_ids.*.is_favorite' => 'required|boolean',
        ]);
        
        DB::transaction(function () use ($validated, $bankAccount) {
            $bankAccount->update([
                'bank_name' => $validated['bank_name'],
                'owner_name' => $validated['owner_name'],
                'account_name' => $validated['account_name'],
                'account_number' => $validated['account_number'],
                'card_number' => $validated['card_number'],
                'clabe' => $validated['clabe'],
            ]);

            $branchesToSync = collect($validated['branch_ids'])->mapWithKeys(function ($branch) {
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