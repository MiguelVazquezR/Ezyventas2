# App móvil EzyVentas (Flutter / Android) — documentación de contexto

Este directorio contiene el contexto técnico necesario para construir la app móvil de
EzyVentas (Flutter + Android) consumiendo la API REST `/api/v1` de este mismo proyecto
Laravel, sobre la **misma base de datos de producción**.

## Archivos

| Archivo | Para qué sirve |
|---|---|
| `00-contexto-app-movil.md` | **Documento maestro.** Alcance, arquitectura, shell de navegación, auth, POS, caja (apertura y corte), Órdenes de servicio, impresión, cuenta (sucursal/perfil/suscripción/soporte), offline/sync, reglas de negocio, convenciones, roadmap y QA. |
| `01-contrato-api-v1.md` | Contrato completo de la API: endpoints, permisos, request y response JSON de cada uno. |
| `02-modelo-de-datos.md` | Tablas, columnas, relaciones y enums que usa la app móvil. |
| `03-design-system-tesla-ui.md` | Design system "Tesla UI": colores exactos, **reglas de contraste**, tipografía, formas, copy de pantallas y mapeo a widgets Flutter. |

## Reglas de oro

1. **La app móvil nunca se conecta directamente a MySQL.** Siempre por HTTPS contra `/api/v1`.
2. **Toda la lógica de negocio vive en el backend** (folios, stock, saldos, caja, comisiones).
   La app solo arma payloads y presenta resultados.
3. **Código en inglés** (clases, variables, archivos) y **textos de UI en español con sentence case**.
4. **El `branch_id` y el `subscription_id` nunca se envían desde la app**: se derivan del token en el servidor.
5. **Caja:** la app puede **abrir**, **unirse** y **cerrar** (corte) sesiones de caja. No registra
   movimientos de efectivo (ingresos/egresos) ni inventa un `cash_register_session_id`.

## Decisiones ya confirmadas para esta versión

| Tema | Decisión |
|---|---|
| Base de datos | Se usa la **misma de producción**, exclusivamente a través de la API |
| Caja | **Completa desde el móvil:** abrir turno (fondo de efectivo + saldos bancarios), unirse a una sesión y **corte/cierre con arqueo** y conciliación bancaria |
| Impresión | **Bluetooth térmico (ESC/POS)** y/o **envío por WhatsApp** |
| Sin conexión | **Offline-first** con cola de operaciones e idempotencia |
| Sobreventa offline | **Permitida**: el servidor aplica la venta y responde `negative_stock` para marcarla como "Requiere revisión" |
| Cuenta y barra superior | Cambio de sucursal, perfil (datos, contraseña, foto, otras sesiones), notificaciones, soporte y vista de suscripción (solo propietario) |
| Legibilidad | Textos importantes y títulos en **alto contraste**; prohibido el gris claro (ver `03-design-system-tesla-ui.md` §13) |
| Módulos de la v1 | Punto de venta y Órdenes de servicio, completos y funcionales |

> Nota técnica: la tabla `personal_access_tokens` **ya existe** en la base de datos (migración
> consolidada `2026_08_22_000001_create_core_platform_tables.php`), así que el login por token no
> requiere migraciones nuevas.

## Estado de implementación

| Fase | Alcance | Estado |
|---|---|---|
| **0** | API `/api/v1`: login, me, logout, errores JSON, throttling | ✅ **implementada** (18 sep 2026) |
| **1** | Lectura: catálogo, categorías, servicios, clientes, caja y bancos | ✅ **implementada** (18 sep 2026) |
| 2 | Lectura/estatus: ventas, órdenes de servicio, diagnóstico con evidencias | ✅ **implementada** (18 sep 2026) |
| 3 | Escrituras: abrir caja, checkout, apartados, pedidos, abonos, alta/edición de órdenes | ✅ **implementada** (18 sep 2026) |
| 4 | Impresión y WhatsApp, corte/cierre de caja, cuenta (sucursal, perfil, suscripción, soporte, notificaciones) | ✅ **implementada** (18 sep 2026) |
| 5 | Offline-first: SQLite local, cola de operaciones, `sync/*` | pendiente |
| 6+ | Movimientos de efectivo, pago de suscripción, 2FA, reportes, intercambios | futuro |

**Endpoints ya disponibles para la app:**

```
POST   /api/v1/auth/login                    (throttle 5/min por correo + IP)
GET    /api/v1/auth/me                       (auth:sanctum)
POST   /api/v1/auth/logout                   (auth:sanctum)

GET    /api/v1/catalog/products              ?search&category_id&updated_since&page&per_page
GET    /api/v1/catalog/products/{productId}
GET    /api/v1/catalog/categories            ?type=product|service
GET    /api/v1/catalog/services              ?search&category_id&page&per_page
GET    /api/v1/customers                     ?search&page&per_page
GET    /api/v1/customers/{customerId}
POST   /api/v1/customers
GET    /api/v1/cash-register-sessions/current
GET    /api/v1/bank-accounts

GET    /api/v1/transactions                  ?search&status&date_start&date_end&sortField&sortOrder
GET    /api/v1/transactions/{transactionId}
GET    /api/v1/service-orders                ?search&status&sortField&sortOrder
GET    /api/v1/service-orders/{serviceOrderId}
PATCH  /api/v1/service-orders/{serviceOrderId}/status
POST   /api/v1/service-orders/{serviceOrderId}/diagnosis        (multipart, máx. 5 fotos)

POST   /api/v1/cash-register-sessions                            (abrir caja)
POST   /api/v1/cash-register-sessions/{cashRegisterSessionId}/join
POST   /api/v1/pos/checkout                                      (cobro)
POST   /api/v1/pos/layaway                                       (apartado)
POST   /api/v1/pos/store-order                                   (pedido)
POST   /api/v1/transactions/{transactionId}/payments             (abono)
POST   /api/v1/transactions/{transactionId}/cancel               (cancelar/reembolsar)
POST   /api/v1/transactions/{transactionId}/refund
POST   /api/v1/service-orders                                    (multipart)
PUT    /api/v1/service-orders/{serviceOrderId}                   (multipart)
POST   /api/v1/service-orders/{serviceOrderId}/ensure-transaction
POST   /api/v1/service-orders/{serviceOrderId}/payments          (anticipo)
DELETE /api/v1/service-orders/{serviceOrderId}

GET    /api/v1/cash-register-sessions/{cashRegisterSessionId}/summary   (datos del corte)
PUT    /api/v1/cash-register-sessions/{cashRegisterSessionId}           (cerrar caja)
POST   /api/v1/cash-register-sessions/{cashRegisterSessionId}/leave
POST   /api/v1/cash-register-sessions/rejoin-or-start
PUT    /api/v1/transactions/{transactionId}/payments/{paymentId}
DELETE /api/v1/transactions/{transactionId}/payments/{paymentId}

GET    /api/v1/print/templates                ?context&type
POST   /api/v1/print/bluetooth-payload        (ESC/POS en Base64)
POST   /api/v1/print/payload                  (etiquetas TSPL)
POST   /api/v1/print/ticket-html              (respaldo PDF/HTML)
POST   /api/v1/print/whatsapp-ticket          (texto del ticket)

PUT    /api/v1/branch/switch/{branchId}
GET    /api/v1/notifications
GET    /api/v1/support
GET    /api/v1/profile
PUT    /api/v1/profile                        (multipart, foto opcional)
DELETE /api/v1/profile/photo
PUT    /api/v1/profile/password
POST   /api/v1/profile/logout-other-devices
GET    /api/v1/subscription                   (solo propietario)
PUT    /api/v1/subscription
POST   /api/v1/subscription/documents         (multipart)
POST   /api/v1/subscription/payments/{paymentId}/request-invoice
```

Verificación rápida: `php artisan route:list --path=api/v1` y
`php artisan test tests/Feature/Api/V1`.

## Orden de lectura recomendado para el agente

1. `00-contexto-app-movil.md` — reglas, arquitectura, flujos y roadmap.
2. `01-contrato-api-v1.md` — qué endpoints existen y con qué payloads.
3. `02-modelo-de-datos.md` — qué significan los campos y enums.
4. `03-design-system-tesla-ui.md` — cómo debe verse y sentirse la app.

> Estado del documento: Fase 0 (diseño). Se actualiza al cerrar cada fase de implementación.
