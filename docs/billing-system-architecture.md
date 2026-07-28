# EzyVentas — Billing System Architecture (Facturación CFDI 4.0)

> **Propósito:** Documento de referencia exhaustivo para que un modelo de IA comprenda la arquitectura, el flujo de datos, las entidades, el PAC utilizado y las rutas del módulo de facturación electrónica (CFDI 4.0) de EzyVentas.  
> **Última actualización:** 2026-07-08  
> **Stack:** Laravel 12 (PHP 8.3+) + Vue 3 (Composition API `<script setup>`) + Inertia.js 2 + PrimeVue 4 + Tailwind CSS  
> **PAC:** [SW Sapien](https://www.sw.com.mx/) — esquema multi-RFC con subcuentas por RFC emisor.

---

## Tabla de contenidos

1. [Visión general del PAC (SW Sapien)](#1-visión-general-del-pac-sw-sapien)
2. [Estructura de la base de datos (migraciones)](#2-estructura-de-la-base-de-datos)
3. [Modelos (Models)](#3-modelos)
4. [Enums](#4-enums)
5. [Servicios (Services)](#5-servicios)
6. [Acciones (Actions)](#6-acciones)
7. [Form Requests (validación)](#7-form-requests)
8. [Controladores (Controllers)](#8-controladores)
9. [Rutas (Routes)](#9-rutas)
10. [Frontend (Vue 3 / Inertia)](#10-frontend)
11. [Vista PDF (Blade)](#11-vista-pdf)
12. [Flujo completo de emisión de CFDI](#12-flujo-completo-de-emisión-de-cfdi)
13. [Flujo de cancelación de CFDI](#13-flujo-de-cancelación-de-cfdi)
14. [Flujo de configuración fiscal (perfiles + CSD)](#14-flujo-de-configuración-fiscal)
15. [Permisos (Spatie)](#15-permisos)
16. [Variables de entorno (.env)](#16-variables-de-entorno)
17. [Resumen de archivos del módulo](#17-resumen-de-archivos-del-módulo)

---

## 1. Visión general del PAC (SW Sapien)

### ¿Qué es SW Sapien?

[SW Sapien](https://www.sw.com.mx/) es un Proveedor Autorizado de Certificación (PAC) mexicano que permite timbrar CFDI 4.0 ante el SAT mediante API REST. EzyVentas usa el **esquema multi-RFC con subcuentas (Esquema 2 / Management V2)**.

### Dos superficies de API

| Superficie | Host | Propósito |
|---|---|---|
| **Timbrado / Cancelación / CSD** | `services.test.sw.com.mx` | Timbrar CFDI, cancelar, subir certificados CSD |
| **Management V2 (administración)** | `api.test.sw.com.mx` | Crear/desactivar subcuentas (sub-users) por RFC |

### Esquema de autenticación

- **Token dealer (permanente):** Se usa SOLO para operaciones de Management V2 (crear/desactivar subcuentas). Se configura en `SW_SAPIEN_TOKEN`.
- **Token de subcuenta (temporal, 2 h):** Se obtiene autenticando como el sub-usuario (`/v2/security/authenticate`) con el email y password de la subcuenta. Se usa para timbrar, cancelar y subir CSDs. Se cachea por 110 minutos en Laravel Cache.

### Flujo multi-RFC

Cada suscripción (tenant) puede tener **múltiples RFC emisores**. Cada RFC se modela como un `FiscalProfile` que se vincula a una **subcuenta en SW Sapien**. Cuando se timbra una factura, el sistema:

1. Autentica como la subcuenta correspondiente al `FiscalProfile` de la factura.
2. Usa el token de subcuenta para timbrar — así cada RFC consume su propio quota de timbres y usa su propio CSD.

---

## 2. Estructura de la base de datos

### 2.1 Tabla `fiscal_profiles`

Representa un RFC emisor registrado en el PAC. Una suscripción puede tener N perfiles fiscales.

| Columna | Tipo | Descripción |
|---|---|---|
| `id` | bigint (PK) | Identificador único |
| `subscription_id` | FK → subscriptions | Suscripción propietaria |
| `rfc` | varchar(13) | RFC del emisor (ej. `MOR830115CZ8`) |
| `razon_social` | varchar(255) | Razón social o nombre fiscal |
| `regimen_fiscal` | varchar(10) | Clave SAT del régimen (ej. `626` RESICO) |
| `postal_code` | varchar(5) | CP de expedición (LugarExpedicion del CFDI) |
| `email` | varchar(255) | Email de contacto para la subcuenta |
| `password` | varchar(255) | Contraseña autogenerada (encrypted) |
| `sw_user_id` | varchar | ID del sub-usuario en SW Sapien (nullable hasta aprovisionar) |
| `sw_account_email` | varchar | Email asignado en el PAC |
| `certificate_number` | varchar(20) | No. de serie del certificado SAT (20 dígitos) |
| `valid_from` | timestamp | Inicio de vigencia del CSD |
| `valid_to` | timestamp | Vencimiento del CSD |
| `cer_file_path` | varchar | Ruta local del .cer |
| `key_file_path` | varchar | Ruta local del .key |
| `is_active` | boolean | Perfil activo/inactivo |
| `timestamps` | | created_at, updated_at |

**Unique constraint:** `(subscription_id, rfc)` — no puede haber dos perfiles con el mismo RFC en la misma suscripción.

### 2.2 Tabla `invoices`

Representa un CFDI 4.0 (factura electrónica). Cada factura está vinculada a una sucursal (`branch`) y a un perfil fiscal emisor (`fiscal_profile`).

| Columna | Tipo | Descripción |
|---|---|---|
| `id` | bigint (PK) | Identificador único |
| `branch_id` | FK → branches | Sucursal que emite |
| `fiscal_profile_id` | FK → fiscal_profiles | RFC emisor (nullable, FK) |
| `customer_id` | FK → customers | Cliente receptor (nullable) |
| `series` | varchar(10) | Serie (ej. `A`, `F`) |
| `folio` | varchar(20) | Folio consecutivo |
| `status` | varchar | Estados: `borrador`, `pendiente`, `certificada`, `cancelada` |
| `uuid` | varchar(36) | UUID del SAT (folio fiscal) — único, nullable hasta timbrar |
| `xml_url` | varchar | Ruta del XML timbrado en storage |
| `pdf_url` | varchar | URL del PDF generado por el PAC |
| `issued_at` | timestamp | Fecha de emisión/timbrado |
| `canceled_at` | timestamp | Fecha de cancelación |
| `receiver_rfc` | varchar(13) | RFC del receptor |
| `receiver_legal_name` | varchar(255) | Razón social del receptor |
| `receiver_tax_regime` | varchar(10) | Régimen fiscal del receptor |
| `receiver_postal_code` | varchar(5) | CP del receptor |
| `cfdi_use` | varchar(10) | Uso CFDI (catálogo SAT, ej. `G03`) |
| `exportacion` | varchar(5) | Exportación CFDI 4.0: `01`, `02`, `03`, `04` (default `01`) |
| `payment_form` | varchar(5) | Forma de pago (ej. `01` efectivo, `03` transferencia) |
| `payment_method` | varchar(5) | Método de pago: `PUE` o `PPD` |
| `currency` | varchar(5) | Moneda (default `MXN`) |
| `exchange_rate` | decimal(10,6) | Tipo de cambio (obligatorio si moneda ≠ MXN) |
| `subtotal` | decimal(12,2) | Suma antes de impuestos |
| `discount_total` | decimal(12,2) | Total de descuentos |
| `taxes_total` | decimal(12,2) | Suma de impuestos trasladados (IVA, IEPS) |
| `retained_taxes_total` | decimal(12,2) | Suma de impuestos retenidos (ISR, IVA retenido) |
| `total` | decimal(12,2) | Total: subtotal − descuentos + taxes − retenciones |
| `cancellation_reason` | varchar | Motivo de cancelación SAT (`01`–`04`) |
| `timestamps` | | created_at, updated_at |

**Índices:** `(branch_id, series, folio)`, `status`, `fiscal_profile_id`.

### 2.3 Tabla `invoice_items`

Partidas o conceptos de una factura. Una factura tiene N items.

| Columna | Tipo | Descripción |
|---|---|---|
| `id` | bigint (PK) | Identificador único |
| `invoice_id` | FK → invoices | Factura padre |
| `product_id` | FK → products | Producto relacionado (nullable) |
| `description` | varchar(255) | Descripción del concepto |
| `quantity` | decimal(12,4) | Cantidad |
| `sat_unit_code` | varchar(10) | Clave de unidad SAT (ej. `H87` pieza, `E48` servicio) |
| `unit_name` | varchar(50) | Nombre comercial de la unidad (ej. "Pieza") |
| `unit_price` | decimal(12,4) | Precio unitario |
| `subtotal` | decimal(12,2) | quantity × unit_price |
| `discount_amount` | decimal(12,2) | Descuento aplicado a la línea |
| `tax_amount` | decimal(12,2) | Impuesto trasladado de la línea |
| `total` | decimal(12,2) | Total de la línea: subtotal − discount + tax − retentions |
| `sat_product_code` | varchar(15) | ClaveProdServ del SAT |
| `no_identificacion` | varchar(100) | SKU o identificador interno del producto |
| `objeto_imp` | varchar(5) | Objeto de impuesto: `01` No objeto, `02` Sí objeto, `03` Sí objeto no obligado |
| `tax_type` | varchar(5) | Tipo de impuesto trasladado (ej. `002` IVA) |
| `tax_rate` | decimal(6,4) | Tasa del impuesto (ej. `0.1600`) |
| `retained_tax_type` | varchar(5) | Tipo de retención (legacy: `001` ISR, `002` IVA) |
| `retained_tax_rate` | decimal(6,6) | Tasa de retención (ej. `0.012500`) |
| `retained_tax_amount` | decimal(12,2) | Importe de retención (legacy) |
| `retentions` | json | Array multi-retención: `[{type, rate, amount}, ...]` |
| `timestamps` | | created_at, updated_at |

### 2.4 Tabla `billing_settings` (legacy/deprecada en favor de FiscalProfile)

Configuración de facturación por sucursal. Actualmente **reemplazada funcionalmente** por `fiscal_profiles` pero la tabla y su migración existen.

| Columna | Tipo | Descripción |
|---|---|---|
| `id` | bigint (PK) | |
| `branch_id` | FK → branches (unique) | Sucursal |
| `emitter_rfc` | varchar(13) | RFC emisor |
| `emitter_legal_name` | varchar(255) | Razón social |
| `emitter_tax_regime` | varchar(10) | Régimen fiscal |
| `emitter_postal_code` | varchar(5) | CP expedición |
| `logo_path` | varchar | Ruta del logo |
| `api_key` | text | API key (encrypted) |

### 2.5 Campos agregados a `customers`

Migración `2026_06_22_000000_add_fiscal_fields_to_customers.php`:

| Columna | Tipo | Descripción |
|---|---|---|
| `tax_regime` | varchar(10) | Régimen fiscal del cliente |
| `fiscal_address` | json | Domicilio fiscal cuando difiere del principal |

---

## 3. Modelos

### 3.1 `App\Models\Billing\FiscalProfile`

- **Tabla:** `fiscal_profiles`
- **Fillable:** `subscription_id`, `rfc`, `razon_social`, `regimen_fiscal`, `postal_code`, `email`, `password`, `sw_user_id`, `sw_account_email`, `certificate_number`, `valid_from`, `valid_to`, `cer_file_path`, `key_file_path`, `is_active`
- **Hidden:** `password`
- **Casts:** `is_active` → boolean, `password` → encrypted
- **Relaciones:**
  - `subscription(): BelongsTo` — La suscripción dueña del perfil
- **Scopes:**
  - `scopeActive(Builder)` — Solo perfiles activos (`is_active = true`)
- **Helpers:**
  - `hasSwSubaccount(): bool` — ¿Tiene `sw_user_id` asignado?
  - `isReadyForInvoicing(): bool` — ¿Activo Y tiene subcuenta PAC?

### 3.2 `App\Models\Billing\Invoice`

- **Tabla:** `invoices`
- **Uses:** `LogsActivity` (Spatie Activitylog)
- **Fillable:** Todos los campos de CFDI (ver tabla arriba)
- **Casts:**
  - `status` → `InvoiceStatus` enum
  - `issued_at`, `canceled_at`, `created_at` → datetime
  - Montos → `decimal:2`
  - `exchange_rate` → `decimal:6`
- **Accessors:**
  - `getFechaAttribute(): string` — Formato ISO 8601 `Y-m-d\TH:i:s` para el SAT
- **Relaciones:**
  - `branch(): BelongsTo`
  - `fiscalProfile(): BelongsTo`
  - `customer(): BelongsTo`
  - `items(): HasMany` → `InvoiceItem`
- **Scopes:**
  - `scopePending`, `scopeCertified`, `scopeCanceled`, `scopeDraft` — por estado
  - `scopeForBranch(int)`, `scopeForCustomer(int)`, `scopeForFiscalProfile(int)`
- **Helpers:**
  - `isCertified(): bool` — status=CERTIFIED y tiene UUID
  - `isEditable(): bool` — solo si status=DRAFT

### 3.3 `App\Models\InvoiceItem`

- **Tabla:** `invoice_items`
- **Casts:**
  - `quantity`, `unit_price` → `decimal:4`
  - `subtotal`, `discount_amount`, `tax_amount`, `total`, `retained_tax_amount` → `decimal:2`
  - `tax_rate` → `decimal:6`
  - `retained_tax_rate` → `decimal:6`
  - `retentions` → `array` (JSON)
- **Relaciones:**
  - `invoice(): BelongsTo`
  - `product(): BelongsTo`
- **Helpers:**
  - `grossSubtotal(): float` — `quantity × unit_price`

---

## 4. Enums

### `App\Enums\InvoiceStatus`

```php
enum InvoiceStatus: string
{
    case NOT_REQUESTED = 'no_solicitada';  // Legacy suscripción
    case REQUESTED     = 'solicitada';     // Legacy suscripción
    case GENERATED     = 'generada';       // Legacy suscripción
    case DRAFT         = 'borrador';       // CFDI 4.0 — recién creado, no timbrado
    case PENDING       = 'pendiente';      // CFDI 4.0 — pendiente de timbrar
    case CERTIFIED     = 'certificada';    // CFDI 4.0 — timbrado exitoso
    case CANCELED      = 'cancelada';      // CFDI 4.0 — cancelado
}
```

> **Nota:** Los primeros 3 estados (`no_solicitada`, `solicitada`, `generada`) son legacy del sistema de facturación de suscripciones (no CFDI 4.0). El módulo de facturación CFDI 4.0 solo usa `borrador`, `pendiente`, `certificada`, y `cancelada`.

---

## 5. Servicios (Services)

### 5.1 `App\Services\Billing\SWSapienService`

**Archivo:** `app/Services/Billing/SWSapienService.php`

Es el servicio central de facturación. Contiene TODA la lógica de negocio reusable relacionada con CFDI 4.0 y la comunicación con el PAC SW Sapien.

#### Métodos públicos

| Método | Descripción |
|---|---|
| `createInvoice(array $data, int $branchId): Invoice` | Persiste factura + partidas en BD. Calcula totales server-side. Genera folio. Retorna el modelo Invoice creado. |
| `buildPayload(Invoice $invoice): array` | Construye el JSON payload CFDI 4.0 para el PAC. Aplica reglas de formato SAT (números como strings, 2/4/6 decimales, agrupación de impuestos globales). |
| `stamp(Invoice $invoice): void` | Timbra la factura contra SW Sapien. Autentica como subcuenta, envía payload, almacena XML, actualiza UUID y status a CERTIFIED. |
| `cancel(Invoice $invoice, string $emitterRfc, string $reason, ?string $substitutionUuid): void` | Cancela CFDI vía PAC. Actualiza status a CANCELED. |
| `syncCustomerFiscalData(int $customerId, array $data): void` | Sincroniza datos fiscales (RFC, razón social, régimen, CP) de vuelta al modelo Customer. |
| `uploadCsd(FiscalProfile $profile, string $cerPath, string $keyPath, string $password): array` | Sube CSD (.cer + .key) al PAC para una subcuenta. Convierte PEM→DER, extrae metadatos del certificado localmente con `openssl_x509_parse()`. |
| `generateFolio(int $branchId): string` | Genera folio consecutivo único por sucursal. |
| `authenticateSubaccount(FiscalProfile $profile): string` | (protected) Autentica como subcuenta en SW Sapien y devuelve token temporal. Cachea 110 min. |

#### Reglas de formato del payload CFDI 4.0 (método `buildPayload`)

- **Montos monetarios:** `number_format($val, 2, '.', '')` — siempre 2 decimales
- **Tasas:** `number_format($val, 6, '.', '')` — siempre 6 decimales
- **Cantidades:** `number_format($val, 4, '.', '')` — 4 decimales
- **Descuento:** Solo se incluye en el nodo si > 0 (SAT lo prohíbe en 0)
- **Impuestos globales:** Se agrupan por `Impuesto|TipoFactor|TasaOCuota` (traslados) y por `Impuesto` (retenciones)
- **CondicionesDePago:** `"Contado"` cuando `payment_form = 99` y `payment_method = PUE`
- **TipoCambio:** Requerido si moneda ≠ MXN, 6 decimales
- **Nodo Emisor:** RFC, Nombre, RegimenFiscal desde `FiscalProfile`
- **Nodo Receptor:** RFC, Nombre, DomicilioFiscalReceptor, RegimenFiscalReceptor, UsoCFDI
- **Nodo Conceptos:** Array de items con Impuestos (Traslados y/o Retenciones) por concepto
- **ObjetoImp:** `02` = Sí objeto de impuesto (genera nodo Traslado), `01` = No objeto

#### Métodos protegidos/privados

| Método | Descripción |
|---|---|
| `authenticateSubaccount(FiscalProfile): string` | POST `/v2/security/authenticate` como subcuenta. Retorna token cacheado 110 min. |
| `extractDerFromPem(string $content, string $label): string` | Convierte PEM armor → DER binario puro para upload de CSD. |
| `extractCertificateData(string $cerDer): array` | Parsea .cer con `openssl_x509_parse()`. Extrae `certificate_number`, `valid_from`, `valid_to`. Convierte serialNumberHex (40 chars hex) → binario (20 bytes). |
| `processCsdResponse(FiscalProfile, array $pacResponse, string $cerDer): array` | Procesa respuesta del PAC tras upload de CSD. Persiste metadatos en FiscalProfile. |

### 5.2 `App\Services\SW\SWUserService`

**Archivo:** `app/Services/SW/SWUserService.php`

Servicio dedicado exclusivamente a la administración de subcuentas en SW Sapien Management V2.

#### Métodos públicos

| Método | Descripción |
|---|---|
| `createSubaccountForProfile(FiscalProfile $profile, string $email, string $password): void` | Crea sub-usuario en el PAC. POST `/management/v2/api/dealers/users`. Actualiza `sw_user_id` y `sw_account_email` en el perfil. |
| `listSubaccounts(): array` | GET para listar subcuentas (debug/reconciliación). |
| `deactivateSubaccount(FiscalProfile $profile): void` | PATCH para desactivar subcuenta (`isActive: false`). No elimina — solo desactiva. |

#### Endpoints Management V2

- **Crear:** `POST {management_endpoint}/management/v2/api/dealers/users`
- **Listar:** `GET {management_endpoint}/management/v2/api/dealers/users`
- **Desactivar:** `PATCH {management_endpoint}/management/v2/api/dealers/users/{userId}`

#### Payload de creación de subcuenta

```json
{
    "taxId": "<RFC>",
    "name": "<Razón Social>",
    "email": "<email>",
    "password": "<password>",
    "stamps": 10,
    "isUnlimited": false,
    "notificationEmail": "<email>",
    "phone": ""
}
```

#### Configuración

- `services.swsapien.management_endpoint` — Host Management V2 (default: `api.test.sw.com.mx`)
- `services.swsapien.token` — Token dealer permanente
- `services.swsapien.management_users_path` — Ruta del endpoint (default: `/management/v2/api/dealers/users`)
- `services.swsapien.default_stamps` — Timbres por defecto (default: 10)

---

## 6. Acciones (Actions)

### 6.1 `App\Actions\Billing\CreateInvoiceAction`

**Orquestador de creación de CFDI.** Una sola responsabilidad: ejecutar el caso de uso "crear factura".

```php
class CreateInvoiceAction
{
    public function __construct(private readonly SWSapienService $swService) {}

    public function execute(array $data, User $user): Invoice
    {
        return DB::transaction(function () use ($data, $user) {
            // 1. Persistir factura + partidas
            $invoice = $this->swService->createInvoice($data, $user->branch_id);

            // 2. Sincronizar datos fiscales del cliente (si aplica)
            if (!empty($data['customer_id'])) {
                $this->swService->syncCustomerFiscalData($data['customer_id'], $data);
            }

            // 3. Timbrar vía SW Sapien
            $this->swService->stamp($invoice);

            return $invoice->fresh('items');
        });
    }
}
```

**Flujo:**
1. `SWSapienService::createInvoice()` — Persiste en BD, calcula totales, asigna folio.
2. `SWSapienService::syncCustomerFiscalData()` — Actualiza modelo Customer si el cliente envió datos fiscales nuevos.
3. `SWSapienService::stamp()` — Construye payload CFDI 4.0, autentica subcuenta, envía a timbrar, guarda XML y UUID.

### 6.2 `App\Actions\Billing\CancelInvoiceAction`

```php
class CancelInvoiceAction
{
    public function execute(Invoice $invoice, string $cancellationReason, ?string $substitutionUuid): Invoice
    {
        // Validación: solo cancelar facturas certificadas
        // Delega en SWSapienService::cancel()
    }
}
```

### 6.3 `App\Actions\Billing\CreateFiscalSubaccountAction`

Orquestador ligero para vincular un `FiscalProfile` con una subcuenta SW Sapien. Manejo estructurado de errores (retorna array con `success`/`message` en lugar de lanzar excepciones).

### 6.4 `App\Actions\Billing\SaveBillingSettingsAction`

Guarda/actualiza `BillingSettings` por sucursal (upsert). Legacy — en proceso de ser reemplazado por `FiscalProfile`.

---

## 7. Form Requests (Validación)

### 7.1 `StoreInvoiceRequest`

**Archivo:** `app/Http/Requests/Billing/StoreInvoiceRequest.php`

Valida la creación de una factura CFDI 4.0.

**Reglas principales:**

| Campo | Reglas |
|---|---|
| `fiscal_profile_id` | required, exists:fiscal_profiles,id |
| `exportacion` | required, in:01,02,03,04 |
| `receiver_rfc` | required, size:13 |
| `receiver_legal_name` | required, max:255 |
| `receiver_tax_regime` | required, max:10 |
| `receiver_postal_code` | required, size:5 |
| `cfdi_use` | required, max:10 |
| `payment_form` | required, max:5 |
| `payment_method` | required, max:5 |
| `currency` | nullable, max:5 |
| `exchange_rate` | nullable, numeric, min:0.000001 |
| `items` | required, array, min:1 |
| `items.*.description` | required, max:255 |
| `items.*.quantity` | required, numeric, gt:0 |
| `items.*.unit_price` | required, numeric, min:0 |
| `items.*.sat_product_code` | required, max:15 |
| `items.*.sat_unit_code` | required, max:10 |
| `items.*.unit_name` | nullable, max:50 |
| `items.*.no_identificacion` | nullable, max:100 |
| `items.*.objeto_imp` | required, in:01,02,03 |
| `items.*.tax_type` | nullable, max:5 |
| `items.*.tax_rate` | nullable, numeric, 0–1 |
| `items.*.discount_amount` | nullable, numeric, min:0 |
| `items.*.retentions` | nullable, array |
| `items.*.retentions.*.type` | required_with, in:001,002 |
| `items.*.retentions.*.rate` | required_with, numeric, 0–1 |
| `items.*.retentions.*.amount` | required_with, numeric, min:0 |

**Permiso requerido:** `create invoices`

### 7.2 `CancelInvoiceRequest`

| Campo | Reglas |
|---|---|
| `cancellation_reason` | required, in:01,02,03,04 |
| `substitution_uuid` | required_if:cancellation_reason,01, uuid |

**Motivos de cancelación SAT:**
- `01` — Comprobante emitido con errores con relación (requiere UUID de sustitución)
- `02` — Comprobante emitido con errores sin relación
- `03` — No se llevó a cabo la operación
- `04` — Operación nominativa relacionada en factura global

**Permiso requerido:** `cancel invoices`

### 7.3 `StoreFiscalProfileRequest`

| Campo | Reglas |
|---|---|
| `rfc` | required, min:12, max:13 |
| `razon_social` | required, max:255 |
| `regimen_fiscal` | required, max:10 |
| `postal_code` | required, size:5 |
| `email` | required, email, max:255, unique:fiscal_profiles |

`prepareForValidation()`: Normaliza RFC a mayúsculas y trim.

**Permiso requerido:** `invoices.settings.access`

### 7.4 `SaveBillingSettingsRequest`

Similar a `StoreFiscalProfileRequest` pero para la tabla legacy `billing_settings`. Campos: `emitter_rfc`, `emitter_legal_name`, `emitter_tax_regime`, `emitter_postal_code`.

---

## 8. Controladores

### 8.1 `App\Http\Controllers\Billing\InvoiceController`

**Archivo:** `app/Http/Controllers/Billing/InvoiceController.php`

**Middleware (Spatie Permissions):**

| Método | Permiso requerido |
|---|---|
| `index` | `invoices.access` |
| `create`, `store` | `create invoices` |
| `show` | `invoices.see_details` |
| `edit`, `update` | `invoices.edit` |
| `cancel` | `cancel invoices` |
| `settings`, `dashboard` | `invoices.settings.access` |

**Métodos:**

| Método | Ruta | Descripción |
|---|---|---|
| `dashboard(Request): Response` | GET `/billing/dashboard` | KPI: total comprobantes, timbres usados, canceladas, monto facturado. Pasa `fiscalProfiles` a la vista. |
| `index(Request): Response` | GET `/billing/invoices` | Listado paginado con búsqueda (folio, receptor, RFC, UUID) y filtro por status. |
| `create(): Response` | GET `/billing/invoices/create` | Formulario de creación. Pasa `customers`, `fiscalProfiles` activos. |
| `store(StoreInvoiceRequest, CreateInvoiceAction): RedirectResponse` | POST `/billing/invoices` | Crea y timbra factura. Redirige a `show`. |
| `show(Invoice): Response` | GET `/billing/invoices/{invoice}` | Detalle de factura con items, customer, branch, fiscalProfile. |
| `pdf(Invoice): Response` | GET `/billing/invoices/{invoice}/pdf` | Genera y streamea PDF profesional CFDI 4.0 con `barryvdh/laravel-dompdf`. |
| `cancel(Invoice, CancelInvoiceRequest, CancelInvoiceAction): RedirectResponse` | POST `/billing/invoices/{invoice}/cancel` | Cancelación fiscal. |
| `settings(): Response` | GET `/billing/settings` | Lista de perfiles fiscales de la suscripción. |

**Métodos privados del controlador:**

| Método | Descripción |
|---|---|
| `extractTimbreData(Invoice): array` | Extrae datos del Timbre Fiscal Digital (UUID, FechaTimbrado, NoCertificadoSAT, SelloCFD, SelloSAT) del XML almacenado. Usa `simplexml_load_string()` con namespaces CFDI. |
| `groupTaxesByType(Invoice): array` | Agrupa impuestos trasladados por `Impuesto + TipoFactor + TasaOCuota` para el PDF. |
| `groupRetentionsByType(Invoice): array` | Agrupa retenciones por `Impuesto` para el PDF. |

### 8.2 `App\Http\Controllers\Billing\FiscalProfileController`

**Archivo:** `app/Http/Controllers/Billing/FiscalProfileController.php`

**Middleware:** `can:invoices.settings.access`

| Método | Ruta | Descripción |
|---|---|---|
| `storeFiscalProfile(StoreFiscalProfileRequest, SWUserService): RedirectResponse` | POST `/billing/settings/fiscal-profiles` | Crea perfil fiscal local Y aprovisiona subcuenta en el PAC en una transacción. Si el PAC falla, hace rollback del perfil local. |
| `uploadCsd(Request, SWSapienService): RedirectResponse` | POST `/billing/settings/fiscal-profiles/upload-csd` | Sube .cer y .key al PAC. Valida extensiones de archivo. Delega en `SWSapienService::uploadCsd()`. |
| `destroy(FiscalProfile): RedirectResponse` | DELETE `/billing/settings/fiscal-profiles/{fiscalProfile}` | Desactiva perfil (soft delete lógico — `is_active = false`). |

---

## 9. Rutas

**Archivo:** `routes/web/billing.php`

```php
Route::middleware(['auth', 'verified'])->prefix('billing')->name('billing.')->group(function () {

    // Dashboard — KPIs de facturación
    Route::get('/dashboard', [InvoiceController::class, 'dashboard'])->name('dashboard');

    // Invoices CRUD
    Route::prefix('invoices')->name('invoices.')->group(function () {
        Route::get('/',                    [InvoiceController::class, 'index'])->name('index');
        Route::get('/create',             [InvoiceController::class, 'create'])->name('create');
        Route::post('/',                  [InvoiceController::class, 'store'])->name('store');
        Route::get('/{invoice}/pdf',      [InvoiceController::class, 'pdf'])->name('pdf');
        Route::get('/{invoice}',          [InvoiceController::class, 'show'])->name('show');
        Route::post('/{invoice}/cancel',  [InvoiceController::class, 'cancel'])->name('cancel');
    });

    // Settings — Perfiles fiscales & CSD
    Route::prefix('settings')->name('settings.')->group(function () {
        Route::get('/',                                  [InvoiceController::class, 'settings'])->name('index');
        Route::post('/fiscal-profiles',                  [FiscalProfileController::class, 'storeFiscalProfile'])->name('storeFiscalProfile');
        Route::post('/fiscal-profiles/upload-csd',       [FiscalProfileController::class, 'uploadCsd'])->name('uploadCsd');
        Route::delete('/fiscal-profiles/{fiscalProfile}',[FiscalProfileController::class, 'destroy'])->name('destroyFiscalProfile');
    });
});
```

### Tabla completa de rutas

| Método | URI | Nombre | Controlador | Permiso |
|---|---|---|---|---|
| GET | `/billing/dashboard` | `billing.dashboard` | InvoiceController@dashboard | `invoices.settings.access` |
| GET | `/billing/invoices` | `billing.invoices.index` | InvoiceController@index | `invoices.access` |
| GET | `/billing/invoices/create` | `billing.invoices.create` | InvoiceController@create | `create invoices` |
| POST | `/billing/invoices` | `billing.invoices.store` | InvoiceController@store | `create invoices` |
| GET | `/billing/invoices/{invoice}/pdf` | `billing.invoices.pdf` | InvoiceController@pdf | — |
| GET | `/billing/invoices/{invoice}` | `billing.invoices.show` | InvoiceController@show | `invoices.see_details` |
| POST | `/billing/invoices/{invoice}/cancel` | `billing.invoices.cancel` | InvoiceController@cancel | `cancel invoices` |
| GET | `/billing/settings` | `billing.settings.index` | InvoiceController@settings | `invoices.settings.access` |
| POST | `/billing/settings/fiscal-profiles` | `billing.settings.storeFiscalProfile` | FiscalProfileController@storeFiscalProfile | `invoices.settings.access` |
| POST | `/billing/settings/fiscal-profiles/upload-csd` | `billing.settings.uploadCsd` | FiscalProfileController@uploadCsd | `invoices.settings.access` |
| DELETE | `/billing/settings/fiscal-profiles/{fiscalProfile}` | `billing.settings.destroyFiscalProfile` | FiscalProfileController@destroy | `invoices.settings.access` |

---

## 10. Frontend (Vue 3 / Inertia.js)

Todas las páginas usan `<script setup>` con Composition API, PrimeVue 4 para componentes UI, y Tailwind CSS con el sistema de diseño "Tesla UI".

### 10.1 `resources/js/Pages/Billing/Dashboard/Index.vue`

**Ruta:** `billing.dashboard`

**Props recibidas:**
- `fiscalProfiles: Array` — Perfiles fiscales de la suscripción
- `totalStampsUsed: Number` — Facturas certificadas
- `totalInvoices: Number` — Total de comprobantes
- `canceledInvoices: Number` — CFDI cancelados
- `totalAmount: Number` — Monto total facturado

**Funcionalidad:**
- 4 tarjetas KPI (comprobantes, timbres usados, canceladas, total facturado)
- Selector de rango de fechas (hoy, semana, mes, año, personalizado con DatePicker)
- Botón de exportar (pendiente de implementar)
- Lista de perfiles fiscales activos

### 10.2 `resources/js/Pages/Billing/Invoices/Index.vue`

**Ruta:** `billing.invoices.index`

**Props recibidas:**
- `invoices: Object` — Resultado paginado (usa DataTable de PrimeVue)
- `filters: Object` — search, status, sortField, sortOrder
- `hasFiscalProfiles: Boolean`

**Funcionalidad:**
- DataTable con todas las facturas (columna: Folio, Receptor, RFC, Total, Moneda, Status, Fecha)
- Búsqueda con debounce (400ms) por folio, nombre receptor, RFC o UUID
- Filtro por status (Tag)
- Status con dots pulsantes (verde = certificada, rojo = cancelada, ámbar = resto)
- Click en fila → navega al detalle (`billing.invoices.show`)
- Botón "Emitir factura" → `billing.invoices.create`
- Botón "Configuración fiscal" → `billing.settings.index`
- Paginación y ordenamiento

### 10.3 `resources/js/Pages/Billing/Invoices/Create.vue`

**Ruta:** `billing.invoices.create`

**Props recibidas:**
- `customers: Array` — Clientes de la sucursal (id, name, company_name, tax_id, tax_regime, address)
- `fiscalProfiles: Array` — Perfiles fiscales activos con subcuenta PAC (id, rfc, razon_social, regimen_fiscal, postal_code)
- `hasFiscalProfiles: Boolean`

**Funcionalidad:**
- Selector de perfil fiscal emisor (si solo hay uno, se autoselecciona)
- Selector de cliente (autocompleta RFC, razón social, régimen fiscal, CP)
- Datos del receptor: RFC, Razón social, Régimen fiscal, CP, Uso CFDI
- Configuración de pago: Forma de pago, Método de pago, Moneda, Tipo de cambio (si ≠ MXN)
- Exportación: 01 (default)
- Tabla de conceptos (items): descripción, cantidad, unidad SAT, precio unitario, ClaveProdServ, ObjetoImp, tasa IVA, descuento, retenciones
- Componente `InvoiceTotals` para ver desglose en tiempo real
- Composable `useInvoiceTaxes` para cálculos de impuestos
- Selectores con catálogos SAT precargados (UsoCFDI, FormaPago, MétodoPago, Moneda, RégimenFiscal, Exportación, ObjetoImp)
- Mock data para desarrollo cuando el backend devuelve colecciones vacías

### 10.4 `resources/js/Pages/Billing/Invoices/Show.vue`

**Ruta:** `billing.invoices.show`

**Props recibidas:**
- `invoice: Object` — Factura con relaciones (items, customer, branch, fiscalProfile)

**Funcionalidad:**
- Cabecera con folio, serie, status (Tag), UUID
- Panel de datos del emisor (RFC, razón social, régimen fiscal, CP)
- Panel de datos del receptor (RFC, nombre, régimen fiscal, CP, Uso CFDI)
- Panel de timbre fiscal (UUID, fecha timbrado, certificado SAT, PAC)
- Tabla de conceptos (DataTable con columnas: ClaveProdServ, Descripción, Cantidad, Unidad, Precio Unit., Subtotal, Descuento, IVA, Retenciones, Total)
- Totales globales
- Botones: XML (abre URL externa), PDF (abre en nueva pestaña), Solicitar cancelación (abre diálogo)
- Diálogo de cancelación: motivo (01-04) + UUID de sustitución (si motivo=01)
- Navegación: breadcrumb para volver atrás

### 10.5 `resources/js/Pages/Billing/Settings/Index.vue`

**Ruta:** `billing.settings.index`

**Props recibidas:**
- `fiscalProfiles: Array`

**Funcionalidad:**
- Tabla/listado de perfiles fiscales con: RFC, Razón Social, Régimen Fiscal, Email, Status (Activo/Pendiente PAC/Inactivo), Certificado (No. de serie, vigencia)
- Diálogo "Agregar perfil fiscal": formulario con RFC, Razón Social, Régimen Fiscal, CP, Email
- Diálogo "Subir CSD": upload de archivos .cer y .key + contraseña
- Botón de dar de baja (confirmación con PrimeVue ConfirmDialog)
- Estado del certificado: muestra número de serie, vigencia, botón para actualizar CSD

### 10.6 `resources/js/Pages/Billing/Invoices/Partials/InvoiceTotals.vue`

Componente reutilizable para mostrar el desglose financiero de una factura.

**Props:**
- `subtotal`, `ivaTrasladado`, `isrRetenido`, `ivaRetenido`, `granTotal` (Number)
- `retentionApplies`, `isResico` (Boolean)

**Muestra:**
- Subtotal
- IVA trasladado (si > 0)
- Banner de retenciones (si aplica, con distinción RESICO)
- ISR retenido (rojo, con −)
- IVA retenido (rojo, con −)
- Total (verde, grande)

---

## 11. Vista PDF (Blade)

**Archivo:** `resources/views/billing/invoices/pdf.blade.php`

Template Blade procesado por `barryvdh/laravel-dompdf` para generar la representación PDF profesional de un CFDI 4.0.

**Datos que recibe:**
- `invoice` — Modelo Invoice con relaciones cargadas (items, customer, branch.subscription.media, fiscalProfile)
- `timbre` — Array con UUID, FechaTimbrado, NoCertificadoSAT, RfcProvCertif, SelloCFD, SelloSAT
- `subtotal`, `discountTotal`, `taxesTotal`, `retainedTotal`, `total` — Valores numéricos
- `logoBase64` — Logo de la empresa en base64 (desde Spatie MediaLibrary)
- `groupedTransfers` — Impuestos trasladados agrupados
- `groupedRetentions` — Retenciones agrupadas

**Secciones del PDF:**
1. Header con logo + serie/folio + fecha
2. Paneles de datos: Emisor, Receptor, Timbre Fiscal
3. Tabla de conceptos
4. Totales globales
5. Cadena original y sellos digitales
6. Código QR (UUID)

---

## 12. Flujo completo de emisión de CFDI

```
┌─────────────────────────────────────────────────────────────────┐
│               FLUJO DE EMISIÓN DE CFDI 4.0                       │
└─────────────────────────────────────────────────────────────────┘

1. USUARIO hace click en "Emitir factura"
   │  GET /billing/invoices/create
   │
2. CONTROLLER (InvoiceController@create)
   │  Carga: customers de la sucursal, fiscalProfiles activos con sw_user_id
   │  Renderiza: Billing/Invoices/Create.vue
   │
3. USUARIO llena formulario y hace submit
   │  POST /billing/invoices
   │
4. FORM REQUEST (StoreInvoiceRequest)
   │  Valida: fiscal_profile_id, receiver_*, items[*], payment_*, etc.
   │  Permiso: create invoices
   │
5. ACTION (CreateInvoiceAction@execute)
   │  │
   │  ├─ 5a. SWSapienService::createInvoice()
   │  │      • Genera folio consecutivo
   │  │      • Crea registro Invoice (status = DRAFT)
   │  │      • Itera items, calcula subtotales/impuestos/retenciones
   │  │      • Crea InvoiceItem por cada concepto
   │  │      • Actualiza totales en Invoice
   │  │
   │  ├─ 5b. SWSapienService::syncCustomerFiscalData()
   │  │      • Si customer_id presente, actualiza tax_id, company_name,
   │  │        tax_regime, address.zip_code del Customer
   │  │
   │  └─ 5c. SWSapienService::stamp()
   │         • Carga relaciones (items, fiscalProfile)
   │         • buildPayload() → construye JSON CFDI 4.0
   │         • authenticateSubaccount() → token de subcuenta (cache 110 min)
   │         • POST {endpoint}/v3/cfdi33/issue/json/v4
   │         • Almacena XML en storage/app/public/invoices/xml/{uuid}.xml
   │         • Actualiza Invoice: uuid, xml_url, pdf_url, status=CERTIFIED, issued_at
   │
6. RESPUESTA
   │  Redirect → billing.invoices.show
   │  Flash: 'Factura creada correctamente.'
   │
7. USUARIO ve detalle de factura
   │  GET /billing/invoices/{invoice}
   │  Puede descargar XML, PDF, o cancelar
```

---

## 13. Flujo de cancelación de CFDI

```
1. USUARIO en Show.vue hace click en "Solicitar cancelación"
   │  Se abre diálogo con:
   │  • Motivo de cancelación (01-04)
   │  • UUID de sustitución (obligatorio si motivo = 01)
   │
2. FORM REQUEST (CancelInvoiceRequest)
   │  Valida: cancellation_reason ∈ {01,02,03,04}
   │         substitution_uuid requerido si motivo = 01
   │
3. ACTION (CancelInvoiceAction@execute)
   │  • Valida que la factura esté certificada
   │  • Carga fiscalProfile
   │  • Llama a SWSapienService::cancel()
   │
4. SWSapienService::cancel()
   │  • authenticateSubaccount() → token de subcuenta
   │  • POST {endpoint}/cfdi33/cancel/{rfc}/{uuid}/{motivo}/{folioSustitucion}
   │  • Actualiza Invoice: status=CANCELED, cancellation_reason, canceled_at
   │
5. RESPUESTA
   │  Redirect → billing.invoices.show
   │  Flash: 'Factura cancelada correctamente.'
```

---

## 14. Flujo de configuración fiscal (perfiles + CSD)

```
┌─────────────────────────────────────────────────────────────────┐
│          FLUJO DE CONFIGURACIÓN FISCAL                           │
└─────────────────────────────────────────────────────────────────┘

PASO 1: Crear perfil fiscal + subcuenta PAC
────────────────────────────────────────────
1. USUARIO en Settings/Index.vue → "Agregar perfil fiscal"
   │  Diálogo con: RFC, Razón Social, Régimen Fiscal, CP, Email
   │
2. POST /billing/settings/fiscal-profiles
   │
3. FISCAL PROFILE CONTROLLER (storeFiscalProfile)
   │  DB::beginTransaction()
   │  │
   │  ├─ Crea FiscalProfile local (password = Str::random(16))
   │  │
   │  └─ SWUserService::createSubaccountForProfile()
   │     • POST {management}/management/v2/api/dealers/users
   │     • Payload: taxId, name, email, password, stamps=10, isUnlimited=false
   │     • Extrae sw_user_id de respuesta (data.idUser)
   │     • Actualiza FiscalProfile: sw_user_id, sw_account_email
   │  │
   │  DB::commit()
   │
   │  Si falla → DB::rollback(), se elimina el perfil local
   │

PASO 2: Subir CSD (.cer + .key)
────────────────────────────────────────────
1. USUARIO en Settings/Index.vue → click en "Subir CSD" para un perfil
   │  Diálogo con: upload .cer, upload .key, password
   │
2. POST /billing/settings/fiscal-profiles/upload-csd
   │
3. FISCAL PROFILE CONTROLLER (uploadCsd)
   │  • Valida extensión de archivos (.cer, .key)
   │  • Verifica que el perfil tenga sw_user_id
   │  • Guarda archivos localmente (storage/csds/{profile_id}/)
   │  • Llama a SWSapienService::uploadCsd()
   │
4. SWSapienService::uploadCsd()
   │  • Convierte PEM → DER binario (extractDerFromPem)
   │  • Codifica DER como base64
   │  • authenticateSubaccount()
   │  • POST {endpoint}/certificates/save con {b64Key, b64Cer, password, type: "stamp"}
   │  • processCsdResponse():
   │    - extractCertificateData(): openssl_x509_parse() → certificate_number, valid_from, valid_to
   │    - Persiste en FiscalProfile
   │
```

---

## 15. Permisos (Spatie Laravel Permission)

Todos los permisos usan **kebab-case** y se verifican tanto en Form Requests (`authorize()`) como en el middleware de controladores.

| Permiso | Descripción | Ámbito |
|---|---|---|
| `invoices.access` | Ver listado de facturas | Lectura |
| `create invoices` | Crear nuevas facturas CFDI | Escritura |
| `invoices.see_details` | Ver detalle de una factura | Lectura |
| `invoices.edit` | Editar facturas | Escritura |
| `cancel invoices` | Cancelar facturas | Escritura |
| `invoices.settings.access` | Acceder a configuración fiscal (perfiles, CSD) | Admin |

---

## 16. Variables de entorno (.env)

```env
# SW Sapien — PAC (entorno de pruebas)
SW_SAPIEN_ENDPOINT="https://services.test.sw.com.mx"
SW_SAPIEN_MANAGEMENT_ENDPOINT="https://api.test.sw.com.mx"
SW_SAPIEN_TOKEN=T2lYQ0t4L0RHVkR4dHZ5Nkk1VHNEakZ3Y0J4Nk9GODZuRyt4cE1wVm5tbXB3YVZxTHdOdHAwVXY2NTdJb1hkREtXTzE3dk9pMmdMdkFDR2xFWFVPUXpTUm9mTG1ySXdZbFNja3FRa0RlYURqbzdzdlI2UUx1WGJiKzViUWY2dnZGbFloUDJ6RjhFTGF4M1BySnJ4cHF0YjUvbmRyWWpjTkVLN3ppd3RxL0dJPQ.T2lYQ0t4L0RHVkR4dHZ5Nkk1VHNEakZ3Y0J4Nk9GODZuRyt4cE1wVm5tbFlVcU92YUJTZWlHU3pER1kySnlXRTF4alNUS0ZWcUlVS0NhelhqaXdnWTRncklVSWVvZlFZMWNyUjVxYUFxMWFxcStUL1IzdGpHRTJqdS9Zakw2UGRIL2x4S242L0V5eURZWm9jNVBVSUk2WjU2YzVqajh6M0FEY3RPZDEybTNJZlAzbjF0TEkrVTFBWTRCelRGaGo5ZmpuWFJITGtiUWxOOXd3RDluOE11VTZwclRwZS9OL3BONnRTd3FnQ3l4cXhJallrLzAvZk14dzJmZXk0ekVYV28vM3BpUjZSYTJ6ZmVwYUNDbm83QVpGdE1GeHdwMC9QRVVRd2tJbXo5d2x1NWdsY0xGU3VYZ0tRNFJMUWh5b1JvWHVLMG5tWjRURHNqVjc5eWVRWGpzYkdFTnhQaThvZmVDUkMxL2lOSWd1MmVuMnVWS0RnOWZjMWEwSzZSUTVncHl1TVZ6RUw1QlhKK2R2T3IrODNNWFVlblZQMmtVWnNKMWhORGRWc05IZmJQZlRZUGV0UzZTdi94eGtMd3d3ZG53WWwrdFhRSERjTnVRK0VndzBwZjUvOWxUbmxqemN0ZUNrUTJwZGhYSENZWHRaczhkQzVDUG53Wi8ra0krN1Y.OUgpoQZFVs51Q3E0lgSnNSlS7gsJJltQsfRzEdcwfnM
```

**Para producción,** cambiar `test` por `api` en los endpoints:
- `SW_SAPIEN_ENDPOINT="https://services.sw.com.mx"`
- `SW_SAPIEN_MANAGEMENT_ENDPOINT="https://api.sw.com.mx"`

---

## 17. Resumen de archivos del módulo

### Backend (Laravel)

```
app/
├── Actions/Billing/
│   ├── CancelInvoiceAction.php          — Orquestador de cancelación
│   ├── CreateFiscalSubaccountAction.php — Orquestador de creación de subcuenta PAC
│   ├── CreateInvoiceAction.php          — Orquestador de creación de CFDI
│   └── SaveBillingSettingsAction.php    — Legacy: guardar billing_settings
│
├── Enums/
│   └── InvoiceStatus.php                — Estados de factura (DRAFT, PENDING, CERTIFIED, CANCELED, + legacy)
│
├── Http/
│   ├── Controllers/Billing/
│   │   ├── FiscalProfileController.php  — CRUD de perfiles fiscales + upload CSD
│   │   └── InvoiceController.php        — Dashboard, CRUD facturas, PDF, cancelación, settings
│   │
│   └── Requests/Billing/
│       ├── CancelInvoiceRequest.php     — Validación de cancelación
│       ├── SaveBillingSettingsRequest.php — Legacy: validación billing_settings
│       ├── StoreFiscalProfileRequest.php — Validación de nuevo perfil fiscal
│       └── StoreInvoiceRequest.php      — Validación de nueva factura CFDI
│
├── Models/
│   ├── Billing/
│   │   ├── FiscalProfile.php            — Perfil fiscal (RFC emisor vinculado a PAC)
│   │   └── Invoice.php                  — Factura CFDI 4.0
│   └── InvoiceItem.php                  — Partida/concepto de factura
│
├── Services/
│   ├── Billing/
│   │   └── SWSapienService.php          — Servicio central: timbrado, cancelación, CSD, payload, autenticación
│   └── SW/
│       └── SWUserService.php            — Administración de subcuentas Management V2
│
└── Traits/ (ninguno específico del módulo)
```

### Frontend (Vue 3 / Inertia)

```
resources/js/Pages/Billing/
├── Dashboard/
│   └── Index.vue                        — KPIs de facturación
├── Invoices/
│   ├── Create.vue                       — Formulario de nueva factura
│   ├── Index.vue                        — Listado de facturas (DataTable)
│   ├── Show.vue                         — Detalle de factura
│   └── Partials/
│       └── InvoiceTotals.vue            — Componente de totales
└── Settings/
    └── Index.vue                        — Perfiles fiscales + upload CSD
```

### Vistas Blade

```
resources/views/billing/invoices/
└── pdf.blade.php                        — Plantilla PDF profesional CFDI 4.0
```

### Rutas

```
routes/web/
└── billing.php                          — 11 rutas del módulo de facturación
```

### Migraciones (orden cronológico)

```
database/migrations/
├── 2026_06_12_000008_create_billing_settings_table.php
├── 2026_06_12_000009_create_invoices_table.php
├── 2026_06_12_000010_create_invoice_items_table.php
├── 2026_06_22_000000_add_fiscal_fields_to_customers.php
├── 2026_06_22_000001_add_logo_path_to_billing_settings.php
├── 2026_06_26_000001_create_fiscal_profiles_table.php
├── 2026_06_26_000002_add_fiscal_profile_id_to_invoices_table.php
├── 2026_06_26_000003_add_postal_code_to_fiscal_profiles_table.php
├── 2026_06_27_000001_add_email_password_to_fiscal_profiles_table.php
├── 2026_06_27_000002_add_csd_columns_to_fiscal_profiles_table.php
├── 2026_06_29_000001_add_cfdi_exportacion_and_retentions_to_invoices_and_items.php
├── 2026_06_29_000002_add_unit_name_and_sku_to_invoice_items.php
└── 2026_07_04_000001_add_exchange_rate_and_retentions_json.php
```

---

## Notas adicionales para desarrollo

### Reglas de nomenclatura
- **Rutas:** kebab-case con guiones (`billing.invoices.create`, `service-orders`)
- **Permisos:** kebab-case (`create invoices`, `cancel invoices`)
- **Archivos de ruta:** kebab-case (`billing.php`, `service-orders.php`)
- **Código:** Todo en inglés (clases, métodos, variables, comentarios)
- **UI:** Todo en español, sentence case (`"Guardar cambios"`, no `"Guardar Cambios"`)

### Principios SOLID aplicados
- **S:** Cada Action tiene una sola responsabilidad (un caso de uso)
- **O:** Los servicios son extensibles sin modificar (se inyectan por constructor)
- **L:** Los Form Requests extienden FormRequest y son intercambiables
- **I:** SWUserService y SWSapienService tienen interfaces separadas (management vs timbrado)
- **D:** Actions y Controllers dependen de abstracciones (servicios inyectados)

### Flujo de datos canónico
```
HTTP Request → Controller (thin) → FormRequest (validation) → Action (orchestrator) → Service(s) (business logic) → Model (persistence)
```

### Dependencias clave (composer)
- `laravel/framework` ^12.0
- `inertiajs/inertia-laravel` ^2.0
- `spatie/laravel-permission`
- `spatie/laravel-activitylog`
- `barryvdh/laravel-dompdf` (para PDF)

### Dependencias clave (npm)
- `@inertiajs/vue3` ^2.0
- `primevue` ^4.5.5
- `tailwindcss` ^3.4.0
- `vue` ^3.3.13
