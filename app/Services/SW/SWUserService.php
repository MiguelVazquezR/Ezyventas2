<?php

namespace App\Services\SW;

use App\Enums\PacAccountStatus;
use App\Models\Billing\FiscalProfile;
use App\Models\Billing\PacAccount;
use App\Models\Billing\StampPurchase;
use App\Enums\StampPurchaseStatus;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SWUserService
 *
 * Manages PAC accounts in SW Sapien (Management V2).
 *
 * Two account types are supported:
 *  - 'subaccount': dealer subaccounts provisioned under our master
 *    account. Stamps are assigned via the dealer API with a dealer token.
 *  - 'shared': external accounts provided by the reseller (Conectia).
 *    Stamps are managed locally (wallet per RFC).
 *    No self-service creation API; activation is manual and stamp
 *    assignment happens outside the system. Balance is queried by
 *    authenticating as the account itself (no dealer token).
 *
 * The permanent dealer token is injected from services.swsapien.token config.
 */
class SWUserService
{
    /**
     * Create a SW Sapien sub-user account for the given PAC account.
     *
     * The sub-user is tied to the RFC and razón social of the fiscal
     * profile. On success the account's sw_user_id and login_email are
     * persisted automatically.
     *
     * @param PacAccount    $account  The dealer subaccount to link.
     * @param FiscalProfile $profile  The fiscal profile providing taxId/name.
     * @param string        $email    Email for the subaccount login.
     * @param string        $password Password for the subaccount.
     *
     * @throws \RuntimeException When SW Sapien configuration is missing.
     * @throws \RuntimeException When the PAC rejects the request (e.g. duplicate RFC).
     * @throws ConnectionException  When the PAC endpoint is unreachable.
     */
    public function createSubaccountForAccount(
        PacAccount $account,
        FiscalProfile $profile,
        string $email,
        string $password,
    ): void {
        $endpoint = $this->resolveManagementEndpoint();
        $token    = config('services.swsapien.token');

        if (! $endpoint || ! $token) {
            throw new \RuntimeException(
                'El servicio de facturación no está configurado. Contacta con soporte técnico.'
            );
        }

        $managementPath = config('services.swsapien.management_users_path', '/management/v2/api/dealers/users');
        $url            = rtrim($endpoint, '/') . '/' . ltrim($managementPath, '/');

        $payload = [
            'taxId'            => $profile->rfc,
            'name'             => $profile->razon_social,
            'email'            => $email,
            'password'         => $password,
            'stamps'           => config('services.swsapien.default_stamps', 10),
            'isUnlimited'      => false,
            'notificationEmail' => $email,
            'phone'            => $profile->phone ?? '',
        ];

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ])->post($url, $payload);

        if ($response->failed()) {
            $status  = $response->status();
            $body    = $response->body();
            $json    = $response->json();
            $message = $json['message']
                ?? $json['error']
                ?? $json['errors']
                ?? $json['detail']
                ?? (is_string($json) ? $json : null);

            Log::error('SW Sapien sub-user creation rejected', [
                'pac_account_id'    => $account->id,
                'fiscal_profile_id' => $profile->id,
                'rfc'               => $profile->rfc,
                'endpoint'          => $url,
                'payload_sent'      => $payload,
                'http_status'       => $status,
                'response_json'     => $json,
                'response_body'     => $body,
            ]);

            throw new \RuntimeException(
                'Verifica que la información ingresada sea correcta. '
                . ($message ?: (is_array($json) ? json_encode($json) : ($body ?: 'Sin cuerpo de respuesta')))
            );
        }

        $data = $response->json();

        // Management V2 returns user identifiers in different shapes.
        // Common keys: data.idUser, data.id, idUser, id
        $swUserId = $data['data']['idUser']
            ?? $data['data']['id']
            ?? $data['idUser']
            ?? $data['id']
            ?? null;

        if (! $swUserId) {
            Log::error('SW Sapien response missing user ID', [
                'pac_account_id'    => $account->id,
                'fiscal_profile_id' => $profile->id,
                'response'          => $data,
            ]);

            throw new \RuntimeException(
                'No se confirmó tu registro fiscal. Revisa tus datos o contacta con soporte si el problema persiste.'
            );
        }

        $account->update([
            'sw_user_id'  => (string) $swUserId,
            'login_email' => $email,
            'status'      => PacAccountStatus::ACTIVE,
        ]);

        Log::info('SW Sapien sub-user created', [
            'pac_account_id'    => $account->id,
            'fiscal_profile_id' => $profile->id,
            'rfc'               => $profile->rfc,
            'sw_user_id'        => $swUserId,
        ]);
    }

    /**
     * Retrieve the list of sub-users from SW Sapien for debugging
     * or administrative reconciliation.
     *
     * @return array
     *
     * @throws \RuntimeException
     */
    public function listSubaccounts(): array
    {
        $endpoint = $this->resolveManagementEndpoint();
        $token    = config('services.swsapien.token');

        if (! $endpoint || ! $token) {
            throw new \RuntimeException('El servicio de facturación no está configurado. Contacta con soporte técnico.');
        }

        $managementPath = config('services.swsapien.management_users_path', '/management/v2/api/dealers/users');
        $url            = rtrim($endpoint, '/') . '/' . ltrim($managementPath, '/');

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'Accept'        => 'application/json',
        ])->get($url);

        if ($response->failed()) {
            Log::error('SW Sapien list users failed', [
                'endpoint'    => $url,
                'http_status' => $response->status(),
                'body'        => $response->body(),
            ]);

            throw new \RuntimeException(
                'No se pudo consultar el servicio de facturación en este momento. Intenta de nuevo.'
            );
        }

        return $response->json();
    }

    /**
     * Deactivate a dealer subaccount in SW Sapien when it is removed
     * or soft-deactivated locally.
     *
     * Uses PATCH /management/v2/api/dealers/users/{userId} with
     * {"isActive": false} per the official API. DELETE would
     * permanently remove the account.
     *
     * Only applies to subaccount-type accounts.
     *
     * @throws \RuntimeException When the PAC endpoint is unreachable or rejects.
     */
    public function deactivateSubaccount(PacAccount $account): void
    {
        $endpoint = $this->resolveManagementEndpoint();
        $token    = config('services.swsapien.token');

        if (! $endpoint || ! $token) {
            throw new \RuntimeException('El servicio de facturación no está configurado. Contacta con soporte técnico.');
        }

        if (! $account->sw_user_id) {
            return;
        }

        $managementPath = config('services.swsapien.management_users_path', '/management/v2/api/dealers/users');
        $url            = rtrim($endpoint, '/') . '/' . ltrim($managementPath, '/') . '/' . $account->sw_user_id;

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ])->patch($url, [
            'isActive' => false,
        ]);

        if ($response->failed()) {
            Log::error('SW Sapien subaccount deactivation failed', [
                'pac_account_id' => $account->id,
                'sw_user_id'     => $account->sw_user_id,
                'endpoint'       => $url,
                'http_status'    => $response->status(),
                'body'           => $response->body(),
            ]);

            throw new \RuntimeException(
                'No se pudo desactivar la cuenta. Contacta con soporte.'
            );
        }

        $account->update([
            'status' => PacAccountStatus::INACTIVE,
        ]);

        Log::info('SW Sapien subaccount deactivated', [
            'pac_account_id' => $account->id,
            'sw_user_id'     => $account->sw_user_id,
        ]);
    }

    /**
     * Consultar el saldo de timbres de una subcuenta en tiempo real.
     *
     * GET /management/v2/api/dealers/balance/users/{idUser}
     * Auth: Bearer token DEALER (token permanente, NO el de la subcuenta).
     *
     * @param string $swUserId The SW Sapien user ID from fiscal_profiles.sw_user_id.
     * @return array The full 'data' payload: stampsBalance, stampsUsed, stampsAssigned, isUnlimited, expirationDate.
     *
     * @throws \RuntimeException When the PAC is unreachable or returns an error.
     */
    public function getStampsBalance(string $swUserId): array
    {
        $endpoint = $this->resolveManagementEndpoint();
        $token    = config('services.swsapien.token');

        if (! $endpoint || ! $token) {
            throw new \RuntimeException('El servicio de facturación no está configurado. Contacta con soporte técnico.');
        }

        $url = rtrim($endpoint, '/') . '/management/v2/api/dealers/balance/users/' . $swUserId;

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'Accept'        => 'application/json',
        ])->timeout(15)->connectTimeout(5)->get($url);

        if ($response->failed()) {
            $status  = $response->status();
            $body    = $response->body();

            Log::error('SW Sapien balance query failed', [
                'sw_user_id'   => $swUserId,
                'endpoint'     => $url,
                'http_status'  => $status,
                'response'     => $body,
            ]);

            throw new \RuntimeException(
                $status === 404
                    ? 'El RFC no se encontró en el servicio de timbrado.'
                    : 'No se pudo consultar el saldo de timbres en este momento.'
            );
        }

        $data = $response->json();

        return $data['data'] ?? [];
    }

    /**
     * Agregar timbres a una subcuenta (abono — delta, no fijar total).
     *
     * POST /management/v2/api/dealers/users/{userId}/stamps
     * Auth: Bearer token DEALER.
     *
     * @param string      $swUserId The SW Sapien user ID.
     * @param int         $quantity Number of stamps to add.
     * @param string|null $comment  Optional audit comment for the PAC.
     * @return array The response data from the PAC (includes new total).
     *
     * @throws \RuntimeException When the PAC call fails.
     */
    public function addStampsToSubaccount(string $swUserId, int $quantity, ?string $comment = null): array
    {
        $endpoint = $this->resolveManagementEndpoint();
        $token    = config('services.swsapien.token');

        if (! $endpoint || ! $token) {
            throw new \RuntimeException('El servicio de facturación no está configurado. Contacta con soporte técnico.');
        }

        $url = rtrim($endpoint, '/') . '/management/v2/api/dealers/users/' . $swUserId . '/stamps';

        $payload = ['stamps' => $quantity];
        if ($comment) {
            $payload['comment'] = $comment;
        }

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ])->post($url, $payload);

        if ($response->failed()) {
            $status = $response->status();
            $body   = $response->body();

            Log::error('SW Sapien add stamps failed', [
                'sw_user_id'  => $swUserId,
                'quantity'    => $quantity,
                'endpoint'    => $url,
                'http_status' => $status,
                'response'    => $body,
            ]);

            throw new \RuntimeException(
                'No se pudieron asignar los timbres. Intenta de nuevo.'
            );
        }

        return $response->json();
    }

    /**
     * Retirar timbres de una subcuenta (uso exclusivo del superadmin para correcciones).
     *
     * DELETE /management/v2/api/dealers/users/{userId}/stamps
     * Auth: Bearer token DEALER.
     *
     * @param string      $swUserId The SW Sapien user ID.
     * @param int         $quantity Number of stamps to remove.
     * @param string|null $comment  Optional audit comment for the PAC.
     * @return array The response data from the PAC (includes new total).
     *
     * @throws \RuntimeException When the PAC call fails or balance is insufficient.
     */
    public function removeStampsFromSubaccount(string $swUserId, int $quantity, ?string $comment = null): array
    {
        $endpoint = $this->resolveManagementEndpoint();
        $token    = config('services.swsapien.token');

        if (! $endpoint || ! $token) {
            throw new \RuntimeException('El servicio de facturación no está configurado. Contacta con soporte técnico.');
        }

        $url = rtrim($endpoint, '/') . '/management/v2/api/dealers/users/' . $swUserId . '/stamps';

        $payload = ['stamps' => $quantity];
        if ($comment) {
            $payload['comment'] = $comment;
        }

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ])->delete($url, $payload);

        if ($response->failed()) {
            $status = $response->status();
            $body   = $response->body();
            $json   = $response->json();
            $message = $json['message'] ?? $body;

            Log::error('SW Sapien remove stamps failed', [
                'sw_user_id'  => $swUserId,
                'quantity'    => $quantity,
                'endpoint'    => $url,
                'http_status' => $status,
                'response'    => $body,
            ]);

            throw new \RuntimeException(
                $message ?: 'No se pudieron retirar los timbres. Intenta de nuevo.'
            );
        }

        return $response->json();
    }

    /**
     * Resolve the correct base URL for Management V2 (dealer) operations.
     *
     * SW Sapien uses separate hosts for different API surfaces:
     *  - services.test.sw.com.mx  → timbrado, cancelación, CSD
     *  - api.test.sw.com.mx       → administración de subcuentas (Usuarios V2)
     *
     * Falls back to the main endpoint if no dedicated management host
     * is configured, preserving backward compatibility.
     */
    private function resolveManagementEndpoint(): string
    {
        return config('services.swsapien.management_endpoint')
            ?: config('services.swsapien.endpoint', 'https://services.test.sw.com.mx');
    }

    /**
     * Consultar el saldo de la cuenta maestra (dealer) en tiempo real.
     *
     * GET /management/v2/api/users/balance
     * Auth: Bearer token DEALER.
     *
     * Este endpoint es distinto al de consultar subcuentas — consulta
     * el saldo de la cuenta dueña del token, es decir, TU cuenta maestra.
     *
     * @return array{
     *     stampsBalance: int,
     *     stampsUsed: int,
     *     stampsAssigned: int,
     *     isUnlimited: bool,
     *     expirationDate: string|null,
     * }
     *
     * @throws \RuntimeException When the PAC is unreachable or returns an error.
     */
    public function getMasterAccountBalance(): array
    {
        $endpoint = $this->resolveManagementEndpoint();
        $token    = config('services.swsapien.token');

        if (! $endpoint || ! $token) {
            throw new \RuntimeException('El servicio de facturación no está configurado. Contacta con soporte técnico.');
        }

        $url = rtrim($endpoint, '/') . '/management/v2/api/users/balance';

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'Accept'        => 'application/json',
        ])->get($url);

        if ($response->failed()) {
            $status = $response->status();
            $body   = $response->body();

            Log::error('SW Sapien master account balance query failed', [
                'endpoint'    => $url,
                'http_status' => $status,
                'response'    => $body,
            ]);

            throw new \RuntimeException(
                'No se pudo consultar el saldo de timbres en este momento. Intenta de nuevo.'
            );
        }

        $data = $response->json();

        return $data['data'] ?? [];
    }

    /*
    |--------------------------------------------------------------------------
    | External "normal" accounts (reseller-provided, no dealer API)
    |--------------------------------------------------------------------------
    */

    /**
     * Request a new "normal" PAC account for a fiscal profile.
     *
     * There is no self-service API to create a normal account — the
     * reseller (Conectia) provisions it externally. This method only
     * creates (or reuses) the local PacAccount record in
     * 'pending_request' status and links the profile to it, leaving the
     * activation to an admin.
     *
     * @return PacAccount The account the profile is now linked to.
     */
    public function requestSharedAccount(FiscalProfile $profile, ?int $requestedByUserId = null): PacAccount
    {
        // Reuse an existing normal account of the subscription that is not
        // fully closed — covers the "add a second RFC to the same account" case.
        $account = PacAccount::where('subscription_id', $profile->subscription_id)
            ->where('account_type', \App\Enums\PacAccountType::SHARED)
            ->whereIn('status', [
                PacAccountStatus::PENDING_REQUEST,
                PacAccountStatus::PENDING_ACTIVATION,
                PacAccountStatus::ACTIVE,
            ])
            ->latest('id')
            ->first();

        // If the subscription has no account of its own, link the profile to
        // the platform shared account (multiple subscribers, shared stamp pool).
        if (! $account) {
            $account = PacAccount::query()->sharedActive()->latest('id')->first();
        }

        if (! $account) {
            $account = PacAccount::create([
                'subscription_id'       => $profile->subscription_id,
                'provider'              => 'sw_sapien',
                'account_type'          => \App\Enums\PacAccountType::SHARED,
                'status'                => PacAccountStatus::PENDING_REQUEST,
                'requested_by_user_id'  => $requestedByUserId,
                'requested_at'          => now(),
            ]);
        }

        $profile->update(['pac_account_id' => $account->id]);

        Log::info('Normal PAC account requested', [
            'pac_account_id'    => $account->id,
            'fiscal_profile_id' => $profile->id,
            'rfc'               => $profile->rfc,
            'subscription_id'   => $profile->subscription_id,
            'status'            => $account->status->value,
        ]);

        return $account;
    }

    /**
     * Activate a "normal" PAC account with the credentials provided by
     * the reseller.
     *
     * 1. Performs a test authentication against the PAC.
     * 2. On failure, throws the PAC error as-is and saves nothing.
     * 3. On success, persists login_email (encrypted password),
     *    sw_user_id from the balance response, status = active and
     *    activated_at.
     *
     * @throws \RuntimeException When the credentials are rejected by the PAC.
     */
    public function activateSharedAccount(
        PacAccount $account,
        string $email,
        string $password,
        ?int $activatedByUserId = null,
    ): PacAccount {
        // Test the credentials before persisting anything.
        $token = $this->authenticateWithCredentials($email, $password);
        $balance = $this->getBalanceWithToken($token);

        $account->update([
            'login_email'           => $email,
            'password'              => $password,
            'sw_user_id'            => $balance['idUser'] ?? null,
            'status'                => PacAccountStatus::ACTIVE,
            'activated_by_user_id'  => $activatedByUserId,
            'activated_at'          => now(),
        ]);

        Log::info('Normal PAC account activated', [
            'pac_account_id'  => $account->id,
            'sw_user_id'      => $account->sw_user_id,
            'login_email'     => $email,
            'by_user_id'      => $activatedByUserId,
        ]);

        return $account;
    }

    /**
     * Consultar el saldo de timbres de una cuenta "normal" autenticándose
     * como la propia cuenta (SIN token dealer).
     *
     * GET {management}/management/v2/api/users/balance
     *
     * @return array The 'data' payload: idUser, stampsBalance, stampsUsed,
     *               stampsAssigned, isUnlimited, expirationDate.
     *
     * @throws \RuntimeException When the account has no credentials or the PAC rejects.
     */
    public function getOwnBalance(PacAccount $account): array
    {
        if (! $account->hasCredentials()) {
            throw new \RuntimeException('La cuenta no tiene credenciales configuradas.');
        }

        $token = $this->authenticateWithCredentials($account->login_email, $account->password);

        return $this->getBalanceWithToken($token);
    }

    /**
     * Authenticate with arbitrary PAC credentials and return the token.
     *
     * POST {endpoint}/v2/security/authenticate {user, password}
     *
     * @throws \RuntimeException When authentication fails (the PAC message is kept as-is).
     */
    private function authenticateWithCredentials(string $email, string $password): string
    {
        $endpoint = config('services.swsapien.endpoint');

        if (! $endpoint) {
            throw new \RuntimeException('El servicio de facturación no está configurado. Contacta con soporte técnico.');
        }

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
        ])->post($endpoint . '/v2/security/authenticate', [
            'user'     => $email,
            'password' => $password,
        ]);

        if ($response->failed()) {
            $message = $response->json('message')
                ?? $response->json('messageDetail')
                ?? $response->body();

            Log::error('SW Sapien account authentication failed', [
                'login_email'  => $email,
                'http_status'  => $response->status(),
                'response'     => $response->body(),
            ]);

            throw new \RuntimeException(
                'No se pudo validar la cuenta: ' . ($message ?: 'revisa las credenciales e inténtalo de nuevo.')
            );
        }

        $data = $response->json();

        $token = $data['data']['token'] ?? $data['token'] ?? null;

        if (! $token) {
            Log::error('SW Sapien auth response missing token', [
                'login_email' => $email,
                'response'    => $data,
            ]);

            throw new \RuntimeException(
                'El servicio de validación fiscal no respondió correctamente. Intenta de nuevo.'
            );
        }

        return $token;
    }

    /**
     * Query the account balance using an already-obtained account token
     * (no dealer token).
     *
     * GET {management}/management/v2/api/users/balance
     *
     * @return array The 'data' payload (idUser, stampsBalance, ...).
     *
     * @throws \RuntimeException When the PAC rejects the balance query.
     */
    private function getBalanceWithToken(string $token): array
    {
        $endpoint = $this->resolveManagementEndpoint();

        if (! $endpoint) {
            throw new \RuntimeException('El servicio de facturación no está configurado. Contacta con soporte técnico.');
        }

        $url = rtrim($endpoint, '/') . '/management/v2/api/users/balance';

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'Accept'        => 'application/json',
        ])->timeout(15)->connectTimeout(5)->get($url);

        if ($response->failed()) {
            Log::error('SW Sapien account balance query failed', [
                'endpoint'    => $url,
                'http_status' => $response->status(),
                'response'    => $response->body(),
            ]);

            throw new \RuntimeException(
                'No se pudo consultar el saldo de timbres en este momento.'
            );
        }

        $data = $response->json();

        return $data['data'] ?? [];
    }
}
