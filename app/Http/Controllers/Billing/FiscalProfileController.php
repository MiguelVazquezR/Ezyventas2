<?php

namespace App\Http\Controllers\Billing;

use App\Actions\Billing\FetchManifestLegendAction;
use App\Actions\Billing\SignManifestAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\AcceptManifestTextRequest;
use App\Http\Requests\Billing\FetchManifestLegendRequest;
use App\Http\Requests\Billing\SignManifestRequest;
use App\Http\Requests\Billing\StoreFiscalProfileRequest;
use App\Enums\StampPaymentMethod;
use App\Enums\StampPurchaseStatus;
use App\Models\Billing\FiscalProfile;
use App\Models\Billing\Invoice;
use App\Models\Billing\StampPurchase;
use App\Models\BankAccount;
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
use Inertia\Inertia;
use Inertia\Response;

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

        if (! $subscription->facturacion_habilitada) {
            return redirect()->route('billing.settings.index')
                ->with('error', 'Activa la facturación antes de agregar perfiles fiscales.');
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

            // Record the initial stamp deposit as a StampPurchase audit trail.
            $defaultStamps = (int) config('services.swsapien.default_stamps', 10);

            if ($defaultStamps > 0) {
                StampPurchase::create([
                    'fiscal_profile_id'    => $profile->id,
                    'requested_by_user_id' => $user->id,
                    'stamp_quantity'       => $defaultStamps,
                    'unit_price'           => 0,
                    'amount_total'         => 0,
                    'payment_method'       => StampPaymentMethod::MANUAL_ADJUSTMENT,
                    'status'               => StampPurchaseStatus::STAMPS_APPLIED,
                    'adjustment_type'      => 'add',
                    'admin_note'           => 'Depósito inicial por apertura de cuenta',
                    'stamps_applied_at'    => now(),
                ]);
            }

            DB::commit();

            Log::info('Fiscal profile created and PAC subaccount provisioned', [
                'fiscal_profile_id' => $profile->id,
                'rfc'               => $profile->rfc,
                'email'             => $profile->email,
                'subscription_id'   => $subscription->id,
                'initial_stamps'    => $defaultStamps,
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

            // Persist CSD files to local storage (certificate_number, valid_from,
            // and valid_to are already persisted inside the service via processCsdResponse)
            $cerStoredPath = $cerFile->storeAs(
                'csds/' . $profile->id,
                'certificado.cer',
            );
            $keyStoredPath = $keyFile->storeAs(
                'csds/' . $profile->id,
                'llave.key',
            );

            $profile->update([
                'cer_file_path' => $cerStoredPath,
                'key_file_path' => $keyStoredPath,
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
     * Show a single fiscal profile with live stamp balance, invoice KPIs,
     * CSD info, logo, and purchase history.
     */
    public function show(FiscalProfile $fiscalProfile, SWUserService $swUserService): Response
    {
        $user         = Auth::user();
        $subscription = $user->branch?->subscription;

        // Authorization: the profile must belong to the user's subscription
        if ($fiscalProfile->subscription_id !== $subscription?->id) {
            abort(403);
        }

        // Load the logo relation
        $fiscalProfile->load('media');

        // Live PAC balance
        $balance = null;
        $balanceError = null;

        if ($fiscalProfile->sw_user_id) {
            try {
                $balance = $swUserService->getStampsBalance($fiscalProfile->sw_user_id);
            } catch (\Exception $e) {
                $balanceError = 'No se pudo consultar el saldo en este momento.';
            }
        }

        // Invoice KPIs for this fiscal profile
        $invoiceQuery = Invoice::where('branch_id', $user->branch_id)
            ->where('fiscal_profile_id', $fiscalProfile->id);

        $invoiceStats = [
            'totalInvoices'  => $invoiceQuery->count(),
            'certifiedCount' => (clone $invoiceQuery)->certified()->count(),
            'canceledCount'  => (clone $invoiceQuery)->canceled()->count(),
            'totalAmount'    => (float) (clone $invoiceQuery)->certified()->sum('total'),
        ];

        // Purchase history
        $purchases = $fiscalProfile->stampPurchases()
            ->with(['requestedBy', 'reviewedBy'])
            ->latest()
            ->paginate(15);

        // Platform bank accounts (same source as "Mejorar suscripción")
        $ourBankAccounts = BankAccount::whereHas('branches', function ($q) {
            $q->where('branch_id', 1)->where('is_favorite', true);
        })->get();

        return Inertia::render('Billing/Settings/Show', [
            'fiscalProfile'     => $fiscalProfile,
            'balance'           => $balance,
            'balanceError'      => $balanceError,
            'invoiceStats'      => $invoiceStats,
            'purchases'         => $purchases,
            'ourBankAccounts'   => $ourBankAccounts,
            'canPurchaseStamps' => $user->can('stamps.purchase'),
            'canRetryManifestSigning' => $fiscalProfile->canRetryManifestSigning(),
        ]);
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

    /**
     * Toggle the active status of a fiscal profile.
     * Inactive profiles are hidden from invoice creation dropdowns
     * but all historical data remains intact.
     */
    public function toggleActive(FiscalProfile $fiscalProfile): RedirectResponse
    {
        $user = Auth::user();
        $subscription = $user->branch?->subscription;

        if ($fiscalProfile->subscription_id !== $subscription?->id) {
            abort(403);
        }

        $newStatus = ! $fiscalProfile->is_active;
        $fiscalProfile->update(['is_active' => $newStatus]);

        Log::info('Fiscal profile active status toggled', [
            'fiscal_profile_id' => $fiscalProfile->id,
            'rfc'               => $fiscalProfile->rfc,
            'is_active'         => $newStatus,
            'by_user_id'        => $user->id,
        ]);

        $message = $newStatus
            ? 'Perfil fiscal activado correctamente.'
            : 'Perfil fiscal inactivado. Ya no aparecerá al crear facturas.';

        return redirect()->route('billing.settings.index')
            ->with('success', $message);
    }

    /**
     * Upload or replace the company logo for a fiscal profile.
     */
    public function uploadLogo(Request $request, FiscalProfile $fiscalProfile): RedirectResponse
    {
        $request->validate([
            'logo' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:2048'],
        ], [
            'logo.required' => 'Selecciona una imagen para el logotipo.',
            'logo.image'    => 'El archivo debe ser una imagen válida.',
            'logo.mimes'    => 'Solo se permiten formatos JPG, PNG o WebP.',
            'logo.max'      => 'La imagen no debe pesar más de 2 MB.',
        ]);

        // MediaLibrary singleFile() replaces the previous one automatically
        $fiscalProfile->addMediaFromRequest('logo')
            ->toMediaCollection('company_logo');

        return back()->with('success', 'Logotipo actualizado correctamente.');
    }

    /**
     * Remove the company logo from a fiscal profile.
     */
    public function deleteLogo(FiscalProfile $fiscalProfile): RedirectResponse
    {
        $fiscalProfile->clearMediaCollection('company_logo');

        return back()->with('success', 'Logotipo eliminado correctamente.');
    }

    /**
     * Step 1 — Fetch the manifest legend (legal text to sign).
     *
     * Only the FIEL public certificate (.cer) is required at this stage.
     * The subscriber sees the text before providing their private key.
     */
    public function fetchManifestLegend(
        FiscalProfile $fiscalProfile,
        FetchManifestLegendRequest $request,
        FetchManifestLegendAction $action,
    ): RedirectResponse {
        $cerFile = $request->file('cer_file');
        $cerContent = file_get_contents($cerFile->getRealPath());

        $result = $action->execute($fiscalProfile, $cerContent);

        if ($result['status'] === 'error') {
            return back()->with('error', $result['message']);
        }

        return back()->with('success', 'Texto del manifiesto obtenido. Léelo cuidadosamente antes de continuar.');
    }

    /**
     * Step 2 — Record that the subscriber has read and accepted the manifest text.
     */
    public function acceptManifestText(
        FiscalProfile $fiscalProfile,
        AcceptManifestTextRequest $request,
    ): RedirectResponse {
        $fiscalProfile->update([
            'manifest_text_accepted_at' => now(),
        ]);

        return back()->with('success', 'Has aceptado el manifiesto. Ahora proporciona tu FIEL para firmarlo.');
    }

    /**
     * Step 3 — Sign the SW manifest using the FIEL (e.firma).
     *
     * Uses the previously fetched manifest text (manifest_text_b64) and
     * generates an RSA-SHA256 signature with the subscriber's private key.
     */
    public function signManifest(
        FiscalProfile $fiscalProfile,
        SignManifestRequest $request,
        SignManifestAction $action,
    ): RedirectResponse {
        $result = $action->execute($fiscalProfile, [
            'cer_file' => $request->file('cer_file'),
            'key_file' => $request->file('key_file'),
            'password' => $request->input('password'),
            'email'    => $request->input('email', $fiscalProfile->email),
        ]);

        if ($result['status'] === 'error') {
            return back()->with('error', $result['message']);
        }

        return back()->with('success', 'Manifiesto firmado exitosamente. Ya puedes descargar el acuse.');
    }

    /**
     * Download the signed manifest PDF for a fiscal profile.
     */
    public function downloadManifest(FiscalProfile $fiscalProfile): \Symfony\Component\HttpFoundation\BinaryFileResponse|\Illuminate\Http\RedirectResponse
    {
        if (! $fiscalProfile->manifest_pdf_path) {
            return back()->with('error', 'Este perfil aún no tiene un manifiesto firmado.');
        }

        $fullPath = storage_path('app/public/' . $fiscalProfile->manifest_pdf_path);

        if (! file_exists($fullPath)) {
            return back()->with('error', 'El archivo del manifiesto ya no está disponible.');
        }

        return response()->download($fullPath, 'Manifiesto_SW_' . $fiscalProfile->rfc . '.pdf');
    }
}
