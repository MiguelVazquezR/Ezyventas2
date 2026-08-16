<?php
/**
 * E2E TEST — Fase 2 (reserva de timbres con customid + cuenta normal).
 *
 * Ejecuta el flujo REAL de la app contra SW Sapien SANDBOX:
 *   1) Activar cuenta normal con credenciales sandbox de Conectia (auth real).
 *   2) getOwnBalance real de la cuenta.
 *   3) Perfil fiscal con RFC del CSD ya cargado en el PAC (GAZE9408204T4).
 *   4) Wallet local (entrada de timbres) para cuentas normal.
 *   5) createInvoice (folio atómico desde invoice_folio_counters).
 *   6) StampInvoiceAction::execute -> timbrado REAL con reserva + customid.
 *   7) Verificaciones: reserva confirmada, factura certificada, movimiento
 *      de salida, pac_call_logs sanitizado, XML en disco.
 *   8) Reenvío del mismo customid+payload -> 307 (recuperación sin timbre extra).
 *
 * Uso: php storage/tmp-e2e-fase2.php
 */

use App\Actions\Billing\StampInvoiceAction;
use App\Enums\PacAccountStatus;
use App\Enums\PacAccountType;
use App\Exceptions\Billing\PacDuplicateContentException;
use App\Models\Branch;
use App\Models\Billing\FiscalProfile;
use App\Models\Billing\InvoiceFolioCounter;
use App\Models\Billing\PacAccount;
use App\Models\Billing\PacCallLog;
use App\Models\Billing\StampMovement;
use App\Models\Billing\StampReservation;
use App\Models\Subscription;
use App\Services\Billing\SWSapienService;
use App\Services\Billing\WalletService;
use App\Services\SW\SWUserService;
use Illuminate\Contracts\Console\Kernel;

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$fail = 0;
function ok(string $msg): void { echo "  ✅ {$msg}\n"; }
function ko(string $msg): void { global $fail; $fail++; echo "  ❌ {$msg}\n"; }

echo "============================================================\n";
echo "PRUEBA E2E FASE 2 — timbrado REAL (SW Sapien SANDBOX)\n";
echo "============================================================\n\n";

$sw       = app(SWSapienService::class);
$swUser   = app(SWUserService::class);
$wallet   = app(WalletService::class);
$action   = app(StampInvoiceAction::class);

$sub = Subscription::find(1);
echo "Suscripción 1: billingEnabled = " . var_export($sub?->billingEnabled(), true) . "\n";
if (! $sub || ! $sub->billingEnabled()) {
    echo "!! Suscripción 1 no tiene el módulo de facturación activo — aborto.\n";
    exit(1);
}
$branch = Branch::where('subscription_id', 1)->first();
echo "Branch: id={$branch->id} name=" . ($branch->name ?? '(sin name)') . "\n\n";

/* ── 1) Cuenta normal: crear si no existe + activación REAL ─────────── */
echo "── Paso 1: cuenta normal + activación con credenciales sandbox ──\n";
$account = PacAccount::where('login_email', 'sandbox@conectia.mx')->first();
if (! $account) {
    $account = PacAccount::create([
        'subscription_id' => 1,
        'provider'        => 'sw_sapien',
        'account_type'    => PacAccountType::SHARED,
        'status'          => PacAccountStatus::PENDING_REQUEST,
        'login_email'     => 'sandbox@conectia.mx',
        'password'        => 'Co1234567890',
    ]);
    try {
        $swUser->activateSharedAccount($account, 'sandbox@conectia.mx', 'Co1234567890');
        ok("Activación REAL OK: status={$account->status->value} sw_user_id={$account->sw_user_id}");
    } catch (\Throwable $e) {
        ko('Activación falló: ' . $e->getMessage());
    }
} else {
    echo "  Cuenta normal ya existía: status={$account->status->value} sw_user_id={$account->sw_user_id}\n";
}

try {
    $bal = $swUser->getOwnBalance($account);
    ok('getOwnBalance REAL: stampsBalance=' . ($bal['stampsBalance'] ?? '?')
        . ' isUnlimited=' . var_export($bal['isUnlimited'] ?? null, true)
        . ' expirationDate=' . ($bal['expirationDate'] ?? '?'));
} catch (\Throwable $e) {
    ko('getOwnBalance falló: ' . $e->getMessage());
}

/* ── 2) Perfil fiscal con el RFC del CSD cargado en la cuenta ───────── */
echo "\n── Paso 2: perfil fiscal RFC=GAZE9408204T4 (CSD ya cargado en el PAC) ──\n";
$profile = FiscalProfile::where('rfc', 'GAZE9408204T4')->where('pac_account_id', $account->id)->first();
if (! $profile) {
    $profile = FiscalProfile::create([
        'subscription_id'    => 1,
        'pac_account_id'     => $account->id,
        'rfc'                => 'GAZE9408204T4',
        'razon_social'       => 'EDUARDO GAYTAN ZAVALA',
        'regimen_fiscal'     => '612',
        'postal_code'        => '45010',
        'email'              => 'e2e-test@example.com',
        'is_active'          => true,
        'manifest_signed_at' => now(),
    ]);
    ok("Perfil creado: id={$profile->id} rfc=GAZE9408204T4");
} else {
    echo "  Perfil reutilizado id={$profile->id}\n";
}

/* ── 3) Wallet: entrada de timbres (lo que haría la confirmación del revendedor) ── */
echo "\n── Paso 3: wallet local (entrada de 5 timbres) ──\n";
if ($wallet->availableBalance($profile->id) < 2) {
    StampMovement::create([
        'fiscal_profile_id' => $profile->id,
        'type'              => 'entry',
        'description'       => 'Prueba E2E — timbres asignados (sandbox)',
        'quantity'          => 5,
        'balance_after'     => 5,
    ]);
}
$balW = $wallet->availableBalance($profile->id);
ok("Wallet disponible (cuenta normal) = {$balW}");

/* ── 4) Factura borrador (folio atómico) ────────────────────────────── */
echo "\n── Paso 4: createInvoice (folio desde invoice_folio_counters) ──\n";
$invoice = $sw->createInvoice([
    'fiscal_profile_id'    => $profile->id,
    'series'               => 'E2E',
    'receiver_rfc'         => 'EKU9003173C9',
    'receiver_legal_name'  => 'ESCUELA KEMPER URGATE',
    'receiver_tax_regime'  => '601',
    'receiver_postal_code' => '42501',
    'cfdi_use'             => 'G03',
    'payment_form'         => '01',
    'payment_method'       => 'PUE',
    'items' => [[
        'description'    => 'Pago',
        'quantity'       => 1,
        'unit_price'     => 1.00,
        'sat_unit_code'  => 'ACT',
        'sat_product_code' => '84111506',
        'objeto_imp'     => '01',
    ]],
], $branch->id);
ok("Factura creada: id={$invoice->id} folio={$invoice->folio} serie=E2E status={$invoice->status->value}");

/* ── 5) Timbrado REAL vía StampInvoiceAction ───────────────────────── */
echo "\n── Paso 5: StampInvoiceAction::execute (timbrado REAL con reserva) ──\n";
try {
    $action->execute($invoice);
    $invoice->refresh();
    ok('execute() terminó sin excepción');
} catch (\Throwable $e) {
    ko('Timbrar falló: ' . get_class($e) . ': ' . $e->getMessage());
    $invoice->refresh();
    echo "  Invoice status ahora: {$invoice->status->value}\n";
}

/* ── 6) Verificaciones ─────────────────────────────────────────────── */
echo "\n── Paso 6: verificaciones ──\n";
$invoice->refresh();
$res = StampReservation::where('reference_id', $invoice->id)->latest()->first();

echo '  Factura: folio=' . $invoice->folio . ' status=' . $invoice->status->value
    . ' uuid=' . ($invoice->uuid ?? '-')
    . ' rfcProvCertif=' . ($invoice->rfc_prov_certif ?? '-') . "\n";

if ($res) {
    echo "  Reserva: id={$res->id} status={$res->status} customid={$res->customid} attempts={$res->attempts}\n";
    $res->status === 'confirmed' ? ok('Reserva CONFIRMED') : ko("Reserva NO confirmada ({$res->status})");
} else {
    ko('No se creó reserva');
}

$invoice->status->value === 'certificada' ? ok('Factura CERTIFICADA') : ko('Factura NO certificada (' . $invoice->status->value . ')');
if ($invoice->uuid) ok("UUID: {$invoice->uuid}");

$balAfter = $wallet->availableBalance($profile->id);
($balAfter === $balW - 1)
    ? ok("Wallet después = {$balAfter} (antes {$balW}) — se descontó 1")
    : ko("Wallet después = {$balAfter} (antes {$balW}) — se esperaba " . ($balW - 1));

$mov = StampMovement::where('fiscal_profile_id', $profile->id)->where('type', 'exit')->latest()->first();
$mov ? ok("Movimiento de salida: qty={$mov->quantity} desc={$mov->description}") : ko('Sin movimiento de salida');

$logs = PacCallLog::where('fiscal_profile_id', $profile->id)->orderByDesc('id')->get();
echo '  pac_call_logs: ' . $logs->count() . " registro(s)\n";
foreach ($logs as $l) {
    $payload = json_encode($l->request_payload ?? []);
    $hasSecret = stripos($payload, 'Co1234567890') !== false || stripos($payload, 'password') !== false;
    $hasCsd = stripos($payload, 'b64Cer') !== false || stripos($payload, 'b64Key') !== false;
    echo '    log id=' . $l->id . ' op=' . $l->operation . ' http=' . $l->response_status_code
        . ' customid=' . ($l->customid ?? '-')
        . ' duration=' . $l->duration_ms . 'ms'
        . ' | sanitizado=' . var_export(! $hasSecret && ! $hasCsd, true) . "\n";
    if ($hasSecret || $hasCsd) {
        ko('⚠️ request_payload contiene password/CSD (viola sanitización)');
    }
}

$xmlPath = storage_path('app/public/invoices/xml/' . $invoice->uuid . '.xml');
if ($invoice->uuid && file_exists($xmlPath)) {
    ok('XML guardado en disco');
} else {
    echo "  (sin XML en disco para este uuid)\n";
}

$counter = InvoiceFolioCounter::where('branch_id', $branch->id)->where('series', 'E2E')->first();
if ($counter) echo "  Folio counter (serie E2E) next_folio={$counter->next_folio}\n";

/* ── 7) Reenvío mismo customid + payload → 307 (sin gastar timbre) ─── */
echo "\n── Paso 7: reenvío mismo customid+payload (esperado: 307 recuperable) ──\n";
if ($res) {
    try {
        $sw->stamp($invoice, customid: $res->customid);
        ko('El reenvío NO lanzó excepción (inesperado)');
    } catch (PacDuplicateContentException $e) {
        $data = $e->response['data'] ?? [];
        if (! empty($data['cfdi'])) {
            ok('307 — timbre previo con CFDI completo recuperable (uuid=' . ($data['uuid'] ?? '?') . ')');
        } else {
            ko('307 sin CFDI completo (CFDI3307) — ' . ($e->response['message'] ?? ''));
        }
    } catch (\Throwable $e) {
        ko('El reenvío falló con: ' . get_class($e) . ': ' . $e->getMessage());
    }
}

/* ── Resumen ──────────────────────────────────────────────────────── */
$balEnd = $wallet->availableBalance($profile->id);
echo "\n============================================================\n";
echo $fail === 0
    ? "RESULTADO: TODOS LOS PASOS OK ✅\n"
    : "RESULTADO: {$fail} verificacion(es) fallida(s) ❌\n";
echo "Wallet final = {$balEnd} | Reservas: "
    . StampReservation::where('fiscal_profile_id', $profile->id)->count() . "\n";
echo "============================================================\n";
