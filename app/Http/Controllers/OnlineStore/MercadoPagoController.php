<?php

namespace App\Http\Controllers\OnlineStore;

use App\Http\Controllers\Controller;
use App\Models\StoreConfig;
use App\Services\MercadoPagoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class MercadoPagoController extends Controller
{
    public function __construct(
        private readonly MercadoPagoService $mpService,
    ) {}

    /**
     * Redirect the subscriber to Mercado Pago OAuth.
     */
    public function connect(): RedirectResponse
    {
        // In sandbox/test mode, OAuth is not needed — use test credentials instead
        if (config('services.mercadopago.env', 'sandbox') === 'sandbox') {
            return redirect()->route('online-store.config')
                ->with('warning', 'En modo de prueba no es necesario conectar una cuenta real. Se usan credenciales de prueba automáticamente.');
        }

        $user = Auth::user();
        $subscriptionId = $user->branch->subscription_id;

        $url = $this->mpService->buildOAuthUrl($subscriptionId);

        return Inertia::location($url);
    }

    /**
     * Handle the OAuth callback from Mercado Pago.
     */
    public function callback(Request $request): RedirectResponse
    {
        // In sandbox/test mode, OAuth callbacks are not expected
        if (config('services.mercadopago.env', 'sandbox') === 'sandbox') {
            return redirect()->route('online-store.config')
                ->with('warning', 'El flujo OAuth no está disponible en modo de prueba.');
        }

        $user = Auth::user();
        $subscriptionId = $user->branch->subscription_id;

        // Validate state matches the authenticated user's subscription
        if ((int) $request->state !== $subscriptionId) {
            return redirect()->route('online-store.config')
                ->with('error', 'La autorización de Mercado Pago no corresponde a tu cuenta.');
        }

        if (!$request->code) {
            return redirect()->route('online-store.config')
                ->with('error', 'No se recibió el código de autorización de Mercado Pago.');
        }

        try {
            $tokenData = $this->mpService->exchangeCode($request->code);

            $storeConfig = StoreConfig::where('subscription_id', $subscriptionId)->firstOrFail();

            $storeConfig->update([
                'mp_access_token'     => $tokenData['access_token'],
                'mp_refresh_token'    => $tokenData['refresh_token'] ?? null,
                'mp_user_id'          => (string) ($tokenData['user_id'] ?? ''),
                'mp_public_key'       => $tokenData['public_key'] ?? null,
                'mp_token_expires_at' => isset($tokenData['expires_in'])
                    ? now()->addSeconds($tokenData['expires_in'])
                    : null,
            ]);

            return redirect()->route('online-store.config')
                ->with('success', 'Mercado Pago conectado exitosamente.');
        } catch (\Exception $e) {
            return redirect()->route('online-store.config')
                ->with('error', 'Error al conectar Mercado Pago: ' . $e->getMessage());
        }
    }

    /**
     * Disconnect Mercado Pago from the subscriber's store.
     */
    public function disconnect(): RedirectResponse
    {
        // In sandbox/test mode, disconnecting is not meaningful — test credentials are from env
        if (config('services.mercadopago.env', 'sandbox') === 'sandbox') {
            return redirect()->route('online-store.config')
                ->with('warning', 'No se puede desconectar Mercado Pago en modo de prueba.');
        }

        $user = Auth::user();
        $subscriptionId = $user->branch->subscription_id;

        $storeConfig = StoreConfig::where('subscription_id', $subscriptionId)->firstOrFail();

        $storeConfig->update([
            'mp_access_token'     => null,
            'mp_refresh_token'    => null,
            'mp_user_id'          => null,
            'mp_public_key'       => null,
            'mp_token_expires_at' => null,
            'payment_mp_enabled'  => false,
        ]);

        return redirect()->route('online-store.config')
            ->with('success', 'Mercado Pago desconectado correctamente.');
    }
}
