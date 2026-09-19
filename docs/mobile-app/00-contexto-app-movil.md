# 00 — Contexto maestro de la app móvil EzyVentas (Flutter · Android)

> **Este es el documento que debes leer primero.** Define qué se construye, con qué reglas y
> contra qué API. Los anexos lo detallan:
> - `01-contrato-api-v1.md` → endpoints, payloads y códigos de error.
> - `02-modelo-de-datos.md` → tablas, columnas y enums de la base de datos.
> - `03-design-system-tesla-ui.md` → colores, tipografía, formas y widgets.

**Stack del backend de referencia:** Laravel 12 (PHP 8.3), MySQL, Sanctum, Spatie Permission,
Spatie Media Library, PrimeVue + Tailwind (web). La app móvil consume la **misma base de datos
de producción** a través de la API REST `/api/v1` de ese mismo proyecto.

---

## 1. Objetivo

Llevar a Android las dos operaciones diarias del negocio, con la misma lógica y el mismo aspecto
que la versión web:

1. **Punto de venta (POS):** cobrar, apartar, crear pedidos/comandas, consultar ventas y
   registrar abonos.
2. **Órdenes de servicio:** registrar y dar seguimiento a reparaciones, cambiar estatus, guardar
   diagnóstico con evidencias fotográficas y cobrar anticipos.

Requisitos transversales confirmados:

| Tema | Decisión |
|---|---|
| Base de datos | La **misma de producción** (nunca conexión directa desde el dispositivo: siempre API) |
| Caja | **Completa:** abrir turno (fondo de efectivo + declaración de saldos bancarios), unirse a una sesión abierta y **hacer el corte/cierre de caja** con arqueo y conciliación bancaria |
| Impresión | Ticket por **impresora térmica Bluetooth** (ESC/POS) **o** envío por **WhatsApp** |
| Sin conexión | **Offline-first** con sincronización; la venta nunca se bloquea por falta de stock local |
| Alcance futuro | Escalar hasta casi igualar la web (fase por fase, sin tocar la web actual) |

### Fuera de alcance (por ahora)
Facturación CFDI, cotizaciones, gastos, reportes financieros/analíticos, tienda en línea,
administración de usuarios/permisos, apertura y cierre de caja, definición de plantillas de
impresión y catálogos (productos/servicios). Todo eso se sigue haciendo en la web.

---

## 2. Reglas de oro (no negociables)

1. **Nunca** conectar la app a MySQL. Solo HTTPS contra `/api/v1`.
2. **Nunca** enviar `branch_id` ni `subscription_id`: el servidor los deriva del token.
3. **Toda** la lógica de negocio se ejecuta en el servidor: folios, stock, saldos, crédito,
   comisiones, caja. La app arma payloads y presenta resultados.
4. **Nunca** calcular folios. Sin conexión se usa `TMP-XXXXXX` y el servidor asigna el real al
   sincronizar.
5. **Idempotencia siempre:** cada operación de escritura lleva un `client_uuid` generado una
   sola vez; reintentar no duplica.
6. **Caja:** la app puede **abrir**, **unirse** y **cerrar** sesiones de caja. Nunca inventa un
   `cash_register_session_id` y nunca registra movimientos de efectivo (`ingreso`/`egreso`); el
   arqueo se limita a capturar el efectivo contado y las notas.
7. **Abrir y cerrar caja requieren conexión** (crean o cierran estado definitivo en el servidor).
   Si el dispositivo está offline y no hay sesión, el POS queda bloqueado con un mensaje claro.
8. **Código en inglés** (clases, variables, archivos, tablas) y **textos de UI en español con
   sentence case** ("Guardar venta", no "Guardar Venta").
9. **Los permisos se consultan, no se asumen:** el servidor revalida cada petición; la UI solo
   oculta lo que el usuario no puede hacer.
10. **Los errores se muestran con el mensaje del servidor** (ya vienen en español); la app no
    inventa traducciones ni mensajes genéricos tipo "Error desconocido".
11. **Nada se borra localmente** hasta que el servidor confirme (`applied` / `duplicate`).
12. **El modo oscuro es el predeterminado** y la referencia visual (ver `03-design-system-tesla-ui.md`).

---

## 3. Arquitectura

```
┌──────────────────────────┐
│  App Flutter (Android)   │
│  ─ UI (Tesla UI)         │
│  ─ Repositorios + caché  │
│  ─ SQLite + cola outbox  │
└───────────┬──────────────┘
            │ HTTPS · Bearer token · JSON / multipart
            ▼
┌──────────────────────────────────────────────────────────┐
│  Laravel 12 (mismo proyecto, misma BD de producción)     │
│                                                          │
│  routes/api/v1/*.php                                     │
│      ↓                                                   │
│  Api/V1/Controllers  (thin: validan, delegan, responden) │
│      ↓                                                   │
│  Actions / Services EXISTENTES                           │
│   · TransactionPaymentService (handleNewSale,            │
│     handleNewOrder, applyPaymentToTransaction)           │
│   · CreateServiceOrderAction / UpdateServiceOrderAction  │
│   · ChangeServiceOrderStatusAction                       │
│   · EnsureServiceOrderTransactionAction                  │
│   · PrintEncoderService (ESC/POS, TSPL, HTML)            │
│   · WhatsAppTicketService                                │
│      ↓                                                   │
│  Models Eloquent  →  MySQL                               │
└──────────────────────────────────────────────────────────┘
```

**Regla de implementación:** si una operación ya existe en un Action/Service, el controlador de
la API **no** la reimplementa: la invoca. Solo se crean piezas nuevas de negocio cuando el
comportamiento es exclusivo del móvil.

### Piezas nuevas que se agregan al backend (por fase)
| Fase | Pieza |
|---|---|
| 0 | ✅ **Hecho:** `Api/V1/Auth/AuthController` (login/logout/me con tokens de Sanctum), `routes/api/v1/auth.php`, `EnsureApiSubscriptionIsActive`, respuestas JSON uniformes (`ApiExceptionRenderer`), rate limiters. La tabla `personal_access_tokens` ya existía |
| 1 | ✅ **Hecho:** catálogo de productos con stock y precio por sucursal, categorías, servicios, clientes (listado/detalle/alta), sesión de caja actual y cuentas bancarias |
| 2 | ✅ **Hecho:** listado/detalle de ventas, listado/detalle de órdenes de servicio, cambio de estatus y diagnóstico con evidencias fotográficas |
| 3 | Escrituras: **apertura de caja** (`POST /cash-register-sessions`) + unirse/salir de sesión, `pos/checkout`, `pos/layaway`, `pos/store-order`, pagos/abonos, alta/edición de órdenes, cancelaciones | ✅ **Hecho** (18 sep 2026) |
| 4 | Impresión (`print/*`) y plantillas; WhatsApp; **corte/cierre de caja**; **cuenta**: cambio de sucursal, perfil, notificaciones, soporte y vista de suscripción | ✅ **Hecho** (18 sep 2026) |
| 5 | Sync: `client_uuid` en `transactions` y `service_orders`, tablas `sync_operations` y `sync_tombstones`, `sync/manifest|pull|push` |

---

## 4. Stack y estructura de la app Flutter

### Dependencias recomendadas
| Necesidad | Paquete |
|---|---|
| Cliente HTTP | `dio` (interceptores para token, `401` y reintentos) |
| Estado | `flutter_riverpod` (o `provider` si el equipo ya lo domina) |
| Navegación | `go_router` |
| Token seguro | `flutter_secure_storage` |
| Base local | `drift` (SQLite tipado) o `sqflite` |
| Formato es-MX | `intl` |
| Bluetooth térmico | `flutter_blue_plus` + `esc_pos_utils_plus` |
| Cámara / evidencias | `image_picker` (+ `flutter_image_compress` antes de subir) |
| WhatsApp | `url_launcher` |
| Conectividad | `connectivity_plus` |
| Notificaciones locales | `flutter_local_notifications` (avisos de sync) |

### Estructura de carpetas propuesta
```
lib/
├── main.dart
├── app.dart
├── core/
│   ├── api/            # dio client, interceptores, ApiException, endpoints
│   ├── auth/           # sesión, token, permisos (PermissionsService)
│   ├── db/             # drift/sqlite, tablas locales, migraciones
│   ├── sync/           # outbox, SyncService, pull/push, resolución de conflictos
│   ├── printing/       # EscPosBuilder (offline), BluetoothPrinterService
│   ├── theme/          # app_theme.dart, colors, text_styles
│   ├── utils/          # formatters (moneda, fecha), validators, uuid
│   └── widgets/        # StatusBadge, SectionCard, FieldLabel, EmptyState, LoadingList
├── features/
│   ├── auth/           # login, session guard
│   ├── pos/            # catálogo, carrito, cobro, apartado, pedido/comanda
│   ├── sales/          # historial y detalle de ventas, abonos
│   ├── service_orders/ # listado, detalle, formulario, estatus, diagnóstico
│   ├── customers/      # búsqueda, alta rápida, estado de cuenta
│   ├── cash/           # sesión de caja (unirse), cuentas bancarias
│   ├── printing/       # selección de plantilla, impresión, WhatsApp
│   └── sync/           # pantalla de pendientes y "requiere revisión"
└── l10n/               # solo si más adelante se agrega otro idioma (hoy: español fijo)
```

Convenciones de código:
- Widgets en `PascalCase` y un archivo por widget (`StatusBadge` → `status_badge.dart`).
- Variables, métodos y campos JSON en inglés; los textos que ve el usuario en español.
- Modelos con `fromJson` / `toJson` explícitos; nunca mapear "a mano" dentro del widget.
- Nada de lógica de negocio en widgets: va en repositorios/servicios.

### 4.1 Cascarón de navegación (shell)

La app es **una sola instancia** con navegación inferior persistente. Las pestañas se muestran u
ocultan según **módulos activos y permisos**:

| Pestaña | Se muestra si | Contenido |
|---|---|---|
| **Vender** | `module_pos` + `pos.access` | Catálogo, carrito, cobro, apartados y pedidos |
| **Órdenes** | `module_services` + `services.orders.access` | Listado y detalle de órdenes de servicio |
| **Caja** | `pos.access` | Sesión actual, apertura, corte/cierre, cuentas bancarias |
| **Ventas** | `transactions.access` | Historial y detalle de ventas, abonos |
| **Cuenta** | siempre | Perfil, sucursal, suscripción, notificaciones, soporte, cerrar sesión |

Si solo hay 2 pestañas disponibles (por ejemplo un técnico sin POS), la barra inferior se reduce a
esas dos; si no hay ninguna, se muestra un estado vacío con el mensaje del módulo no contratado.

**Barra superior de cada pantalla**
- Título de la pantalla (sin margen, `text-2xl font-light tracking-tight`).
- Icono de **campana** con badge (`GET /notifications`) — solo si `transactions.access`.
- Botón de **sucursal activa** (solo si hay más de una sucursal y `system.branches.switch`),
  que muestra el nombre del negocio y la sucursal, igual que el topbar web.
- Acceso directo al **menú de cuenta** (avatar) en la pestaña "Cuenta".

**Equivalencias con la barra superior web (`AppTopbar.vue`)**

| Web | Móvil |
|---|---|
| Selector de sucursal (desktop y menú móvil) | Botón "Sucursal" en la cabecera + pantalla dedicada en Cuenta |
| Campana de notificaciones | Badge en la cabecera → pantalla de notificaciones |
| Menú de usuario: Perfil | Pestaña Cuenta → "Mi perfil" |
| Menú de usuario: Suscripción (solo propietario) | Pestaña Cuenta → "Mi suscripción" (oculto si no es propietario) |
| Menú de usuario: Soporte | Pestaña Cuenta → "Centro de soporte" |
| Menú de usuario: Cerrar sesión | Pestaña Cuenta → "Cerrar sesión" (con confirmación) |
| Modo oscuro (toggle) | La app abre en modo oscuro; el cambio a claro se guarda en preferencias locales del dispositivo |
| Notas de la versión / Referidos | Fase 6 (opcional) |

**Reglas de sesión en el shell**
- Al abrir la app: si hay token guardado → `GET /auth/me`; si falla con `401` → login.
- Si el usuario cae en una pestaña sin permiso (por cambio de sucursal o de permisos), se le
  devuelve a la primera pestaña disponible con el mensaje del servidor.
- El badge de la pestaña **Caja** muestra un punto pulsante cuando hay una sesión abierta.

---

## 5. Autenticación, sesión y permisos

Flujo completo:

1. **Login** (`POST /auth/login`): el usuario captura email y contraseña. La app guarda en
   `flutter_secure_storage`: `token`, `user`, `permissions`, `module_keys`, `branch`.
2. **Contexto:** al terminar el login (y en cada arranque con sesión guardada) se llama
   `GET /auth/me` para refrescar permisos, módulos y la sesión de caja.
3. **Headers en cada petición:** `Authorization: Bearer <token>` y `Accept: application/json`.
4. **`401`:** cerrar sesión local, limpiar token y enviar a login con el mensaje
   "Tu sesión expiró. Inicia sesión de nuevo."
5. **`403`:** mostrar el mensaje del servidor y **no** reintentar.
6. **Logout:** llamar `POST /auth/logout`, borrar el token, la base local y la cola pendiente
   (avisando si quedan operaciones sin sincronizar).
7. **Sin conexión:** la app permite navegar y vender con el token guardado; los datos se leen
   de la caché local y las escrituras van a la cola. Al recuperar conexión, se sincroniza.

### Permisos en la UI
`PermissionsService` expone `can(String permission)` a partir de la lista que devuelve el login.
**La Fase 0 ya entrega esa lista** (`user.permissions` en `POST /auth/login` y `GET /auth/me`),
filtrada por los módulos contratados y con la misma lógica que la web.
- Sin `pos.access` → no se muestra el módulo POS.
- Sin `pos.create_sale` → catálogo en modo consulta (sin botón "Cobrar").
- Sin `pos.edit_prices` → el precio del carrito es de solo lectura.
- Sin `services.orders.change_status` → el stepper de estatus es informativo.
- Sin `transactions.add_payment` → sin botón "Registrar abono".
- Sin `services.orders.create` → sin botón "Nueva orden".

> La lista completa de permisos por endpoint está en `01-contrato-api-v1.md` §3.

### Módulos habilitados
`module_keys` (`module_pos`, `module_services`, `module_transactions`, …) indican qué contrató el
negocio. Si `module_pos` no está, la app oculta el POS completo aunque el usuario tenga permisos.

---

## 6. Configuración de entorno

```dart
class AppConfig {
  static const apiBaseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: 'https://<dominio-de-produccion>/api/v1',
  );
  static const connectTimeout = Duration(seconds: 15);
  static const receiveTimeout = Duration(seconds: 30);
  static const uploadTimeout = Duration(minutes: 2); // evidencias
  static const currency = 'MXN';
  static const locale = 'es_MX';
}
```
- El proyecto debe instalar **release** con `--dart-define=API_BASE_URL=https://…/api/v1`.
- Solo HTTPS en producción; certificado válido (sin `badCertificateCallback`).
- Timeout de subida amplio para fotos; timeout corto para lecturas.
- Reintentos automáticos con espera exponencial **solo** en `GET` y en escrituras idempotentes
  (las que llevan `client_uuid`).

---

## 7. Módulo POS

### 7.1 Pantallas

| Pantalla | Contenido | Endpoints |
|---|---|---|
| **Cierre de caja (corte)** | Resumen del turno (fondo inicial, ventas por método, ingresos/egresos, total esperado, cuentas bancarias) → captura del efectivo contado con diferencia en vivo → notas de arqueo → "Finalizar turno". | `GET /cash-register-sessions/{id}/summary`, `PUT /cash-register-sessions/{id}` |
| **Apertura / selección de caja** | Si no hay sesión: **1)** con terminales libres → formulario "Inicializar caja" (terminal, fondo de efectivo y saldos bancarios) y botón "Iniciar turno"; **2)** sin terminales libres pero con sesiones abiertas → lista con botón "Unirme"; **3)** sin nada disponible → "Pide que abran caja desde la versión web". | `GET /cash-register-sessions/current`, `POST /cash-register-sessions`, `POST /cash-register-sessions/{id}/join` |
| **Catálogo** | Buscador fijo arriba (nombre/SKU), chips de categoría horizontales, grid de tarjetas de producto (imagen, nombre, precio, stock, badge de promoción). | `GET /catalog/products`, `GET /catalog/categories` |
| **Detalle de producto** | Bottom sheet con imágenes, descripción, selector de variantes (chips por atributo), cantidad, precio (con mayoreo si aplica) y botón "Agregar". | `GET /catalog/products/{id}` |
| **Carrito** | Bottom sheet a pantalla completa: cliente, lista de líneas (cantidad ±, precio editable si hay permiso, subtítulo de descuento), total, botones "Cobrar", "Apartar", "Pedido". | — |
| **Cobro** | Monto total grande, campo "Recibido" con numpad, cálculo de cambio en vivo, botón "Agregar método" (efectivo/tarjeta/transferencia/saldo a favor), propina **no** existe. | `POST /pos/checkout`, `GET /bank-accounts` |
| **Apartado** | Igual que cobro + selector de fecha límite (`layaway_expiration_date`). | `POST /pos/layaway` |
| **Pedido / comanda** | Datos de contacto (nombre, teléfono), fecha y hora de entrega, dirección, costo de envío, notas, tipo (`pedido`/`comanda`). | `POST /pos/store-order` |
| **Ticket** | Vista previa del ticket con acciones "Imprimir" y "Enviar por WhatsApp". | `POST /print/bluetooth-payload`, `POST /print/whatsapp-ticket` |
| **Historial de ventas** | Lista con búsqueda, filtro por estatus y fechas; cada fila muestra folio, cliente, total, estatus y saldo pendiente. | `GET /transactions` |
| **Detalle de venta** | Ítems, pagos, totales, datos del cliente, fecha de entrega/apartado y acciones según estatus. | `GET /transactions/{id}` |
| **Registrar abono** | Métodos de pago + "usar saldo a favor"; al confirmar, muestra el ticket de abono. | `POST /transactions/{id}/payments` |

### 7.2 Precios y descuentos (regla exacta)

1. `original_price` = `products.selling_price` (lo que se muestra tachado si hay promoción).
2. `price` = precio final del producto tras promociones de línea (`ITEM_DISCOUNT`).
3. **Variante:** precio = `selling_price + variant_combinations[].price_modifier`
   (y luego las promociones aplicables).
4. **Mayoreo (`price_tiers`):** con la cantidad de la línea, buscar el tier con mayor
   `min_quantity` que cumpla `quantity >= min_quantity` y usar su `price`.
   Ejemplo: `[{"min_quantity":6,"price":130}]` → 5 piezas a 150, 6 piezas a 130.
5. **Descuento manual:** permitido solo con `pos.edit_prices`; se refleja como
   `unit_price` menor y `discount = original_price - unit_price` con
   `discount_reason = "Descuento manual"`. Si el vendedor **sube** el precio, se envía
   `discount: 0` y `discount_reason: "Aumento manual"`.
6. **Totales:** `subtotal = Σ(unit_price × quantity)`,
   `total_discount = Σ(discount × quantity)`, `total = subtotal - total_discount`
   (+ `shipping_cost` si es pedido). `total_tax` es siempre 0.
7. **Promociones de carrito** (mínimo de compra, N×M): las evalúa el servidor al cobrar; la app
   puede mostrar el aviso de la promoción pero **no** descuenta por su cuenta.

### 7.3 Cobro (reglas)

- Métodos: `efectivo`, `tarjeta`, `transferencia`, `saldo` (interfaz) → el servidor espera
  `efectivo` | `tarjeta` | `transferencia` y el saldo se envía como `use_balance: true`.
- `bank_account_id` obligatorio para `tarjeta` y `transferencia` (`GET /bank-accounts`).
- La app **puede** enviar varios pagos (pago mixto) y un monto mayor al total: el servidor
  recorta y devuelve `change`.
- El cambio se calcula al mostrar: `change = Σ pagos en efectivo - total` (nunca negativo).
- Si no se cubre el total:
  - con cliente → confirmar que quedará **a crédito** (estatus `pendiente`) y avisar el
    `available_credit` insuficiente antes de enviar;
  - sin cliente → bloqueado: "Selecciona un cliente para dejar saldo pendiente."
- Si el cliente tiene saldo a favor, ofrecer "Usar saldo a favor" (`use_balance: true`).

### 7.4 Después de cobrar
1. Mostrar el folio real (`V-014`) y el cambio.
2. Ofrecer imprimir (Bluetooth) o enviar por WhatsApp.
3. Refrescar el catálogo local (`GET /catalog/products`) para actualizar stock.
4. Si la venta se guardó offline: mostrar folio `TMP-XXXXXX` con la etiqueta
   "Pendiente de sincronizar" y el ticket impreso localmente.

### 7.5 Apartados y pedidos
- **Apartado** (`apartado`): el stock queda **reservado**; requiere `layaway_expiration_date`.
  El detalle debe mostrar los días restantes y avisar cuando venza.
- **Pedido / comanda** (`por_entregar`): requiere `contact_info.name` y `delivery_date`.
  Los pedidos se cobran total o parcialmente (abonos) y se consultan en el historial.
- Al cancelar un apartado o pedido, el servidor **libera la reserva** de stock.
- La app **no** cambia el estatus logístico (`en_ruta`, `entregado_por_pagar`) en esta versión;
  eso se sigue haciendo desde la web.

### 7.6 Apertura de caja (capacidad confirmada)

Pantalla "Inicializar caja" (equivalente a `resources/js/Components/StartSessionModal.vue`):

1. **Terminal:** selector con las cajas de la sucursal que estén `is_active = true` e `in_use = false`.
   Placeholder: "Seleccionar terminal libre…".
2. **Fondo de efectivo:** `opening_cash_balance`, obligatorio y ≥ 0, capturado en formato moneda `es-MX`.
3. **Declaración de fondos iniciales:** un campo por cada cuenta de `bank_accounts`, precargado con
   su saldo actual y editable. Etiqueta: "Saldo en banco: {bank_name} ({account_name})".
4. Botón **"Iniciar turno"** → `POST /cash-register-sessions`.

Reglas:
- **Requiere conexión** (regla de oro 7). Sin red y sin sesión, el POS muestra
  "Necesitas conexión para abrir caja".
- Si el servidor responde `cash_register_in_use`, la app ofrece **"Unirme a esa sesión"**
  (`POST /cash-register-sessions/{id}/join`) en lugar de mostrar un error seco.
- Al abrir con éxito: guardar la sesión en caché y **habilitar el cobro de inmediato**;
  no es necesario recargar el catálogo ni llamar de nuevo a `current`.
- La app **no registra movimientos de efectivo** (ingresos/egresos): eso se hace en la web.
  El cierre de la caja sí se hace desde la app (ver §7.7).
- **Cierre remoto:** si otro usuario cierra la sesión desde la web mientras el teléfono vende,
  la app debe reaccionar. Estrategia:
  - escuchar el canal `cash-register-session.{id}` (evento `App\Events\SessionClosed` vía
    Pusher/Echo, como lo hace `AppLayout.vue`), o
  - refrescar `GET /cash-register-sessions/current` al volver la app a primer plano y cada 60 s.
  Al detectar el cierre: mostrar "La sesión de caja se cerró. Abre caja para seguir vendiendo."
  y regresar a la pantalla de apertura.
- **Idempotencia:** reintentar la apertura con el mismo `client_uuid` **no** crea una segunda sesión
  ni deja dos cajas `in_use`.

### 7.7 Cierre de caja / corte (capacidad confirmada)

Réplica móvil de `resources/js/Components/CloseSessionModal.vue`, que en la web tiene tres vistas:
`initial` (resumen) → `confirmClose` (aviso si hay más usuarios) → `finalClose` (arqueo).

**Paso 1 — Resumen del turno** (`GET /cash-register-sessions/{id}/summary`):

| Bloque | Contenido |
|---|---|
| Sesión | Terminal, quién la abrió, hora de apertura, usuarios participantes |
| Efectivo | Fondo inicial, ventas en efectivo, ingresos, egresos, **total esperado** |
| Cobros por método | Efectivo, tarjeta, transferencia, saldo a favor |
| Movimientos | Ingresos y egresos manuales con su descripción y usuario |
| Bancos | Por cuenta: saldo inicial, recibido, gastado, transferencias y saldo final |

**Paso 2 — Advertencia de usuarios:** si `session.users.length > 1`, mostrar
"Hay N usuarios en esta sesión; al cerrarla, todos saldrán de la caja." y pedir confirmación
(equivalente a `isLastUser` en la web).

**Paso 3 — Arqueo:** campo "Efectivo físico contado" (`closing_cash_balance`, obligatorio) con
monto grande, y **diferencia en vivo** (`contado − esperado`):
- `diferencia === 0` → banner verde con check ("Sin diferencia").
- `diferencia !== 0` → banner naranja con triángulo y el monto ("Descuadre / Diferencia").
Después, "Notas de arqueo (Opcional)" y el botón **"Finalizar turno"** (rojo/danger).

Reglas:
- **Requiere conexión**; sin red el botón queda deshabilitado con
  "Necesitas conexión para cerrar la caja."
- Solo puede cerrarla un usuario que participe en la sesión.
- Al confirmar: limpiar la sesión y el carrito de la caché, volver a la pantalla de apertura y
  ofrecer **imprimir el corte** (encoder ESC/POS local) o **enviarlo por WhatsApp**.
- Si otro dispositivo cierra la sesión al mismo tiempo, el servidor responde `session_not_open`
  y la app debe mostrar "Esa sesión de caja ya fue cerrada." y refrescar el estado.
- La app **no** edita el corte después de cerrarlo ni registra movimientos de efectivo.

---

## 8. Módulo Órdenes de servicio

### 8.1 Pantallas

| Pantalla | Contenido | Endpoints |
|---|---|---|
| **Listado** | Buscador (folio, cliente o equipo), filtro por estatus con chips, tarjetas con folio, cliente, equipo, estatus, fecha prometida y saldo pendiente. | `GET /service-orders` |
| **Detalle** | Stepper de estatus, datos del cliente y del equipo, problemas reportados, diagnóstico, ítems (mano de obra + refacciones), panel financiero (subtotal, descuento, total, pagado, saldo), anticipos, evidencias (inicial y final), historial de cambios. | `GET /service-orders/{id}` |
| **Nueva orden** | Formulario completo: cliente (existente o alta rápida), equipo, problemas, promesa de entrega, técnico + comisión, ítems, descuento, campos personalizados y fotos iniciales. | `POST /service-orders` |
| **Editar orden** | Igual que la nueva, precargada, con opción de eliminar evidencias existentes. | `PUT /service-orders/{id}` |
| **Diagnóstico** | Texto del diagnóstico + cámara/galería para fotos finales. | `POST /service-orders/{id}/diagnosis` |
| **Cobro de anticipo** | Reutiliza la pantalla de abono y envía el pago contra la orden. | `POST /service-orders/{id}/payments` |
| **Ticket de la orden** | Recibo de servicio: imprimir o enviar por WhatsApp. | `POST /print/*` con `data_source_type = service_order` |

### 8.2 Flujo de estatus (stepper)

`pendiente` → `en_progreso` → (`esperando_refaccion`) → `terminado` → `entregado`

- El stepper muestra 5 pasos con los iconos y colores de `03-design-system-tesla-ui.md` §7.
- Un paso a la derecha (avanzar) requiere `services.orders.change_status`.
- Un paso a la izquierda (regresar) requiere `services.orders.edit` y confirmación explícita:
  "¿Seguro que quieres regresar la orden a esta etapa? Esto podría anular el progreso de las
  etapas posteriores."
- `cancelado` pinta el estado como banda roja y bloquea todo cambio de estatus.
- **Al pasar a `entregado` con saldo pendiente (`amount_due > 0`)**, la app abre el cobro
  (mismo comportamiento que la web, que emite `requirePayment`).
- Antes de cambiar de estatus con operaciones pendientes de sincronizar, la app avisa y espera
  conexión (el estatus es una operación de escritura).

### 8.3 Formulario de nueva orden (reglas)

- Cliente: selector con búsqueda (`GET /customers?search=`). Se puede capturar un cliente nuevo
  con `create_customer: true` + `credit_limit`; o dejar `customer_id: null` y capturar los datos
  a mano (orden de mostrador).
- Ítems: se agregan desde el catálogo de **servicios** (`GET /catalog/services`, con variantes) o
  de **productos** (`GET /catalog/products`, refacciones). Cada línea: descripción, cantidad,
  precio unitario y total. Los ítems de producto **descuentan stock** al guardar.
- Técnico: switch "Asignar técnico"; al activarse pide nombre, tipo de comisión
  (`percentage` | `fixed`) y valor, ambos obligatorios.
- Descuento: tipo (`fixed` | `percentage`), valor y monto calculado; de ahí sale `final_total`.
- Campos personalizados: se renderizan desde `custom_field_definitions` (texto, número, switch).
- Evidencias: hasta 5 fotos (`initial_evidence_images[]`), comprimidas antes de subir.
- Requiere sesión de caja abierta (`cash_register_session_id`), porque el servidor crea la venta
  vinculada.

### 8.4 Cobros de una orden (anticipos)

- La orden nace con una **venta vinculada** (`OS-V-###`) por el `final_total`; el saldo se calcula
  con `transaction.remaining_due`.
- Los anticipos se registran con `POST /service-orders/{id}/payments` (efectivo / tarjeta /
  transferencia / saldo a favor). El ticket de abono se muestra con el `print.payload` que
  devuelve el servidor.
- Si una orden antigua no tiene venta (`transaction: null`), la app llama primero a
  `POST /service-orders/{id}/ensure-transaction`.
- Los anticipos **no** se editan desde la app: para corregir hay que ir a la venta en la web
  (la UI móvil lo dice explícitamente).

### 8.5 Comisión del técnico y utilidad (informativo)

Se calcula igual que en la web (`OrderFinancialPanel.vue`):
- `parts_cost` = Σ(`unit_price` × `quantity`) de los ítems `Product` / `ProductAttribute`.
- Si la comisión es `percentage`: `comision = max(0, final_total - parts_cost) × (valor / 100)`.
- Si es `fixed`: `comision = valor`.
- `utilidad_neta = final_total - (comision + parts_cost)`.
Se muestra **solo** si el usuario tiene `services.orders.see_financial_info`.

---

## 9. Impresión y WhatsApp (resumen)

Detalle completo en `01-contrato-api-v1.md` §10.

| Necesidad | Cómo se resuelve |
|---|---|
| Ticket con la plantilla del negocio | `POST /print/bluetooth-payload` → `commands_base64` → enviar bytes a la impresora BT |
| Enviar ticket por WhatsApp | `POST /print/whatsapp-ticket` → abrir `https://wa.me/{phone}?text=…` |
| Etiquetas (TSPL) | `POST /print/payload` → `operations` |
| Respaldo sin impresora | `POST /print/ticket-html` → PDF o compartir |
| Imprimir **sin conexión** | Encoder ESC/POS local en la app (ticket mínimo 58/80 mm) |

Reglas:
- La app **no** reimplementa plantillas: usa el servidor cuando hay red.
- Envío Bluetooth en **chunks de 20 bytes** con pausa de 25 ms.
- Permisos Android 12+: `BLUETOOTH_CONNECT` y `BLUETOOTH_SCAN`.
- Nunca imprimir automáticamente el mismo ticket dos veces; marcar el ticket como impreso.
- Si no hay teléfono del cliente, abrir WhatsApp sin destinatario para elegir contacto.

---

## 9b. Cuenta: sucursal, perfil, suscripción, soporte y notificaciones

Todo vive en la pestaña **Cuenta** del shell (§4.1) y es el equivalente móvil del menú de usuario
del topbar web. Endpoints y payloads completos en `01-contrato-api-v1.md` §11b.

### 9b.1 Sucursal activa
- Se muestra arriba: nombre del negocio (`subscription.commercial_name`) y la sucursal activa
  (`current_branch.name`), con el mismo criterio visual que el topbar web.
- Solo aparece si hay más de una sucursal y el usuario tiene `system.branches.switch`.
- Al tocar: pantalla con la lista de sucursales (`available_branches`); la activa lleva un check
  verde. Elegir otra ejecuta `PUT /branch/switch/{branch}` con confirmación previa.
- **Bloqueos:** no se permite cambiar de sucursal si hay operaciones sin sincronizar
  (`unsynced_operations`). Si el super admin (`user.id === 1`) entra en "modo soporte", la lista
  viene agrupada por suscripción.
- Tras cambiar: limpiar la caché de la sucursal anterior, refrescar `GET /auth/me`, re-sincronizar
  el catálogo y volver a la pantalla de apertura de caja.

### 9b.2 Mi perfil
Tres bloques, igual que las pestañas de la web (`Profile/Show.vue`):

| Bloque | Qué permite | Endpoint |
|---|---|---|
| **Información personal** | Foto (tomar/cambiar/eliminar), nombre, correo | `GET /profile`, `PUT /profile`, `DELETE /profile/photo` |
| **Seguridad** | Cambiar contraseña | `PUT /profile/password` |
| **Sesiones activas** | Cerrar las demás sesiones/dispositivos | `POST /profile/logout-other-devices` |

- Al cambiar el correo se envía un **código OTP** al nuevo correo; la app lo avisa y no marca la
  cuenta como verificada hasta la confirmación.
- Ya en Fase 6 (opcional): autenticación de dos factores y lista de dispositivos con token.

### 9b.3 Mi suscripción (solo propietario)
Visible únicamente si `is_subscription_owner = true` (el servidor devuelve `403` a los empleados).

| Bloque | Contenido |
|---|---|
| Estado | Etiqueta (activa / por vencer / expirada / suspendida), fecha de vencimiento y días restantes |
| Datos generales | Nombre comercial, razón social, teléfono de contacto, dirección (editables por el propietario) |
| Plan | Módulos contratados (activos/inactivos) y límites con su consumo (sucursales, usuarios, productos…) |
| Sucursales y bancos | Listado informativo de sucursales y cuentas de la suscripción |
| Historial | Versiones del plan con sus pagos (folio, monto, estatus, fecha) y acción "Solicitar factura" |
| Documentos | Constancia/documento fiscal subido (`POST /subscription/documents`) |
| Acción | **"Renovar o mejorar plan"** → abre `https://<dominio>/subscription/manage` en el navegador externo |

- **Editar** nombre comercial/razón social/teléfono/dirección/horarios: sí (`PUT /subscription`).
- **Pagar/renovar dentro de la app:** no en esta versión (el checkout de Mercado Pago vive en la
  web). Se documentará el flujo nativo en Fase 6.
- El banner de estado se muestra también en la parte superior de la app cuando la suscripción está
  por vencer o expirada (equivalente a `subscriptionWarning` del layout web).

### 9b.4 Centro de soporte
Pantalla informativa alimentada por `GET /support`: título "Centro de soporte", subtítulo
"Estamos aquí para ayudarte", mensaje, **horario de atención** y canales:
- **Correo electrónico:** notificaciones@ezyventas.com (abre `mailto:`).
- **WhatsApp:** +52 33 2170 5650 (abre `https://wa.me/5213321705650`).
Además, acceso al **Centro de ayuda** web (`/centro-ayuda`) dentro de la app y una lista de temas
de ayuda (primeros pasos, facturación, mi cuenta, inventario).

### 9b.5 Notificaciones
Pantalla con las 4 categorías (`GET /notifications`): deudas por vencer, entregas próximas,
novedades sin leer y pedidos pendientes de la tienda en línea. Cada fila navega al listado
correspondiente. Sin conexión se muestra el último valor cacheado.

### 9b.6 Cerrar sesión
Botón con confirmación ("¿Quieres cerrar sesión?"). Llama a `POST /auth/logout`, borra el token,
la base local y la cola de sync. **Si hay operaciones sin sincronizar**, la app lo advierte primero
("Tienes N operaciones sin sincronizar. Si cierras sesión se perderán.") y exige confirmación.

## 10. Modo offline y sincronización

### 10.1 Qué funciona sin conexión
| Funciona offline | Requiere conexión |
|---|---|
| Ver catálogo, clientes y ventas/órdenes ya descargados | **Abrir caja** y **cerrar caja (corte)** |
| Armar carrito, cobrar, apartar, crear pedidos, cambiar estatus de órdenes | **Cambiar de sucursal** |
| Registrar abonos (se encolan) | **Mi perfil** (datos, contraseña, foto) y **Mi suscripción** |
| Imprimir con el encoder local (tickets y corte) | Crear una orden (necesita folio y venta vinculada) |
| Ver la sesión de caja activa y el último corte guardado | Unirse a una sesión, subir evidencias, WhatsApp |
| Ver el último valor cacheado de notificaciones y soporte | Cobrar con la plantilla de impresión del servidor |

### 10.2 Modelo local (SQLite)
Tablas espejo mínimas: `products`, `product_variants`, `customers`, `transactions`,
`transaction_items`, `payments`, `service_orders`, `service_order_items`,
`cash_register_sessions`, `bank_accounts`, `print_templates`, `custom_field_definitions`,
`sync_cursors` (fecha del último pull por entidad) y `outbox_operations`.

### 10.3 Cola de operaciones (outbox)
Cada operación pendiente guarda: `client_uuid`, `type`, `payload`, `entity_id`, `created_at`,
`attempts`, `last_error`, `status` (`pending` | `synced` | `requires_review`).

Ciclo:
1. La app escribe la operación localmente y la presenta como si hubiera tenido éxito
   (folio `TMP-XXXXXX`, etiqueta "Pendiente de sincronizar").
2. Al recuperar conexión, `POST /sync/push` envía el lote **en orden**.
3. Con `applied`/`duplicate` se actualiza el folio, el `server_id` y se borra de la cola.
4. Con `failed` se reintenta con espera exponencial (1 min, 5 min, 15 min, 1 h).
5. Tras 5 intentos → `requires_review`: aparece en la pantalla "Pendientes de revisar" con el
   mensaje del servidor y opciones "Reintentar" / "Descartar" (descartar solo con confirmación).

### 10.4 Pull de datos
`GET /sync/manifest` al iniciar; `GET /sync/pull?entity=…&since=…` cada 5 minutos (o al abrir cada
módulo) usando `sync_cursors`. Reglas:
- Nunca hacer pull completo en cada arranque (solo con `since`).
- Respetar `deleted_ids` para borrar registros locales.
- Las ventas y órdenes se sincronizan hacia abajo con ventana de 60 días.
- El refresh de catálogo **reemplaza** precios y stock locales (el servidor es la verdad).

### 10.5 Conflictos y sobreventa
- **Precio o stock cambiaron en el servidor:** gana el servidor para futuras ventas; las ya
  enviadas conservan el `unit_price` con el que se cerraron.
- **Sobreventa offline (permitida):** si el stock resultante queda negativo, el servidor responde
  `applied` + `flags: ["negative_stock"]`. La venta se marca "Requiere revisión".
- **Sesión de caja cerrada mientras el dispositivo estaba offline:** la operación falla con
  `session_closed`; queda en revisión y el POS se bloquea hasta obtener sesión abierta.
- **Permisos revocados:** si el push devuelve `permission_denied`, se marca en revisión; el usuario
  deberá rehacer la operación con permiso válido.
- Nunca resolver conflictos "en silencio": siempre mostrar la etiqueta al usuario.

---

## 11. Reglas de negocio críticas (resumen operativo)

| Tema | Regla |
|---|---|
| Folios | `V-###` ventas, `OS-###` órdenes, `OS-V-###` venta de una orden, `ABONO-###` abonos a saldo. Siempre del servidor |
| Stock | `disponible = current_stock - reserved_stock`. Se descuenta al cobrar; se **reserva** en apartados y pedidos; se **libera** al cancelarlos |
| Crédito | `available_credit = credit_limit + balance` si `balance < 0`; el total de la venta no puede excederlo |
| Saldo a favor | `balance > 0`; se usa como pago con `use_balance` (se registra como método `saldo`) |
| Pagos | Nunca exceden el total: el servidor recorta y devuelve el cambio calculado |
| Caja | Toda venta, apartado, pedido, anticipo, cancelación con devolución en efectivo y creación de orden exigen sesión `abierta` |
| Apertura de caja | La app **puede abrirla**: requiere una terminal libre (`is_active` y `!in_use`), fondo de efectivo ≥ 0 y declaración de saldos bancarios. Si la terminal ya está en uso, se ofrece unirse a la sesión existente |
| Cierre de caja | **Desde la app** (y también desde la web): `closing_cash_balance` + notas; el servidor calcula el total esperado, la diferencia, concilia los bancos y libera la terminal |
| Sesión remota | Si otro usuario cierra la sesión, la app lo detecta y vuelve a la pantalla de apertura |
| Movimientos de efectivo | La app **no** crea ingresos/egresos manuales (`session_cash_movements`); eso es web |
| Cambio de sucursal | Requiere `system.branches.switch`, no permite operaciones sin sincronizar y reinicia la caché local |
| Órdenes | Al crearse generan una venta vinculada y, con cliente, la deuda correspondiente |
| Órdenes entregadas | No deben quedar con saldo pendiente: la app ofrece cobrar antes de avanzar el estatus |
| Cancelación | Devuelve stock y revierte deuda/saldo; `apartado`/`por_entregar` liberan reserva |
| Reembolso | Método obligatorio (`cash` requiere caja, `balance` requiere cliente, `transfer` requiere cuenta) |
| Impuestos | `total_tax` siempre 0 en POS: no hay motor fiscal en esta versión |
| Zona horaria | El servidor guarda en UTC y la sucursal tiene `timezone`; la app muestra en hora local del dispositivo |

---

## 12. Convenciones de texto e interacción

- **Idioma de UI:** español, siempre en **sentence case**.
  ✅ "Guardar venta", "Registrar abono", "Órdenes de servicio"
  ❌ "Guardar Venta", "REGISTRAR ABONO"
- **Micro-etiquetas de campo:** mayúsculas técnicas de 10 px (ver `03-design-system-tesla-ui.md`).
- **Montos:** `$1,240.00 MXN`, cifras tabulares, sin símbolo doble.
- **Fechas:** `18 sep 2026, 14:35` (hora local del dispositivo).
- **Confirmaciones destructivas** siempre con texto explícito; nunca solo "OK".
- **Estados vacíos:** con una frase útil y una acción sugerida.
- **Cargando:** skeletons en listas, spinner solo en acciones puntuales.
- **Errores:** mensaje del servidor + acción ("Reintentar"), nunca un código numérico.
- **Accesibilidad:** área táctil mínima 48 px; contraste AA en ambos temas.

---

## 13. Roadmap con criterios de aceptación

### Fase 0 — Base de API (backend) — ✅ IMPLEMENTADA (18 sep 2026)
`Api/V1/Auth/AuthController` con `POST /api/v1/auth/login` (throttle 5/min por correo + IP),
`GET /api/v1/auth/me` y `POST /api/v1/auth/logout`, respuestas JSON uniformes en `/api/*`
(`app/Exceptions/Api/ApiExceptionRenderer.php`) y middleware `EnsureApiSubscriptionIsActive`.
La tabla `personal_access_tokens` ya existía en la BD, así que **no hizo falta migración**.
Arquitectura: `LoginMobileUserAction` → `UserAccessContextService` + `SubscriptionAccessGuard`;
rutas en `routes/api/v1/auth.php`; rate limiters en `AppServiceProvider`.
✅ Criterio cumplido: `php artisan route:list --path=api` lista `api/v1/auth/login|me|logout`;
login devuelve token + permisos; sin token → `401` JSON; sin permiso → `403` JSON;
`tests/Feature/Api/V1/AuthTest.php` pasa con 10 casos.

### Fase 1 — Lectura (app + API) — ✅ IMPLEMENTADA (18 sep 2026)
Catálogo, categorías, servicios, clientes, sesión de caja y cuentas bancarias.
Endpoints listos: `GET /catalog/products`, `GET /catalog/products/{id}`, `GET /catalog/categories`,
`GET /catalog/services`, `GET /customers`, `GET /customers/{id}`, `POST /customers`,
`GET /cash-register-sessions/current` y `GET /bank-accounts`.
La lógica de precios, promociones, variantes y stock se **extrajo** de `PointOfSaleController`
a `App\Services\Catalog\ProductCatalogService`, que ahora alimenta a la web **y** a la app: el
POS web sigue funcionando igual (cubierto por `tests/Feature/PointOfSaleControllerTest.php`) y ya
no puede haber discrepancias de precios entre ambos clientes.
Tests: `tests/Feature/Api/V1/{CatalogTest,CustomerApiTest,CashRegisterSessionApiTest,BankAccountApiTest}.php`.
✅ Criterio cumplido: la app lista productos con el stock y el precio correctos de su sucursal
(incluidas variantes y promociones), busca y crea clientes, sabe si hay sesión de caja abierta
(y cuáles puede abrir o a cuáles unirse) y conoce los saldos bancarios a declarar.

### Fase 2 — Órdenes de servicio y ventas (lectura + estatus/diagnóstico) — ✅ IMPLEMENTADA (18 sep 2026)
Listado y detalle de ventas y órdenes, cambio de estatus y diagnóstico con fotos.
Endpoints listos: `GET /transactions`, `GET /transactions/{id}`, `GET /service-orders`,
`GET /service-orders/{id}`, `PATCH /service-orders/{id}/status` y
`POST /service-orders/{id}/diagnosis` (multipart con hasta 5 fotos).
El cambio de estatus reutiliza `ChangeServiceOrderStatusAction` (el mismo que usa la web: al
cancelar devuelve stock y ajusta la venta vinculada) y el diagnóstico usa
`SaveServiceOrderDiagnosisAction`, que ahora también usa la web: **las fotos tomadas en el teléfono
aparecen en la orden de la web** y las de la web en el teléfono.
Tests: `tests/Feature/Api/V1/TransactionApiTest.php` y `ServiceOrderApiTest.php`.
✅ Criterio cumplido: cambiar el estatus desde el teléfono se refleja en la web (y queda en el
historial de la orden, que la app muestra en el detalle); las fotos del diagnóstico se ven en
ambos clientes; las ventas y órdenes de la sucursal se listan y se abren con su detalle completo,
y todo lo de otras sucursales queda oculto (`404`).

### Fase 3 — Escrituras (POS, caja y cobros) — ✅ IMPLEMENTADA (18 sep 2026)
**Apertura de caja** (`POST /cash-register-sessions`) y unirse a la sesión, `pos/checkout`,
`pos/layaway`, `pos/store-order`, abonos, alta/edición de órdenes, anticipos, reparación de
órdenes antiguas y cancelaciones/reembolsos.
Endpoints listos: `POST /cash-register-sessions`, `POST /cash-register-sessions/{id}/join`,
`POST /pos/checkout`, `POST /pos/layaway`, `POST /pos/store-order`,
`POST /transactions/{id}/payments`, `POST /transactions/{id}/cancel`, `POST /transactions/{id}/refund`,
`POST /service-orders`, `PUT /service-orders/{id}`, `POST /service-orders/{id}/ensure-transaction` y
`POST /service-orders/{id}/payments`.
Para no duplicar reglas de negocio se extrajeron tres piezas compartidas con la web:
`CashRegisterSessionOpenService` (apertura con snapshot de saldos bancarios),
`TransactionCancellationService` (stock, deuda y salida de dinero) y `CreateStoreOrderAction`
(pedidos). El resto se apoya en los servicios y acciones que ya usaba la web
(`TransactionPaymentService`, `CreateServiceOrderAction`, `UpdateServiceOrderAction`,
`EnsureServiceOrderTransactionAction`). Todos los endpoints de escritura exigen una **sesión de caja
abierta de la sucursal** (`422 session_required`) donde aplica.
Tests: `PosApiTest`, `TransactionWriteApiTest`, `ServiceOrderWriteApiTest` y los casos nuevos de
`CashRegisterSessionApiTest` (93 tests verdes en `tests/Feature/Api/V1`).
✅ Criterio cumplido: se puede **abrir caja desde el teléfono** con fondo de efectivo y saldos
bancarios (y unirse a la caja de un compañero cuando ya está abierta); una venta, apartado o pedido
hecho en el teléfono aparece en la web con el mismo folio, stock descontado o reservado, deuda
correcta y dentro del corte de la sesión; los abonos y anticipos actualizan la deuda del cliente; y
una orden de servicio creada desde el teléfono se ve en la web con su venta vinculada.

### Fase 4 — Impresión, caja (corte) y cuenta — ✅ IMPLEMENTADA (18 sep 2026)
Plantillas, ESC/POS Bluetooth, ticket HTML, WhatsApp, edición de pagos, **corte/cierre de caja**,
y el bloque **cuenta**: cambio de sucursal, perfil (datos, contraseña, foto, otras sesiones),
notificaciones, soporte y vista de suscripción.
Endpoints listos: `GET /cash-register-sessions/{id}/summary`, `PUT /cash-register-sessions/{id}`,
`POST /cash-register-sessions/{id}/leave`, `POST /cash-register-sessions/rejoin-or-start`,
`GET /print/templates`, `POST /print/bluetooth-payload`, `POST /print/payload`,
`POST /print/ticket-html`, `POST /print/whatsapp-ticket`,
`PUT|DELETE /transactions/{id}/payments/{paymentId}`, `DELETE /service-orders/{id}`,
`PUT /branch/switch/{branch}`, `GET /notifications`, `GET /support`, `GET|PUT /profile`,
`DELETE /profile/photo`, `PUT /profile/password`, `POST /profile/logout-other-devices`,
`GET|PUT /subscription`, `POST /subscription/documents` y
`POST /subscription/payments/{id}/request-invoice`.
La lógica quedó compartida con la web (`CashRegisterSessionLifecycleService`,
`TransactionPaymentEditService`, `DeleteServiceOrderAction`, `PrintDataSourceResolver`,
`WhatsAppTicketService::buildSalePayload` y `config/support.php`), así que un corte, un reembolso o
un ticket salen idénticos desde los dos clientes. El corte desde el móvil emite además el broadcast
`SessionClosed`, con lo que la web y los demás dispositivos se actualizan al instante.
Tests: `CashRegisterCloseApiTest`, `PrintingApiTest` y `AccountApiTest` (132 tests verdes en la
carpeta de la API).
✅ Criterio cumplido: se imprime un ticket real en impresora térmica (los bytes ESC/POS los genera el
servidor) y se envía el mismo ticket por WhatsApp; se **cierra la caja desde el teléfono** y el corte
coincide con el que muestra la web (total esperado, contado, diferencia y saldos bancarios
conciliados); se puede cambiar de sucursal, editar el perfil con foto, cambiar la contraseña, cerrar
las demás sesiones, ver la suscripción y abrir soporte.

### Fase 5 — Offline y sincronización
SQLite local, outbox, `sync/manifest|pull|push`, pantalla de pendientes y sobreventa.
✅ Criterio: se puede vender en modo avión, la venta se sincroniza al volver la red con folio
real, sin duplicados al reintentar, y la sobreventa queda marcada "Requiere revisión".

### Fase 6+ (futuro)
Movimientos de efectivo (ingresos/egresos) desde el móvil, pago/renovación de suscripción dentro de
la app, autenticación de dos factores, lista de dispositivos activos, notas de la versión,
referidos, intercambios de producto, reprogramar/extender apartados y pedidos, cancelación con
penalización avanzada, reportes del día en el teléfono, tienda en línea y facturación.

---

## 14. Checklist de pruebas (QA)

> **Nota sobre la suite del backend (18 sep 2026):** `php artisan test` reporta **23 fallos
> preexistentes** que **no** tienen relación con la app móvil (verificado con baseline: 23 antes y
> 23 después de las fases 0 y 1):
> - `it_denies_access_without_permissions` en `ProductControllerTest`, `PrintTemplateControllerTest`,
>   `QuoteControllerTest`, `ServiceControllerTest`, `ServiceOrderControllerTest` y
>   `TransactionControllerTest`: el **primer usuario del test es el superadmin `id 1`**
>   (`Gate::before` le concede todos los permisos), así que quitarle los roles no lo bloquea.
>   El de `CustomerControllerTest` se corrigió en la Fase 1.
> - `AiAgentToolsTest` (15 casos) y `Admin\TutorialsTest` (2 casos).
>
> La API móvil tiene su propia cobertura: `tests/Feature/Api/V1` (Auth, Catalog, CustomerApi,
> CashRegisterSessionApi y BankAccountApi), todas en verde.

**Auth y permisos**
- [ ] Login correcto, credenciales erróneas (`422`), usuario inactivo (`403`).
- [ ] Token inválido o vencido → `401` y regreso al login.
- [ ] Usuario sin `pos.create_sale` no ve "Cobrar" y el servidor lo rechaza igual.

**POS**
- [ ] Venta de contado en efectivo (cambio correcto).
- [ ] Pago mixto (efectivo + tarjeta con cuenta bancaria obligatoria).
- [ ] Mayoreo (> cantidad mínima del tier) y descuento manual.
- [ ] Venta con variante (stock de la variante correcto).
- [ ] Venta a crédito dentro y fuera del límite de crédito.
- [ ] Venta usando saldo a favor.
- [ ] Sin sesión de caja: no se puede cobrar y el mensaje es claro.

**Caja (apertura)**
- [ ] Abrir caja con terminal libre, fondo de efectivo y saldos bancarios declarados.
- [ ] El snapshot `opening_bank_balances` incluye todas las cuentas de la sucursal (declaradas + heredadas del último corte).
- [ ] Terminal ya en uso → `409` y la app ofrece "Unirme a esa sesión".
- [ ] Sin terminales libres → mensaje correcto y sin botón de apertura.
- [ ] Ya tengo sesión activa → `session_already_open` y la app muestra el POS directo.
- [ ] Abrir caja sin conexión → bloqueado con "Necesitas conexión para abrir caja".
- [ ] Reintentar la apertura con el mismo `client_uuid` no crea una segunda sesión.
- [ ] Cierre remoto desde la web: la app detecta el cierre y regresa a la pantalla de apertura.
- [ ] La terminal queda `in_use = true` al abrir y las ventas caen en el corte correcto de la web.

**Caja (cierre / corte)**
- [ ] El resumen muestra fondo inicial, ventas en efectivo, tarjeta, transferencia, ingresos, egresos y total esperado correctos.
- [ ] La diferencia se recalcula en vivo y cambia de color (verde en 0, naranja con descuadre).
- [ ] Cerrar con más de un usuario pide confirmación adicional.
- [ ] Cerrar con descuadre: el monto queda registrado y coincide con la web (`cash_difference`).
- [ ] Los saldos bancarios se escriben en las cuentas y coinciden con la web.
- [ ] La terminal vuelve a estar libre (`in_use = false`) y la sesión aparece cerrada.
- [ ] Se puede imprimir el corte en térmica y enviarlo por WhatsApp.
- [ ] Sin conexión el cierre está bloqueado con el mensaje correcto.
- [ ] Cerrar dos veces → `session_not_open` y el estado se refresca.

**Cuenta: sucursal, perfil, suscripción y soporte**
- [ ] Cambiar de sucursal recarga permisos, catálogo y caja; la web queda en la nueva sucursal.
- [ ] Cambiar de sucursal con operaciones pendientes → bloqueado con `unsynced_operations`.
- [ ] El selector de sucursal solo aparece con más de una sucursal y `system.branches.switch`.
- [ ] Editar nombre/correo/foto del perfil; el cambio de correo pide verificación.
- [ ] Cambiar contraseña con contraseña actual incorrecta → error del servidor.
- [ ] "Cerrar otras sesiones" invalida los demás dispositivos y mantiene el actual.
- [ ] La vista de suscripción solo aparece para el propietario; el empleado recibe `403`.
- [ ] "Renovar o mejorar plan" abre la web de pago en el navegador externo.
- [ ] Soporte abre correo y WhatsApp con los datos correctos y el Centro de ayuda carga.
- [ ] El badge de notificaciones muestra los contadores y navega a cada listado.
- [ ] Cerrar sesión con operaciones sin sincronizar advierte antes de salir.
- [ ] Apartado (reserva stock) y su cancelación (libera stock).
- [ ] Pedido con contacto y fecha de entrega.

**Ventas**
- [ ] Búsqueda por folio y por cliente; filtros por estatus y fechas.
- [ ] Detalle con ítems, pagos y saldos correctos.
- [ ] Abono parcial y liquidación total; se genera el ticket de abono.
- [ ] Cancelación con devolución en efectivo, a saldo y por transferencia.
- [ ] Venta cancelada no admite pagos ni cambios.

**Órdenes de servicio**
- [ ] Crear orden con cliente existente, con cliente nuevo y sin cliente.
- [ ] Ítems de servicio y de refacción (stock descontado).
- [ ] Técnico con comisión porcentual y fija.
- [ ] Cambio de estatus hacia adelante y hacia atrás (con confirmación).
- [ ] Diagnóstico + evidencias visibles en la web.
- [ ] Anticipo y liquidación; al pasar a `entregado` con saldo, se exige cobro.
- [ ] Orden antigua sin venta (`ensure-transaction`).

**Impresión y WhatsApp**
- [ ] Imprimir ticket de venta y de orden (conexión, pérdida de conexión, reimpresión).
- [ ] Enviar por WhatsApp con teléfono y sin teléfono.
- [ ] Ticket offline con encoder local (58 mm y 80 mm).

**Offline**
- [ ] Vender en modo avión y ver la etiqueta "Pendiente de sincronizar".
- [ ] Volver la red: folio real asignado y sin duplicados.
- [ ] Cerrar la caja en la web mientras el teléfono está offline → operación en revisión.
- [ ] Sobreventa offline → "Requiere revisión".
- [ ] Reiniciar la app conservando la cola pendiente.

---

## 15. Glosario

| Término | Significado |
|---|---|
| **Venta (transaction)** | Operación de cobro; puede ser contado, crédito, apartado o pedido |
| **Abono** | Pago parcial (o total) a una venta con saldo pendiente |
| **Apartado** | Venta con stock **reservado** y fecha límite de liquidación |
| **Pedido / comanda** | Venta con contacto y fecha de entrega; el stock queda reservado |
| **Orden de servicio** | Orden de reparación con estatus, técnico y evidencias |
| **Refacción** | Producto del catálogo usado como parte de una reparación |
| **Mano de obra** | Servicio del catálogo que se cobra dentro de la orden |
| **Sesión de caja** | Apertura de caja donde se acumulan los cobros; puede abrirse, unirse y cerrarse desde la app o la web |
| **Corte de caja** | Cierre de la sesión: se captura el efectivo contado y el servidor calcula la diferencia contra el total esperado y concilia los bancos |
| **Saldo a favor** | Dinero a favor del cliente (`balance > 0`) usable como pago |
| **Sobreventa** | Vender más de lo que el stock local indicaba; permitido offline y marcado para revisión |

---

## 16. Cómo mantener estos documentos

1. La **API se implementa primero**; el contrato (`01-…`) se actualiza en el mismo cambio.
2. Si cambia el esquema, se actualiza `02-modelo-de-datos.md`.
3. Si cambia el look & feel, se actualiza `03-design-system-tesla-ui.md` y después la app.
4. Cada fase cerrada se marca en la sección 13 con fecha.
5. La app nunca debe depender de campos que **no** estén documentados aquí: si necesita algo
   nuevo, primero se agrega a la API y al contrato.