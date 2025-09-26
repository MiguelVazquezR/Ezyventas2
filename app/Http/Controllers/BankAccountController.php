<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;

class BankAccountController extends Controller
{
    public function store(Request $request)
    {
        $subscription = Auth::user()->branch->subscription;

        $validated = $request->validate([
            'bank_name' => 'required|string|max:255',
            'account_name' => 'required|string|max:255',
            'account_number' => 'nullable|string|max:255',
            'branch_ids' => 'required|array|min:1',
            'branch_ids.*' => ['required', Rule::in($subscription->branches->pluck('id'))],
        ]);
        
        DB::transaction(function () use ($validated, $subscription) {
            $bankAccount = $subscription->bankAccounts()->create([
                'bank_name' => $validated['bank_name'],
                'account_name' => $validated['account_name'],
                'account_number' => $validated['account_number'],
            ]);
            $bankAccount->branches()->attach($validated['branch_ids']);
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
            'account_name' => 'required|string|max:255',
            'account_number' => 'nullable|string|max:255',
            'branch_ids' => 'required|array|min:1',
            'branch_ids.*' => ['required', Rule::in($bankAccount->subscription->branches->pluck('id'))],
        ]);
        
        DB::transaction(function () use ($validated, $bankAccount) {
            $bankAccount->update([
                'bank_name' => $validated['bank_name'],
                'account_name' => $validated['account_name'],
                'account_number' => $validated['account_number'],
            ]);
            $bankAccount->branches()->sync($validated['branch_ids']);
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