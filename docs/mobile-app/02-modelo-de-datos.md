# 02 — Modelo de datos usado por la app móvil

> Fuente: `database/migrations/*` y los modelos en `app/Models`.
> Los valores de los enums están **en español** (son datos); los nombres de tablas,
> columnas y claves JSON están **en inglés**.

---

## 1. Convenciones

| Aspecto | Regla |
|---|---|
| Llaves primarias | `id` bigint autoincremental |
| Llaves foráneas | `*_id` (ej. `customer_id`, `branch_id`) |
| Fechas | `*_at` con `timestamp`; `*_date` con `date` |
| Dinero | `decimal(10,2)` — **llegan como string en JSON** (`"120.00"`), convertir con `double.parse` |
| Cantidades | `decimal(10,3)` en ventas, `decimal(10,2)` en órdenes |
| JSON | `address`, `contact_info`, `custom_fields`, `price_tiers`, `attributes`, `opening_bank_balances` |
| Borrado | Físico (no hay `deleted_at`) → el sync necesita la tabla `sync_tombstones` |
| Timestamps | `created_at`, `updated_at` en todas las tablas |

---

## 2. Tenencia y contexto (multi-tenant)

```
subscriptions (negocio/tenant)
   └── branches (sucursales)
          ├── users (branch_id = sucursal del usuario)
          ├── customers (branch_id)
          ├── cash_registers → cash_register_sessions
          ├── products (vía branch_product) / services (vía branch_service)
          ├── transactions (branch_id)
          └── service_orders (branch_id)
```

### `users`
`id`, `name`, `email`, `password`, `phone`, `is_active`, `branch_id`, `email_verified_at`,
`google_id`, `profile_photo_path`, campos 2FA de Fortify.

- La app **solo** necesita: `id`, `name`, `email`, `phone`, `branch_id`, `profile_photo_url`.
- `branch_id` define la sucursal activa → **todo** se filtra por ella en el servidor.

### `branches`
`id`, `subscription_id`, `name`, `is_main`, `manager_id`, `address` (json),
`contact_phone`, `contact_email`, `timezone`, `operating_hours` (json).

### `subscriptions`
`id`, `business_name`, `commercial_name`, `business_type_id`, `status`
(`activa` | `expirada` | `suspendida`), `slug`, `tax_id`, `address` (json),
`contact_phone`, `contact_email`.

- Los **módulos habilitados** se derivan de la suscripción (`getActiveModuleKeys()` /
  `getAvailableModuleNames()`), y los permisos del usuario se filtran por módulo.

### `personal_access_tokens` (Sanctum — tokens de la app móvil)
Estructura propia del proyecto (creada en `2026_08_22_000001_create_core_platform_tables.php`):
`id`, `tokenable_type`, `tokenable_id`, `name` (texto: se usa el nombre del dispositivo),
`token` (string 64, único), `abilities` (texto/JSON), `last_used_at`, `expires_at` (con índice),
`created_at`, `updated_at`.

- **No requiere migración nueva:** la tabla ya existe en producción.
- El login móvil guarda el `name` como nombre legible del dispositivo ("Pixel 7 · María") y puede
  usar `abilities` con el valor `mobile`.
- Para "Cerrar otras sesiones" se borran todos los tokens del usuario **excepto el actual**.
- `expires_at` permite caducar tokens a futuro sin cambiar el código de la app.

---

## 3. Catálogo usado por el POS

### `categories`
`id`, `name`, `type` (`product` | `service` | `both`), `business_type`, `subscription_id`.

### `products`
| Columna | Tipo | Notas |
|---|---|---|
| `name`, `description`, `sku` | string/text | `sku` es buscable desde el POS |
| `selling_price` | decimal(10,2) | precio base de venta |
| `price_tiers` | json | precios por volumen: `[{"min_quantity": 6, "price": 95}]` |
| `cost_price` | decimal(10,2) | costo (solo si el usuario puede ver financieros) |
| `category_id`, `brand_id`, `provider_id`, `global_product_id` | FK | |
| `measure_unit` | string | `pz`, `kg`, `lt`… |
| `is_bulk` | boolean | venta a granel (permite cantidades decimales) |
| `show_in_pos` | boolean | **solo los `true` aparecen en el POS** |
| `show_online`, `online_price`, `slug`, `is_featured`, `is_on_sale`, `sale_price`, `sale_start_date`, `sale_end_date`, `weight`, `length`, `width`, `height`, `requires_shipping`, `view_count`, `purchase_count`, `tags`, `delivery_days`, `currency` | — | módulo de tienda en línea: **la app móvil no los usa** |

### `product_attributes` (variantes)
`id`, `product_id`, `attributes` (json, ej. `{"Talla": "M", "Color": "Azul"}`),
`selling_price_modifier` (decimal, se **suma** a `products.selling_price`), `sku_suffix`,
`global_product_id`.

### `branch_product` (stock por sucursal — producto simple)
`branch_id`, `product_id`, `current_stock` (decimal 10,3), `reserved_stock`,
`min_stock`, `max_stock`, `location`. Único por (`branch_id`, `product_id`).

### `branch_product_attribute` (stock por sucursal — variante)
Igual estructura pero con `product_attribute_id`.

> **Stock disponible = `current_stock - reserved_stock`** (nunca negativo: `max(0, …)`).
> El stock del producto padre se mantiene **sumado** desde sus variantes
> (`sync_parent_stock_with_variants`), pero para mostrar se usan los pivotes de la sucursal.

### `services` y `service_variants`
- `services`: `id`, `branch_id`, `category_id`, `name`, `description`, `slug`,
  `base_price` (decimal), `duration_estimate`, `sat_product_code`, `sat_unit_code`,
  `show_online`.
- `service_variants`: `id`, `service_id`, `name`, `price` (decimal), `duration_estimate`.
- `branch_service`: `branch_id`, `service_id` (pivote de disponibilidad por sucursal).

### `product_components` (kits/combos)
`composite_product_id`, `componentable_type`, `componentable_id`, `quantity`.
Al vender un kit, el stock se descuenta de sus componentes, no del kit.

---

## 4. Clientes

### `customers`
`id`, `branch_id`, `name`, `company_name`, `email` (único), `phone`, `address` (json),
`fiscal_address` (json), `tax_id`, `tax_regime`, `balance` (decimal 10,2),
`credit_limit` (decimal 10,2).

**Convención de signo del saldo (crítico):**
- `balance < 0` → el cliente **debe** (crédito usado).
- `balance > 0` → el cliente tiene **saldo a favor**.
- `available_credit` (accessor, no existe en BD):
  `balance < 0 ? credit_limit + balance : credit_limit`.
- Una venta a crédito **no puede** exceder `available_credit`.

### `customer_balance_movements`
`id`, `customer_id`, `transaction_id` (nullable), `type` (string, ver enum),
`amount` (decimal, negativo = deuda), `balance_after`, `notes`, timestamps.

Sirve para el estado de cuenta del cliente en la app (histórico de movimientos).

---

## 5. Caja (apertura, unirse, corte y lectura)

| Tabla | Columnas clave |
|---|---|
| `cash_registers` | `id`, `branch_id`, `name`, `is_active`, `in_use` |
| `cash_register_sessions` | `id`, `cash_register_id`, `user_id` (quien abrió), `opened_at`, `closed_at`, `status` (`abierta`/`cerrada`), `opening_cash_balance`, `opening_bank_balances` (json), `closing_cash_balance`, `closing_bank_balances` (json), `calculated_cash_total`, `cash_difference`, `notes` |
| `cash_register_session_user` | pivote: qué usuarios participan en la sesión |
| `session_cash_movements` | `cash_register_session_id`, `user_id`, `type` (`ingreso`/`egreso`), `amount`, `description` |
| `bank_accounts` | `id`, `subscription_id`, `bank_name`, `owner_name`, `account_name`, `account_number`, `card_number`, `clabe`, `balance` |

### Cómo se abre una caja (flujo real del servidor)
1. Se elige una terminal con `is_active = true` e `in_use = false`. Si `in_use = true`, la
   apertura se rechaza (`cash_register_in_use`) porque otro usuario abrió primero.
2. Se envía `opening_cash_balance` (efectivo físico) y `bank_accounts[]` con los saldos declarados.
3. Los saldos declarados **se escriben** en `bank_accounts.balance`.
4. Se construye el snapshot `opening_bank_balances` con **todas** las cuentas de la sucursal:
   - las declaradas usan el saldo enviado;
   - las no declaradas heredan el `balance` del último corte cerrado de esa caja
     (`closing_bank_balances`) y, si no hay, el saldo actual de la cuenta.
5. Se crea la sesión con `status = abierta`, `opened_at = now()` y `user_id` = quien abrió.
6. Se agrega al usuario al pivote `cash_register_session_user` y se marca `in_use = true`.

### Reglas para la app
1. **El POS móvil exige una sesión con `status = 'abierta'`** en la sucursal del usuario.
2. Si el usuario no participa en ninguna, puede **abrir caja**
   (`POST /cash-register-sessions`) o **unirse** a una sesión abierta
   (`POST /cash-register-sessions/{id}/join`).
3. La app **puede cerrar** la caja (corte con arqueo) e igualmente **no** registra movimientos de
   efectivo (`session_cash_movements` se sigue creando desde la web).
4. `bank_account_id` es **obligatorio** en pagos con `tarjeta` o `transferencia`.
5. Abrir caja **exige conexión**: sin red no se puede inventar una sesión.
6. Al abrir caja, el `opening_bank_balances` resultante debe guardarse en la caché local para
   mostrarlo durante el turno sin nuevas peticiones.

---

### Cómo se cierra una caja (corte)
1. Solo un usuario que participe en la sesión puede cerrarla.
2. Se envía `closing_cash_balance` (efectivo físico contado) y, opcionalmente, `notes`.
3. El servidor calcula:
   - `calculated_cash_total = opening_cash_balance + efectivo completado + ingresos − egresos`
     (los movimientos de caja son `session_cash_movements` con tipo `ingreso`/`egreso`);
   - `cash_difference = closing_cash_balance − calculated_cash_total`.
4. **Concilia los bancos:** por cada cuenta calcula
   `final_balance = initial_balance + recibido − gastado + transferencias_entrantes − transferencias_salientes`,
   lo guarda en `closing_bank_balances` y **lo escribe** en `bank_accounts.balance`.
5. Marca la sesión `cerrada` con `closed_at`, guarda `calculated_cash_total`, `cash_difference` y
   las notas, y libera la terminal (`cash_registers.in_use = false`).
6. Emite el evento `App\Events\SessionClosed` en el canal `cash-register-session.{id}`.

> El **siguiente** turno arranca heredando justo ese `closing_cash_balance` y esos
> `closing_bank_balances`, por eso el corte es la fuente de verdad del efectivo y de los bancos.

## 6. Ventas (POS)

### `transactions`
| Columna | Tipo | Notas |
|---|---|---|
| `folio` | string | `V-001`, `ABONO-003` (consecutivo por sucursal) |
| `customer_id` | FK nullable | `null` = público en general |
| `contact_info` | json | `{name, phone, type}` — en pedidos/comandas |
| `branch_id`, `user_id` | FK | |
| `cash_register_session_id` | FK nullable | sesión donde se cobró |
| `transactionable_type` / `transactionable_id` | morph | origen: orden de servicio, tienda, cotización |
| `status` | string | ver enum de estatus |
| `delivery_status` | string nullable | logística de la tienda en línea |
| `channel` | string | `punto_de_venta`, `tienda_en_linea`, `orden_de_servicio`, `cotizacion`, `manual`, `abono_a_saldo`, `whatsapp` |
| `subtotal` | decimal | Σ(`unit_price` × `quantity`) sin descuentos |
| `total_discount` | decimal | Σ descuentos (línea + promociones) |
| `total_tax` | decimal | **siempre 0** en POS (no hay motor de impuestos) |
| `shipping_cost` | decimal | costo de envío en pedidos |
| `currency` | string(3) | `MXN` |
| `notes` | text | |
| `status_changed_at` | timestamp | |
| `invoiced` | boolean | si tiene CFDI |
| `layaway_expiration_date` | date nullable | límite para liquidar un apartado |
| `delivery_date` | datetime nullable | **si tiene valor, la venta es un PEDIDO** |
| `shipping_address` | text | dirección de entrega |

**Campos calculados (accessors, no existen en BD):**
- `total = (subtotal - total_discount) + total_tax + shipping_cost`
- `total_paid = Σ payments.amount`
- `remaining_due = max(0, total - total_paid)`
- `isOrder()` = `delivery_date != null` o estatus logístico.

### `transactions_items` ⚠️ (así se llama la tabla, en plural irregular)
`id`, `transaction_id`, `itemable_type`, `itemable_id`, `description` (texto congelado al
momento de la venta), `quantity` (decimal 10,3), `unit_price` (decimal 10,2),
`discount_amount` (por unidad), `discount_reason`
(`Promoción de producto`, `Precio de mayoreo`, `Descuento manual`, `Aumento manual`),
`tax_amount`, `line_total`.

### `payments`
`id`, `transaction_id`, `cash_register_session_id` (nullable), `bank_account_id` (nullable),
`amount` (decimal 10,2), `payment_method` (`efectivo` | `tarjeta` | `transferencia` | `saldo` | `intercambio`),
`status` (`procesando` | `completado` | `fallido`), `payment_date` (timestamp), `notes`.

**Reglas de cobro (implementadas en `TransactionPaymentService`):**
1. Si los pagos exceden el total, el servidor los **recorta** al total (`capPaymentsToAmount`).
   El **cambio** se calcula en el cliente y no se guarda como pago.
2. `use_balance = true` con cliente con saldo a favor: el saldo se aplica como pago
   (movimiento `uso_de_credito`) hasta el monto del total.
3. Si tras los pagos queda `remaining_due > 0`:
   - con cliente → la venta pasa a `pendiente` (crédito) y se genera deuda
     (`venta_a_credito`) en `customer_balance_movements`;
   - sin cliente → **no se permite** dejar saldo pendiente.
4. Apartado → estatus `apartado`, stock **reservado** (`reserved_stock`), deuda
   `deuda_por_apartado`.
5. Pedido → estatus `por_entregar`, stock reservado.

### `promotion_transaction`
`promotion_id`, `transaction_id`, `discount_applied`. Registra qué promociones de
carrito se aplicaron a la venta.

---

## 7. Órdenes de servicio

### `service_orders`
| Columna | Tipo | Notas |
|---|---|---|
| `folio` | string nullable | `OS-001`, `OS-002`… generado por `ServiceOrder::generateFolio(branchId)` |
| `branch_id`, `user_id` | FK | `user_id` = quien la registró |
| `quote_id`, `customer_id` | FK nullable | puede venir de una cotización |
| `customer_name` | string | **obligatorio** (se copia del cliente o se captura) |
| `customer_email`, `customer_phone` | string nullable | |
| `customer_address` | json nullable | `{street, city}` |
| `itemable_type` / `itemable_id` | morph | el aparato/equipo que se va a reparar |
| `item_description` | string | ej. "iPhone 13, pantalla rota" |
| `reported_problems` | text | fallas reportadas por el cliente |
| `technician_diagnosis` | text nullable | se guarda al avanzar el trabajo |
| `technician_name` | string nullable | |
| `technician_commission_type` | string nullable | `percentage` \| `fixed` |
| `technician_commission_value` | decimal nullable | |
| `status` | string | ver enum de órdenes |
| `received_at` | timestamp | fecha de recepción |
| `promised_at` | timestamp nullable | fecha prometida al cliente |
| `subtotal` | decimal | Σ líneas |
| `discount_type` | string nullable | `fixed` \| `percentage` |
| `discount_value` | decimal nullable | valor capturado |
| `discount_amount` | decimal default 0 | descuento en pesos |
| `final_total` | decimal nullable | `subtotal - discount_amount` |
| `custom_fields` | json nullable | campos personalizados (`key => value`) |

### `service_order_items`
`id`, `service_order_id`, `itemable_type`, `itemable_id`, `description`,
`quantity` (decimal 10,2), `unit_price`, `line_total`.

- Ítems de **mano de obra** → `itemable` = `App\Models\Service` o `App\Models\ServiceVariant`.
- Ítems de **refacciones** → `itemable` = `App\Models\Product` o `App\Models\ProductAttribute`
  (**sí descuentan stock** al crear/actualizar la orden).
- El `unit_price` de los ítems Product/ProductAttribute es el costo de refacciones, y alimenta
  el cálculo de utilidad y comisión del técnico.

### Evidencias fotográficas (Spatie Media Library)
Colecciones sobre la orden:
- `initial-service-order-evidence` — estado del equipo al recibirlo.
- `closing-service-order-evidence` — resultado final.

La app sube archivos como **multipart** (`initial_evidence_images[]`,
`closing_evidence_images[]`, máx. 5, tipo `image`, ≤ 2048 KB) y recibe las URLs completas.

### `custom_field_definitions`
`id`, `subscription_id`, `module` (`service_orders`), `name` (etiqueta visible),
`key` (clave dentro de `custom_fields`), `type` (`text` | `number` | `boolean` | `textarea`),
`options` (json), `is_required`. Único por (`subscription_id`, `module`, `key`).

La app debe **renderizar dinámicamente** el formulario de `custom_fields` a partir de estas
definiciones: `text`/`textarea` → campo de texto, `number` → numérico, `boolean` → switch.

### `activity_log` (Spatie Activitylog)
`log_name`, `description`, `subject_type`, `subject_id`, `causer_type`, `causer_id`,
`properties` (json), `event`, `created_at`. La app solo **lee** el historial de la orden.

---

## 8. Plantillas de impresión

### `print_templates`
`id`, `subscription_id`, `name`, `type` (`ticket_venta` | `etiqueta` | `cotizacion` | `recibo_servicio`),
`context_type` (`pos` | `transaction` | `product` | `service_order` | `quote` | `customer` | `general`),
`content` (json: estructura de operaciones ESC/POS + `config.paperWidth` 58/80 mm y `feedLines`),
`is_default`.

### `branch_print_template` (pivote)
`branch_id`, `print_template_id`. Solo las plantillas de la sucursal del usuario son válidas.

---

## 9. Tipos polimórficos de `itemable` / `transactionable`

| Valor de `*_type` en la BD | Modelo | Qué es |
|---|---|---|
| `App\Models\Product` | Product | producto simple |
| `App\Models\ProductAttribute` | ProductAttribute | variante (talla/color) |
| `App\Models\Service` | Service | servicio (mano de obra) |
| `App\Models\ServiceVariant` | ServiceVariant | variante de servicio |
| `App\Models\ServiceOrder` | ServiceOrder | origen de una `transaction` vinculada |
| `App\Models\Order` | Order | pedido de la tienda en línea |

En la app basta con **mostrar `description`** congelado en el ítem; usa el tipo solo cuando
necesite distinguir refacción vs. mano de obra (ej. utilidad del técnico).

---

## 10. Enums completos (valores tal como viajan en JSON)

| Enum | Valores |
|---|---|
| `TransactionStatus` | `completado`, `pendiente`, `cancelado`, `reembolsado`, `apartado`, `cambiado`, `por_entregar`, `en_ruta`, `entregado_por_pagar` |
| `TransactionChannel` | `punto_de_venta`, `tienda_en_linea`, `orden_de_servicio`, `cotizacion`, `manual`, `abono_a_saldo`, `whatsapp` |
| `PaymentMethod` | `efectivo`, `tarjeta`, `transferencia`, `saldo`, `intercambio` |
| `PaymentStatus` | `procesando`, `completado`, `fallido` |
| `ServiceOrderStatus` | `pendiente`, `en_progreso`, `esperando_refaccion`, `terminado`, `entregado`, `cancelado` |
| `CashRegisterSessionStatus` | `abierta`, `cerrada` |
| `CustomerBalanceMovementType` | `venta_a_credito`, `abono`, `compra_de_credito`, `deuda_por_apartado`, `uso_de_credito`, `devolucion_a_balance`, `ajuste_manual`, `credito_por_cancelacion`, `credito_por_reembolso` |
| `TemplateType` | `ticket_venta`, `etiqueta`, `cotizacion`, `recibo_servicio` |
| `TemplateContextType` | `pos`, `transaction`, `product`, `service_order`, `quote`, `customer`, `general` |

---

## 11. Notas de integridad que la app debe respetar

1. **Folios:** se generan en el servidor al crear la venta (`V-###`) o la orden (`OS-###`).
   Una venta creada sin conexión usa un folio temporal `TMP-XXXXXX` que se reemplaza al
   sincronizar; **nunca** se calcula el consecutivo en el dispositivo.
2. **Stock:** la app recibe el stock disponible y lo usa como referencia visual; el descuento
   real ocurre en el servidor al confirmar la operación.
3. **Sesión de caja:** sin sesión `abierta` no hay venta; el `cash_register_session_id` se toma
   de `GET /cash-register-sessions/current` y nunca se inventa.
4. **Cancelaciones:** cancelar devuelve stock y revierte deudas/saldos. Una venta `apartado` o
   `por_entregar` libera la **reserva**; una venta normal hace **restock**. Una venta en
   `cancelado` o `reembolsado` no admite cambios ni pagos.
5. **Reembolsos:** requieren método (`cash` | `balance` | `transfer`); `cash` exige sesión de
   caja abierta y `balance` exige cliente asignado.
6. **Órdenes de servicio:** al pasar a `entregado` con saldo pendiente, la app debe ofrecer
   registrar el pago (la web dispara `requirePayment` en ese caso).
7. **Borrado de órdenes:** eliminar una orden elimina también su `transaction` vinculada.
8. **Aislamiento:** todo registro pertenece a una sucursal de la suscripción del usuario.
   La app **nunca** envía `branch_id` ni `subscription_id`.
9. **Apertura de caja:** el saldo inicial de las cuentas bancarias **no declaradas** se hereda del
   último corte cerrado de esa caja (`closing_bank_balances`); por eso el `opening_bank_balances`
   de una sesión nueva puede contener montos que el cajero no capturó.
10. **Cierre de caja:** al cerrar (desde la app o la web) se escriben los `closing_bank_balances` en
    las cuentas, se calcula `calculated_cash_total` y `cash_difference`, y la terminal vuelve a
    `in_use = false`. El cierre lo puede hacer cualquier usuario que participe en la sesión.
11. **Sucursal activa:** es `users.branch_id`; cambiarla afecta a todos los dispositivos de ese
    usuario (no existe una sucursal por dispositivo).

---

## 12. Cuenta, sucursal, suscripción y soporte

### 12.1 Preferencias, sesiones y dispositivos
| Tabla | Uso en móvil |
|---|---|
| `users` | `phone`, `is_active`, `branch_id`, `profile_photo_path`, `google_id`, campos 2FA de Fortify |
| `personal_access_tokens` | Un registro por dispositivo con token activo (ver §2) |
| `setting_definitions` + `setting_values` | Configuración dinámica; `setting_values` es **polimórfico** (`settable_type`/`settable_id`) sobre `User`, `Branch` o `Subscription`. `User::getPreferences()` devuelve el mapa `key => value` (ej. `default_table_click_action`) |
| `sessions` | Sesiones web de Laravel; la app móvil **no** las usa |

### 12.2 Cambio de sucursal
- La sucursal activa es simplemente **`users.branch_id`**: cambiarla afecta a **todos** los
  dispositivos y a la web del mismo usuario (no hay "sucursal por dispositivo").
- Validación del servidor: la sucursal destino debe pertenecer a la **misma suscripción**
  (excepción: `user.id = 1`).
- `branches`: `id`, `subscription_id`, `name`, `is_main`, `manager_id`, `address` (json),
  `contact_phone`, `contact_email`, `timezone`, `operating_hours` (json).
- Permiso: `system.branches.switch`.

### 12.3 Suscripción (vista y datos generales)
| Tabla | Columnas clave |
|---|---|
| `subscriptions` | `id`, `business_name`, `commercial_name`, `business_type_id`, `status` (`activa`/`expirada`/`suspendida`), `slug`, `tax_id`, `address` (json), `contact_phone`, `contact_email`, `onboarding_completed_at` |
| `subscription_versions` | `id`, `subscription_id`, `start_date`, `end_date` — una por renovación/mejora; la vigente la devuelve `Subscription::currentVersion()` |
| `subscription_items` | `id`, `subscription_version_id`, `item_key` (`base_plan`, `module_pos`, `limit_users`…), `item_type` (`module` \| `user_limit`), `name`, `quantity`, `unit_price`, `billing_period` |
| `subscription_payments` | `id`, `subscription_version_id`, `amount`, `referral_discount_pct`, `referral_discount_amount`, `invoice_status`, `payment_method`, `status`, `payment_details` (json), `invoiced` |
| `plan_items` | Catálogo de módulos y límites (`key`, `name`, `type`) — fuente de `PlanItemSeeder` |
| `media` | Colección `fiscal-documents` de la suscripción (`getFirstMediaUrl('fiscal-documents')`) |

Cómo se derivan los datos de la vista:
- **Módulos activos:** `subscription_items.item_key` con `item_type = module` de la versión vigente
  (`Subscription::getActiveModuleKeys()` → `module_pos`, `module_services`, `module_transactions`…).
- **Nombres legibles:** `plan_items.name` para esas claves (`getAvailableModuleNames()`).
- **Límites y consumo:** ítems `user_limit` + `withCount` de sucursales, usuarios, productos,
  cajas, plantillas y servicios.

### 12.4 Soporte y centro de ayuda
| Tabla | Columnas clave |
|---|---|
| `help_categories` | `id`, `name`, `parent_id` (jerárquico) |
| `help_articles` | `id`, `help_category_id`, `title`, `slug`, `type`, `content`, `youtube_id`, `views`, `status` (`draft`/`published`) |
| `support_tickets` | `id`, `user_id`, `ticket_number`, `subject`, `status`, `category` |
| `ticket_responses` | `id`, `support_ticket_id`, `user_id`, `message`, `is_internal_note` |

> Hoy **ni la web ni la app crean tickets**: el modal de soporte solo muestra canales de contacto
> (correo y WhatsApp) y el Centro de ayuda es informativo. Estas tablas quedan listas para una
> bandeja de soporte en la app (Fase 6). El contenido de `GET /support` sale de
> `config/support.php` para poder cambiarlo sin publicar una nueva versión.

### 12.5 Notificaciones
No hay tabla propia: los contadores de `GET /notifications` se calculan en
`User::getGlobalNotifications()` a partir de:
- `transactions` (`apartado`/`pendiente` con `layaway_expiration_date` ≤ 3 días; `por_entregar` con
  `delivery_date` ≤ 3 días),
- `release_notes` + `release_note_user` (novedades sin leer),
- `orders` (pedidos de la tienda en línea pendientes o en revisión).