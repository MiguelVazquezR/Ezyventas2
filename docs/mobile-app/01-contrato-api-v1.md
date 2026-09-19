# 01 — Contrato de la API móvil `/api/v1`

> **Estado:** contrato objetivo. Todos los endpoints viven en este mismo proyecto Laravel
> (`routes/api/v1/*.php`) y reutilizan los servicios de negocio existentes.
> Cada endpoint indica su **fase de implementación** y el **permiso** requerido.
> La app Flutter se programa **contra este documento**, no contra la web.
> Si algo cambia en el backend, se actualiza aquí primero.

---

## 1. Convenciones generales

| Aspecto | Regla |
|---|---|
| Base URL | `https://<dominio-de-produccion>/api/v1` |
| Autenticación | `Authorization: Bearer <token>` (Sanctum) |
| Headers obligatorios | `Accept: application/json`, `Content-Type: application/json` (excepto multipart) |
| Versionado | prefijo `/v1` en la ruta |
| Fechas | ISO-8601 UTC: `2026-09-18T14:35:00.000000Z` (parsear y mostrar en `es-MX`) |
| Dinero | string decimal (`"120.00"`) → convertir con `double.parse` |
| Cantidades | `double` (pueden ser decimales en productos a granel) |
| Paginación | `{ data: [...], current_page, last_page, per_page, total, next_page_url, prev_page_url }`; `page`, `per_page` (máx. 100) |
| Búsqueda | parámetro `search` (folio, cliente, SKU o descripción) |
| Filtro por fecha | `date_start`, `date_end` (`YYYY-MM-DD`) |
| Errores de validación | `422` `{ message, errors: { campo: ["mensaje"] } }` — `message` es el primer error en español |
| Errores de negocio | `422`/`409` `{ message: "texto en español" }` |
| No autenticado | `401` `{ "message": "No autenticado." }` |
| Sin permiso | `403` `{ "message": "Tu usuario no tiene permiso para esta acción." }` |
| No encontrado | `404` `{ "message": "Recurso no encontrado." }` |
| Límite de peticiones | **Implementado:** 60 req/min por usuario (o IP) en `/api/*`; **5 req/min** por correo + IP en `/api/v1/auth/login`. `429` al exceder. |
| Aislamiento | el servidor deriva `branch_id` y `subscription_id` del token. **Nunca** se envían. |
| Idempotencia | las escrituras aceptan `client_uuid` (UUID v4). Repetir la petición **no duplica** la operación. |
| Idioma | todos los `message` y mensajes de validación van en español |

### `client_uuid` (obligatorio en escrituras offline-capables)
- Es un `uuid v4` que la app genera **una sola vez** por operación y guarda en su cola local.
- Si el servidor recibe de nuevo el mismo `client_uuid`, responde el mismo resultado sin volver
  a descontar stock ni generar folio.
- Lo aceptan: `pos/checkout`, `pos/layaway`, `pos/store-order`, `service-orders` (store/update),
  `PATCH service-orders/{id}/status` y los endpoints de pagos.

---

## 2. Autenticación

> **Estado: ✅ implementado (Fase 0, 18 sep 2026).**
> Rutas: `routes/api/v1/auth.php` → `POST /api/v1/auth/login` (throttle 5/min por email+IP),
> `GET /api/v1/auth/me` y `POST /api/v1/auth/logout` (requieren `auth:sanctum`).
> Implementación: `Api\V1\Auth\AuthController` → `LoginMobileUserAction` →
> `UserAccessContextService` + `SubscriptionAccessGuard`.<br>
> Cobertura: `tests/Feature/Api/V1/AuthTest.php` (10 casos, incluidos throttling y errores en español).

### `POST /auth/login` — ✅ implementado

Request:
```json
{
  "email": "maria@negocio.com",
  "password": "secreto",
  "device_name": "Pixel 7 · María",
  "client_uuid": "3f1c9a2e-2d61-4c3f-9a0e-2b6a5f0f7c11"
}
```

Response `200`:
```json
{
  "token": "14|Yb3k...",
  "token_type": "Bearer",
  "user": {
    "id": 7,
    "name": "María López",
    "email": "maria@negocio.com",
    "phone": "4771234567",
    "profile_photo_url": "https://.../profile-photos/user7.jpg",
    "branch_id": 2,
    "branch": { "id": 2, "name": "Sucursal Centro", "timezone": "America/Mexico_City" },
    "subscription": { "id": 15, "commercial_name": "Refaccionaria López" },
    "is_subscription_owner": false,
    "permissions": ["pos.access", "pos.create_sale", "transactions.access", "services.orders.access"],
    "email_verified_at": "2025-01-10T10:00:00.000000Z",
    "is_active": true
  },
  "module_keys": ["module_pos", "module_services"],
  "modules": ["Punto de Venta", "Órdenes de Servicio"],
  "active_session": null,
  "joinable_sessions": [
    {
      "id": 41,
      "cash_register": { "id": 3, "name": "Caja 1" },
      "opened_at": "2026-09-18T13:00:00.000000Z",
      "opener": { "id": 4, "name": "José Pérez" }
    }
  ],
  "available_cash_registers": []
}
```

Errores:
- `422` credenciales: `{"message": "Las credenciales no coinciden con nuestros registros.", "errors": {"email": ["Las credenciales no coinciden con nuestros registros."]}}`
- `422` campos faltantes: `errors.email` = "Escribe tu correo electrónico." / `errors.password` = "Escribe tu contraseña."
- `403` usuario desactivado: `{"message": "Tu usuario está desactivado. Contacta al administrador."}`
- `403` empleado con suscripción expirada o suspendida: `{"message": "La suscripción de este negocio ha expirado."}`
- `429` demasiados intentos: `{"message": "Demasiadas solicitudes. Espera un momento e inténtalo de nuevo."}`

Implementación:
- Validación en `LoginRequest` (normaliza el correo a minúsculas y usa el `User-Agent` como
  `device_name` si la app no lo envía).
- Credenciales con `Hash::check`; el usuario inactivo y el empleado sin suscripción se bloquean en
  `SubscriptionAccessGuard`.
- **El propietario (usuario sin roles) siempre puede iniciar sesión**, incluso con la suscripción
  expirada, para que pueda renovarla: en ese caso recibe `subscription.status` y **sin permisos de
  módulos** (`permissions` solo traerá los de `Sistema`), de modo que la app muestra el banner y
  oculta los módulos.
- Token con `createToken($deviceName, ['mobile'])`; `expires_at` queda nulo (sin caducidad) hasta
  definir una política de expiración.
- `module_keys` provienen de `Subscription::getActiveModuleKeys()` (`module_pos`, `module_services`,
  `module_transactions`, …) y `modules` de `getAvailableModuleNames()` ("Punto de Venta",
  "Órdenes de Servicio", …). Se usan para mostrar u ocultar módulos completos en la app.
- `permissions` replica la lógica de `HandleInertiaRequests::share()`: propietario = todos los
  permisos de sus módulos contratados + `Sistema`; empleado = `getAllPermissions()` filtrado por
  módulos activos (y vacío si la suscripción no está vigente).
- `active_session`, `joinable_sessions` y `available_cash_registers` se calculan con los métodos
  del modelo `User` (los mismos que usa el POS web).

### `POST /auth/logout` — ✅ implementado
Revoca **el token de este dispositivo** (`$request->user()->currentAccessToken()->delete()`).
Sin body. Response `204` (sin contenido).
Los demás dispositivos del usuario siguen con su token válido.

### `GET /auth/me` — ✅ implementado
Devuelve el mismo bloque `user` + `module_keys` + `modules` de `login`, más
`active_session`, `joinable_sessions` y `available_cash_registers` actualizados.
Se usa al abrir la app y después de cada sincronización de permisos.

---

## 3. Permisos por endpoint

| Endpoint | Permiso (Spatie) |
|---|---|
| `GET /catalog/products`, `GET /catalog/products/{id}` | `pos.access` |
| `GET /catalog/categories`, `GET /catalog/services` | `pos.access` o `services.orders.access` (el catálogo de servicios también sirve al módulo de órdenes) |
| `GET /customers`, `GET /customers/{id}` | `pos.access` (en el contexto del POS); aceptar también `customers.access` / `customers.see_details` si el usuario abre el módulo de clientes sin POS |
| `POST /customers` | `customers.create` (la web exige este permiso para el alta rápida) |
| `GET /cash-register-sessions/current` | `pos.access` |
| `POST /cash-register-sessions` (abrir caja) | `pos.access` (la web solo exige `auth`; se recomienda `pos.access` porque el POS es el único consumidor) |
| `POST /cash-register-sessions/{id}/join` | `pos.access` |
| `POST /cash-register-sessions/{id}/leave` | `pos.access` |
| `POST /cash-register-sessions/rejoin-or-start` | `pos.access` |
| `GET /bank-accounts` | `pos.access` |
| `POST /pos/checkout` | `pos.create_sale` |
| `POST /pos/layaway` | `pos.create_sale` |
| `POST /pos/store-order` | `pos.create_sale` |
| `GET /transactions` | `transactions.access` |
| `GET /transactions/{id}` | `transactions.see_details` |
| `POST /transactions/{id}/cancel` | `transactions.cancel` |
| `POST /transactions/{id}/refund` | `transactions.refund` |
| `POST /transactions/{id}/payments` | `transactions.add_payment` |
| `DELETE /transactions/{id}/payments/{payment}` | `transactions.edit_payment` |
| `GET /service-orders` | `services.orders.access` |
| `GET /service-orders/{id}` | `services.orders.see_details` |
| `POST /service-orders` | `services.orders.create` |
| `PUT /service-orders/{id}` | `services.orders.edit` |
| `PATCH /service-orders/{id}/status` | `services.orders.change_status` |
| `POST /service-orders/{id}/diagnosis` | `services.orders.edit` |
| `POST /service-orders/{id}/payments` | `transactions.add_payment` |
| `DELETE /service-orders/{id}` | `services.orders.delete` |
| `POST /print/*` (ventas) | `pos.access` |
| `POST /print/*` (órdenes) | `services.print_tickets` |
| `GET /sync/*` | cualquier sesión válida |
| `GET /cash-register-sessions/{id}/summary` (corte) | `pos.access` |
| `PUT /cash-register-sessions/{id}` (cerrar caja) | `pos.access` |
| `PUT /branch/switch/{branch}` | `system.branches.switch` |
| `GET /notifications` | sesión válida |
| `GET /support` | sesión válida |
| `GET /profile`, `PUT /profile`, `DELETE /profile/photo` | sesión válida |
| `PUT /profile/password`, `POST /profile/logout-other-devices` | sesión válida (+ contraseña actual) |
| `GET /subscription`, `PUT /subscription`, `POST /subscription/documents`, `POST /subscription/payments/{id}/request-invoice` | **propietario de la suscripción** (usuario sin roles) |

Reglas de UX derivadas:
- El módulo **Punto de venta** solo se muestra con `pos.access`.
- El módulo **Órdenes de servicio** solo con `services.orders.access`.
- La app **oculta** (no solo deshabilita) las acciones sin permiso; el servidor **siempre** revalida.
- Si el usuario es propietario (`is_subscription_owner: true`), recibe todos los permisos
  de sus módulos activos.

---

## 4. Catálogo (POS)

### `GET /catalog/products` — ✅ implementado (Fase 1)
Query: `search` (nombre o SKU), `category_id`, `page`, `per_page` (**máx. 100**, por
defecto 20), `updated_since` (ISO-8601, para sincronización incremental).

> **Implementado** en `Api\V1\Catalog\ProductController` → `App\Services\Catalog\ProductCatalogService`,
> el **mismo** servicio que alimenta el POS web, así que el precio nunca difiere entre la web y la app.
> Tipos: `selling_price` llega como **string decimal** (`"150.00"`) y `price` / `original_price`
> como número. `variant_combinations[].price` ya viene calculado (`selling_price +
> price_modifier`). `components` describe los insumos de un kit
> (`{ id, component_id, component_type, name, quantity }`).

Response `200` (paginado):
```json
{
  "data": [
    {
      "id": 45,
      "name": "Filtro de aceite",
      "sku": "FIL-001",
      "description": "Filtro para motor 1.6",
      "category": "Refacciones",
      "image": "https://.../producto.jpg",
      "general_images": ["https://.../producto.jpg", "https://.../producto-2.jpg"],
      "selling_price": "150.00",
      "price": 135.0,
      "original_price": 150.0,
      "price_tiers": [{ "min_quantity": 6, "price": 130 }],
      "stock": 24.0,
      "reserved_stock": 0.0,
      "is_bulk": false,
      "measure_unit": "pz",
      "show_in_pos": true,
      "promotions": [
        { "name": "Ofertas de septiembre", "type": "ITEM_DISCOUNT", "description": "10 % en filtros" }
      ],
      "variants": { "Talla": [{ "value": "M", "stock": 3 }] },
      "variant_combinations": [
        {
          "id": 88,
          "attributes": { "Talla": "M", "Color": "Azul" },
          "price_modifier": 15.0,
          "price": 165.0,
          "sku_suffix": "M-AZ",
          "stock": 3,
          "reserved_stock": 0.0,
          "image_url": null
        }
      ],
      "components": []
    }
  ],
  "current_page": 1, "last_page": 3, "per_page": 20, "total": 52
}
```

Reglas:
- Solo productos con `show_in_pos = true` y pertenecientes a la sucursal del usuario.
- `stock` = `current_stock - reserved_stock` **de la sucursal** (nunca negativo).
- Producto **con variantes**: el stock mostrado es la suma de las variantes; para vender hay que
  elegir una `variant_combinations[].id` (`product_attribute_id`).
- `price` = precio final tras promociones de línea; `original_price` = `selling_price`.
  La app debe mostrar ambos cuando difieran (tachado + precio final).
- `price_tiers` define precio por volumen: se aplica el `price` del tier con el mayor
  `min_quantity` que cumpla `quantity >= min_quantity` (ver `POS/IndexMobile.vue`).

### `GET /catalog/products/{id}` — ✅ implementado (Fase 1)
Mismo objeto que el listado, con `components` y `variant_combinations` completos.
`404` `{ "message": "Recurso no encontrado." }` cuando el producto no existe **o pertenece a otra
sucursal/suscripción** (el servidor nunca revela datos de otro negocio).

### `GET /catalog/categories` — ✅ implementado (Fase 1)
```json
[{ "id": 3, "name": "Refacciones", "type": "product" }]
```
Query: `type` = `product` (por defecto) o `service`. Devuelve **todas** las categorías de la
suscripción del tipo pedido, ordenadas por nombre (la app no necesita contadores).
Permiso: `pos.access`; para `type=service` también vale `services.orders.access`.

### `GET /catalog/services` — ✅ implementado (Fase 1)
Servicios **disponibles en la sucursal** (`branch_service`), con buscador `search`,
`category_id` y paginación (`page`, `per_page` máx. 100). Permiso: `pos.access` o
`services.orders.access`.
```json
{
  "data": [
    {
      "id": 12,
      "name": "Cambio de pantalla",
      "description": "Incluye instalación",
      "category": "Reparaciones",
      "base_price": "850.00",
      "duration_estimate": "2 horas",
      "show_online": false,
      "variants": [
        { "id": 31, "name": "Original", "price": "1450.00", "duration_estimate": null },
        { "id": 32, "name": "Compatible", "price": "850.00", "duration_estimate": null }
      ]
    }
  ],
  "current_page": 1, "last_page": 1, "per_page": 20, "total": 1
}
```
Si el servicio tiene variantes, se vende la variante (`variants[].id`), no el servicio base.

---

## 5. Clientes

### `GET /customers` — ✅ implementado (Fase 1)
Query: `search` (nombre, razón social, correo o teléfono), `page`, `per_page` (máx. 100).
Solo devuelve clientes de la sucursal del usuario, ordenados por nombre.
Permiso: `pos.access` o `customers.access` / `customers.see_details`.
```json
{
  "data": [
    {
      "id": 8,
      "name": "Ana Ramírez",
      "company_name": null,
      "email": "ana@correo.com",
      "phone": "4771112233",
      "balance": "-350.00",
      "credit_limit": "2000.00",
      "available_credit": 1650.0
    }
  ]
}
```

### `GET /customers/{id}` — ✅ implementado (Fase 1)
Igual al anterior + `address`, `tax_id`, `tax_regime`, `layaway_transactions` (apartados activos) y
`balance_movements` (últimos 50 movimientos: `date`, `type`, `description`, `amount`,
`resulting_balance`).
```json
{
  "layaway_transactions": [
    {
      "id": 91, "folio": "V-5001", "created_at": "2026-09-10T18:00:00.000000Z",
      "expires_at": "2026-10-10", "total": "900.00", "total_paid": "300.00",
      "pending_amount": "600.00", "items_count": 2,
      "items": [{ "id": 501, "name": "Playera", "quantity": 2, "unit_price": "450.00", "line_total": "900.00" }]
    }
  ],
  "balance_movements": [
    { "date": "2026-09-10T18:05:00.000000Z", "type": "apartado_deuda", "description": "Abono...", "amount": "-600.00", "resulting_balance": "-600.00", "transaction_id": 91 }
  ]
}
```
`404` si el cliente pertenece a otra sucursal.

### `POST /customers` — ✅ implementado (Fase 1)
```json
{
  "name": "Ana Ramírez",
  "company_name": null,
  "email": "ana@correo.com",
  "phone": "4771112233",
  "address": { "street": "Av. Hidalgo 120", "city": "León" },
  "credit_limit": 2000,
  "client_uuid": "…"
}
```
`name` obligatorio; `email` único (`422` "Ya existe un cliente con ese correo electrónico.").
También se acepta `tax_id` (RFC). `client_uuid` se **valida** como UUID, pero la protección
contra duplicados llega en la Fase 5 (idempotencia offline).
Response `201` con el cliente en **formato de listado** (el mismo objeto que `GET /customers`),
que es justo lo que necesita el POS para cobrarle de inmediato.
Permiso: `customers.create`. El `branch_id` siempre lo pone el servidor.

---

## 6. Caja y cuentas bancarias (apertura, unirse, corte y lectura)

### `GET /cash-register-sessions/current` — ✅ implementado (Fase 1)
> Devuelve el mismo bloque que `active_session` en `login`/`me`, más `totals`.
```json
{
  "active_session": {
    "id": 41,
    "status": "abierta",
    "opened_at": "2026-09-18T13:00:00.000000Z",
    "cash_register": { "id": 3, "name": "Caja 1" },
    "opener": { "id": 4, "name": "José Pérez" },
    "users": [{ "id": 7, "name": "María López" }],
    "totals": { "cash": 1250.5, "card": 800.0, "transfer": 0, "balance": 0 }
  },
  "joinable_sessions": [
    { "id": 42, "cash_register": { "id": 4, "name": "Caja 2" }, "opener": { "id": 5, "name": "Luis" } }
  ],
  "available_cash_registers": [{ "id": 5, "name": "Caja 3" }],
  "bank_accounts": [
    {
      "id": 2,
      "name": "Cuenta principal - BBVA (...4471)",
      "bank_name": "BBVA",
      "account_name": "Cuenta principal",
      "balance": "5000.00"
    }
  ]
}
```
- `active_session` = la sesión abierta del usuario en su sucursal (null si no participa en ninguna).
- Si `active_session` es null:
  - con `available_cash_registers` disponible → la app ofrece **abrir caja** (ver §6.1);
  - sin terminales libres pero con `joinable_sessions` → ofrece **unirse**;
  - sin nada disponible → mostrar "Pide que abran caja desde la versión web".
- `bank_accounts` = cuentas bancarias de la sucursal (o las del empleado) con su saldo actual,
  para declararlos al abrir la caja. Mismo formato que `GET /bank-accounts`.
- `totals` solo suma pagos con estatus `completado`, agrupados por método de pago.
- Una terminal está disponible si `is_active = true` y `in_use = false`.

### 6.1 `POST /cash-register-sessions` — ✅ implementado (Fase 3)
Permiso: `pos.access`. Reutiliza la lógica de apertura, extraída a
`App\Services\CashRegisters\CashRegisterSessionOpenService` (la web usa el mismo servicio).

> **Detalles de la implementación:** `active_session` incluye `opening_bank_balances` (el snapshot
> que la app muestra en la pantalla de corte) y `totals`. El servidor aplica primero los saldos
> declarados y después completa el snapshot heredando el saldo del último corte de esa caja.
> Respuestas de negocio (además del `message` en español): `409 cash_register_in_use` con
> `session_id`, `cash_register` y `opened_by` (para ofrecer "Unirme a esa sesión");
> `422 session_already_open` "Ya tienes una sesión de caja activa.";
> `422 no_cash_register_available` si la terminal está desactivada;
> `404` si la caja no existe o es de otra sucursal.

```json
{
  "client_uuid": "7d4b1c2a-9f31-4a77-b6ce-0f1e2d3c4b5a",
  "cash_register_id": 5,
  "user_id": 7,
  "opening_cash_balance": 1500,
  "bank_accounts": [
    { "id": 2, "balance": 5000 },
    { "id": 3, "balance": 820.5 }
  ]
}
```

| Campo | Regla |
|---|---|
| `cash_register_id` | required, debe existir en `cash_registers` de la sucursal y estar libre (`is_active = true`, `in_use = false`) |
| `user_id` | required, debe existir. Normalmente es el propio usuario; al abrir queda como `opener` de la sesión |
| `opening_cash_balance` | required, numérico ≥ 0 (fondo de efectivo físico en caja) |
| `bank_accounts[]` | nullable. Cada elemento: `id` (required, existe) y `balance` (required, numérico ≥ 0) |
| `client_uuid` | idempotencia: reintentar no abre una segunda caja |

Qué hace el servidor (importante para la app):
1. Verifica que la terminal esté libre. Si `in_use = true` → `409` con `code: cash_register_in_use`
   y el nombre del usuario que la abrió (la app ofrece "Unirme a esa sesión").
2. **Aplica** los saldos declarados a cada `bank_accounts.balance`.
3. Completa el snapshot `opening_bank_balances` con **todas** las cuentas de la sucursal:
   las declaradas usan el saldo enviado; las no declaradas heredan el saldo de
   `closing_bank_balances` del último corte cerrado de esa caja (o el saldo actual como respaldo).
4. Crea la sesión con `user_id`, `opening_cash_balance`, `opening_bank_balances`,
   `status = abierta` y `opened_at = now()`.
5. Añade al usuario al pivote `cash_register_session_user` y marca la terminal `in_use = true`.

Response `201`:
```json
{
  "active_session": {
    "id": 43,
    "status": "abierta",
    "opened_at": "2026-09-18T14:50:00.000000Z",
    "opening_cash_balance": "1500.00",
    "opening_bank_balances": [
      { "id": 2, "account_name": "Cuenta principal", "bank_name": "BBVA", "balance": 5000.0 },
      { "id": 3, "account_name": "Caja chica", "bank_name": "Santander", "balance": 820.5 }
    ],
    "cash_register": { "id": 5, "name": "Caja 3" },
    "opener": { "id": 7, "name": "María López" },
    "users": [{ "id": 7, "name": "María López" }],
    "totals": { "cash": 0, "card": 0, "transfer": 0, "balance": 0 }
  },
  "message": "La caja ha sido abierta con éxito."
}
```

Errores esperados:
| HTTP | `code` | message |
|---|---|---|
| `409` | `cash_register_in_use` | "Parece que otro usuario abrió caja antes que tú. Puedes unirte a la sesión." |
| `422` | `no_cash_register_available` | "No hay terminales disponibles en esta sucursal." |
| `422` | `session_already_open` | "Ya tienes una sesión de caja activa." |
| `403` | `permission_denied` | sin `pos.access` |

Reglas para la app:
- Abrir caja **requiere conexión** (crea estado en el servidor). Sin red y sin sesión, el POS
  muestra "Necesitas conexión para abrir caja".
- Al abrir con éxito: guardar el `active_session.id` en caché, cerrar la pantalla de apertura y
  **habilitar el cobro** inmediatamente (no hace falta volver a llamar a `current`).
- El fondo de efectivo y los saldos bancarios son **editables** antes de enviar; los valores
  precargados vienen de `GET /cash-register-sessions/current` (`bank_accounts[].balance`).
- Si el usuario no ve cuentas bancarias (no tiene permisos sobre ellas), se envía
  `bank_accounts: []` y el servidor hereda el saldo del último corte.

### `POST /cash-register-sessions/{id}/join` — ✅ implementado (Fase 3)
Permiso: `pos.access`. Añade al usuario a la sesión abierta (pivote `cash_register_session_user`).
Sin body (o `{ "client_uuid": "…" }`).
Response `200`: `{ "active_session": { … }, "message": "Te has unido a la sesión de caja." }`
Errores: `409` `session_not_open` "Esa sesión de caja ya fue cerrada."; `404` si la sesión es de
otra sucursal.

### `POST /cash-register-sessions/{id}/leave` — ✅ implementado (Fase 4)
Permiso: `pos.access`. Saca al usuario de la sesión (pivote), sin cerrarla.
Response `200`: `{ "message": "Has salido de la sesión de caja." }`

### `POST /cash-register-sessions/rejoin-or-start` — ✅ implementado (Fase 4)
Permiso: `pos.access`. Caso de uso: el dispositivo perdió la sesión (por ejemplo tras un cierre
remoto) y necesita retomar el turno sin pedir el fondo de caja otra vez.
```json
{ "cash_register_id": 5, "original_opener_id": 7 }
```
- Si la caja ya tiene una sesión abierta → el usuario se **une** a ella.
- Si no la tiene → crea una sesión nueva heredando el `closing_cash_balance` y los
  `closing_bank_balances` del último corte cerrado de esa caja.
Response `200`: `{ "active_session": { … }, "message": "Te has unido a la nueva sesión." }`
Errores: `422` `session_already_open` ("Ya tienes una sesión activa."); `404` si la caja o el
usuario indicado no existen / la caja es de otra sucursal.
Reutiliza `CashRegisterSessionLifecycleService::rejoinOrStart()` (el mismo que usa la web).

### 6.2 `GET /cash-register-sessions/{id}/summary` — ✅ implementado (Fase 4)
Permiso: `pos.access`. Alimenta la pantalla de cierre **antes** de enviar el monto contado.
Implementado en `CashRegisterSessionQueryService::summaryPayload()` (mismas fórmulas que
`CloseSessionModal.vue` y `CashRegisterSession::closeSession()`).

```json
{
  "session": {
    "id": 41,
    "status": "abierta",
    "opened_at": "2026-09-18T13:00:00.000000Z",
    "cash_register": { "id": 3, "name": "Caja 1" },
    "opener": { "id": 4, "name": "José Pérez" },
    "users": [{ "id": 7, "name": "María López" }, { "id": 9, "name": "Luis Torres" }],
    "opening_cash_balance": "1500.00",
    "closing_cash_balance": null,
    "calculated_cash_total": null,
    "cash_difference": null,
    "notes": null
  },
  "cash": {
    "opening": 1500.0,
    "cash_sales": 3500.0,
    "inflows": 200.0,
    "outflows": 150.0,
    "expected_total": 5050.0,
    "counted_total": null,
    "difference": null
  },
  "payments_by_method": { "efectivo": 3500.0, "tarjeta": 1200.0, "transferencia": 300.0, "saldo": 0.0 },
  "cash_movements": [
    { "id": 88, "type": "egreso", "amount": "150.00", "description": "Compra de bolsas", "user": { "id": 7, "name": "María López" }, "created_at": "2026-09-18T15:20:00.000000Z" }
  ],
  "bank_accounts": [
    {
      "id": 2,
      "account_name": "Cuenta principal",
      "bank_name": "BBVA",
      "initial_balance": 5000.0,
      "received": 1200.0,
      "spent": 0.0,
      "transferred_in": 0.0,
      "transferred_out": 300.0,
      "final_balance": 5900.0
    }
  ],
  "counts": { "transactions": 12, "payments": 14 }
}
```

Fórmulas (idénticas a la web, `CloseSessionModal.vue` y `CashRegisterSession::closeSession()`):
- `cash.expected_total = opening + cash_sales + inflows - outflows`
  (solo pagos en **efectivo** con estatus `completado`).
- `cash.difference = counted_total - expected_total` (negativo = faltante, positivo = sobrante).
- `bank_accounts[].final_balance = initial_balance + received - spent + transferred_in - transferred_out`
  (si la sesión ya está cerrada, se usa el valor congelado en `closing_bank_balances`).

Visibilidad: el **propietario** ve todas las cuentas; un **empleado** solo las cuentas que tiene
asignadas (`calculateBankAccountSummary`). Si el arreglo viene vacío, la app oculta el bloque bancario.

### 6.3 `PUT /cash-register-sessions/{id}` — ✅ implementado (Fase 4) — Cerrar caja / corte
Permiso: `pos.access`. Reutiliza `CashRegisterSessionLifecycleService::close()` →
`CashRegisterSession::closeSession()` y emite el broadcast `SessionClosed` (igual que la web).

```json
{
  "client_uuid": "…",
  "closing_cash_balance": 5040,
  "notes": "Faltante por un cambio mal dado."
}
```

| Campo | Regla |
|---|---|
| `closing_cash_balance` | required, numérico ≥ 0 (efectivo físico contado) |
| `notes` | nullable, texto libre ("Notas de arqueo") |

Qué hace el servidor:
1. Calcula `calculated_cash_total = opening_cash_balance + efectivo completado + ingresos − egresos`.
2. Calcula `cash_difference = closing_cash_balance − calculated_cash_total`.
3. **Concilia los bancos:** calcula el saldo final de cada cuenta, lo guarda en el snapshot
   `closing_bank_balances` y lo **escribe** en `bank_accounts.balance`.
4. Marca la sesión `status = cerrada`, `closed_at = now()` y libera la terminal (`in_use = false`).
5. Emite el evento de broadcast `App\Events\SessionClosed` en el canal
   `cash-register-session.{id}` para que la web y otros dispositivos lo reflejen al instante.

Response `200`:
```json
{
  "session": { "id": 41, "status": "cerrada", "closed_at": "2026-09-18T20:05:00.000000Z", "calculated_cash_total": 5050.0, "closing_cash_balance": 5040.0, "cash_difference": -10.0 },
  "summary": { "…mismo objeto de 6.2, ahora con los valores de cierre…" },
  "message": "Corte de caja realizado con éxito."
}
```

Errores: `422` `session_not_open` ("Esa sesión de caja ya fue cerrada."),
`422` validación del monto ("El monto de cierre es obligatorio." / "…debe ser un número."),
`403` `not_session_participant` ("No participas en esta sesión de caja.").

Reglas para la app:
- **Requiere conexión** (crea estado definitivo en el servidor).
- Si la sesión tiene **más de un usuario**, la app debe advertir y pedir confirmación explícita
  antes de cerrar ("Hay N usuarios en esta sesión; al cerrarla, todos saldrán de la caja.").
  Es el equivalente móvil de las vistas `initial → confirmClose → finalClose` del modal web.
- Mostrar la **diferencia en vivo** mientras se captura el monto contado, con el mismo criterio
  de color: verde cuando `diferencia === 0`, naranja cuando hay descuadre.
- Tras cerrar: limpiar la sesión y el carrito de la caché local, volver a la pantalla de apertura
  y ofrecer **imprimir o enviar el corte**.
- **Impresión del corte:** se genera en el dispositivo con el encoder ESC/POS local a partir del
  objeto `summary` (encabezado, periodo del turno, desglose por método, movimientos, bancos,
  total esperado, contado y diferencia). El endpoint de impresión del servidor no soporta
  `cash_register_session` como `data_source_type`; si más adelante se quiere plantilla del negocio,
  se agrega ese tipo a `PrintController::resolveDataSource`.

### `GET /bank-accounts` — ✅ implementado (Fase 1)
```json
[{ "id": 2, "name": "Cuenta principal - BBVA (...4471)", "bank_name": "BBVA", "account_name": "Cuenta principal", "balance": "5000.00" }]
```
`name` es la etiqueta lista para mostrar (`cuenta - banco (...últimos 4 dígitos)`).
Alcance: el **propietario de la suscripción** (usuario sin roles) ve **todas** las cuentas de la
sucursal; un **empleado** solo ve las cuentas que tiene asignadas (`bank_account_user`). Es la
misma regla que usa el POS web, y es justo lo que la app debe precargar al abrir caja.
Requerido para pagos con `tarjeta` / `transferencia` (`bank_account_id` obligatorio).

---

## 7. POS — registro de venta

### `POST /pos/checkout` — ✅ implementado (Fase 3)
Permiso: `pos.create_sale`. Reutiliza `TransactionPaymentService::handleNewSale()`.

Request:
```json
{
  "client_uuid": "9c2f7a1b-7d3e-4a55-8f21-0c9d4e7a1b30",
  "cash_register_session_id": 41,
  "customerId": 8,
  "guest_name": null,
  "cartItems": [
    {
      "id": 45,
      "product_attribute_id": null,
      "quantity": 2,
      "unit_price": 135,
      "description": "Filtro de aceite",
      "discount": 15,
      "discount_reason": "Promoción de producto"
    }
  ],
  "subtotal": 270,
  "total_discount": 30,
  "total": 270,
  "payments": [
    { "amount": 300, "method": "efectivo", "bank_account_id": null, "notes": null }
  ],
  "use_balance": false,
  "layaway_expiration_date": null
}
```

Reglas del payload:
- `cartItems[].id` = `products.id`; `product_attribute_id` = `variant_combinations[].id` (o null).
- `discount` es **por unidad**: `original_price - unit_price` (nunca negativo; si el precio subió
  manualmente, usar `discount: 0` y `discount_reason: "Aumento manual"`).
- `subtotal` = Σ(`unit_price` × `quantity`); `total_discount` = Σ(`discount` × `quantity`);
  `total` = `subtotal - total_discount` (+ `shipping_cost` si es pedido).
- `payments[].method` ∈ `efectivo` | `tarjeta` | `transferencia` | `saldo`;
  `bank_account_id` **obligatorio** para `tarjeta` y `transferencia`.
- `use_balance: true` solo si hay cliente con saldo a favor; el servidor limita el uso al total.

Response `201`:
```json
{
  "transaction": {
    "id": 987,
    "folio": "V-014",
    "status": "completado",
    "channel": "punto_de_venta",
    "subtotal": "270.00",
    "total_discount": "30.00",
    "total_tax": "0.00",
    "shipping_cost": "0.00",
    "total": 270.0,
    "total_paid": 270.0,
    "remaining_due": 0.0,
    "created_at": "2026-09-18T14:35:00.000000Z",
    "customer": { "id": 8, "name": "Ana Ramírez" },
    "items": [
      { "id": 5510, "description": "Filtro de aceite", "quantity": 2, "unit_price": "135.00", "discount_amount": "15.00", "line_total": "240.00" }
    ],
    "payments": [
      { "id": 3312, "amount": "270.00", "payment_method": "efectivo", "status": "completado", "payment_date": "2026-09-18T14:35:00.000000Z" }
    ]
  },
  "change": 30.0,
  "print": { "data_source_type": "pos", "data_source_id": 987, "template_ids": [7] }
}
```

Errores esperados:
- `422` sin sesión abierta (salvaguarda del servidor; el POS debe abrir caja o unirse antes de cobrar): `{"message": "Necesitas una sesión de caja abierta para registrar ventas."}`
- `422` crédito sin cliente: `{"message": "Selecciona un cliente para dejar saldo pendiente."}`
- `422` crédito excedido: `{"message": "El cliente no tiene crédito disponible suficiente."}`
- `409` `client_uuid` repetido con datos distintos: `{"message": "Esta operación ya fue registrada."}`

> `change` (cambio en efectivo) lo calcula el servidor: `Σ pagos - total` cuando el pago es
> en efectivo puro. La app **no** lo envía.

> **Detalles de la implementación (Fase 3):** el servidor **limita el pago guardado** al total de la
> venta (no guarda sobrepagos), así que `change` se calcula con lo que envió la app y el `total_paid`
> queda igual al total. `print.template_ids` trae los ids de las plantillas de ticket de venta
> disponibles en la sucursal (contexto `pos`/`general`); a partir de la Fase 4 se usan en
> `POST /print/bluetooth-payload` (ver §10).
> Se valida además que la `cash_register_session_id` esté **abierta y sea de la sucursal**:
> si no, `422 session_required`. El `customerId` debe ser de la **sucursal**; y si la venta deja
> saldo, se responde `422 customer_required` (sin cliente) o `422 credit_limit_exceeded`
> (sin crédito) **antes** de tocar la base de datos.

### `POST /pos/layaway` — ✅ implementado (Fase 3)
Mismo payload que `checkout` + `layaway_expiration_date` **obligatoria** y posterior a hoy
(`after:today`). El estatus resultante es `apartado` y el stock queda **reservado**.
Response: igual a `checkout`.

### `POST /pos/store-order` — ✅ implementado (Fase 3)
Permiso: `pos.create_sale`. Crea un **pedido** (estatus `por_entregar`) con stock reservado.
Reutiliza `App\Actions\Pos\CreateStoreOrderAction` (la web usa la misma acción).

```json
{
  "client_uuid": "…",
  "cash_register_session_id": 41,
  "customerId": 8,
  "cartItems": [{ "id": 45, "quantity": 1, "unit_price": 135, "description": "Filtro", "discount": 0, "product_attribute_id": null }],
  "subtotal": 135,
  "total_discount": 0,
  "shipping_cost": 80,
  "contact_info": { "name": "Ana Ramírez", "phone": "4771112233", "type": "pedido" },
  "delivery_date": "2026-09-20T18:00:00.000000Z",
  "shipping_address": "Av. Hidalgo 120, León",
  "notes": "Entregar después de las 6 pm"
}
```
- `contact_info.name` obligatorio (mín. 2 caracteres) y `delivery_date` obligatoria.
- `contact_info.type`: `pedido` (retail) o `comanda` (modo comandas/restaurante).
- Si el contacto no trae teléfono y hay cliente, el servidor toma el del cliente.
- Este endpoint usa `TransactionPaymentService::handleNewOrder()` y **no** recibe pagos.

---

## 8. Historial de ventas

### `GET /transactions` — ✅ implementado (Fase 2)
Permiso: `transactions.access`. Query: `search`, `status`, `date_start`, `date_end`,
`page`, `per_page`, `updated_since`, `sortField`, `sortOrder`.

> **Implementado** en `Api\V1\Transactions\TransactionController` → `App\Services\Transactions\TransactionReadService`.
> Detalles: `items_count` es el **número de líneas** de la venta (no la suma de cantidades);
> `total`, `total_paid` y `remaining_due` llegan como **número**, mientras `subtotal`,
> `total_discount` y `shipping_cost` llegan como **string decimal**; `sortField` acepta
> `created_at`, `folio`, `total` y `customer.name`; `per_page` máx. 100. Igual que la web, se
> **excluye** el canal `abono_a_saldo`.

Response `200` (paginado):
```json
{
  "data": [
    {
      "id": 987,
      "folio": "V-014",
      "status": "completado",
      "channel": "punto_de_venta",
      "customer": { "id": 8, "name": "Ana Ramírez" },
      "user": { "id": 7, "name": "María López" },
      "contact_info": null,
      "delivery_date": null,
      "layaway_expiration_date": null,
      "subtotal": "270.00",
      "total_discount": "30.00",
      "shipping_cost": "0.00",
      "total": 270.0,
      "total_paid": 270.0,
      "remaining_due": 0.0,
      "items_count": 1,
      "is_order": false,
      "invoiced": false,
      "created_at": "2026-09-18T14:35:00.000000Z"
    }
  ],
  "current_page": 1, "last_page": 12, "per_page": 20, "total": 236
}
```
Notas:
- Excluye el canal `abono_a_saldo` (igual que la web `Transaction/Index.vue`).
- `is_order` = `delivery_date != null` o estatus logístico (`por_entregar`, `en_ruta`,
  `entregado_por_pagar`).
- Orden por defecto: `created_at` desc. Permitir `sortField` (`created_at`, `folio`, `total`) y
  `sortOrder` (`asc`|`desc`).

### `GET /transactions/{id}` — ✅ implementado (Fase 2)
Permiso: `transactions.see_details`. `404` si la venta es de otra sucursal.

> Devuelve el payload del listado **más**: `branch`, `notes`, `shipping_address`, `total_tax`,
> `items[]`, `payments[]`, `cash_register_session` (solo `id`), `invoice` (solo
> `{id, folio, status}`, `null` si no está facturada) y el desglose de pagos ya resuelto
> (`paid_amount`, `pending_balance`, `is_paid`). Cada pago trae `bank_account` en el mismo
> formato que `GET /bank-accounts` (o `null`).

```json
{
  "id": 987,
  "folio": "V-014",
  "status": "completado",
  "channel": "punto_de_venta",
  "branch": { "id": 2, "name": "Sucursal Centro" },
  "user": { "id": 7, "name": "María López" },
  "customer": { "id": 8, "name": "Ana Ramírez", "balance": "-350.00", "credit_limit": "2000.00" },
  "contact_info": null,
  "delivery_date": null,
  "shipping_address": null,
  "layaway_expiration_date": null,
  "notes": null,
  "subtotal": "270.00",
  "total_discount": "30.00",
  "total_tax": "0.00",
  "shipping_cost": "0.00",
  "total": 270.0,
  "paid_amount": 270.0,
  "pending_balance": 0.0,
  "is_paid": true,
  "invoiced": false,
  "invoice": null,
  "created_at": "2026-09-18T14:35:00.000000Z",
  "cash_register_session": { "id": 41 },
  "items": [
    {
      "id": 5510,
      "description": "Filtro de aceite",
      "itemable_type": "App\\Models\\Product",
      "itemable_id": 45,
      "quantity": 2,
      "unit_price": "135.00",
      "discount_amount": "15.00",
      "discount_reason": "Promoción de producto",
      "tax_amount": "0.00",
      "line_total": "240.00"
    }
  ],
  "payments": [
    {
      "id": 3312,
      "amount": "270.00",
      "payment_method": "efectivo",
      "status": "completado",
      "payment_date": "2026-09-18T14:35:00.000000Z",
      "notes": null,
      "bank_account": null
    }
  ]
}
```
**La app debe calcular/verificar pagos con `paid_amount` y `pending_balance`** (ya vienen resueltos),
y mostrar acciones disponibles según el estatus y los permisos:

| Estatus | Acciones disponibles |
|---|---|
| `completado` | imprimir, WhatsApp, devolución, cambio, cancelar |
| `pendiente` | registrar abono, imprimir, WhatsApp, cancelar |
| `apartado` | registrar abono, extender vigencia, modificar, cancelar |
| `por_entregar` / `en_ruta` | registrar abono, reprogramar entrega, cancelar |
| `cancelado` / `reembolsado` | solo consulta e impresión |

### `POST /transactions/{id}/cancel` — ✅ implementado (Fase 3)
Permisos: `transactions.cancel`. Reutiliza la lógica de cancelación extraída a
`App\Services\Transactions\TransactionCancellationService` (la web usa el mismo servicio).

```json
{
  "client_uuid": "…",
  "action": "refund",
  "refund_method": "cash",
  "bank_account_id": null
}
```
- `action`: `refund` (devuelve el dinero pagado) o `penalty` (cancela **reteniendo** el dinero).
- `refund_method`: obligatorio si `action = refund`. Valores `cash` | `balance` | `transfer`.
  - `cash` → **requiere sesión de caja abierta**; si no hay, el servidor responde `422`.
  - `balance` → **requiere cliente** asignado a la venta.
  - `transfer` → requiere `bank_account_id`.
- Si ya estaba `cancelado` o `reembolsado` → `422 {"message": "La venta ya se encuentra cancelada o reembolsada."}`

> **Detalles de la implementación (Fase 3):** el stock vuelve a la sucursal (o se libera la reserva
> si era apartado/pedido) y la deuda del cliente se revierte. `refund_method = cash` exige una
> sesión de caja **abierta de la sucursal** y registra la salida de efectivo en
> `session_cash_movements`; `transfer` descuenta el saldo de la cuenta y registra un pago negativo;
> `balance` acredita al cliente. El `message` de la respuesta explica el caso (reembolsado en
> efectivo / al saldo / por transferencia / cancelado con penalización), igual que en la web.

Response `200`: `{ "transaction": { …transacción actualizada… }, "message": "Venta cancelada correctamente." }`

### `POST /transactions/{id}/refund` — ✅ implementado (Fase 3)
Permiso: `transactions.refund`. Equivale a `cancel` con `action = refund` forzado.
Body: `{ "refund_method": "cash|balance|transfer", "bank_account_id": null }`.

### `POST /transactions/{id}/payments` — ✅ implementado (Fase 3)
Permiso: `transactions.add_payment`. Registra un **abono** (pago a venta existente).
Reutiliza `TransactionPaymentService::applyPaymentToTransaction()`.

```json
{
  "client_uuid": "…",
  "cash_register_session_id": 41,
  "use_balance": false,
  "payments": [
    { "amount": 200, "method": "efectivo", "bank_account_id": null, "notes": null },
    { "amount": 150, "method": "tarjeta", "bank_account_id": 2, "notes": "Voucher 1234" }
  ]
}
```
Reglas:
- Al menos un pago **o** `use_balance: true`; si no → `422`
  `{"message": "Debe proporcionar al menos un método de pago o usar el saldo a favor."}`
- `method` aquí solo admite `efectivo` | `tarjeta` | `transferencia` (`saldo` se envía por `use_balance`).
- `bank_account_id` obligatorio para `tarjeta` y `transferencia`.
- Si la venta está `cancelado` o `reembolsado` → `422` (no admite pagos).

Response `200`:
```json
{
  "transaction": { "…": "venta actualizada con total_paid y remaining_due" },
  "print": {
    "type": "abono",
    "payload": { "kind": "abono", "folio": "V-014", "abonado": "$350.00 MXN", "remainingDue": "$0.00 MXN", "liquidated": true },
    "customer_phone": "4771112233",
    "customer_id": 8
  }
}
```
- `print.type` es `abono` para ventas normales y `order_payment` para pedidos.
- `payload` es el mismo objeto que la web envía a WhatsApp (`WhatsAppTicketService`), listo para
  renderizar el ticket de WhatsApp desde la app.

### `PUT /transactions/{id}/payments/{paymentId}` — ✅ implementado (Fase 4)
Permiso: `transactions.edit_payment`. Body: `{ amount, payment_method, bank_account_id, notes }`.
Concilia el saldo de la cuenta bancaria (revierte el efecto anterior y aplica el nuevo).
`amount` mínimo `0.01`; `bank_account_id` obligatorio para `tarjeta`/`transferencia`.
Response `200`: `{ "message": "Pago actualizado correctamente.", "payment": { … }, "transaction": { … } }`.
Lógica en `TransactionPaymentEditService` (compartida con la web).

### `DELETE /transactions/{id}/payments/{paymentId}` — ✅ implementado (Fase 4)
Permiso: `transactions.edit_payment`. Elimina el pago y ajusta el saldo bancario, el saldo a favor
del cliente y el movimiento de caja. Response `204`.

### Endpoints fuera del alcance móvil (por ahora)
`POST /transactions/{id}/exchange`, `/exchange-layaway`, `/reschedule-order`,
`/extend-layaway`, `/update-date`, `/customers/{id}/pending-debts` y
`DELETE /transactions/{id}`.
Se documentarán cuando se decida llevarlos a la app (Fase 5+).

---

## 9. Órdenes de servicio

### `GET /service-orders` — ✅ implementado (Fase 2)
Permiso: `services.orders.access`. Query: `search` (folio, cliente o descripción del equipo),
`status`, `sortField` (`received_at`, `promised_at`, `folio`, `final_total`), `sortOrder`,
`page`, `per_page`, `updated_since`.

> **Implementado** en `Api\V1\ServiceOrders\ServiceOrderController` → `App\Services\ServiceOrders\ServiceOrderReadService`.
> Solo órdenes de la sucursal del usuario (las de otra sucursal no se listan ni se pueden abrir:
> `404`). `amount_due` nunca es negativo y `has_transaction = false` en órdenes antiguas que aún
> no tienen venta vinculada.

```json
{
  "data": [
    {
      "id": 314,
      "folio": "OS-014",
      "customer_name": "Ana Ramírez",
      "customer_phone": "4771112233",
      "item_description": "iPhone 13, pantalla rota",
      "status": "en_progreso",
      "technician_name": "Luis Torres",
      "received_at": "2026-09-15T16:00:00.000000Z",
      "promised_at": "2026-09-20T18:00:00.000000Z",
      "subtotal": "1450.00",
      "discount_amount": "50.00",
      "final_total": "1400.00",
      "total_paid": 700.0,
      "amount_due": 700.0,
      "has_transaction": true,
      "created_at": "2026-09-15T16:00:00.000000Z"
    }
  ],
  "current_page": 1, "last_page": 4, "per_page": 20, "total": 68
}
```
- `amount_due = final_total - Σ transaction.payments.amount` (nunca negativo).
- Orden por defecto: `received_at` desc.

### `GET /service-orders/{id}` — ✅ implementado (Fase 2)
Permiso: `services.orders.see_details`.

> Detalles de la implementación: `media.*` trae `{ id, file_name, original_url, thumb_url, size }`
> (`thumb_url` cae al original mientras no exista la conversión `thumb`); `activities` son los
> **últimos 20** eventos ordenados por id descendente (el más reciente primero, con `causer`
> `{id, name}` o `null`); `transaction` es `null` cuando la orden no tiene venta vinculada;
> `custom_field_definitions` se filtran por `module = service_orders` de la suscripción.

```json
{
  "id": 314,
  "folio": "OS-014",
  "status": "en_progreso",
  "customer": { "id": 8, "name": "Ana Ramírez", "phone": "4771112233", "email": "ana@correo.com", "balance": "-350.00" },
  "customer_name": "Ana Ramírez",
  "customer_phone": "4771112233",
  "customer_email": "ana@correo.com",
  "customer_address": { "street": "Av. Hidalgo 120", "city": "León" },
  "item_description": "iPhone 13, pantalla rota",
  "reported_problems": "No enciende después de una caída",
  "technician_diagnosis": "Display dañado y batería al 62 %",
  "technician_name": "Luis Torres",
  "technician_commission_type": "percentage",
  "technician_commission_value": "20.00",
  "received_at": "2026-09-15T16:00:00.000000Z",
  "promised_at": "2026-09-20T18:00:00.000000Z",
  "subtotal": "1450.00",
  "discount_type": "fixed",
  "discount_value": "50.00",
  "discount_amount": "50.00",
  "final_total": "1400.00",
  "custom_fields": { "pin_desbloqueo": "1234" },
  "custom_field_definitions": [
    { "key": "pin_desbloqueo", "name": "PIN de desbloqueo", "type": "text", "options": null, "is_required": false }
  ],
  "items": [
    {
      "id": 902,
      "description": "Cambio de pantalla (original)",
      "itemable_type": "App\\Models\\ServiceVariant",
      "itemable_id": 31,
      "quantity": "1.00",
      "unit_price": "1450.00",
      "line_total": "1450.00"
    },
    {
      "id": 903,
      "description": "Mica templada",
      "itemable_type": "App\\Models\\Product",
      "itemable_id": 78,
      "quantity": "1.00",
      "unit_price": "120.00",
      "line_total": "120.00"
    }
  ],
  "media": {
    "initial_service_order_evidence": [
      { "id": 551, "file_name": "equipo-1.jpg", "original_url": "https://.../equipo-1.jpg", "thumb_url": "https://.../equipo-1-368.jpg", "size": 245000 }
    ],
    "closing_service_order_evidence": []
  },
  "transaction": {
    "id": 1201,
    "folio": "OS-V-006",
    "status": "pendiente",
    "total": 1400.0,
    "total_paid": 700.0,
    "remaining_due": 700.0,
    "payments": [
      { "id": 5520, "amount": "700.00", "payment_method": "efectivo", "payment_date": "2026-09-15T16:10:00.000000Z", "bank_account": null }
    ]
  },
  "activities": [
    { "description": "La orden de servicio ha sido actualizada", "event": "updated", "causer": { "id": 7, "name": "María López" }, "created_at": "2026-09-16T10:00:00.000000Z" }
  ],
  "created_at": "2026-09-15T16:00:00.000000Z"
}
```
- `media` agrupa las dos colecciones; si no hay fotos, arreglos vacíos.
- `transaction` puede ser `null` en órdenes antiguas (usar `POST …/ensure-transaction` antes de cobrar).
- `activities` limitado a los últimos 20 eventos para no saturar la pantalla.

### `POST /service-orders` — ✅ implementado (Fase 3, multipart/form-data)
Permiso: `services.orders.create`. Reutiliza `CreateServiceOrderAction`.
**Response `201`:** `{ "message": "Orden de servicio creada.", "service_order": { …igual que GET /service-orders/{id}… } }`
(usamos la clave `service_order`, igual que en `/diagnosis`, en lugar de devolver el objeto en la raíz).
La `cash_register_session_id` debe estar **abierta y ser de la sucursal** (`422 session_required`).
El `customer_id` debe pertenecer a la **suscripción** (igual que el formulario web, que ofrece todos
los clientes de la suscripción).

| Campo | Regla |
|---|---|
| `customer_id` | nullable, debe existir |
| `create_customer` | **required boolean** (true = dar de alta al cliente al vuelo) |
| `credit_limit` | required si `create_customer = true`, numérico ≥ 0 |
| `customer_name` | required, máx. 255 |
| `customer_email` | nullable, email |
| `customer_phone` | nullable, máx. 20 |
| `customer_address` | nullable `{street, city}` |
| `item_description` | required, máx. 255 |
| `reported_problems` | required, texto |
| `promised_at` | nullable, fecha |
| `assign_technician` | **required boolean** |
| `technician_name` | required si `assign_technician = true` |
| `technician_commission_type` | required si `assign_technician = true`: `percentage` o `fixed` |
| `technician_commission_value` | required si `assign_technician = true`, ≥ 0 |
| `items[]` | nullable array |
| `items[].itemable_id` / `itemable_type` | nullable (null = ítem libre) |
| `items[].description` | required |
| `items[].quantity` | required, numérico ≥ 0 |
| `items[].unit_price` | required, ≥ 0 |
| `items[].line_total` | required, ≥ 0 |
| `subtotal` | required, ≥ 0 |
| `discount_type` | required: `fixed` o `percentage` |
| `discount_value` | nullable, ≥ 0 |
| `discount_amount` | required, ≥ 0 |
| `final_total` | required, ≥ 0 |
| `custom_fields` | nullable objeto |
| `initial_evidence_images[]` | nullable, máx. 5, `image`, ≤ 2048 KB |
| `cash_register_session_id` | **required**, debe existir con `status = abierta` |
| `client_uuid` | requerido por la app para idempotencia |

Ejemplo `curl` (multipart):
```bash
curl -X POST "$API/service-orders" \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
  -F "create_customer=false" -F "customer_id=8" \
  -F "customer_name=Ana Ramírez" -F "customer_phone=4771112233" \
  -F "item_description=iPhone 13, pantalla rota" \
  -F "reported_problems=No enciende después de una caída" \
  -F "assign_technician=true" -F "technician_name=Luis Torres" \
  -F "technician_commission_type=percentage" -F "technician_commission_value=20" \
  -F "items[0][itemable_type]=App\\Models\\ServiceVariant" -F "items[0][itemable_id]=31" \
  -F "items[0][description]=Cambio de pantalla" \
  -F "items[0][quantity]=1" -F "items[0][unit_price]=1450" -F "items[0][line_total]=1450" \
  -F "subtotal=1450" -F "discount_type=fixed" -F "discount_value=0" \
  -F "discount_amount=0" -F "final_total=1450" \
  -F "cash_register_session_id=41" -F "client_uuid=$UUID" \
  -F "initial_evidence_images[]=@/ruta/foto1.jpg"
```

Efectos en el servidor (importante para la app):
1. Genera el folio `OS-###` y el estatus inicial `pendiente`.
2. Crea **automáticamente una venta vinculada** con folio `OS-V-###`, canal `orden_de_servicio`
   y estatus `pendiente` si `final_total > 0` (si es 0, queda `completado`).
3. Si hay cliente y `final_total > 0`, genera la **deuda** del cliente (`venta_a_credito`,
   nota "Cargo por Orden de Servicio #OS-014").
4. Da de baja el stock de los ítems de tipo `Product` / `ProductAttribute`.
5. Guarda las fotografías en `initial-service-order-evidence`.

Response `201`: el objeto de `GET /service-orders/{id}` (con `transaction`) y
`"message": "Orden de servicio creada."`.

### `PUT /service-orders/{id}` — ✅ implementado (Fase 3, multipart)
Permiso: `services.orders.edit`. Usa las mismas reglas que el store (sin `create_customer` ni
`credit_limit`) y `UpdateServiceOrderAction`, que:
- revierte el stock de los ítems previos y lo vuelve a aplicar con los nuevos,
- acepta `deleted_media_ids[]` (ids de la tabla `media`) para borrar evidencias,
- acepta `initial_evidence_images[]` para agregar nuevas,
- ajusta la venta vinculada y la deuda del cliente a los nuevos totales.

**Response `200`:** `{ "message": "Orden de servicio actualizada.", "service_order": { … } }`

### `PATCH /service-orders/{id}/status` — ✅ implementado (Fase 2)
Permiso: `services.orders.change_status`. Reutiliza `ChangeServiceOrderStatusAction`.
```json
{ "status": "terminado", "client_uuid": "…" }
```
> **422** `{"message": "El estatus ya es el seleccionado.", "errors": {"status": ["El estatus ya es el seleccionado."]}}`
> cuando se envía el estatus actual; **422** `errors.status[0] = "El estatus seleccionado no es válido."`
> con un estatus desconocido. Revertir a un paso anterior **sí** se permite y el `message` lo
> indica ("Estatus de la orden revertido correctamente."). La app debe leer el mensaje de
> `errors.status[0]` para mostrarlo. `client_uuid` se valida como UUID, pero la idempotencia real
> llega en la Fase 5.
- `status` ∈ `pendiente` | `en_progreso` | `esperando_refaccion` | `terminado` | `entregado` | `cancelado`
  (validado con `Rule::enum(ServiceOrderStatus::class)`).
- No hay máquina de estados estricta, pero la app debe mostrar solo los pasos siguientes naturales:
  pendiente → en progreso → (esperando refacción) → terminado → entregado.
- Al pasar a `entregado` con saldo pendiente, la app debe abrir el cobro.
- Al cancelar, la acción libera el stock y ajusta la venta vinculada.
- La acción devuelve `{ success, message }`; si `success = false`, responder `422` con ese `message`.

Response `200`: `{ "service_order": { …resumen… }, "message": "Estatus actualizado a “Terminado”." }`

### `POST /service-orders/{id}/diagnosis` — ✅ implementado (Fase 2, multipart)
Permiso: `services.orders.edit`.
```
technician_diagnosis: string, máx. 1000 (nullable)
closing_evidence_images[]: máx. 5, image
```
> **Importante:** si **no** envías `technician_diagnosis`, el diagnóstico existente se conserva
> (solo se agregan fotos). Si lo envías vacío (`""`), se borra. Las fotos se agregan a la colección
> `closing-service-order-evidence` (la web y la app ven lo mismo) y cada archivo debe ser una
> imagen: `422` `errors.closing_evidence_images.0 = "Cada archivo debe ser una imagen."`.
Guarda el diagnóstico y agrega fotos a `closing-service-order-evidence`.
Response `200`: `{ "message": "Diagnóstico y evidencias guardados correctamente.", "service_order": { … } }`

### `POST /service-orders/{id}/ensure-transaction` — ✅ implementado (Fase 3)
Permiso: `services.orders.edit`. Crea la venta vinculada si la orden no tiene una
(órdenes antiguas) y devuelve `{ "transaction_id": 1201 }`. Si ya tiene venta, devuelve la misma
(sin duplicar). Reutiliza `EnsureServiceOrderTransactionAction`.

### `POST /service-orders/{id}/payments` — ✅ implementado (Fase 3)
Permiso: `transactions.add_payment`. Mismo body que `POST /transactions/{id}/payments`,
pero enviado contra la orden: el servidor resuelve (o crea) la venta vinculada y registra el
abono. Es el flujo de **anticipos** de una orden. Requiere sesión de caja abierta de la sucursal
(`422 session_required`).

Response `200`: `{ "service_order": { … }, "transaction": { … }, "print": { "type": "abono", … } }`

### `DELETE /service-orders/{id}` — ✅ implementado (Fase 4)
Permiso: `services.orders.delete`. Elimina la orden **y su venta vinculada**.
Response `204`. La app debe pedir confirmación explícita
("Esta acción no se puede deshacer."). Lógica en `DeleteServiceOrderAction` (la web usa la misma).

---

## 10. Impresión térmica y WhatsApp

> **Clave:** el servidor ya sabe convertir plantillas a comandos ESC/POS
> (`PrintEncoderService`). La app **no** debe reimplementar plantillas: recibe bytes listos
> en Base64 y los envía a la impresora.

### `GET /print/templates` — ✅ implementado (Fase 4)
Query: `context` (`pos` | `transaction` | `service_order` | `product` | `customer` | `quote` | `general`),
`type` (`ticket_venta` | `etiqueta` | `cotizacion` | `recibo_servicio`).
Permiso: sesión válida con acceso operativo (`pos.access` **o** `transactions.access` **o**
`services.orders.access`). Solo se listan las plantillas de la **suscripción** del usuario.

```json
[
  {
    "id": 7,
    "name": "Ticket de venta 80 mm",
    "type": "ticket_venta",
    "context_type": "pos",
    "paper_width": "80mm",
    "is_default": true,
    "config": { "paperWidth": "80mm", "feedLines": 3 }
  }
]
```
La app guarda esta lista para poder imprimir **sin conexión** después.

### `POST /print/bluetooth-payload` — ✅ implementado (Fase 4)
```json
{
  "template_id": 7,
  "data_source_type": "pos",
  "data_source_id": 987,
  "open_drawer": false
}
```
Response `200`:
```json
{ "commands_base64": "G0AaG0E=", "paperWidth": "80mm" }
```

`data_source_type` acepta: `pos` | `transaction` | `service_order` | `product` | `customer` |
`order` | `general`.

Procedimiento en la app (idéntico a `resources/js/Composables/useBluetoothPrinter.js`):
1. `base64Decode(commands_base64)` → `Uint8List`.
2. Escribir en la característica del servicio GATT (UUIDs probados:
   `0000af30-0000-1000-8000-00805f9b34fb`, `49535343-fe7d-4ae5-8fa9-9fafd205e455`,
   `00001101-0000-1000-8000-00805f9b34fb`).
3. Enviar **chunks de 20 bytes** con una pausa de **25 ms** entre cada uno
   (las impresoras térmicas BT baratas pierden datos si se envían rápido).
4. Preferir `writeWithoutResponse` si el dispositivo lo soporta; si no, `write`.

Notas Android:
- Requiere permisos en runtime desde Android 12: `BLUETOOTH_CONNECT` y `BLUETOOTH_SCAN`
  (además de `BLUETOOTH` y `BLUETOOTH_ADMIN` para versiones anteriores).
- Guardar el identificador del dispositivo emparejado para reconectar sin escanear cada vez.
- Si la impresora se desconecta en medio del envío: mostrar "Se perdió la conexión con la impresora"
  y permitir reimprimir (no dejar el ticket a medias sin avisar).

### `POST /print/payload` — ✅ implementado (Fase 4) (etiquetas / TSPL)
Mismos campos que `bluetooth-payload` + `offset_x`, `offset_y` opcionales.
```json
{ "operations": [/* operaciones de la plantilla */], "paperWidth": "80mm", "feedLines": 3 }
```
Se usa para impresoras de **etiquetas** (TSPL), no para tickets de venta.

### `POST /print/ticket-html` — ✅ implementado (Fase 4) (respaldo)
```json
{ "template_id": 7, "data_source_type": "pos", "data_source_id": 987 }
```
```json
{ "html": "<html>…</html>", "paperWidth": "80mm", "template_name": "Ticket de venta 80 mm" }
```
Respaldo para generar PDF o compartir el ticket cuando no hay impresora Bluetooth.

### `POST /print/whatsapp-ticket` — ✅ implementado (Fase 4)
```json
{ "data_source_type": "transaction", "data_source_id": 987 }
```
Response `200`:
```json
{
  "ticket": {
    "kind": "sale",
    "businessName": "Refaccionaria López",
    "date": "18/09/2026 - 14:35",
    "folio": "V-014",
    "customer": "Ana Ramírez",
    "items": [{ "cantidad": 2, "descripcion": "Filtro de aceite", "total": "$240.00" }],
    "total": "$270.00 MXN",
    "paymentMethod": "Efectivo (Pagado: $300.00 | Cambio: $30.00)",
    "finalMessage": "¡Gracias por tu compra!"
  },
  "customer_phone": "4771112233",
  "customer_id": 8
}
```

Variantes de `ticket` (las construye `WhatsAppTicketService`):

| `kind` | Cuándo ocurre | Campos propios |
|---|---|---|
| `sale` | venta normal / crédito / apartado | `items[]`, `total`, `paymentMethod`, `saleType` |
| `abono` | abono a una venta existente | `saleTotal`, `previousDue`, `abonado`, `remainingDue`, `liquidated` |
| `order` | pedido (por entregar) | estado del pedido, `items[]`, `total` |
| `order_payment` | abono a un pedido | `estado`, `total`, `previousDue`, `abonado`, `paymentMethod`, `remainingDue` |

> **Implementación (Fase 4):** el ticket de venta lo construye ahora
> `WhatsAppTicketService::buildSalePayload()` (antes vivía en el controlador web), así que la web y
> la app mandan exactamente el mismo texto; añade `kind: "sale"`. Los tres endpoints con
> `data_source_type` resuelven el documento con `PrintDataSourceResolver`, que **limita cada origen a
> la suscripción** del usuario (un documento de otro negocio responde `404`). Un
> `data_source_type` inválido responde `422`, igual que una plantilla de otra suscripción (`404`).

Todos los tickets traen: `businessName`, `date` (`d/m/Y - H:i`), `folio`, `customer` y
`finalMessage`. Los montos vienen **ya formateados como texto** (`"$270.00 MXN"`): la app solo
los concatena, **no** los reformatea.

Flujo WhatsApp:
1. Llamar `POST /print/whatsapp-ticket`.
2. Convertir el objeto `ticket` a texto plano (una línea por campo, respetando el orden:
   negocio, fecha, folio, cliente, ítems, total, método de pago, leyenda).
3. Abrir `https://wa.me/{customer_phone}?text={Uri.encodeComponent(texto)}`.
   Si `customer_phone` es `null`, abrir `https://wa.me/?text=…` para que el usuario elija contacto.

### Impresión sin conexión (Fase 5)
Cuando no hay red, la app arma el ticket localmente con un encoder ESC/POS propio
(58/80 mm) usando estos datos: nombre comercial, folio (temporal `TMP-XXXXXX` si aún no
sincroniza), fecha/hora local, líneas (cantidad × descripción y total), subtotal, descuento,
total, métodos de pago, cambio, leyenda de agradecimiento.
Reglas:
- Marcar el ticket como impreso **localmente** y no reimprimirlo al sincronizar.
- Una vez sincronizada la venta, si el usuario reimprime, usar el endpoint del servidor para
  respetar la plantilla configurada por el negocio.

---

## 11. Sincronización (modo offline)

### `GET /sync/manifest` — Fase 5
Se llama al iniciar sesión y al recuperar conexión. Devuelve todo lo "estático" que la app
necesita en caché:
```json
{
  "server_time": "2026-09-18T14:40:00.000000Z",
  "user": { "id": 7, "permissions": ["pos.access", "pos.create_sale"] },
  "active_session": { "id": 41, "status": "abierta" },
  "print_templates": [{ "id": 7, "name": "Ticket 80 mm", "paper_width": "80mm" }],
  "custom_field_definitions": [
    { "key": "pin_desbloqueo", "name": "PIN de desbloqueo", "type": "text", "is_required": false }
  ],
  "counts": { "products": 52, "customers": 310, "service_orders": 68 }
}
```

### `GET /sync/pull` — Fase 5
Paginado, **una entidad por llamada**:
`GET /sync/pull?entity=products&since=2026-09-18T13:00:00.000000Z&page=1&per_page=100`

`entity` ∈ `products` | `customers` | `service_orders` | `transactions`.

```json
{
  "data": [ /* misma forma que los endpoints de listado/detalle */ ],
  "deleted_ids": [55, 61],
  "server_time": "2026-09-18T14:40:00.000000Z",
  "has_more": true
}
```
Reglas:
- `since` filtra por `updated_at >= since`. Sin `since`, devuelve todo (primera carga).
- `deleted_ids` sale de la tabla `sync_tombstones` (registra los borrados de
  `transactions` y `service_orders`, que en la web se eliminan físicamente).
- `service_orders` y `transactions` solo devuelven los últimos **60 días** (volumen).
- El sync de catálogo (`products`, `customers`) incluye stock y saldos actualizados:
  al terminar, la app **reemplaza** su copia local de esos registros.

### `POST /sync/push` — Fase 5
Envía la **cola de operaciones** pendientes del dispositivo, en orden.

Request:
```json
{
  "device_id": "a1b2c3d4",
  "operations": [
    {
      "client_uuid": "9c2f7a1b-7d3e-4a55-8f21-0c9d4e7a1b30",
      "type": "pos.checkout",
      "payload": { "…payload completo de /pos/checkout (sin client_uuid)…" },
      "created_at": "2026-09-18T13:05:00.000000Z"
    },
    {
      "client_uuid": "1f7c…",
      "type": "service_order.status",
      "entity_id": 314,
      "payload": { "status": "terminado" },
      "created_at": "2026-09-18T13:10:00.000000Z"
    }
  ]
}
```

Tipos soportados: `pos.checkout`, `pos.layaway`, `pos.store_order`, `transaction.payment`,
`transaction.cancel`, `service_order.create`, `service_order.update`, `service_order.status`,
`service_order.diagnosis` (sin imágenes), `customer.create`.

Response `200`:
```json
{
  "results": [
    { "client_uuid": "9c2f…", "status": "applied",   "server_id": 987, "folio": "V-014", "flags": ["negative_stock"], "data": { } },
    { "client_uuid": "1f7c…", "status": "duplicate",  "server_id": 1201, "folio": "OS-014" },
    { "client_uuid": "55aa…", "status": "failed",     "error": { "code": "session_closed", "message": "La sesión de caja fue cerrada. La operación quedó pendiente de revisión." } }
  ],
  "server_time": "2026-09-18T14:45:00.000000Z"
}
```

Significado de `status`:

| Status | Qué hace la app |
|---|---|
| `applied` | Reemplaza el folio temporal por `folio`, guarda `server_id` y marca la operación como sincronizada. Si trae `flags: ["negative_stock"]`, muestra "Requiere revisión" en la venta. |
| `duplicate` | La operación ya se había aplicado (mismo `client_uuid`). Se marca como sincronizada sin duplicar. |
| `failed` | Error recuperable o permanente; la operación **permanece** en la cola con intentos y retroceso exponencial (1 min, 5 min, 15 min, 1 h). Tras 5 intentos → pasa a "requiere revisión". |

Reglas de oro del sync:
1. **Orden estricto:** las operaciones se envían en el orden en que ocurrieron. Un fallo no
   detiene las demás, pero la app no debe reordenarlas.
2. **Idempotencia obligatoria:** el `client_uuid` se genera una sola vez y nunca se regenera
   al reintentar.
3. **Sin folios inventados:** mientras esté pendiente, el folio local es `TMP-XXXXXX`.
4. **Sesión de caja:** si el servidor responde `failed` con `code: "session_closed"`, la venta
   queda en revisión y la app bloquea el POS hasta obtener una sesión abierta.
5. **Evidencias:** las fotos se suben **en línea** (`service_order.diagnosis` no viaja con
   imágenes en el sync). Si no hay red, se guardan localmente y se suben con
   `POST /service-orders/{id}/diagnosis` al recuperar conexión.
6. **Nada se borra en el dispositivo** hasta recibir `applied` o `duplicate`.

### Política de sobreventa offline (confirmada)
- La app **no bloquea** una venta por stock insuficiente hallándose sin conexión: el vendedor
  puede cerrar la venta con el stock local que conoce.
- El servidor **aplica** la operación y, si el stock resultante queda negativo, responde
  `applied` con `flags: ["negative_stock"]`.
- La app marca esa venta con la etiqueta "Requiere revisión" y la lista en la pantalla
  "Pendientes de revisar" para que el negocio ajuste inventario.
- Esta política es para el modo offline. **Con conexión**, el POS sí debe advertir cuando la
  cantidad supera el stock disponible (igual que la web hoy).

---

## 11b. Cuenta, sucursal, suscripción, soporte y notificaciones

> Equivalente móvil de la barra superior web (`resources/js/Layouts/AppTopbar.vue`): selector de
> sucursal, menú de usuario (Perfil · Suscripción · Soporte · Cerrar sesión) y notificaciones.

### 11b.1 `PUT /branch/switch/{branch}` — ✅ implementado (Fase 4) — Cambiar sucursal
Permiso: `system.branches.switch` (la web oculta el selector completo sin él).
Sin body; la sucursal va en la URL. La lista de sucursales llega en
`available_branches` de `GET /auth/me` (con `is_current`; el super admin la recibe agrupada por
suscripción).

Reglas del servidor:
- El usuario solo puede cambiar a una sucursal de **su propia suscripción**
  (`user->branch->subscription_id !== branch->subscription_id` → `403`).
- **Excepción:** el usuario `id = 1` (super admin / modo soporte) puede cambiar entre suscripciones.
- Efecto: actualiza `users.branch_id` del usuario autenticado → **aplica a todos sus dispositivos**
  y a la web al instante.

Response `200`:
```json
{
  "branch": { "id": 2, "name": "Sucursal Centro" },
  "message": "Cambiado a la sucursal: Sucursal Centro",
  "context": { "…mismo bloque que `GET /auth/me` (permisos, módulos, sesión de caja)…" }
}
```
Errores: `403` `branch_out_of_scope` ("No tienes permiso para cambiar a esta sucursal.").

Reglas para la app:
- **Requiere conexión** (recalcula permisos, módulos y caja de la nueva sucursal).
- Antes de cambiar: si hay **operaciones en la cola de sincronización**, advertir y bloquear el
  cambio ("Tienes N operaciones sin sincronizar. Sincronízalas antes de cambiar de sucursal.").
- Después de cambiar: **limpiar la caché local de la sucursal anterior** (catálogo, clientes,
  ventas, órdenes, caja), volver a llamar a `GET /auth/me`, re-sincronizar el catálogo y regresar
  a la pantalla de apertura de caja (la sesión anterior pertenecía a otra sucursal).
- El selector lista `available_branches` de `GET /auth/me`; para el super admin viene agrupado por
  suscripción (`[{ subscription_name, branches[] }]`).

### 11b.2 `GET /notifications` — ✅ implementado (Fase 4)
Alimenta el icono de campana del topbar. Permiso: sesión válida.
Los cinco contadores llegan **siempre** (aunque el usuario no tenga acceso a ventas, en ese caso en
`0`). Se calculan con `User::getGlobalNotifications()`.
```json
{ "expiring_debts": 3, "upcoming_deliveries": 2, "unread_updates": 5, "pending_orders": 1, "total": 11 }
```
- `expiring_debts`: apartados/créditos que vencen en ≤ 3 días.
- `upcoming_deliveries`: pedidos `por_entregar` con entrega en ≤ 3 días.
- `unread_updates`: notas de la versión sin leer.
- `pending_orders`: pedidos de la tienda en línea pendientes o en revisión (solo si el módulo está activo).
- Si el usuario no tiene `transactions.access`, todos los contadores vienen en `0`.
- **Sin conexión:** mostrar el último valor cacheado (sin badge si nunca se ha cargado).

### 11b.3 Soporte — ✅ implementado (Fase 4)
La web abre un modal informativo (`SupportModal.vue`) con canales de contacto y un Centro de ayuda
estático. El contenido vive ahora en **`config/support.php`** y se sirve tal cual; `help_center_url`
se arma con el dominio de la instalación. Permiso: sesión válida.

`GET /support` (permiso: sesión válida)
```json
{
  "title": "Centro de soporte",
  "subtitle": "Estamos aquí para ayudarte",
  "message": "Puedes solicitar soporte técnico, reportar algún error, sugerir mejoras al sistema o proponer nuevas funcionalidades. Con gusto lo evaluaremos.",
  "schedule": [
    { "label": "Lunes a viernes", "hours": "8:00 AM — 7:00 PM" },
    { "label": "Sábados", "hours": "9:00 AM — 3:00 PM" }
  ],
  "channels": [
    { "type": "email", "label": "Correo electrónico", "value": "notificaciones@ezyventas.com", "url": "mailto:notificaciones@ezyventas.com" },
    { "type": "whatsapp", "label": "WhatsApp", "value": "+52 33 2170 5650", "url": "https://wa.me/5213321705650" }
  ],
  "help_center_url": "https://<dominio>/centro-ayuda",
  "help_topics": [
    { "id": "steps", "title": "Primeros pasos", "description": "Configura tu cuenta y realiza tu primera venta." },
    { "id": "billing", "title": "Facturación", "description": "Gestiona tus pagos, facturas y suscripciones." },
    { "id": "account", "title": "Mi cuenta", "description": "Actualiza tu perfil, seguridad y preferencias." },
    { "id": "inventory", "title": "Inventario", "description": "Controla productos, stock y sucursales." }
  ]
}
```
Notas:
- Los canales se abren con el navegador/telefono del sistema (`mailto:` y `wa.me`).
- `help_center_url` abre el Centro de ayuda web dentro de la app (WebView o navegador externo).
- El contenido debe venir de un archivo de configuración del servidor
  (`config/support.php`) para no tener que publicar una nueva versión de la app al cambiarlo.

### 11b.4 Perfil de usuario — ✅ implementado (Fase 4)
Tres pestañas: **Información personal**, **Seguridad** y **Sesiones activas**. Permiso: sesión válida.
La foto se guarda con `HasProfilePhoto` (Jetstream) y `has_photo` indica si existe.
Errores con `code`: `invalid_current_password` (contraseña actual incorrecta) en el cambio de
contraseña y en «cerrar otras sesiones»; al cambiar el correo se envía el código OTP y
`email_verified_at` vuelve a `null` (`email_verification_sent: true`).

`GET /profile` (permiso: sesión válida)
```json
{
  "user": {
    "id": 7, "name": "María López", "email": "maria@negocio.com",
    "email_verified_at": "2025-01-10T10:00:00.000000Z",
    "phone": "4771234567",
    "profile_photo_url": "https://.../profile-photos/user7.jpg",
    "has_photo": true
  },
  "context": { "…bloque de `GET /auth/me`…" }
}
```

`PUT /profile` (multipart/form-data)
| Campo | Regla |
|---|---|
| `name` | required, string, máx. 255 |
| `email` | required, email, único (ignorando su propio id) |
| `photo` | nullable, `image`, ≤ 1024 KB |

- Al cambiar el **correo**, el sistema envía un **código OTP** al nuevo correo
  (`EmailVerificationCodeService`); la app debe avisar:
  "Te enviamos un código de verificación a tu nuevo correo." y no marcar la cuenta como verificada
  hasta que se confirme.
- Response `200`: `{ "user": { …actualizado… }, "message": "Tus datos se guardaron." }`.

`DELETE /profile/photo` → elimina la foto ("Eliminar foto"). Response `200`.

`PUT /profile/password`
```json
{ "current_password": "…", "password": "…", "password_confirmation": "…" }
```
- `current_password` se valida contra la contraseña actual (`invalid_current_password` si no coincide).
- `password` con confirmación y las reglas por defecto de contraseña.
- Response `200`: `{ "message": "Tu contraseña se actualizó." }`

`POST /profile/logout-other-devices`
```json
{ "password": "…" }
```
- Equivale a "Cerrar otras sesiones": revoca **todos los tokens Sanctum excepto el actual**
  (y las sesiones web). Response `200`: `{ "message": "Se cerraron las demás sesiones." }`.

**Fuera de alcance por ahora:** autenticación de dos factores (en la web está deshabilitada) y la
lista de dispositivos activos. Se pueden agregar en Fase 6 usando `personal_access_tokens`.

### 11b.5 Suscripción — ✅ implementado (Fase 4) — solo propietario
La web exige ser **propietario de la suscripción** (`!$user->roles()->exists()`); si el usuario
tiene roles → `403` ("No tienes permiso para acceder a esta sección."). El menú del topbar solo
muestra esta opción cuando `is_subscription_owner = true`. Implementado en
`App\Services\Account\SubscriptionReadService` (el `history[].version` es la posición de la versión:
1 = la más antigua; `payment.folio` viene de `payment_details.folio`).

`GET /subscription`
```json
{
  "subscription": {
    "id": 15,
    "commercial_name": "Refaccionaria López",
    "business_name": "López Servicios S.A. de C.V.",
    "status": "activa",
    "tax_id": "LOMM850101HDF",
    "contact_phone": "4771234567",
    "contact_email": "contacto@negocio.com",
    "address": { "text": "Av. Hidalgo 120, León" },
    "slug": "refaccionaria-lopez"
  },
  "plan": {
    "modules": [
      { "key": "module_pos", "name": "Punto de Venta", "active": true },
      { "key": "module_services", "name": "Órdenes de Servicio", "active": true }
    ],
    "limits": [
      { "key": "limit_branches", "name": "Sucursales", "limit": 3, "used": 2 },
      { "key": "limit_users", "name": "Usuarios", "limit": 10, "used": 6 },
      { "key": "limit_products", "name": "Productos", "limit": 5000, "used": 1240 }
    ]
  },
  "usage": { "branches": 2, "users": 6, "bank_accounts": 3, "products": 1240, "cash_registers": 2, "print_templates": 4, "services": 18 },
  "status_data": { "label": "Activa", "expires_at": "2026-10-18T00:00:00.000000Z", "days_left": 30, "is_expired": false, "warning": null },
  "pending_payment": null,
  "last_rejected_payment": null,
  "fiscal_document_url": "https://.../constancia.pdf",
  "history": [
    { "version": 3, "created_at": "2026-09-18T12:00:00.000000Z", "total": "599.00", "payment": { "folio": "PAGO-004", "status": "completado", "paid_at": "2026-09-18T12:05:00.000000Z", "can_request_invoice": true } }
  ]
}
```
- `status_data.warning` alimenta el banner de suscripción por vencer/expirada
  (equivalente a `subscriptionWarning` del layout web).

`PUT /subscription` — datos generales (solo propietario)
| Campo | Regla |
|---|---|
| `commercial_name` | required, máx. 255 |
| `business_name` | nullable, máx. 255 |
| `contact_phone` | nullable, máx. 20 |
| `address` | nullable, máx. 500 (texto) |
| `operating_hours` | nullable, array de 7 con `day`, `open` (bool), `from`/`to` (`H:i`) |

`POST /subscription/documents` (multipart) → subir documentos fiscales (constancia de situación fiscal).
`POST /subscription/payments/{paymentId}/request-invoice` → solicitar factura de un pago del historial.

**Renovar o mejorar el plan:** la app **no** reimplementa el checkout de Mercado Pago. La pantalla
de suscripción muestra el botón "Renovar o mejorar plan" que **abre en el navegador externo**
`https://<dominio>/subscription/manage` (requiere la sesión web del propietario). Al volver a la app,
refrescar `GET /subscription`. Se documentará un flujo nativo (WebView con retorno) en Fase 6.

Reglas para la app:
- Ocultar la opción "Suscripción" del menú si `is_subscription_owner = false`.
- Mostrar el estado con color: `activa` (verde), por vencer (ámbar), `expirada`/`suspendida` (rojo).
- Los datos de uso/plan son **solo lectura**; los límites no se pueden exceder desde la app.

---

## 12. Catálogo de códigos de error (`error.code`)

> **Estado: ✅ implementado el manejador uniforme (Fase 0).**
> `app/Exceptions/Api/ApiExceptionRenderer.php` responde **siempre** con JSON en `/api/*`:
> `{ "message": "...", "errors": { ... } }`, en español y sin trazas. Los errores de página de
> Inertia siguen funcionando igual para las rutas web.

Los siguientes `code` son códigos **de negocio** que devuelven los endpoints a partir de la Fase 3
(en `POST /sync/push` van dentro de `error.code`; en el resto de endpoints se comunican por el
`message` en español).

> **Desde la Fase 3** los endpoints de escritura devuelven el `code` como campo extra junto al
> `message` en español (y a veces datos útiles, como `session_id` / `opened_by` en
> `cash_register_in_use`). La app debe mostrar **siempre** el `message` y usar `code` solo para
> decidir el flujo (por ejemplo, ofrecer "Unirme a esa sesión").

| code | message (español) | ¿Reintentable? |
|---|---|---|
| `session_closed` | "La sesión de caja fue cerrada. Ábrela de nuevo desde la versión web." | Sí, cuando haya sesión |
| `session_required` | "Necesitas una sesión de caja abierta para registrar ventas." | No |
| `cash_register_in_use` | "Parece que otro usuario abrió caja antes que tú. Puedes unirte a la sesión." | No (ofrecer unirse) |
| `no_cash_register_available` | "No hay terminales disponibles en esta sucursal." | No |
| `session_already_open` | "Ya tienes una sesión de caja activa." | No |
| `session_not_open` | "Esa sesión de caja ya fue cerrada." | No |
| `offline_operation_not_allowed` | "Necesitas conexión para realizar esta operación." | Sí, al volver la red |
| `insufficient_stock` | "El producto ya no tiene stock suficiente." | No |
| `credit_limit_exceeded` | "El cliente no tiene crédito disponible suficiente." | No |
| `customer_required` | "Selecciona un cliente para dejar saldo pendiente." | No |
| `already_cancelled` | "La venta ya se encuentra cancelada o reembolsada." | No |
| `invalid_status` | "El estatus enviado no es válido." | No |
| `status_unchanged` | "El estatus ya es el seleccionado." | No |
| `order_pending_balance` | "No se puede entregar una orden con saldo pendiente." | Sí, tras cobrar |
| `payment_required` | "Debe proporcionar al menos un método de pago o usar el saldo a favor." | No |
| `bank_account_required` | "Se requiere una cuenta destino para pagos con tarjeta o transferencia." | No |
| `media_too_large` | "La imagen supera el tamaño máximo permitido (2 MB)." | No |
| `duplicate_operation` | "Esta operación ya fue registrada." | No (marcar sincronizada) |
| `permission_denied` | "Tu usuario no tiene permiso para esta acción." | No |
| `branch_out_of_scope` | "El recurso no pertenece a tu sucursal." | No |
| `not_session_participant` | "No participas en esta sesión de caja." | No |
| `owner_only` | "No tienes permiso para acceder a esta sección." (solo propietario de la suscripción) | No |
| `invalid_current_password` | "La contraseña actual no es correcta." | No |
| `unsynced_operations` | "Tienes operaciones sin sincronizar. Sincronízalas antes de continuar." | Sí, tras sincronizar |

---

## 13. Pruebas rápidas (`curl`)

```bash
API=https://ezyventas2.test/api/v1

# 1. Login
TOKEN=$(curl -s -X POST "$API/auth/login" -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"email":"maria@negocio.com","password":"secreto","device_name":"Pruebas"}' \
  | jq -r .token)

# 2. Contexto y permisos
curl -s "$API/auth/me" -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" | jq

# 3. Catálogo del POS
curl -s "$API/catalog/products?search=filtro" -H "Authorization: Bearer $TOKEN" | jq '.data[0]'

# 4. Sesión de caja
curl -s "$API/cash-register-sessions/current" -H "Authorization: Bearer $TOKEN" | jq

# 4b. Abrir caja (si no hay sesión abierta)
curl -s -X POST "$API/cash-register-sessions" -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" -H "Content-Type: application/json" -d '{
    "client_uuid":"00000000-0000-4000-8000-000000000010",
    "cash_register_id":1,"user_id":1,"opening_cash_balance":1500,
    "bank_accounts":[]}' | jq '.active_session.id'

# 5. Venta de contado
curl -s -X POST "$API/pos/checkout" -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" -H "Content-Type: application/json" -d '{
    "client_uuid":"00000000-0000-4000-8000-000000000001",
    "cash_register_session_id":1,"customerId":null,"guest_name":null,
    "cartItems":[{"id":1,"product_attribute_id":null,"quantity":1,"unit_price":100,
      "description":"Producto de prueba","discount":0,"discount_reason":null}],
    "subtotal":100,"total_discount":0,"total":100,
    "payments":[{"amount":100,"method":"efectivo","bank_account_id":null,"notes":null}],
    "use_balance":false,"layaway_expiration_date":null}' | jq '.transaction.folio'

# 6. Detalle de venta
curl -s "$API/transactions?per_page=1" -H "Authorization: Bearer $TOKEN" | jq '.data[0].folio'

# 7. Órdenes de servicio
curl -s "$API/service-orders?status=en_progreso" -H "Authorization: Bearer $TOKEN" | jq '.data[0]'

# 8. Ticket para WhatsApp
curl -s -X POST "$API/print/whatsapp-ticket" -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"data_source_type":"transaction","data_source_id":1}' | jq

# 9. Payload ESC/POS de una venta
curl -s -X POST "$API/print/bluetooth-payload" -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"template_id":1,"data_source_type":"transaction","data_source_id":1,"open_drawer":false}' | jq

# 10. Corte de caja (resumen) y cierre
curl -s "$API/cash-register-sessions/1/summary" -H "Authorization: Bearer $TOKEN" | jq '.cash'
curl -s -X PUT "$API/cash-register-sessions/1" -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"closing_cash_balance":5040,"notes":"Faltante por cambio mal dado."}' | jq '.session.cash_difference'

# 11. Cambio de sucursal
curl -s -X PUT "$API/branch/switch/2" -H "Authorization: Bearer $TOKEN" | jq '.branch'

# 12. Cuenta, notificaciones y soporte
curl -s "$API/profile" -H "Authorization: Bearer $TOKEN" | jq '.user'
curl -s "$API/notifications" -H "Authorization: Bearer $TOKEN" | jq
curl -s "$API/support" -H "Authorization: Bearer $TOKEN" | jq '.channels'
curl -s "$API/subscription" -H "Authorization: Bearer $TOKEN" | jq '.status_data'
```

Verificación del lado del servidor:
```bash
php artisan route:list --path=api
```

---

## 14. Control de cambios de este contrato

| Fecha | Cambio | Fase |
|---|---|---|
| 2026-09-18 | Versión inicial: auth, catálogo, clientes, caja, POS, ventas, órdenes, impresión/WhatsApp, sync. | 0–5 |
| 2026-09-18 | **Cambio de alcance:** la app ahora puede **abrir caja** (`POST /cash-register-sessions`) y unirse/salir de una sesión; se agrega `bank_accounts` a `GET /cash-register-sessions/current` y los códigos `cash_register_in_use`, `no_cash_register_available`, `session_already_open`, `session_not_open`, `offline_operation_not_allowed`. *(El cierre de caja aún no estaba en alcance; ver el cambio siguiente.)* | 3 |
| 2026-09-18 | **Cambio de alcance (2):** se agrega el **corte/cierre de caja** desde la app (`GET /cash-register-sessions/{id}/summary` y `PUT /cash-register-sessions/{id}`), y el bloque **§11b**: cambio de sucursal, notificaciones, soporte, perfil y suscripción (equivalente a la barra superior web). Nuevos códigos `not_session_participant`, `owner_only`, `invalid_current_password`, `unsynced_operations`. | 4 |
| 2026-09-18 | **Fase 0 implementada:** `/api/v1` con `POST /auth/login` (throttle 5/min), `GET /auth/me`, `POST /auth/logout`; middleware `EnsureApiSubscriptionIsActive`; respuestas JSON uniformes en `/api/*` (`ApiExceptionRenderer`); limitadores `api` (60/min) y `api-login`. Tests: `tests/Feature/Api/V1/AuthTest.php`. | 0 ✅ |
| 2026-09-18 | **Fase 1 implementada:** `GET /catalog/products`, `GET /catalog/products/{id}`, `GET /catalog/categories` (con `type`), `GET /catalog/services`, `GET /customers`, `GET /customers/{id}`, `POST /customers`, `GET /cash-register-sessions/current` (con `totals`) y `GET /bank-accounts`. La lógica de catálogo, promociones, variantes y stock se extrajo de `PointOfSaleController` a `App\Services\Catalog\ProductCatalogService` (una sola fuente para web y app). Aclaraciones de forma: `selling_price` es **string decimal**, `variant_combinations[]` incluye `price` ya calculado, `components` queda normalizado, `per_page` acepta máx. 100 y `POST /customers` responde `201` con el formato de listado. Tests: `tests/Feature/Api/V1/CatalogTest.php`, `CustomerApiTest.php`, `CashRegisterSessionApiTest.php`, `BankAccountApiTest.php`. | 1 ✅ |
| 2026-09-18 | **Fase 2 implementada:** `GET /transactions`, `GET /transactions/{id}`, `GET /service-orders`, `GET /service-orders/{id}`, `PATCH /service-orders/{id}/status` (reutiliza `ChangeServiceOrderStatusAction`) y `POST /service-orders/{id}/diagnosis` (multipart con evidencias). Lógica nueva en `App\Services\Transactions\TransactionReadService`, `App\Services\ServiceOrders\ServiceOrderReadService` y `App\Actions\ServiceOrders\SaveServiceOrderDiagnosisAction` (esta última también la usa la web, así que el diagnóstico se guarda igual desde ambos clientes). Aclaraciones: `items_count` = líneas de la venta; `invoice` solo `{id, folio, status}`; `thumb_url` cae al original si no hay conversión; `activities` = últimos 20 por id desc; el diagnóstico **no** se borra si no se envía; el estatus inválido o repetido responde `422` con `errors.status[0]`. Cobertura: `tests/Feature/Api/V1/TransactionApiTest.php` y `ServiceOrderApiTest.php`. | 2 ✅ |
| 2026-09-18 | **Fase 3 implementada (escrituras):** abrir caja (`POST /cash-register-sessions`) y unirse (`/join`), cobro (`POST /pos/checkout`), apartado (`/pos/layaway`), pedido (`/pos/store-order`), abonos (`POST /transactions/{id}/payments`), cancelación y reembolso (`/cancel`, `/refund`), alta y edición de órdenes de servicio (`POST`/`PUT /service-orders`), reparación de órdenes antiguas (`/ensure-transaction`) y anticipos (`POST /service-orders/{id}/payments`). Para no duplicar lógica se extrajeron tres piezas que ahora **comparten web y app**: `CashRegisterSessionOpenService`, `TransactionCancellationService` y `CreateStoreOrderAction` (además de reutilizar `TransactionPaymentService`, `CreateServiceOrderAction`, `UpdateServiceOrderAction` y `EnsureServiceOrderTransactionAction`). Nuevos códigos de negocio devueltos en `code`: `session_required`, `customer_required`, `credit_limit_exceeded`, `already_cancelled`, `cash_register_in_use`, `session_already_open`, `session_not_open`, `no_cash_register_available`. Correcciones: el reembolso por transferencia no guardaba `payment_date` y fallaba (web incluida); los abonos ahora aceptan `payments: []` cuando `use_balance = true`; el detalle de la venta incluye `phone` del cliente para el ticket de WhatsApp. Cobertura: `PosApiTest`, `TransactionWriteApiTest`, `ServiceOrderWriteApiTest` y los casos nuevos de `CashRegisterSessionApiTest` (93 tests verdes en la carpeta de la API). | 3 ✅ |
| 2026-09-18 | **Fase 4 implementada (corte de caja, impresión, edición de pagos y cuenta):** `GET /cash-register-sessions/{id}/summary`, `PUT /cash-register-sessions/{id}` (corte), `POST .../leave`, `POST /cash-register-sessions/rejoin-or-start`, `GET /print/templates`, `POST /print/bluetooth-payload`, `POST /print/payload`, `POST /print/ticket-html`, `POST /print/whatsapp-ticket`, `PUT|DELETE /transactions/{id}/payments/{paymentId}`, `DELETE /service-orders/{id}`, `PUT /branch/switch/{branch}`, `GET /notifications`, `GET /support`, `GET|PUT /profile`, `DELETE /profile/photo`, `PUT /profile/password`, `POST /profile/logout-other-devices`, `GET|PUT /subscription`, `POST /subscription/documents` y `POST /subscription/payments/{id}/request-invoice`. Piezas compartidas nuevas (las usa también la web): `CashRegisterSessionLifecycleService` (antes `…OpenService`: open/join/leave/rejoinOrStart/close + broadcast `SessionClosed`), `TransactionPaymentEditService`, `DeleteServiceOrderAction`, `PrintDataSourceResolver`, `WhatsAppTicketService::buildSalePayload()` y `config/support.php`. `GET /auth/me` ahora incluye `available_branches`. Correcciones: la edición de un pago ya no descuadra la cuenta bancaria al cambiar de método, y al quitar un pago la venta vuelve a `apartado` (no a `pendiente`) si tenía fecha límite. Códigos nuevos: `not_session_participant`, `branch_out_of_scope`, `invalid_current_password`, `payment_not_approved`. Cobertura: `CashRegisterCloseApiTest` (11), `PrintingApiTest` (9), `AccountApiTest` (12) y los casos añadidos a `TransactionWriteApiTest` y `ServiceOrderWriteApiTest` (132 tests verdes en la carpeta de la API). | 4 ✅ |
| — | Se documentarán `exchange`, `extend-layaway`, `reschedule-order`, 2FA, reportes y el pago de suscripción dentro de la app. | 6+ |

> Cuando se implemente un endpoint, **no** se cambia su forma: si hace falta algo distinto, se
> agrega un campo nuevo y se anota aquí. La app debe poder seguir funcionando si el servidor
> agrega campos (tolerancia a campos extra), pero **no** si desaparecen los existentes.