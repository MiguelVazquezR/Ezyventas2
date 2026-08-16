# Investigación CustomID SW Sapien + contexto para Fase 2 (reserva de timbres)

> **Fecha:** 2026-08-11
> **Alcance:** (A) Prueba técnica del comportamiento del CustomID de SW Sapien en TEST; (B) lectura del código actual (sin modificaciones) para diseñar el mecanismo de reserva de timbres de la Fase 2.
> **Entorno:** TEST (`services.test.sw.com.mx` / `api.test.sw.com.mx`). Cuenta ajena "normal" CONECTIA SANDBOX (solo user/password, sin token dealer).

---

## PARTE A — Comportamiento del CustomID (evidencia real en TEST)

Endpoint usado: `POST https://services.test.sw.com.mx/v4/cfdi33/issue/json/v4` (la ruta `/v4/cfdi33/...` **sí existe y funciona**; la app hoy usa `/v3/cfdi33/issue/json/v4`, que también funciona). Header: `customid: <string ≤ 100 chars>` (minúsculas, confirmado en SDK `lunasoft/sw-sdk-java` → `IssueRequest.java`: `httppost.setHeader("customid", customId)`).

Emisor de prueba: `GAZE9408204T4` (EDUARDO GAYTAN ZAVALA, régimen 612, CSD activo vigente de la cuenta). CustomID usado: `test-20260811162309-df321a`.

### PASO 1 — Timbrado normal CON customid
`HTTP 200` · `status: "success"`
```json
{"data": {"uuid": "2bda5186-bdd9-4215-9ff9-c63b51ac4495",
          "fechaTimbrado": "2026-08-11T10:23:10",
          "noCertificadoSAT": "30001000000500003456",
          "noCertificadoCFDI": "00001000000725327175",
          "cadenaOriginalSAT": "||1.1|2bda5186-...|SPR190613I52|...|", ...}}
```
➡️ Timbra igual de bien con customid. **UUID PASO 1 = `2bda5186-bdd9-4215-9ff9-c63b51ac4495`** (referencia para los pasos siguientes).

### PASO 2 — Reenvío EXACTO (mismo customid, mismo Serie/Folio/body)
`HTTP 400` · `status: "error"`
```json
{"message": "307. El comprobante contiene un timbre previo.",
 "messageDetail": null,
 "data": {
   "cadenaOriginalSAT": "||1.1|2bda5186-bdd9-4215-9ff9-c63b51ac4495|2026-08-11T10:23:10|SPR190613I52|...|30001000000500003456||",
   "noCertificadoSAT": "30001000000500003456",
   "noCertificadoCFDI": "00001000000725327175",
   "uuid": "2bda5186-bdd9-4215-9ff9-c63b51ac4495",   // ← IDÉNTICO al PASO 1
   "selloSAT": "Z9ZHy19...",
   "selloCFDI": "ipoWAYW...",
   "fechaTimbrado": "2026-08-11T10:23:10",
   "qrCode": "iVBORw0KGgo...",
   "cfdi": "<?xml ... <tfd:TimbreFiscalDigital ... UUID=\"2bda5186-...\" .../></cfdi:Comprobante>"
 }}
```
➡️ **NO devuelve CFDI3307 en el reenvío exacto**: devuelve **`307 - El comprobante contiene un timbre previo`** (detección por **contenido**). Y **SÍ incluye el UUID + TFD completo + el CFDI XML original** (`data.cfdi`), recuperable sin gastar otro timbre.

### PASO 3 — Mismo customid, contenido DIFERENTE (folio nuevo)
`HTTP 400` · `status: "error"`
```json
{"message": "CFDI3307 - Timbre duplicado. El customId proporcionado está duplicado.",
 "messageDetail": null,
 "data": {
   "cadenaOriginalSAT": null,
   "noCertificadoSAT": "30001000000500003456",
   "noCertificadoCFDI": null,
   "uuid": "2bda5186-bdd9-4215-9ff9-c63b51ac4495",   // ← IDÉNTICO al PASO 1
   "selloSAT": "Z9ZHy19...",
   "selloCFDI": null,
   "fechaTimbrado": "2026-08-11T10:23:10",
   "qrCode": null,
   "cfdi": ""
 }}
```
➡️ El PAC **rechaza por customid duplicado aunque el contenido sea distinto** → protege por el **identificador (customid) SOLO**, sin importar el contenido. Devuelve **UUID + TFD parcial** (selloSAT, fechaTimbrado, noCertificadoSAT) pero **NO** el CFDI XML ni la cadena original.

### PASO 4 — Customid nuevo + Serie/Folio nuevos (control)
`HTTP 200` · `status: "success"`
```json
{"data": {"uuid": "e9cb1951-a3d7-4c8e-86a0-eeae0a06e94d",
          "fechaTimbrado": "2026-08-11T10:23:12",
          "noCertificadoSAT": "30001000000500003456", ...}}
```
➡️ Control OK: timbra sin problema.

### Conclusión Parte A (una línea)
> **El customid protege duplicados basado en customid SOLO (CFDI3307 incluso con contenido distinto), y SÍ recupera el UUID original en el reintento** (reenvío exacto → `307` con TFD completo + CFDI XML; customid reutilizado → `CFDI3307` con UUID + TFD parcial).

### Implicaciones para la Fase 2 (reserva de timbres)
1. **Hay dos capas de deduplicación:** por contenido (`307` "timbre previo") y por customid (`CFDI3307`). Para reintentos idempotentes lo robusto es reenviar el **mismo payload + mismo customid**: el PAC devuelve `307` con todos los datos del timbrado original (`data.uuid`, `data.cfdi`, `data.cadenaOriginalSAT`, `data.selloSAT`, etc.) y **no gasta un timbre nuevo**.
2. **El customid debe ser único por factura** (p. ej. `uuid` local de la factura) y **nunca reutilizarse** para otra factura (CFDI3307 lo bloquearía aunque el contenido sea distinto). Máx. 100 caracteres.
3. En el manejo de errores hay que parsear `data.uuid`/`data.cfdi` cuando `status=error` con `307`/`CFDI3307`, para poder "recuperar" el timbrado original en vez de reportar un fallo.
4. Nota: el endpoint `/v4/cfdi33/issue/json/v4` también es válido (además del `/v3/cfdi33/...` que usa la app).

---

## PARTE B — Información del código actual (solo lectura)

### B.1 — Punto de disparo del timbrado real de negocio

**Solo hay 2 llamadores de `SWSapienService::stamp()`** (grep `->stamp\(` en `app/`):

1. **Alta de factura (no borrador):**
   - UI: `resources/js/Pages/Billing/Invoices/Create.vue:15` → `form.post(route('billing.invoices.store'))`.
   - Ruta: `POST /billing/invoices` → `InvoiceController::store()` — `routes/web/billing.php:29` + `app/Http/Controllers/Billing/InvoiceController.php:203-228`.
   - `store()` delega en `CreateInvoiceAction::execute()` → dentro de `DB::transaction` llama a `SWSapienService::stamp($invoice)` si `draft=false` — `app/Actions/Billing/CreateInvoiceAction.php:27-39` (línea exacta `:36`).
   - **Síncrono** (dentro del request HTTP). El `RuntimeException` del PAC se captura en `store()` (`InvoiceController.php:211-220`) y se redirige con `->with('error')`. El `RuntimeException` se lanza desde `SWSapienService::stamp()` (`SWSapienService.php:593-622`).
2. **Botón "Timbrar" sobre prefactura guardada:**
   - UI: `resources/js/Pages/Billing/Invoices/Index.vue:234` → `router.post(route('billing.invoices.stamp', invoice.id))`.
   - Ruta: `POST /billing/invoices/{invoice}/stamp` — `routes/web/billing.php:37`.
   - `InvoiceController::stamp()` — `app/Http/Controllers/Billing/InvoiceController.php:649-667` → `app(SWSapienService::class)->stamp($invoice)` (`:656-657`).
   - **Síncrono.**

**No existe** integración automática "venta → factura": el módulo de facturación es manual (no hay acción/job que genere la factura desde POS/transacciones). `UpdateInvoiceAction` **no timbra** (solo edita borradores; `app/Actions/Billing/UpdateInvoiceAction.php`). Tampoco hay job de timbrado de facturas (los jobs existentes son de timbres, ver B.5). El timbrado **nunca corre en background hoy**; está acoplado al request.

Flujo completo "clic en Facturar → PAC":
```
Create.vue → POST /billing/invoices (store)
  → InvoiceController::store()                [sync]
    → CreateInvoiceAction::execute()          [sync, DB::transaction]
      → SWSapienService::createInvoice()      (persiste + folio)
      → SWSapienService::stamp($invoice)      [sync] → POST /v3/cfdi33/issue/json/v4
  → redirect show() con flash success/error
```
(Alternativo: `Index.vue` botón Timbrar → `POST /billing/invoices/{id}/stamp` → `InvoiceController::stamp()` → `stamp()`.)

### B.2 — Generación de Serie y Folio

- **Serie:** la captura el usuario (opcional/nullable). `SWSapienService::createInvoice()` → `'series' => $data['series'] ?? null` (`SWSapienService.php:37`). No hay generación automática de serie.
- **Folio:** lo genera **el sistema automáticamente** al crear la factura (incluso borradores) con `SWSapienService::generateFolio($branchId)` (`SWSapienService.php:38`; definición `:1077-1082`):
  ```php
  $lastInvoice = Invoice::where('branch_id', $branchId)->orderByDesc('id')->first();
  return (string) (($lastInvoice ? ((int) $lastInvoice->folio) + 1 : 1));
  ```
  → Secuencial **por sucursal (branch_id)**, **NO por RFC**, **NO por serie**. Es el último `id` + 1 (no usa `max(folio)` ni locking).
- **Unicidad:** `invoices.uuid` es `unique()` (post-timbrado) — `2026_06_12_000009_create_invoices_table.php:29`. Pero **NO hay constraint único sobre `(branch_id, series, folio)`**; solo hay índice `['branch_id','series','folio']` (misma migración `:51-52`).
- **Riesgo de colisión:** `generateFolio()` hace SELECT del último por `id` y suma 1 **sin lock ni transacción dedicada** → dos creaciones concurrentes en la misma sucursal pueden calcular el mismo folio (race condition). En el entorno TEST ya se observan folios repetidos (p. ej. `Folio="8"` en varios XMLs timbrados de distinta fecha — aunque de distintas sucursales). Con el mecanismo de reserva de timbres en background (Fase 2), este punto **deberá endurecerse** (p. ej. tabla de secuencias con `lockForUpdate`, o unique `(branch_id, series, folio)`).

### B.3 — Esquema completo actual de `fiscal_profiles` (fuente: migraciones)

Migración base `2026_06_26_000001_create_fiscal_profiles_table.php`:
| Columna | Tipo | Notas |
|---|---|---|
| `id` | bigint PK | |
| `subscription_id` | FK `subscriptions` (cascade) | NOT NULL |
| `rfc` | string(13) | RFC del emisor |
| `razon_social` | string | |
| `regimen_fiscal` | string(10) | clave SAT (p. ej. "626") |
| `sw_user_id` | string nullable | ID subcuenta PAC |
| `sw_account_email` | string nullable | login subcuenta |
| `is_active` | boolean default true | |
| `created_at` / `updated_at` | timestamps | |
| unique `(subscription_id, rfc)` | | `fiscal_profiles_subscription_rfc_unique` |

Ampliaciones posteriores (en orden):
- `2026_06_26_000003_add_postal_code...`: `postal_code` string(5) nullable (LugarExpedicion).
- `2026_06_27_000001_add_email_password...`: `email` string nullable; `password` string nullable (cast `encrypted` en modelo).
- `2026_06_27_000002_add_csd_columns...`: `certificate_number` string(20) nullable; `valid_from` timestamp; `valid_to` timestamp; `cer_file_path` string; `key_file_path` string.
- `2026_07_18_000004_add_manifest_columns...`: `manifest_signed_at` timestamp; `manifest_pdf_path` string; `manifest_sent_to_email` string; `manifest_last_attempt_error` string.
- `2026_07_19_000001_add_manifest_text_columns...`: `manifest_text_b64` text; `manifest_text_shown_at` timestamp; `manifest_text_accepted_at` timestamp.

Modelo: `app/Models/Billing/FiscalProfile.php` (cast `password => encrypted`, helpers `hasSwSubaccount()`/`isReadyForInvoicing()`).

### B.4 — Esquema completo actual de `stamp_purchases` (fuente: migraciones + enums)

Migración base `2026_07_18_000002_create_stamp_purchases_table.php`:
| Columna | Tipo | Notas |
|---|---|---|
| `id` | bigint PK | |
| `fiscal_profile_id` | FK `fiscal_profiles` (cascade) | |
| `requested_by_user_id` | FK `users` | |
| `stamp_quantity` | unsignedInteger | |
| `unit_price` | decimal(10,4) | |
| `amount_total` | decimal(10,2) | |
| `pricing_tier_id` | FK `stamp_pricing_tiers` nullable (nullOnDelete) | |
| `payment_method` | string | ver enum abajo |
| `status` | string | ver enum abajo |
| `mp_payment_id` | string nullable | |
| `mp_preference_id` | string nullable | |
| `proof_file_path` | string nullable | |
| `proof_uploaded_at` | timestamp nullable | |
| `reviewed_by_user_id` | FK `users` nullable (nullOnDelete) | |
| `reviewed_at` | timestamp nullable | |
| `rejection_reason` | string nullable | |
| `pac_stamps_response_raw` | json nullable | única persistencia cruda de respuesta PAC hoy |
| `stamps_applied_at` | timestamp nullable | |
| `admin_note` | string nullable | |
| `adjustment_type` | string nullable | add/remove (solo manual_adjustment) |
| `created_at` / `updated_at` | timestamps | |
| índices | | `fiscal_profile_id`, `status`, `payment_method` |

Ampliación: `2026_07_22_000001_add_review_reason_to_stamp_purchases.php`: `review_reason` string nullable + índice.

**Valores válidos (fuente de verdad del código — la BD es `string`/varchar sin enum/check nativo):**
- `status` → enum `App\Enums\StampPurchaseStatus` (`app/Enums/StampPurchaseStatus.php`): `pending`, `awaiting_review`, `approved`, `rejected`, `failed`, `stamps_applied`.
- `payment_method` → enum `App\Enums\StampPaymentMethod`: `mercadopago`, `bank_transfer`, `manual_adjustment`.
- `adjustment_type` → enum `StampAdjustmentType` (`app/Enums/StampAdjustmentType.php`): `add`, `remove`.
- El modelo castea `status`/`payment_method`/`adjustment_type` a esos enums — `app/Models/Billing/StampPurchase.php:51-69`.

> ⚠️ Nota Fase 2: `stamp_purchases` no tiene concepto de "cuenta destino" (subcuenta vs cuenta normal); habrá que tocar esquema (p. ej. añadir `account_type`/`pac_account` o similar).

### B.5 — Sistema de colas/jobs

- **Driver: `database`** — `config/queue.php:16` `'default' => env('QUEUE_CONNECTION', 'database')` y `.env:38` `QUEUE_CONNECTION=database`.
- **No hay Horizon** (no está en `composer.json`). El único script relacionado es el dev en `composer.json:78` que corre `php artisan queue:listen --tries=1`.
- **Jobs existentes** (todos `ShouldQueue`):
  - `App\Jobs\Billing\ApplyStampsToPacJob` (`app/Jobs/Billing/ApplyStampsToPacJob.php`) — aplica timbres al PAC tras aprobación; `tries=3`, `backoff=30`, idempotente vía `status=stamps_applied` y `uniqueId()`.
  - `App\Jobs\Billing\RefreshStampGlobalStatsJob` (`app/Jobs/Billing/RefreshStampGlobalStatsJob.php`) — estadísticas globales.
  - Mails con `ShouldQueue` (`PaymentStatusNotification`, `WelcomeEmail`).
- Conclusión: el reintento en background con customid **sí es viable** con el driver `database` (necesita `queue:work`/`queue:listen` corriendo en el entorno de producción; el flujo hoy es 100% síncrono, por lo que habrá que introducir el job).

### B.6 — Log/auditoría de llamadas al PAC

- **No existe tabla de auditoría de request/response para el timbrado.** En `SWSapienService::stamp()` solo hay `Log::error(...)` cuando falla (`SWSapienService.php:600-603` y `:612-617`); en éxito **no** se persiste el request/response crudo (solo los campos procesados en `invoices`: uuid, xml_url, sellos, cadena_original, etc. — `SWSapienService.php:623-657`). En `cancel()`/`uploadCsd()`/`authenticateSubaccount()` también solo `Log::error/info` (`:710-724`, `:831-849`, `:909-939`).
- **Única persistencia cruda de PAC hoy:** `stamp_purchases.pac_stamps_response_raw` (json), usada por `StampPurchaseService::applyStampsToPac()` para compras de timbres (no para CFDI).
- **`stamp_movements`** (`2026_07_24_000001_create_stamp_movements_table.php`) es un **ledger de saldo** (entry/exit con `balance_after` y `metadata`), no un log de llamadas HTTP. Servicios: `StampMovementService` + `StampMovementObserver`.
- **Spatie Activitylog** (tabla `activity_log`, config `config/activitylog.php:44`) registra cambios de modelo: en `Invoice` solo `['status','uuid']` (`app/Models/Billing/Invoice.php:103-107`) — no guarda las llamadas al PAC.
- Conclusión: para la Fase 2 hay que **construir el log de auditoría de llamadas PAC desde cero** (p. ej. tabla `pac_call_logs` con request/response crudos, customid, uuid devuelto, status HTTP, duración). El patrón a imitar es `pac_stamps_response_raw` (json) + `stamp_movements` (ledger).

---

*Fin del informe. No se modificó código de producción; la Parte A se ejecutó con un script desechable (sin credenciales persistidas) y la Parte B es solo lectura.*
