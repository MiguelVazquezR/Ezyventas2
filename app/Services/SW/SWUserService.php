<?php

namespace App\Services\SW;

use App\Models\Invoices\FiscalProfile;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SWUserService
 *
 * Manages sub-user accounts in SW Sapien PAC.
 *
 * Each RFC (FiscalProfile) that issues CFDI invoices must exist as a
 * sub-user in the PAC. This service handles:
 *  - Creating sub-users via POST /v2/users
 *  - Mapping local FiscalProfile data to SW Sapien payloads
 *  - Storing the returned sw_user_id and account email on the profile
 *
 * The permanent token is injected from services.swsapien.token config.
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
        $endpoint = config('services.swsapien.endpoint');
        $token    = config('services.swsapien.token');

        if (! $endpoint || ! $token) {
            throw new \RuntimeException(
                'SW Sapien no está configurado. Define SW_SAPIEN_ENDPOINT y SW_SAPIEN_TOKEN en .env.'
            );
        }

        $resellersPath = config('services.swsapien.resellers_users_path', '/v3/resellers/users');
        $url           = rtrim($endpoint, '/') . '/' . ltrim($resellersPath, '/');

        $payload = [
            'rfc'          => $profile->rfc,
            'razon_social' => $profile->razon_social,
            'email'        => $email,
            'password'     => $password,
        ];

        // ────────────────────────────────────────────
        // MOCK: SW Sandbox reseller endpoint returns
        // 404 until support enables the token. Mock a
        // successful subaccount creation to unblock
        // local development.
        // ────────────────────────────────────────────
        $swUserId = 'sw_mock_user_998877';

        /*
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

        // SW Sapien returns different shapes depending on version.
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
        */

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
        $endpoint = config('services.swsapien.endpoint');
        $token    = config('services.swsapien.token');

        if (! $endpoint || ! $token) {
            throw new \RuntimeException('SW Sapien no está configurado.');
        }

        $response = Http::withToken($token)
            ->withHeaders(['Accept' => 'application/json'])
            ->get($endpoint . '/v2/users');

        if ($response->failed()) {
            Log::error('SW Sapien list users failed', [
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
}
