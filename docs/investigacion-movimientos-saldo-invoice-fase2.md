# Investigación: `stamp_movements`, saldo de timbres y estados de `Invoice` (solo lectura)

> **Fecha:** 2026-08-11
> **Alcance:** Tres piezas puntuales de información, sin modificar código, con referencias a archivo:línea. Contexto: diseño del mecanismo de reserva de timbres (Fase 2).
> **Nota:** No se modificó ningún archivo de código; este documento es solo lectura.

---

## 1. Migración `stamp_movements` + `StampMovementService` + `StampMovementObserver`

### 1.1 Migración `database/migrations/2026_07_24_000001_create_stamp_movements_table.php` (contenido completo)

```php
public function up(): void
{
    Schema::create('stamp_movements', function (Blueprint $table) {
        $table->id();
        $table->foreignId('fiscal_profile_id')
            ->constrained('fiscal_profiles')
            ->cascadeOnDelete();
        $table->string('type');               // 'entry' | 'exit'
        $table->string('description');         // Human-readable in Spanish
        $table->integer('quantity');           // Always positive
        $table->integer('balance_after');      // Running balance after this movement
        $table->nullableMorphs('reference');   // Links to StampPurchase, Invoice, etc.
        $table->json('metadata')->nullable();  // Extra info (payment_method, status, amount_total, etc.)
        $table->timestamps();

        $table->index('fiscal_profile_id');
        $table->index('created_at');
    });
}
```

| Columna | Tipo | Notas | Línea |
|---|---|---|---|
| `id` | bigint PK | | `:9` |
| `fiscal_profile_id` | FK `fiscal_profiles` (`cascadeOnDelete`) | **eje del ledger: por perfil fiscal** | `:13-14` |
| `type` | string | `'entry'` \| `'exit'` | `:15` |
| `description` | string | texto en español | `:16` |
| `quantity` | integer | siempre positivo | `:17` |
| `balance_after` | integer | saldo corrido tras el movimiento | `:19` |
| `reference` | nullableMorphs | `reference_type` + `reference_id` → `StampPurchase`, `Invoice`, etc. | `:20` |
| `metadata` | json nullable | payment_method, status, amount_total, etc. | `:21` |
| `created_at` / `updated_at` | timestamps | | `:22` |
| índices | | `fiscal_profile_id` (`:24`), `created_at` (`:25`) | |

### 1.2 `StampMovementService` — `app/Services/Billing/StampMovementService.php`

- **Propósito:** backfill (reconstrucción histórica) del ledger, idempotente.
- `backfillAll()` (`:21-38`): recorre `FiscalProfile` en chunks de 50 y llama `backfillForProfile()`.
- `backfillForProfile(FiscalProfile $profile)` (`:42-156`):
  - **Salta si el perfil ya tiene movimientos** (`:48-51`).
  - Arma eventos cronológicos desde: (1) `StampPurchase` con `status = stamps_applied` (entradas; `adjustment_type=remove` → salida) y (2) `Invoice` `CERTIFIED` con `fecha_timbrado` (salidas, **1 timbre por factura**) (`:55-83`).
  - Ordena por fecha y mantiene un `$runningBalance` **local por `fiscal_profile_id`**, calculando `balance_after` (`:86-154`).
- **Sobre qué entidad calcula `balance_after`:** `fiscal_profile_id` (el único eje del ledger; no existe `pac_account` en el esquema).
- **Tipos de movimiento que soporta:** solo `entry`/`exit` (compra/depósito/ajuste y timbrado de factura). **NO** hay tipo `reserva`/`hold`.

### 1.3 `StampMovementObserver` — `app/Observers/Billing/StampMovementObserver.php`

**Registro:** `app/Providers/AppServiceProvider.php:77-78` — observa **dos** modelos:
```php
StampPurchase::observe(StampMovementObserver::class);   // L77
Invoice::observe(StampMovementObserver::class);          // L78
```

**Eventos que disparan movimientos:**
| Evento | Condición | Acción | Línea |
|---|---|---|---|
| `StampPurchase::created` | `status = stamps_applied` y no existe movimiento | `recordEntryFromPurchase()` | `:27-33` |
| `StampPurchase::updated` | `isDirty('status')` y pasa a `stamps_applied` | `handlePurchaseApplied()` | `:43-48` |
| `Invoice::created` | `status = CERTIFIED` y no existe movimiento | `recordExitFromInvoice()` | `:34-40` |
| `Invoice::updated` | `isDirty('status')`, `CERTIFIED` y no existe movimiento | `recordExitFromInvoice()` | `:49-56` |

- **Cálculo de `balance_after`:** `calculateNextBalance(int $fiscalProfileId, int $delta)` (`:148-156`) → toma el último `balance_after` de `stamp_movements` para **ese `fiscal_profile_id`** (`latest('id')`) y suma el delta. **Siempre por `fiscal_profile_id`.**
- **Tipos:** `entry` (compra/depósito/ajuste add) y `exit` (timbrado de factura = **1**, o ajuste remove). **Solo `entry`/`exit` — no hay reserva.**
- **Idempotencia:** `movementExists()` (`:58-63`) consulta `reference_type` + `reference_id`; con `getKey()`.
- **Caso especial transferencia:** `handlePurchaseApplied()` (`:160-193`) detecta un movimiento previo con `metadata.status = pending` (flujo `bank_transfer`), actualiza su `balance_after` y **propaga el delta** a los movimientos posteriores con `bumpSubsequentBalances()` (`:195-202`, `UPDATE balance_after + delta`).
- `RejectStampPurchaseAction` (`app/Actions/Billing/RejectStampPurchaseAction.php:26`) marca el movimiento pendiente como rechazado (los timbres nunca cuentan para el saldo).

> **Conclusión Fase 2:** para reservas de timbres no hay nada reutilizable como tipo de movimiento; habría que añadir un tipo (`reserve`/`hold`) o tabla/columna aparte, y el `balance_after` hoy es **por `fiscal_profile_id`** (que mapea a la subcuenta `sw_user_id`); una "cuenta normal" requeriría redefinir el eje.

---

## 2. ¿Existe saldo cacheado o el saldo se calcula en tiempo real?

- **NO existe campo de saldo cacheado** (`stamps_balance`, `balance`, etc.) en `fiscal_profiles` ni en ninguna tabla relacionada con timbres. Verificación por grep en migraciones: la única columna `balance_after` de timbres está en `stamp_movements` (`2026_07_24_000001...:19`); el otro `balance_after` pertenece a `customer_balance_movements` (`2025_08_06_120450...:17`), que es otra feature (saldo de clientes). Las 6 migraciones de `fiscal_profiles` no definen ninguna columna de saldo.
- **El saldo autoritativo SIEMPRE se consulta EN VIVO al PAC**, vía:
  - `SWUserService::getStampsBalance(sw_user_id)` — `app/Services/SW/SWUserService.php:240` (`GET .../dealers/balance/users/{id}` con token dealer).
  - `SWUserService::getMasterAccountBalance()` — `:426` (`GET .../users/balance`).
- **`stamp_movements.balance_after` es un ledger cronológico de auditoría** para el historial de la UI, **no** la fuente del saldo mostrado. Así lo documenta el modelo `app/Models/Billing/StampMovement.php:16-18`:
  > "The authoritative balance is always live from the PAC (SW Sapien). This table provides a chronological ledger for the UI."

**Dónde se lee el saldo hoy (todos en vivo al PAC, ninguno suma `stamp_movements`):**
| Lugar | Línea | Qué hace |
|---|---|---|
| `app/Http/Controllers/Billing/FiscalProfileController.php` | `:245` | `show()` → saldo en vivo del perfil |
| ídem | `:265` | ledger `stampMovements()->latest()->paginate(15)` (solo historial UI) |
| `app/Http/Controllers/Billing/InvoiceController.php` | `:80`, `:568` | dashboard y settings → saldo en vivo por perfil |
| `app/Http/Controllers/Admin/AdminStampDashboardController.php` | `:39`, `:136` | saldo maestra en vivo |
| ídem | `:73`, `:152`, `:250` | saldo por subcuenta en vivo |
| `app/Http/Controllers/Admin/SubscriptionController.php` | `:230` | saldo por perfil (superadmin) |
| `app/Jobs/Billing/RefreshStampGlobalStatsJob.php` | `:49-52` | stats globales desde PAC (corre cada 30 min, `routes/console.php:8`) |
| `app/Services/Billing/StampPurchaseService.php` | `:165-175` | `checkMasterBalance()` antes de comprar timbres |

> **Conclusión Fase 2:** si el mecanismo de reserva necesita un saldo "disponible menos reservado" sin depender del PAC en cada lectura, no hay campo ni suma local que lo provea hoy; habrá que decidir entre (a) columna `stamps_reserved`/`balance` cacheada en `fiscal_profiles`, o (b) derivarlo del ledger con un tipo `reserve`. Hoy el sistema no lleva contabilidad local del saldo.

---

## 3. Enum de estados de `Invoice` y columna `status` en la migración

### 3.1 Enum completo — `app/Enums/InvoiceStatus.php`
```php
enum InvoiceStatus: string
{
    // --- Subscription-payment invoice lifecycle (existing) ---
    case NOT_REQUESTED = 'no_solicitada';        // L8
    case REQUESTED = 'solicitada';               // L9
    case GENERATED = 'generada';                 // L10

    // --- CFDI 4.0 lifecycle ---
    case DRAFT = 'borrador';                     // L13
    case PENDING = 'pendiente';                  // L14
    case CERTIFIED = 'certificada';              // L15
    case CANCELATION_PENDING = 'cancelacion_pendiente'; // L16
    case CANCELED = 'cancelada';                 // L17
}
```
8 casos en total (3 del ciclo de facturas de suscripción + 5 del ciclo CFDI 4.0). Se castea en el modelo con `'status' => InvoiceStatus::class` (`app/Models/Billing/Invoice.php:80`).

### 3.2 Columna `status` de la tabla `invoices`
Archivo: `database/migrations/2026_06_12_000009_create_invoices_table.php`
- **`L19`:** `$table->string('status')->default(\App\Enums\InvoiceStatus::DRAFT->value);` → columna **string** (sin enum nativo en BD), default `'borrador'`.
- **`L55`:** `$table->index('status');` → solo índice, sin constraint de valores (la validación de valores vive en el enum de la app).

---

*Fin del informe. Sin cambios de código; solo lectura + este documento.*
