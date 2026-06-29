<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\StoreFiscalProfileRequest;
use App\Models\Billing\FiscalProfile;
use App\Models\Billing\Invoice;
use App\Services\Billing\SWSapienService;
use App\Services\SW\SWUserService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class FiscalProfileController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:invoices.settings.access'),
        ];
    }

    /**
     * Create a new fiscal profile for the subscription and provision
     * a SW Sapien sub-user account automatically.
     */
    public function storeFiscalProfile(
        StoreFiscalProfileRequest $request,
        SWUserService $swUserService,
    ): RedirectResponse {
        $user = Auth::user();
        $subscription = $user->branch?->subscription;

        if (! $subscription) {
            return redirect()->route('billing.settings.index')
                ->with('error', 'No se encontró una suscripción activa asociada a tu cuenta.');
        }

        $validated = $request->validated();

        DB::beginTransaction();

        try {
            $profile = $subscription->fiscalProfiles()->create([
                'rfc'             => $validated['rfc'],
                'razon_social'    => $validated['razon_social'],
                'regimen_fiscal'  => $validated['regimen_fiscal'],
                'postal_code'     => $validated['postal_code'],
                'email'           => $validated['email'],
                'password'        => Str::random(16),
                'is_active'       => true,
            ]);

            // Auto-provision the PAC sub-user account
            $swUserService->createSubaccountForProfile(
                $profile,
                $profile->email,
                $profile->password,
            );

            DB::commit();

            Log::info('Fiscal profile created and PAC subaccount provisioned', [
                'fiscal_profile_id' => $profile->id,
                'rfc'               => $profile->rfc,
                'email'             => $profile->email,
                'subscription_id'   => $subscription->id,
            ]);

            return redirect()->route('billing.settings.index')
                ->with('success', 'Perfil fiscal creado y vinculado al PAC exitosamente.');
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('PAC subaccount provisioning failed — local profile rolled back', [
                'rfc'               => $validated['rfc'],
                'email'             => $validated['email'],
                'error'             => $e->getMessage(),
                'exception_class'   => get_class($e),
                'trace'             => $e->getTraceAsString(),
            ]);

            return redirect()->route('billing.settings.index')
                ->with('error', 'El PAC rechazó la creación de la subcuenta: ' . $e->getMessage());
        }
    }

    /**
     * Upload CSD certificates (.cer + .key) to SW Sapien for a fiscal profile.
     */
    public function uploadCsd(Request $request, SWSapienService $swService): RedirectResponse
    {
        $validated = $request->validate([
            'fiscal_profile_id' => ['required', 'integer', 'exists:fiscal_profiles,id'],
            'cer' => [
                'required',
                'file',
                function ($attribute, $value, $fail) {
                    if (! in_array(strtolower($value->getClientOriginalExtension()), ['cer'])) {
                        $fail('El archivo cargado debe tener una extensión .cer válida.');
                    }
                },
            ],
            'key' => [
                'required',
                'file',
                function ($attribute, $value, $fail) {
                    if (! in_array(strtolower($value->getClientOriginalExtension()), ['key'])) {
                        $fail('El archivo cargado debe tener una extensión .key válida.');
                    }
                },
            ],
            'password' => ['required', 'string', 'min:1'],
        ], [
            'cer.required'          => 'El archivo .cer es obligatorio.',
            'key.required'          => 'El archivo .key es obligatorio.',
            'password.required'     => 'La contraseña del CSD es obligatoria.',
        ]);

        $profile = FiscalProfile::findOrFail($validated['fiscal_profile_id']);

        if (! $profile->sw_user_id) {
            return back()
                ->with('error', 'Este perfil fiscal aún no tiene una subcuenta en el PAC. Espera a que se complete el aprovisionamiento.');
        }

        try {
            $cerFile = $request->file('cer');
            $keyFile = $request->file('key');

            $cerPath = $cerFile->getPathname();
            $keyPath = $keyFile->getPathname();

            $csdResult = $swService->uploadCsd($profile, $cerPath, $keyPath, $validated['password']);

            // Persist CSD files to local storage
            $cerStoredPath = $cerFile->storeAs(
                'csds/' . $profile->id,
                'certificado.cer',
            );
            $keyStoredPath = $keyFile->storeAs(
                'csds/' . $profile->id,
                'llave.key',
            );

            // Update the fiscal profile with CSD data and file paths
            $profile->update([
                'certificate_number' => $csdResult['certificate_number'] ?? null,
                'valid_from'         => $csdResult['valid_from'] ?? null,
                'valid_to'           => $csdResult['valid_to'] ?? null,
                'cer_file_path'      => $cerStoredPath,
                'key_file_path'      => $keyStoredPath,
            ]);

            Log::info('CSD uploaded and persisted locally', [
                'fiscal_profile_id'  => $profile->id,
                'certificate_number' => $csdResult['certificate_number'] ?? null,
                'cer_file_path'      => $cerStoredPath,
                'key_file_path'      => $keyStoredPath,
            ]);

            return back()
                ->with('success', $csdResult['message'] ?? 'Certificados CSD cargados exitosamente en el PAC.');
        } catch (ConnectionException $e) {
            Log::warning('PAC unreachable during CSD upload', [
                'fiscal_profile_id' => $profile->id,
                'rfc'               => $profile->rfc,
            ]);

            return back()
                ->with('warning', 'El PAC no está disponible en este momento. Intenta cargar los certificados más tarde.');
        } catch (\RuntimeException $e) {
            Log::error('PAC rejected CSD upload', [
                'fiscal_profile_id' => $profile->id,
                'error'             => $e->getMessage(),
            ]);

            return back()
                ->with('error', 'El PAC rechazó los certificados: ' . $e->getMessage());
        }
    }

    /**
     * Delete or deactivate a fiscal profile.
     *
     * If the profile has issued invoices it is soft-deactivated;
     * otherwise the record is physically deleted.
     */
    public function destroy(FiscalProfile $fiscalProfile): RedirectResponse
    {
        $hasInvoices = Invoice::where('fiscal_profile_id', $fiscalProfile->id)->exists();

        // ── Deactivate subaccount in SW Sapien PAC ──
        if ($fiscalProfile->sw_user_id) {
            $swUserService = app(SWUserService::class);
            $swUserService->deactivateSubaccount($fiscalProfile);
        }

        if (! $hasInvoices) {
            $fiscalProfile->delete();

            Log::info('Fiscal profile physically deleted', [
                'fiscal_profile_id' => $fiscalProfile->id,
                'rfc'               => $fiscalProfile->rfc,
            ]);
        } else {
            $fiscalProfile->update(['is_active' => false]);

            Log::info('Fiscal profile deactivated (has associated invoices)', [
                'fiscal_profile_id' => $fiscalProfile->id,
                'rfc'               => $fiscalProfile->rfc,
            ]);
        }

        return redirect()->route('billing.settings.index')
            ->with('success', 'Perfil fiscal dado de baja correctamente.');
    }
}
