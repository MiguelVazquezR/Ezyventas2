<?php

namespace App\Services\SW;

use App\Models\Billing\FiscalProfile;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SWUserService
 *
 * Manages sub-user accounts in SW Sapien PAC (Management V2).
 *
 * Each RFC (FiscalProfile) that issues CFDI invoices must exist as a
 * sub-user in the PAC. This service handles:
 *  - Creating sub-users via POST /management/v2/api/dealers/users
 *  - Mapping local FiscalProfile data to SW Sapien payloads
 *  - Storing the returned sw_user_id and account email on the profile
 *  - Deactivating subaccounts when a profile is removed
 *
 * The permanent dealer token is injected from services.swsapien.token config.
 */
class SWUserService
{
    /**
     * Create a SW Sapien sub-user account for the given fiscal profile.
     *
     * The sub-user is tied to the RFC and razón social stored in the
     * FiscalProfile. On success the profile's sw_user_id and
     * sw_account_email are persisted automatically.
     *
     * @param FiscalProfile $profile  The local fiscal profile to link.
     * @param string        $email    Email for the subaccount login.
     * @param string        $password Password for the subaccount.
     *
     * @throws \RuntimeException When SW Sapien configuration is missing.
     * @throws \RuntimeException When the PAC rejects the request (e.g. duplicate RFC).
     * @throws ConnectionException  When the PAC endpoint is unreachable.
     */
    public function createSubaccountForProfile(
        FiscalProfile $profile,
        string $email,
        string $password,
    ): void {
        $endpoint = $this->resolveManagementEndpoint();
        $token    = config('services.swsapien.token');

        if (! $endpoint || ! $token) {
            throw new \RuntimeException(
                'SW Sapien Management no está configurado. Define SW_SAPIEN_MANAGEMENT_ENDPOINT y SW_SAPIEN_TOKEN en .env.'
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
                'fiscal_profile_id' => $profile->id,
                'rfc'               => $profile->rfc,
                'endpoint'          => $url,
                'payload_sent'      => $payload,
                'http_status'       => $status,
                'response_json'     => $json,
                'response_body'     => $body,
            ]);

            throw new \RuntimeException(
                'El PAC rechazó la creación de la subcuenta. '
                . 'HTTP ' . $status . ' — '
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
                'fiscal_profile_id' => $profile->id,
                'response'          => $data,
            ]);

            throw new \RuntimeException(
                'El PAC no devolvió un identificador de usuario válido.'
            );
        }

        $profile->update([
            'sw_user_id'       => (string) $swUserId,
            'sw_account_email' => $email,
        ]);

        Log::info('SW Sapien sub-user created', [
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
            throw new \RuntimeException('SW Sapien Management no está configurado.');
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
                'No se pudo obtener la lista de subcuentas: '
                . ($response->json('message') ?? $response->body())
            );
        }

        return $response->json();
    }

    /**
     * Deactivate a sub-user account in SW Sapien when a fiscal profile
     * is removed or soft-deactivated locally.
     *
     * Uses PATCH /management/v2/api/dealers/users/{userId} with
     * {"isActive": false} per the official API. DELETE would
     * permanently remove the account.
     *
     * @throws \RuntimeException When the PAC endpoint is unreachable or rejects.
     */
    public function deactivateSubaccount(FiscalProfile $profile): void
    {
        $endpoint = $this->resolveManagementEndpoint();
        $token    = config('services.swsapien.token');

        if (! $endpoint || ! $token) {
            throw new \RuntimeException('SW Sapien Management no está configurado.');
        }

        if (! $profile->sw_user_id) {
            return;
        }

        $managementPath = config('services.swsapien.management_users_path', '/management/v2/api/dealers/users');
        $url            = rtrim($endpoint, '/') . '/' . ltrim($managementPath, '/') . '/' . $profile->sw_user_id;

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ])->patch($url, [
            'isActive' => false,
        ]);

        if ($response->failed()) {
            Log::error('SW Sapien subaccount deactivation failed', [
                'fiscal_profile_id' => $profile->id,
                'sw_user_id'        => $profile->sw_user_id,
                'endpoint'          => $url,
                'http_status'       => $response->status(),
                'body'              => $response->body(),
            ]);

            throw new \RuntimeException(
                'El PAC rechazó la desactivación de la subcuenta: '
                . ($response->json('message') ?? $response->body())
            );
        }

        Log::info('SW Sapien subaccount deactivated', [
            'fiscal_profile_id' => $profile->id,
            'sw_user_id'        => $profile->sw_user_id,
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
            throw new \RuntimeException('SW Sapien Management no está configurado.');
        }

        $url = rtrim($endpoint, '/') . '/management/v2/api/dealers/balance/users/' . $swUserId;

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'Accept'        => 'application/json',
        ])->get($url);

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
                    ? 'La subcuenta no pertenece a la cuenta dealer.'
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
            throw new \RuntimeException('SW Sapien Management no está configurado.');
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
                'El PAC rechazó la asignación de timbres. HTTP ' . $status . ' — ' . $body
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
            throw new \RuntimeException('SW Sapien Management no está configurado.');
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
                $message ?: 'El PAC rechazó el retiro de timbres. HTTP ' . $status
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
            throw new \RuntimeException('SW Sapien Management no está configurado.');
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
                'No se pudo consultar el saldo de la cuenta maestra en este momento.'
            );
        }

        $data = $response->json();

        return $data['data'] ?? [];
    }
}
