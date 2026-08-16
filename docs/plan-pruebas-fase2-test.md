# Plan de pruebas — Fase 2 en ambiente TEST (Conectia Sandbox)

> Alcance: valida en **TEST** todo lo implementado en la Fase 2 del módulo de facturación:
> **Archivo 1** (cuentas PAC normal vs subcuenta) — Fase 2.6 pendiente de ambiente real.
> **Archivo 2** (reserva de timbres con `customid`) — Fase 2.13 E2E con el PAC real.
>
> Los flujos internos ya están cubiertos por tests automatizados
> (`tests/Feature/Billing/StampReservationFlowTest.php`, 11 tests / 39 assertions — PASS).
> Este plan ejecuta la **parte que no se puede simular**: la interacción con SW Sapien Sandbox.

---

## 0. Prerrequisitos

- Ambiente **TEST** con base MySQL apuntando a SW Sapien **sandbox** (`services.test.sw.com.mx`).
- Credenciales sandbox disponibles:
  - **Cuenta normal**: `sandbox@conectia.mx` (proporcionada por el reseller/conectia).
  - **Subcuentas**: generadas automáticamente por la API bajo el dealer maestro.
- Archivo **`.cer` + `.key` (CSD)** de prueba válido para timbrar en sandbox.
- Ejecutar migraciones pendientes y el backfill ANTES de probar:
  ```bash
  php artisan migrate
  php artisan pac-accounts:backfill
  ```
- Verificar que el job de reconciliación está programado:
  ```bash
  php artisan schedule:list   # debe mostrar reconcile:normal-account-balances a las 04:00
  ```

---

## PARTE A — Archivo 1 (Fase 2.6): cuentas PAC normal vs subcuenta

### A.1 Backfill de cuentas PAC
1. Ejecutar `php artisan pac-accounts:backfill --dry-run` y confirmar que detecta los perfiles fiscales existentes.
2. Ejecutar `php artisan pac-accounts:backfill` (sin `--dry-run`).
3. **Esperado**: cada perfil fiscal con credenciales legacy (`sw_user_id`) tiene su `pac_accounts` de tipo `subaccount` / estado `active`, y `fiscal_profiles.pac_account_id` poblado.
4. Re-ejecutar el comando → no debe duplicar nada (idempotente).

### A.2 Activación de cuenta normal con credenciales reales
1. En el panel **Admin → Timbres → Cuentas PAC**, ver la cuenta `pending_request` generada al dar de alta un perfil fiscal.
2. Pulsar **"Activar"** e ingresar las credenciales sandbox (`sandbox@conectia.mx` / password).
3. **Esperado**: el sistema autentica contra el PAC (llamada real `POST /v2/security/authenticate`), guarda el `idUser` y pasa la cuenta a `active`. Si el password es incorrecto, debe mostrar error y NO activar.
4. Verificar que `pac_call_logs` registró la llamada de activación **sin** el password en `request_payload` (sanitización).

### A.3 Alta de perfil fiscal (onboarding normal)
1. En la app, **Configuración → Facturación → Nueva configuración fiscal**.
2. Crear un perfil sin cuenta activa previa.
3. **Esperado**: se crea la `pac_accounts` en `pending_request` (sin subcuenta automática, sin depósito de 10 timbres). El cliente ve estados genéricos ("Pendiente de activación") y **nunca** la palabra "normal"/"subcuenta" (UI oculta `account_type`).

### A.4 Subir CSD y timbrar con subcuenta
1. Con un perfil tipo subcuenta activo: subir `.cer`/`.key`.
2. Crear una factura y timbrar.
3. **Esperado**: se autentica con las credenciales de la `pac_accounts`, se timbra, la factura queda `certificada`, se descuenta 1 timbre (movimiento de salida) y el KPI del panel refleja la baja.

### A.5 Compra de timbres por revendedor (cuenta normal)
1. En Admin → Timbres → **Nueva compra**, elegir una suscripción con cuenta **normal**.
2. **Esperado**: aplica el mínimo de compra (`services.swsapien.normal_min_purchase`, default 100) y NO valida saldo maestro. La compra queda en `awaiting_reseller`.
3. En **Admin → Timbres → Asignación por revendedor**, confirmar la asignación (botón confirmar).
4. **Esperado**: compara saldo real del PAC (`GET management/v2/api/users/balance` con la cuenta normal) contra el esperado (`balance_before + cantidad`); si coincide, marca `stamps_applied` y crea el movimiento de entrada. Probar también `force=1` cuando el saldo no coincide.
5. Verificar que llegó el email `AdminStampResellerNotification` (solo se envía en `production`; en TEST puede omitirse).

### A.6 Verificación admin
- **Admin → Suscripciones → Ver**: las cards de perfil muestran etiqueta "Subcuenta"/"Cuenta externa" + estado.
- **Admin → Timbres**: la columna "Cuenta PAC" en la tabla de emisores muestra tipo y estado.

---

## PARTE B — Archivo 2 (Fase 2.13): reserva de timbres con `customid`

### B.1 Timbrado normal con reserva
1. Factura borrador con perfil activo (subcuenta o normal con saldo).
2. Timbrar.
3. **Esperado**:
   - Se crea `stamp_reservations` con `customid` (UUID) y `status=held`; al confirmar → `confirmed`.
   - `invoice_folio_counters` incrementó (`next_folio`).
   - Factura `certificada`, `requires_manual_review=false`.
   - `pac_call_logs` registró la llamada con el header `customid` y payload sanitizado.
   - Se gastó **1** timbre (movimiento de salida) — no más.
4. Consultas de verificación:
   ```sql
   SELECT * FROM stamp_reservations ORDER BY id DESC LIMIT 5;
   SELECT * FROM invoice_folio_counters;
   SELECT * FROM pac_call_logs ORDER BY id DESC LIMIT 5;  -- verificar que NO hay password ni CSD
   ```

### B.2 Error de validación (rechazo claro)
1. Provocar un CFDI con datos inválidos (p. ej. RFC de receptor mal formado o CSD vencido).
2. Timbrar.
3. **Esperado**: el PAC responde error de validación → la reserva pasa a `released`, la factura regresa a `borrador`, **no** se consume timbre. El usuario ve el error del PAC.

### B.3 Timbre previo (duplicado 307)
1. Intentar timbrar una factura cuyo contenido ya fue timbrado (o reintentar el mismo CFDI).
2. **Esperado**: respuesta con código `307` / mensaje "timbre previo" → el sistema **recupera** el timbre existente: la reserva pasa a `confirmed`, la factura a `certificada` con el UUID del CFDI anterior, **sin** gastar timbre adicional (1 movimiento de salida máximo).

### B.4 Ambigüedad forzada (timeout) → resolución por 307
> Escenario clave: el PAC timbra pero la respuesta se pierde (timeout). El reintento devuelve 307.
1. Temporalmente bajar el timeout en `config/services.php` (`timeout`/`connect_timeout` de swsapien, p. ej. 2s/1s) **solo en TEST**.
2. Timbrar una factura nueva.
3. **Esperado**:
   - La reserva queda `ambiguous`, la factura en `en_verificacion` (`AWAITING_VERIFICATION`) con `requires_manual_review=true`.
   - Se despachó `ResolveAmbiguousStampJob` (cola; en TEST asegurar que el worker esté corriendo o ejecutar `php artisan queue:work --once`).
   - El job reintenta con el **mismo** `customid`; el PAC responde 307 → la reserva pasa a `confirmed`, la factura a `certificada` (se recupera el timbre). **Sin gastar timbre extra.**
4. Restaurar el timeout original.

### B.5 Ambigüedad no resuelta → revisión manual
1. Repetir B.4 pero apagar la cola (no procesar `ResolveAmbiguousStampJob`), o forzar que agote reintentos.
2. **Esperado**: tras 5 intentos (backoff 10,30,60,120,300 s) la reserva queda `manual_review`.
3. En **Admin → Revisión manual de timbrado**:
   - Ver la fila con datos de la factura (RFC, folio, monto, cliente), `customid`, intentos y `last_pac_response` expandible.
   - **Confirmar timbrado**: si `last_pac_response` contiene el CFDI → auto-recupera (persiste XML, certifica). Si no, capturar UUID/CFDI del panel de SW Sapien y confirmar → factura `certificada`.
   - **Liberar y descartar**: la reserva pasa a `released`, la factura a `borrador` (reintento posterior con `customid` nuevo).
   - **NUNCA** debe haber botón de reintento automático en el panel.

### B.6 Reconciliación de saldos (cuenta normal)
1. Ejecutar manualmente el job:
   ```bash
   php artisan tinker --execute="app(\App\Jobs\Billing\ReconcileNormalAccountBalancesJob::class)->handle(app(\App\Services\Billing\SWSapienService::class));"
   ```
   (o correr con `php artisan schedule:run` en TEST, o encolar el job).
2. **Esperado**:
   - Por cada cuenta normal activa: `expected = Σ availableBalance(perfiles)` vs `real = getOwnBalance()`.
   - Si difiere → `Log::error` con detalle del mismatch + `pac_call_logs` con `operation='reconcile'` (audit sanitizado). Email solo en producción.
   - Si falla la consulta de saldo → warning y continúa con la siguiente cuenta.

### B.7 Folios atómicos
1. Desde dos pestañas/sesiones, timbrar en paralelo varias facturas de la misma sucursal/serie.
2. **Esperado**: `invoice_folio_counters` asigna folios **sin duplicados** (constraint `unique(branch_id, series, folio)` como red de seguridad; el `lockForUpdate` garantiza unicidad).
3. Verificar que no quedó ninguna factura con folio repetido.

---

## PARTE C — Verificaciones de seguridad y UI

### C.1 Sanitización de logs
- Revisar `pac_call_logs`: el `request_payload` de las llamadas de autenticación/timbrado **nunca** contiene el password ni el binario del CSD (solo `Serie`, `Folio`, `Fecha`, `Emisor.Rfc`, `Receptor.Rfc`, montos).

### C.2 UI de cliente (nunca revelar tipo de cuenta)
- En **Configuración → Facturación** (Index y Show) y **Dashboard de facturación**:
  - Los estados visibles son genéricos: "Activo", "Pendiente de activación", "Inactivo".
  - **No** debe aparecer la palabra "normal", "subcuenta" ni "cuenta externa".
  - El botón de CSD y el saldo solo se muestran con cuenta activa.

### C.3 UI de admin
- **Cuentas PAC** (Admin → Timbres): CRUD/activación/credenciales/notas funcionando con los datos reales.
- **Revisión manual de timbrado**: tabla + confirmar/liberar con diálogos.

---

## Resultados esperados (resumen)

| Escenario | Estado final factura | Reserva | Timbres consumidos |
|---|---|---|---|
| Timbrado OK | `certificada` | `confirmed` | 1 |
| Validación falla | `borrador` | `released` | 0 |
| Duplicado 307 | `certificada` (uuid previo) | `confirmed` | 0 |
| Timeout → job resuelve 307 | `certificada` | `confirmed` | 1 (el del primer intento) |
| Timeout → agota reintentos | `en_verificacion` | `manual_review` | 1 (a confirmar en panel) |
| Admin libera | `borrador` | `released` | 0 adicionales |

---

## Checklist final antes de producción

- [ ] Backfill ejecutado en TEST sin duplicados.
- [ ] Cuenta normal activada con credenciales sandbox reales.
- [ ] Timbrado real OK (subcuenta y normal).
- [ ] Compra + confirmación por revendedor OK.
- [ ] Flujo 307 (duplicado) no gasta timbre extra.
- [ ] Ambigüedad forzada se resuelve por el job con el mismo `customid`.
- [ ] Panel de revisión manual confirma/libera correctamente.
- [ ] Reconciliación diaria registra el mismatch esperado (o sin mismatch).
- [ ] `pac_call_logs` sin password/CSD.
- [ ] UI de cliente oculta `account_type`; UI de admin lo muestra.
