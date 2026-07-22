# Investigación: Ajuste de Timbres por Perfil Fiscal — Panel de Suscriptores

> **Fecha:** 2026-07-22  
> **Objetivo:** Documentar con precisión el flujo actual de "Ajustar timbres" (agregar/retirar) dentro del panel de administración de Suscriptores, diagnosticar por qué no se refleja el ajuste.

---

## 1. Mapa de archivos involucrados

### Frontend — Vue 3 + Inertia

| Archivo | Rol |
|---|---|
| `resources/js/Pages/Admin/Subscriptions/Show.vue` | Página de detalle del suscriptor. Contiene la sección "Timbres por perfil fiscal" (cards de perfiles + botón "Ajustar timbres" + modal Dialog con el formulario de ajuste) y la tabla "Historial de movimientos". |
| `resources/js/Pages/Admin/Stamps/Adjust.vue` | Página independiente de ajuste manual (accesible vía menú admin). Similar al modal pero como página completa. Usa el mismo endpoint y misma estructura de datos. |

### Backend — Controladores

| Archivo | Rol |
|---|---|
| `app/Http/Controllers/Admin/AdminStampPurchaseController.php` | Controlador principal de timbres en el panel admin. Métodos: `index` (bandeja de revisión), `show`, `approve`, `reject`, `retry`, **`manualAdjustment`** (el endpoint del ajuste), `balance` (AJAX de saldo en vivo). |
| `app/Http/Controllers/Admin/SubscriptionController.php` | Controlador del detalle de suscriptor. En su método `show` carga los `fiscalProfiles` con saldo en vivo desde el PAC y los `allStampPurchases` (historial combinado). |

### Backend — Form Requests

| Archivo | Rol |
|---|---|
| `app/Http/Requests/Admin/StoreManualStampAdjustmentRequest.php` | Valida `fiscal_profile_id` (exists:fiscal_profiles), `stamp_quantity` (min:1, max:999999), `adjustment_type` (in:add,remove), `admin_note` (required, max:1000). |

### Backend — Actions

| Archivo | Rol |
|---|---|
| `app/Actions/Billing/CreateManualStampAdjustmentAction.php` | Crea el registro `StampPurchase` con `status=approved`, `payment_method=manual_adjustment`, `unit_price=0`, `amount_total=0`. Despacha `ApplyStampsToPacJob`. |

### Backend — Services

| Archivo | Rol |
|---|---|
| `app/Services/Billing/StampPurchaseService.php` | Lógica reutilizable de compras de timbres. Métodos clave: `calculatePrice`, `createPurchase`, **`applyStampsToPac`** (orquesta la llamada al PAC según tipo de ajuste), `checkMasterBalance` (verifica saldo de cuenta maestra). |
| `app/Services/SW/SWUserService.php` | Cliente HTTP para la API Management V2 de SW Sapien. Métodos: `createSubaccountForProfile`, `getStampsBalance`, **`addStampsToSubaccount`**, **`removeStampsFromSubaccount`**, `getMasterAccountBalance`, `deactivateSubaccount`, `listSubaccounts`. |

### Backend — Jobs

| Archivo | Rol |
|---|---|
| `app/Jobs/Billing/ApplyStampsToPacJob.php` | Job encolable (`ShouldQueue`). Idempotente. Al ejecutarse llama a `StampPurchaseService->applyStampsToPac()`. Configurado con `tries=3`, `backoff=30s`. |

### Backend — Modelos

| Archivo | Rol |
|---|---|
| `app/Models/Billing/StampPurchase.php` | Modelo de la tabla `stamp_purchases`. Registra cada movimiento de timbres (compra, transferencia o ajuste manual). Campos: `stamp_quantity`, `unit_price`, `amount_total`, `payment_method`, `status`, `admin_note`, `adjustment_type`, `pac_stamps_response_raw`, `stamps_applied_at`, etc. |
| `app/Models/Billing/FiscalProfile.php` | Modelo de `fiscal_profiles`. Representa un RFC bajo el cual se timbra. Campo clave: `sw_user_id` (ID de la subcuenta en SW Sapien). Relación: `hasMany StampPurchase`. |

### Backend — Enums

| Archivo | Rol |
|---|---|
| `app/Enums/StampPurchaseStatus.php` | `pending`, `awaiting_review`, `approved`, `rejected`, `failed`, `stamps_applied` |
| `app/Enums/StampAdjustmentType.php` | `add`, `remove` |
| `app/Enums/StampPaymentMethod.php` | `mercadopago`, `bank_transfer`, `manual_adjustment` |

### Rutas

| Archivo | Ruta relevante |
|---|---|
| `routes/web/super-admin.php` | `POST admin/stamps/manual-adjustment` → `AdminStampPurchaseController@manualAdjustment` (name: `admin.stamps.manual-adjustment`) |
| `routes/web/super-admin.php` | `GET admin/stamps/balance/{fiscalProfile}` → `AdminStampPurchaseController@balance` (name: `admin.stamps.balance`) |
| `routes/web/super-admin.php` | `GET admin/stamps/adjust` → renderiza `Admin/Stamps/Adjust.vue` (name: `admin.stamps.adjust-form`) |
| `routes/web/billing.php` | Rutas del lado del suscriptor para compra de timbres (`billing.fiscal-profiles.stamps.quote`, `.store`, `.return`) |

### Configuración

| Archivo | Rol |
|---|---|
| `config/services.php` (sección `swsapien`) | `endpoint` (services.test.sw.com.mx), `management_endpoint` (api.test.sw.com.mx), `token` (dealer), `default_stamps`, `mock`, `low_balance_threshold` |
| `.env` | `SW_SAPIEN_ENDPOINT`, `SW_SAPIEN_MANAGEMENT_ENDPOINT`, `SW_SAPIEN_TOKEN` |
| `.env` | `QUEUE_CONNECTION=database` |

### Migraciones

| Archivo | Rol |
|---|---|
| `database/migrations/2026_07_18_000002_create_stamp_purchases_table.php` | Schema de `stamp_purchases` |

---

## 2. Flujo paso a paso de "Ajustar timbres" tal como está hoy

### Paso 1: Superadmin ve la página de detalle del suscriptor

**`SubscriptionController@show`** (líneas ~192–243) carga los datos:

```php
// 10. Fiscal profiles with live stamp balances for the superadmin stamp section
$fiscalProfiles = $subscription->fiscalProfiles()
    ->with(['stampPurchases' => fn ($q) => $q->latest()->limit(50)])
    ->get();

$swUserService = app(SWUserService::class);
$fiscalProfilesData = $fiscalProfiles->map(function ($profile) use ($swUserService) {
    $balance = null;
    $balanceError = null;

    if ($profile->sw_user_id) {
        try {
            $balance = $swUserService->getStampsBalance($profile->sw_user_id);
        } catch (\Exception $e) {
            $balanceError = 'No se pudo consultar el saldo.';
        }
    }

    return [
        'id'             => $profile->id,
        'rfc'            => $profile->rfc,
        'razon_social'   => $profile->razon_social,
        'sw_user_id'     => $profile->sw_user_id,
        'is_active'      => $profile->is_active,
        'balance'        => $balance,
        'balanceError'   => $balanceError,
        'purchases'      => $profile->stampPurchases,
    ];
});

// Combined stamp purchase history for all profiles
$allStampPurchases = StampPurchase::whereIn('fiscal_profile_id', $fiscalProfiles->pluck('id'))
    ->with(['fiscalProfile', 'requestedBy', 'reviewedBy'])
    ->latest()
    ->limit(100)
    ->get();
```

**→ Lo que se envía al frontend:** `fiscalProfiles` (array con balance en vivo desde PAC), `allStampPurchases` (historial combinado).

### Paso 2: Vue renderiza las cards con el saldo en vivo y el botón "Ajustar timbres"

**`Show.vue`** (líneas ~465–530) renderiza para cada perfil fiscal una card con:
- Razón social y RFC
- Tag Activo/Inactivo
- Balance en 3 columnas (Disponibles, Usados, Asignados) — estos vienen **directamente de la consulta live al PAC**
- Si `profile.balanceError`, muestra el error en rojo
- Si no tiene `sw_user_id`, muestra "Sin subcuenta PAC vinculada"
- Botón "Ajustar timbres" (solo si `profile.is_active`)

```html
<Button
    v-if="profile.is_active"
    icon="pi pi-cog"
    label="Ajustar timbres"
    size="small"
    severity="secondary"
    class="!rounded-full w-full"
    @click="openStampModal(profile)"
/>
```

### Paso 3: Superadmin abre el modal y llena el formulario

**`Show.vue`** — `openStampModal(profile)` (líneas ~139–144):

```js
function openStampModal(profile) {
    selectedFiscalProfile.value = profile;
    stampAdjustmentType.value = 'add';
    stampQuantity.value = 1;
    stampAdminNote.value = '';
    showStampModal.value = true;
}
```

El modal (`<Dialog>`, líneas ~560–618) contiene:
- RadioButton: "Agregar timbres" / "Retirar timbres"
- InputNumber: cantidad (min 1, max 999999)
- Textarea: motivo del ajuste (obligatorio)
- Botones: Cancelar / Acción (el botón de acción está deshabilitado si `admin_note` está vacío)

### Paso 4: Superadmin da clic en "Agregar timbres" o "Retirar timbres"

**`Show.vue`** — `submitStampAdjustment()` (líneas ~146–161):

```js
function submitStampAdjustment() {
    if (!stampAdminNote.value.trim()) return;

    stampFormProcessing.value = true;
    router.post(route('admin.stamps.manual-adjustment'), {
        fiscal_profile_id: selectedFiscalProfile.value.id,
        stamp_quantity: stampQuantity.value,
        adjustment_type: stampAdjustmentType.value,
        admin_note: stampAdminNote.value,
    }, {
        preserveScroll: true,
        preserveState: false,
        onFinish: () => {
            stampFormProcessing.value = false;
            showStampModal.value = false;
        },
    });
}
```

**→ Endpoint:** `POST /admin/stamps/manual-adjustment`  
**→ Destino:** `AdminStampPurchaseController@manualAdjustment`

### Paso 5: El controlador recibe la petición

**`AdminStampPurchaseController@manualAdjustment`** (líneas ~119–161):

```php
public function manualAdjustment(
    StoreManualStampAdjustmentRequest $request,
    CreateManualStampAdjustmentAction $adjustmentAction,
    StampPurchaseService $stampPurchaseService,
): RedirectResponse {
    $user = Auth::user();

    $data = $request->validated();
    $data['requested_by_user_id'] = $user->id;

    $isRemoval = ($data['adjustment_type'] ?? 'add') === 'remove';
    $stampQuantity = (int) $data['stamp_quantity'];

    // Block "add" adjustments if master balance is insufficient.
    if (! $isRemoval) {
        $balanceCheck = $stampPurchaseService->checkMasterBalance($stampQuantity, false);

        if (! $balanceCheck['sufficient']) {
            return back()->with(
                'error',
                "Tu cuenta maestra tiene {$balanceCheck['stampsBalance']} timbres disponibles "
                . "y este ajuste requiere {$stampQuantity}. Recarga tu cuenta maestra en el portal de SW "
                . "antes de intentar de nuevo."
            );
        }
    }

    $purchase = $adjustmentAction->execute($data);

    $action = $isRemoval ? 'retiraron' : 'agregaron';

    return back()->with(
        'success',
        "Se {$action} {$purchase->stamp_quantity} timbres al perfil fiscal. Los cambios se reflejarán en breve."
    );
}
```

**→ Lo que hace:**
1. Valida con `StoreManualStampAdjustmentRequest`
2. Si es "add", verifica el saldo de la cuenta maestra contra el PAC (`checkMasterBalance`)
3. Si no hay saldo suficiente, devuelve error (flash)
4. Si hay saldo, delega en `CreateManualStampAdjustmentAction`

### Paso 6: El Action crea el registro y despacha el Job

**`CreateManualStampAdjustmentAction@execute`** (archivo completo):

```php
public function execute(array $data): StampPurchase
{
    $adjustmentType = $data['adjustment_type'] === 'remove'
        ? StampAdjustmentType::REMOVE
        : StampAdjustmentType::ADD;

    $purchase = StampPurchase::create([
        'fiscal_profile_id'    => $data['fiscal_profile_id'],
        'requested_by_user_id' => $data['requested_by_user_id'],
        'stamp_quantity'       => $data['stamp_quantity'],
        'unit_price'           => 0,
        'amount_total'         => 0,
        'payment_method'       => StampPaymentMethod::MANUAL_ADJUSTMENT,
        'status'               => StampPurchaseStatus::APPROVED,
        'admin_note'           => $data['admin_note'],
        'adjustment_type'      => $adjustmentType,
        'reviewed_by_user_id'  => $data['requested_by_user_id'],
        'reviewed_at'          => now(),
    ]);

    // Dispatch job to apply stamps to PAC (idempotent, queued)
    ApplyStampsToPacJob::dispatch($purchase->id);

    return $purchase;
}
```

**→ Lo que hace:**
1. Crea un registro en `stamp_purchases` con:
   - `status = 'approved'` (se salta la revisión)
   - `payment_method = 'manual_adjustment'`
   - `unit_price = 0`, `amount_total = 0`
   - `admin_note` con el motivo
   - `adjustment_type = 'add'` o `'remove'`
   - `reviewed_by_user_id` = el mismo superadmin
   - `reviewed_at = now()`
   - ⚠️ **`stamps_applied_at` queda NULL** — los timbres NO se han aplicado todavía al PAC
2. Despacha `ApplyStampsToPacJob` a la cola

### Paso 7: El controlador responde con redirect + flash message

```php
return back()->with(
    'success',
    "Se {$action} {$purchase->stamp_quantity} timbres al perfil fiscal. Los cambios se reflejarán en breve."
);
```

### Paso 8: El frontend recarga la página

Como `preserveState: false`, Inertia hace una visita completa al servidor. El servidor vuelve a ejecutar `SubscriptionController@show`, que:
1. Vuelve a consultar el saldo en vivo desde el PAC para cada perfil
2. Vuelve a cargar `allStampPurchases` (que ahora incluye el nuevo registro de ajuste)

### Paso 9: El Job (teóricamente) se ejecuta

**`ApplyStampsToPacJob@handle`:**

```php
public function handle(StampPurchaseService $stampPurchaseService): void
{
    $purchase = StampPurchase::find($this->stampPurchaseId);

    if (! $purchase) {
        Log::warning('ApplyStampsToPacJob: stamp purchase not found', [...]);
        return;
    }

    // Idempotency: if already applied, skip.
    if ($purchase->isStampsApplied()) {
        Log::info('ApplyStampsToPacJob: stamps already applied — skipping', [...]);
        return;
    }

    $stampPurchaseService->applyStampsToPac($purchase);
}
```

**`StampPurchaseService@applyStampsToPac`:**

```php
public function applyStampsToPac(StampPurchase $purchase): void
{
    if ($purchase->isStampsApplied()) { return; }

    $fiscalProfile = $purchase->fiscalProfile;

    if (! $fiscalProfile || ! $fiscalProfile->sw_user_id) {
        throw new \RuntimeException(
            "El perfil fiscal #{$purchase->fiscal_profile_id} no tiene una subcuenta PAC vinculada."
        );
    }

    $comment = $this->buildPacComment($purchase);

    if ($purchase->isManualAdjustment() && $purchase->adjustment_type?->value === 'remove') {
        $response = $this->swUserService->removeStampsFromSubaccount(
            $fiscalProfile->sw_user_id,
            $purchase->stamp_quantity,
            $comment,
        );
    } else {
        $response = $this->swUserService->addStampsToSubaccount(
            $fiscalProfile->sw_user_id,
            $purchase->stamp_quantity,
            $comment,
        );
    }

    $purchase->update([
        'pac_stamps_response_raw' => $response,
        'stamps_applied_at'       => now(),
        'status'                  => StampPurchaseStatus::STAMPS_APPLIED,
    ]);
}
```

**→ Si el Job se ejecuta correctamente:**
1. Llama a `SWUserService->addStampsToSubaccount()` o `removeStampsFromSubaccount()`
2. Estos métodos hacen HTTP POST/DELETE a `https://api.test.sw.com.mx/management/v2/api/dealers/users/{swUserId}/stamps`
3. Usan el token dealer de `SW_SAPIEN_TOKEN`
4. En éxito, actualizan el registro con `status = 'stamps_applied'`, `stamps_applied_at = now()`, y guardan la respuesta del PAC en `pac_stamps_response_raw`

---

## 3. Integración con el PAC (SW Sapien)

### Sí existe. Está completamente implementada.

**`SWUserService`** (`app/Services/SW/SWUserService.php`) contiene:

#### `addStampsToSubaccount` (líneas ~310–345)
```php
public function addStampsToSubaccount(string $swUserId, int $quantity, ?string $comment = null): array
{
    $endpoint = $this->resolveManagementEndpoint();
    $token    = config('services.swsapien.token');
    // ...
    $url = rtrim($endpoint, '/') . '/management/v2/api/dealers/users/' . $swUserId . '/stamps';
    $payload = ['stamps' => $quantity];
    if ($comment) { $payload['comment'] = $comment; }

    $response = Http::withHeaders([
        'Authorization' => 'Bearer ' . $token,
        'Content-Type'  => 'application/json',
        'Accept'        => 'application/json',
    ])->post($url, $payload);
    // ...
}
```

#### `removeStampsFromSubaccount` (líneas ~355–395)
```php
public function removeStampsFromSubaccount(string $swUserId, int $quantity, ?string $comment = null): array
{
    // ...
    $url = rtrim($endpoint, '/') . '/management/v2/api/dealers/users/' . $swUserId . '/stamps';
    $payload = ['stamps' => $quantity];
    if ($comment) { $payload['comment'] = $comment; }

    $response = Http::withHeaders([...])->delete($url, $payload);
    // ...
}
```

#### `getMasterAccountBalance` (líneas ~430–470)
```php
public function getMasterAccountBalance(): array
{
    $url = rtrim($endpoint, '/') . '/management/v2/api/users/balance';
    // GET con Bearer token dealer
    // Retorna { stampsBalance, stampsUsed, stampsAssigned, ... }
}
```

#### `resolveManagementEndpoint` (líneas ~408–413)
```php
private function resolveManagementEndpoint(): string
{
    return config('services.swsapien.management_endpoint')
        ?: config('services.swsapien.endpoint', 'https://services.test.sw.com.mx');
}
```

### Configuración en `.env`:
```
SW_SAPIEN_ENDPOINT="https://services.test.sw.com.mx"
SW_SAPIEN_MANAGEMENT_ENDPOINT="https://api.test.sw.com.mx"
SW_SAPIEN_TOKEN=T2lYQ0t4L0RHVkR4dHZ5Nkk1VHNEakZ3Y0J4Nk9GODZuRyt4cE1wVm5tbXB3YVZxTHdOdHAwVXY2NTdJb1hkREtXTzE3dk9pMm...
```

### Conclusión sobre el PAC:

**La integración existe y está correctamente cableada.** `addStampsToSubaccount` y `removeStampsFromSubaccount` están implementados, y el `StampPurchaseService->applyStampsToPac()` decide correctamente cuál llamar según el `adjustment_type`. **Pero estas llamadas solo se ejecutan dentro del Job encolado (`ApplyStampsToPacJob`), no de forma síncrona durante la petición HTTP.**

---

## 4. Relación con la tabla `stamp_purchases`

### Schema completo (`database/migrations/2026_07_18_000002_create_stamp_purchases_table.php`):

```php
Schema::create('stamp_purchases', function (Blueprint $table) {
    $table->id();
    $table->foreignId('fiscal_profile_id')->constrained('fiscal_profiles')->cascadeOnDelete();
    $table->foreignId('requested_by_user_id')->constrained('users');
    $table->unsignedInteger('stamp_quantity');
    $table->decimal('unit_price', 10, 4);
    $table->decimal('amount_total', 10, 2);
    $table->foreignId('pricing_tier_id')->nullable()->constrained('stamp_pricing_tiers')->nullOnDelete();
    $table->string('payment_method');   // mercadopago, bank_transfer, manual_adjustment
    $table->string('status');           // pending, awaiting_review, approved, rejected, failed, stamps_applied
    $table->string('mp_payment_id')->nullable();
    $table->string('mp_preference_id')->nullable();
    $table->string('proof_file_path')->nullable();
    $table->timestamp('proof_uploaded_at')->nullable();
    $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamp('reviewed_at')->nullable();
    $table->string('rejection_reason')->nullable();
    $table->json('pac_stamps_response_raw')->nullable();
    $table->timestamp('stamps_applied_at')->nullable();
    $table->string('admin_note')->nullable();
    $table->string('adjustment_type')->nullable();  // add, remove — solo manual_adjustment
    $table->timestamps();

    $table->index('fiscal_profile_id');
    $table->index('status');
    $table->index('payment_method');
});
```

### ¿El ajuste manual crea un registro en esta tabla?

**Sí.** El `CreateManualStampAdjustmentAction` crea un registro con:
- `payment_method = 'manual_adjustment'`
- `status = 'approved'`
- `unit_price = 0`, `amount_total = 0`
- `admin_note` = motivo ingresado
- `adjustment_type = 'add'` o `'remove'`
- `reviewed_by_user_id` = el superadmin
- `reviewed_at = now()`
- **`stamps_applied_at = null`** (se llena cuando el Job se ejecuta exitosamente)
- **`pac_stamps_response_raw = null`** (se llena cuando el Job se ejecuta exitosamente)

**Esta tabla es un audit trail, NO un ledger de balance.** El balance autoritativo siempre se consulta en vivo desde el PAC.

---

## 5. Relación con "Settings de Billing" (perfiles fiscales, configuración del suscriptor)

### ¿Comparten el mismo modelo `FiscalProfile`?

**Sí.** Tanto el panel de administración como el panel del suscriptor usan el mismo modelo `App\Models\Billing\FiscalProfile`. La tabla `fiscal_profiles` es compartida.

### Flujo del lado del suscriptor

El suscriptor accede a:
- `resources/js/Pages/Billing/Settings/Index.vue` → lista de perfiles fiscales, subida de CSD, logo, manifest
- `resources/js/Pages/Billing/Settings/Show.vue` → detalle de un perfil fiscal con opción de comprar timbres
- `resources/js/Pages/Billing/Settings/Partials/PurchaseStampsModal.vue` → modal de compra de timbres (vía MercadoPago/transferencia)

Rutas del suscriptor:
- `routes/web/billing.php` → prefijo `billing/fiscal-profiles/`

### ¿Hay algún servicio compartido?

**Sí.** `SWUserService` y `StampPurchaseService` son servicios compartidos usados tanto por el panel admin como por el lado del suscriptor (a través de `StampPurchaseController` del lado billing).

### ¿Qué token/credenciales de autenticación con SW se usan?

**Siempre el token DEALER (`SW_SAPIEN_TOKEN`).** No hay tokens por suscriptor ni por perfil fiscal. Toda la comunicación con el PAC (crear subcuentas, consultar saldos, agregar/retirar timbres) se autentica con el token dealer configurado en `.env`. Esto es correcto según la arquitectura de SW Sapien Management V2: el dealer administra las subcuentas con su propio token.

### ¿Están conectados o desconectados?

Están **conectados a nivel de datos** (misma tabla `fiscal_profiles`, mismo modelo), pero **desconectados a nivel de UI**:
- El panel admin (`Admin/Subscriptions/Show.vue`) muestra balances y permite ajustes manuales (superadmin)
- El panel del suscriptor (`Billing/Settings/Index.vue`) permite gestionar perfiles, CSD, logos y comprar timbres
- Ambos escriben en `stamp_purchases` y ambos dependen del PAC para el balance real

---

## 6. Diagnóstico de por qué no se refleja el ajuste

### Hipótesis principal: El queue worker no está corriendo

**`QUEUE_CONNECTION=database`** en `.env` significa que los jobs se almacenan en la tabla `jobs` de la base de datos y requieren un proceso `php artisan queue:work` ejecutándose para ser procesados.

**El flujo completo depende de que `ApplyStampsToPacJob` se ejecute:**

```
Frontend → Controller → Action (crea StampPurchase status=approved) → dispatch(ApplyStampsToPacJob)
                                                                              ↓
                                                                     ⚠️ JOB NUNCA SE EJECUTA
                                                                     (no hay queue worker)
                                                                              ↓
                                                                     stamps_applied_at = NULL
                                                                     status = 'approved' (no 'stamps_applied')
                                                                     PAC nunca recibe la llamada
```

### Evidencia que apunta a esta hipótesis:

1. **El registro en `stamp_purchases` se crea** (con `status='approved'`, `stamps_applied_at=null`), por lo que el historial de movimientos en la UI SÍ debería mostrar el nuevo registro (si la página se recarga).

2. **El balance mostrado en la UI viene de una consulta LIVE al PAC** (`getStampsBalance` en `SubscriptionController@show`). Como el job nunca corrió, el PAC nunca recibió la orden de agregar/retirar timbres, por lo que el balance sigue igual.

3. **Cuando el superadmin vuelve a entrar a la página**, el servidor consulta el PAC en vivo y devuelve el mismo balance de siempre. La tabla de historial muestra el registro con status "Aprobado" (no "Acreditado"), lo cual es otra pista.

4. **No hay llamada síncrona al PAC** en el flujo del ajuste manual. Todo el apply de timbres está delegado al Job. Si el Job no corre, nunca se aplican.

### Hipótesis secundarias (menos probables pero posibles):

1. **`sw_user_id` es null en el perfil fiscal:** Si el perfil no tiene subcuenta PAC vinculada, `applyStampsToPac` lanza `RuntimeException`. El job fallaría 3 veces y quedaría en `failed_jobs`. El registro en `stamp_purchases` se creó igual (con `status='approved'`), pero el balance nunca cambia.

2. **Error de red/API del PAC:** Si `api.test.sw.com.mx` no es accesible o el token es inválido, el job falla en los 3 intentos. Mismo resultado: registro creado, balance sin cambios.

3. **La página NO se recarga correctamente:** Si por alguna razón `preserveState: false` no fuerza la recarga completa, el frontend podría estar mostrando datos stale. Pero incluso si se recarga, el balance del PAC no habrá cambiado porque el job no corrió.

### Diagnóstico concreto:

> **El botón "Ajustar timbres" SÍ crea un registro en `stamp_purchases` con `status='approved'` y `stamps_applied_at=null`. El problema es que la aplicación real de timbres al PAC está delegada a un Job encolado (`ApplyStampsToPacJob`) que requiere un queue worker (`php artisan queue:work`) para ejecutarse. Con `QUEUE_CONNECTION=database` y sin worker corriendo, los jobs se acumulan en la tabla `jobs` pero nunca se procesan.**
>
> **Adicionalmente, el balance que se muestra en la UI se consulta EN VIVO desde el PAC (`getStampsBalance`), no desde la base de datos local. Por lo tanto, aunque el registro local en `stamp_purchases` se cree correctamente, el balance mostrado nunca cambia porque el PAC nunca recibe la instrucción de modificar los timbres.**

### Cómo verificarlo:

1. Revisar la tabla `jobs` de la base de datos — debería haber registros de `ApplyStampsToPacJob` acumulados.
2. Revisar la tabla `stamp_purchases` — buscar registros con `payment_method='manual_adjustment'` y `status='approved'` pero `stamps_applied_at=null`.
3. Revisar `storage/logs/laravel.log` — si el job se ejecutó y falló, habrá entradas de `ApplyStampsToPacJob: stamps already applied — skipping` o errores de PAC.
4. Ejecutar `php artisan queue:work` manualmente y observar si los jobs pendientes se procesan.

