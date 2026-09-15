# Reporte de implementación — Fase 3 de Facturación

> **Fecha:** 2026-09-14 · **Estado:** ✅ Implementada (T301–T308) · 🟡 Parcial (T307: drop de columnas legacy pendiente de confirmación) · ⬜ Diferida (T309, opcional)
> **Base:** `docs/context/fase-3.md` (propuesta reconstruida) · **Antecedentes:** Fase 1 (diagnóstico) y Fase 2 (cuentas PAC compartidas + reserva de timbres).
> **Además (más allá de facturación):** sistema de **videotutoriales para todos los módulos** — ver §5.

---

## 1. Resultado general

Se implementaron los tres bloques de la fase:

- **Bloque A — Ciclo de cancelación automático:** la factura ya no se queda "pendiente de cancelación" esperando una acción manual; un job horario consulta al SAT, actualiza el estatus y notifica al suscriptor.
- **Bloque B — Avisos preventivos:** alertas visibles y por correo de saldo bajo de timbres y de CSD por vencer (30/15/5 días) o vencido.
- **Bloque C — Cierre técnico:** auditoría PAC completa en `pac_call_logs`, limpieza de BillingSetting, y dashboard con filtro real de fechas + exportación a Excel.

**Validaciones ejecutadas:**

| Validación | Resultado |
|---|---|
| `php artisan test tests/Feature/Billing` | ✅ **22 passed (77 assertions)** |
| `php artisan test tests/Feature/Admin` (Tutoriales) | ✅ **6 passed (51 assertions)** |
| Regresión (`Billing` + `Admin` + `ProductControllerTest`) | ✅ 42 passed · 1 failed **preexistente conocido** (`it denies access without permissions`) |
| Build de Vite (`pnpm build`, tras tutoriales) | ✅ sin errores |
| Lints (PHP + Vue editados) | ✅ sin errores |
| `php artisan schedule:list` | ✅ ambos jobs registrados (horario / 08:00) |
| Ziggy regenerado | ✅ rutas `billing.dashboard.export` y `admin.tutorials.*` incluidas |

---

## 2. Detalle por tarea

### T301 — Job de verificación automática de cancelaciones ✅
- Nuevo `app/Jobs/Billing/RefreshPendingCancelationsJob.php` (horario, `routes/console.php`).
- Reutiliza `RefreshCancelationStatusAction` + `SatConsultationService` existentes; excluye las filas cuyo plazo ya venció (`Plazo vencido`) porque no cambian solas.
- Registra cada consulta en `pac_call_logs` (`operation: cancel_status`).

### T302 — Notificación al suscriptor ✅
- Nuevo Mailable `app/Mail/CancelationResolvedNotification.php` + plantilla `resources/views/emails/cancelation-resolved-notification.blade.php` (markdown, estilo de los correos existentes).
- Se envía cuando la cancelación se **acepta**, se **rechaza** o **vence**, a `subscription->contact_email`, con guard de **solo producción** (mismo patrón que la alerta de reconciliación).
- Deduplicación: aceptadas/rechazadas salen del filtro al cambiar de estatus; el vencimiento se notifica **una vez** comparando el `cancelation_status` previo.

### T303 — Indicador del plazo en la UI ✅
- En `Billing/Invoices/Show.vue`: el aviso de cancelación pendiente ahora muestra "queda X (vence el ...)" calculado con `cancelation_requested_at` + 72 h, o "el plazo ya venció" cuando aplica.

### T304 — Alerta de saldo bajo de timbres ✅
- `config/billing.php`: `low_stamp_threshold` (default 5, configurable por env).
- UI: aviso ámbar por emisor en `Billing/Dashboard/Index.vue` y tag/número ámbar con icono en `Billing/Settings/Index.vue`.
- Correo diario vía `CheckPreventiveAlertsJob` con cooldown de 3 días por emisor (sin spam mientras siga bajo).

### T305 — Avisos de vencimiento de CSD ✅
- UI: en Configuración fiscal el estado del CSD ahora distingue **Activo / Por vencer (X días) / Vencido**; en el detalle del emisor se muestra la alerta con la fecha de vigencia.
- Correo diario con hitos 30/15/5 días (una vez por hito) y recordatorio de vencido cada 30 días.
- Nota: la validación al timbrar ya se había robustecido (mensajes "LCO" en `SWSapienService::classifyPacFailure`); no se tocó.

### T306 — Auditoría PAC completa ✅
- Nuevo `app/Services/Billing/PacCallLogger.php` (único punto de escritura, **sanitizado**: sin password, tokens ni CSD).
- `SWSapienService::logPacCall` ahora delega en el logger (comportamiento del timbrado intacto); se añadió registro para `authenticate` (SWSapienService), `upload_csd` (SWSapienService), `balance` (SWUserService::getOwnBalance) y `cancel_status` (SatConsultationService).

### T307 — Limpieza de deuda técnica 🟡 Parcial
- ✅ **BillingSetting eliminado:** se borraron `SaveBillingSettingsAction`, `SaveBillingSettingsRequest`, la relación `Branch::billingSetting()` (referenciaba un modelo que ya no existía) y una prop muerta del frontend (`hasBillingSettings`).
- 🟡 **Columnas legacy (`sw_user_id`, `sw_account_email`, `password`): NO se dropearon.** Decisión deliberada: la migración se ejecutaría automáticamente también en producción y no es posible verificar aquí su estado. Datos en desarrollo (2026-09-14): **16 perfiles, 0 sin `pac_account_id`** (6 conservan `sw_user_id` legacy por el backfill). El drop quedó **listo para ejecutarse** cuando lo confirmes, y debe ir acompañado de quitar los fallbacks (`FiscalProfile::resolvePacCredentials()/isLinkedToPac()/scopeReadyForInvoicing()`, `isAccountActive()` en Vue).

### T308 — Filtro de fechas + exportación en dashboard ✅
- `InvoiceController::dashboard()` acepta `range` (all / today / week / month / year / custom) y filtra KPIs y tarjetas por emisor por **fecha de registro** (`created_at`); el rango personalizado usa `start_date`/`end_date`.
- Nueva ruta `billing.dashboard.export` + `app/Exports/InvoicesExport.php` (Excel vía Maatwebsite, estados en español, permiso `invoices.settings.access`).
- UI: barra de filtro + botón "Exportar" en `Billing/Dashboard/Index.vue` (estilo Tesla, `ptConfigs` compartidos).
- Se regeneró `resources/js/ziggy.js`.

### T309 — Reporte admin por suscriptor ⬜ Diferida
- Diferida por prioridad baja: la ficha de suscriptor del admin no tiene un área de facturas y añadirla excede el alcance de este pase. Se puede retomar como mejora (la ruta de exportación del dashboard puede reutilizarse).

---

## 3. Archivos

**Nuevos (11):** `PacCallLogger.php`, `RefreshPendingCancelationsJob.php`, `CheckPreventiveAlertsJob.php`, `CancelationResolvedNotification.php`, `PreventiveBillingAlertNotification.php`, `InvoicesExport.php`, `config/billing.php`, 2 plantillas de correo, `CancelationAutomationTest.php`, `PreventiveAlertsTest.php`.

**Modificados (15):** `SWSapienService`, `SatConsultationService`, `SWUserService`, `InvoiceController`, `FiscalProfileController`, `routes/web/billing.php`, `routes/console.php`, `Branch`, `StoreFiscalProfileRequest`, 4 vistas de Billing, `ziggy.js`, `docs/context/fase-3.md`, `docs/MODULO_FACTURACION.md`.

**Eliminados (2):** `SaveBillingSettingsAction.php`, `SaveBillingSettingsRequest.php` (y `Pages/Billing/tutorials.js`, reemplazado por la base de datos).

**Tutoriales (multi-módulo, ver §5):** nuevos `TutorialVideo`, `AdminTutorialController`, `Store/UpdateTutorialVideoRequest`, 2 migraciones (`tutorial_videos` + marcadores de facturación), `config/tutorials.php`, `Pages/Admin/Tutorials/Index.vue` + `Partials/TutorialVideoFormModal.vue`, `tests/Feature/Admin/TutorialsTest.php`; modificados `TutorialHelp.vue` (multi-módulo), `HandleInertiaRequests`, `AppMenu.vue`, `super-admin.php`, `PosLeftPanel.vue` y las 2 páginas de facturación.

---

## 4. Notas operativas

1. **Los correos se envían solo en producción** (patrón del proyecto). En local puedes probarlos con `Mail::fake()` (ver las pruebas nuevas) o quitando temporalmente el guard.
2. **El scheduler debe estar corriendo en producción** (cron de Laravel) para el job horario de cancelaciones y el diario de alertas.
3. Los umbrales se ajustan por env: `BILLING_LOW_STAMP_THRESHOLD` (default 5) y `BILLING_CSD_WARNING_DAYS` (default `30,15,5`).
4. **Pendiente recomendado:** confirmar el drop de columnas legacy (T307) y, en el mismo cambio, eliminar los fallbacks legacy. También sigue en pie la Fase 2.6 (E2E en ambiente TEST).

---

## 5. Sistema de videotutoriales (todos los módulos)

> Este reporte nació del módulo de facturación, pero los videos **no son exclusivos de facturación**: se implementó un sistema administrable para **todos los módulos y sus secciones**, donde cada módulo muestra únicamente sus propios videos.

- **Panel admin "Tutoriales"** (`/admin/tutorials`, solo superadmin; menú Administración → Tutoriales): CRUD completo agrupado por **módulo → sección**, con filtro por módulo, activar/desactivar y orden (subir/bajar).
- **Cada video** admite dos orígenes (se elige por video): **enlace externo** (YouTube/Vimeo) o **archivo subido** (MP4/WebM/MOV). Los archivos se guardan en `storage/app/public/tutorials`; el tamaño máximo es configurable con `TUTORIALS_MAX_UPLOAD_KB` (default 50 MB) y también depende del límite de subida del servidor. Para videos largos se recomienda el enlace.
- **Base de datos:** tabla `tutorial_videos`. Las secciones actuales de facturación (Configuración fiscal / Facturas / Pagos y complementos) se migraron como **borradores** listos para pegarles los enlaces.
- **En las páginas del sistema:** el icono de tutorial junto al título abre el modal de **su** módulo (acordeón por secciones, carga diferida del reproductor; los videos sin enlace se muestran como "Próximamente"). **Si un módulo no tiene videos activos, el icono no aparece.**
- **Colocado hoy en:** Facturación (Lista de facturas y Emisores fiscales) y Punto de venta. El resto de los módulos se conectan con un cambio de ~3 líneas por página cuando se desee.
- **Módulos disponibles en el selector del admin:** Inicio, Punto de venta, Historial de ventas, Productos, Clientes, Cotizaciones, Servicios y órdenes, Gastos, Cajas, Reporte financiero, Facturación, Tienda en línea, Promociones, Suscripción, Referidos, Configuraciones y Primeros pasos.
- **Pruebas:** `tests/Feature/Admin/TutorialsTest.php` — 6 pruebas (listado, alta por enlace, alta por archivo, validación, activar/ordenar/eliminar y la prop compartida de Inertia filtrada por módulo).

---

> ⚠️ Recordatorio: esta Fase 3 fue **reconstruida** (la propuesta original se perdió). Si tu plan original incluía otras tareas, compártelo y lo integro.
