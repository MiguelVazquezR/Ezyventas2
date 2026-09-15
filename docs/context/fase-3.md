# Fase 3 — Facturación: automatización operativa y cierre técnico del módulo

> **Proyecto:** EzyVentas · Módulo de Facturación CFDI 4.0
> **Antecedentes:** Fase 1 (diagnóstico — `docs/diagnostico-flujo-pac-fase1.md`) · Fase 2 (cuentas PAC compartidas, wallet local de timbres, reserva CustomID, panel de revisión manual — implementada 2026-08-13).
> **Fecha:** 2026-09-14 · **Estado:** ✅ IMPLEMENTADA — T301–T308 en producción de código; T307 con la limpieza de columnas legacy pendiente de confirmación; T309 diferida (opcional). Reporte: `docs/reporte-fase-3-facturacion.md`.

> ℹ️ **Nota de reconstrucción:** la propuesta original de la Fase 3 no existe en el repositorio (no hay archivo `fase-3.md` ni referencias en el código, los documentos o el historial de sesiones). Este documento se reconstruyó a partir de:
> - Pendientes documentados en `docs/MODULO_FACTURACION.md` (§1.2, §7.2, §10).
> - Pendientes de cierre de la Fase 2 (auditoría PAC, columnas legacy).
> - Incidentes reales recientes (estados de cancelación que no se actualizan solos, avisos de CSD vencido).

---

## 1. Objetivo

Cerrar los huecos operativos del módulo para que el estado de una factura y los avisos dependan cada vez menos de acciones manuales — sin tocar los flujos de timbrado ya estables de la Fase 2.

## 2. Estado actual (verificado 2026-09-14)

| Pieza | Estado |
|---|---|
| Verificación **manual** de estatus de cancelación (`checkCancelationStatus` + `RefreshCancelationStatusAction`) | ✅ Existe (botón en el detalle) |
| Aceptación/rechazo del receptor (`acceptReject` + `AcceptRejectInvoiceAction`) | ✅ Existe |
| Actualización **automática** del estado de cancelación (job/cron) | ❌ No existe |
| Notificación al suscriptor cuando se resuelve una cancelación | ❌ No existe (solo flash al entrar) |
| Alertas de saldo bajo de timbres (§7.2 del doc) | ❌ No existe |
| Avisos de vencimiento de CSD (hoy solo valida al momento de timbrar) | ❌ No existe |
| Auditoría PAC completa en `pac_call_logs` (`authenticate`, `balance`, `upload_csd`) | ⚠️ Parcial (hallazgo Fase 2.13) |
| Filtro de fechas y exportación en el dashboard de facturación | ❌ No existe |
| Limpieza de deuda técnica (`BillingSetting` legacy, columnas legacy) | ❌ Pendiente |
| Límite de perfiles fiscales / RFC repetido entre suscripciones (§10.4) | 🟡 Decisión de negocio — fuera de alcance |

## 3. Alcance propuesto

### Bloque A — Ciclo de cancelación automático (prioridad alta)

- **T301 — Job de verificación automática.** Nuevo job programado (proposal: cada 6 h) que tome las facturas en `cancelacion_pendiente` y ejecute `RefreshCancelationStatusAction` para actualizarlas a `cancelada` (aceptada) o `certificada` (rechazada) sin intervención del usuario. Registra cada consulta en `pac_call_logs` (`operation: cancel_status`).
- **T302 — Notificación al suscriptor.** Nuevo Mailable (patrón de `app/Mail/*`) cuando una cancelación se resuelve: aceptada, rechazada o expirada.
- **T303 — Indicador del plazo en la UI.** Mostrar el tiempo restante del plazo de aceptación (72 h) en el detalle/listado de la factura (persistir la fecha de solicitud de cancelación si hoy no se guarda).

### Bloque B — Avisos preventivos (prioridad media)

- **T304 — Alerta de saldo bajo de timbres.** Umbral configurable (default: 5) con aviso visible en `Billing/Dashboard` + email. Base: `WalletService::availableBalance()`.
- **T305 — Avisos de vencimiento de CSD.** Avisos a 30 / 15 / 5 días en Configuración fiscal + email, y revisión de la validación de vigencia al timbrar para eliminar falsos positivos (incidente del 13-sep), con mensaje claro y fecha de vencimiento.

### Bloque C — Cierre técnico y reportes (prioridad media / baja)

- **T306 — Auditoría PAC completa.** Registrar en `pac_call_logs` también `authenticate`, `balance`, `upload_csd` y `cancel_status` (sanitizado: sin passwords ni CSD binario).
- **T307 — Limpieza de deuda técnica.** Eliminar `BillingSetting` legacy (modelo + `SaveBillingSettingsAction`/`SaveBillingSettingsRequest` + referencias, tras verificar que no esté en uso) y programar la migración de limpieza de las columnas legacy `sw_user_id` / `sw_account_email` / `password` de `fiscal_profiles` (solo cuando el 100 % de los perfiles tenga `pac_account_id`, con verificación previa y `down()` de emergencia).
- **T308 — Filtro de fechas + exportación en el dashboard.** Selector (hoy / semana / mes / año / personalizado) que filtre KPIs y tarjetas por emisor, y botón "Exportar" funcional (CSV/XLSX con Maatwebsite) del período seleccionado.
- **T309 (opcional) — Reporte admin por suscriptor.** Exportable de facturas emitidas por período en el panel admin (§7.2 del doc).

## 4. Fuera de alcance (por ahora)

- **Fase 2.6** (E2E en ambiente TEST) — pendiente operativo ya planificado en `docs/plan-pruebas-fase2-test.md`.
- **Facturación interna de suscripciones con CFDI** (§10.5 #14) — decisión de negocio pendiente.
- **Límite de perfiles por suscripción** (§10.4 #11) — depende de confirmar el tope comercial con Conectia.
- **Modo "subcuenta en cuenta maestra"** — se mantiene pospuesto (acuerdo previo).

## 5. Criterios de aceptación

1. Una factura `cancelacion_pendiente` aceptada por el receptor pasa a `cancelada` sin intervención del usuario (≤ 6 h) y el suscriptor recibe notificación.
2. Una cancelación rechazada regresa la factura a `certificada` + notificación.
3. Saldo de timbres ≤ umbral y CSD por vencer generan avisos visibles (y por email).
4. `pac_call_logs` registra `authenticate`, `balance`, `upload_csd` y `cancel_status` (sanitizado).
5. El dashboard filtra por rango de fechas y exporta el período.
6. `php artisan test tests/Feature/Billing` en verde y build de Vite sin errores.

## 6. Riesgos

- **Dependencia del PAC/SAT:** el job aumenta la frecuencia de consultas; respetar throttling, manejar fallos con logging y reintentos suaves (que un fallo del PAC nunca deje la factura en un estado inconsistente).
- **Jobs nuevos:** respetar los guards existentes (suscripción con facturación activa / módulo contratado).
- **Migración de limpieza (T307):** irreversible en producción → verificación previa (0 filas sin `pac_account_id`), respaldo y `down()` documentado.

## 7. Orden de implementación sugerido

| Paso | Tareas | Dependencias |
|---|---|---|
| 1 | T301 → T302 → T303 | `RefreshCancelationStatusAction`, patrón `app/Mail/*` |
| 2 | T304 → T305 | `WalletService`, `fiscal_profiles.valid_to` |
| 3 | T306 → T307 | `pac_call_logs`, revisión de referencias legacy |
| 4 | T308 → T309 | Queries del dashboard + Maatwebsite Excel |

## 8. Control de tareas

| Tarea | Descripción | Estado |
|---|---|---|
| T301 | Job de verificación automática de cancelaciones | ✅ Implementado (`RefreshPendingCancelationsJob`, horario) |
| T302 | Notificación al suscriptor (cancelación resuelta) | ✅ Implementado (`CancelationResolvedNotification`, solo producción) |
| T303 | Indicador del plazo de cancelación en la UI | ✅ Implementado (Show de factura, plazo de 72 h) |
| T304 | Alerta de saldo bajo de timbres | ✅ Implementado (UI + correo diario con cooldown de 3 días) |
| T305 | Avisos de vencimiento de CSD + validación robusta | ✅ Implementado (avisos 30/15/5 días y vencido en UI + correo) |
| T306 | Auditoría PAC completa en `pac_call_logs` | ✅ Implementado (`PacCallLogger`: authenticate, balance, upload_csd, cancel_status) |
| T307 | Limpieza de deuda técnica (BillingSetting + columnas legacy) | 🟡 Parcial — `BillingSetting` eliminado; drop de columnas legacy verificado en dev (0 perfiles sin `pac_account_id`) pero pendiente de confirmación para producción |
| T308 | Filtro de fechas + exportación en dashboard | ✅ Implementado (`billing.dashboard.export`, rango por fecha de registro) |
| T309 | (Opcional) Reporte admin por suscriptor | ⬜ Diferida — prioridad baja; requiere área de facturas en la ficha admin |

---

> **Siguiente paso:** aprobar o ajustar el alcance. Al terminar la implementación se actualizará este control de tareas y se generará el reporte de la fase en `docs/reporte-fase-3-facturacion.md`.
