# 00 — Descubrimiento: "Modo Caja Inteligente" en Punto de venta

> **Fase 0 (solo lectura).** Este documento no cambia código: describe el repositorio tal como está hoy,
> con evidencia `archivo:línea`, y termina con las decisiones que necesito de ti antes de la Fase 1.
>
> Repositorio: `Ezyventas2` · Fecha de la investigación: 2026-11-27 · Rama actual: `Miguel` (limpia)
> Informes de apoyo con el detalle completo: [`_research/01-backend-pos.md`](_research/01-backend-pos.md),
> [`_research/02-frontend-pos.md`](_research/02-frontend-pos.md), [`_research/03-plataforma.md`](_research/03-plataforma.md).

---

## 0. Resumen ejecutivo

| # | Pregunta | Respuesta verificada | Consecuencia para el proyecto |
|---|---|---|---|
| A | ¿Existe un punto de entrada **backend** para agregar **una** partida al carrito? | **No.** El carrito es 100 % frontend Vue; el backend solo lo conoce en el POST de cobro. | La detección por cámara y la báscula deben llamar a la función Vue `addToCart`, no a un endpoint. |
| B | ¿Cuál es la acción que agrega al carrito? | `addToCart(data)` en `resources/js/Pages/POS/Index.vue:133` y **duplicada** en `IndexMobile.vue:135`. | Es reutilizable por **evento** (`emit('addToCart', …)`), no por import. |
| C | ¿Esa acción acepta cantidad? | **No**: `quantityToAdd = 1` está fijo (`Index.vue:138`). | Hay que extenderla (cambio aditivo) para poder agregar 0.750 kg. |
| D | ¿Existe ya el concepto de producto a granel/unidad de medida? | **Sí**: `products.is_bulk` (bool) y `products.measure_unit` (texto). | **No hace falta columna nueva** para marcar pesables. |
| E | ¿Se puede cobrar hoy una cantidad fraccionaria? | **No en el POS web**: `cartItems.*.quantity => min:1` (`PointOfSaleController.php:229`), y el cast `decimal:2` redondea al leer. | Es el **bloqueante técnico nº 1** de la báscula. Necesito tu decisión (§16, D1). |
| F | ¿Multi-tenant por base o por columna? | **Por columna**, base única. `Subscription` → `branches` → `users`; `branch_id` en lo operativo, `subscription_id` en catálogo. | Modelos nuevos: `HasSubscription` + acotar **a mano** cada consulta. |
| G | ¿Hay feature flags/planes? | **Sí**: `PlanItem` (`module_*`) + `SettingValue` polimórfico por suscripción. | Los dos flags nuevos encajan sin tocar tablas existentes (§9). |
| H | ¿Hay sistema de diseño? | **Sí**: PrimeVue 4.5.5 (preset propio sobre Aura) + Tailwind 3.4, primario `#F68C0F`, fuente Figtree, iconos PrimeIcons. | La UI nueva se construye con ese sistema, sin librerías nuevas. |
| I | ¿Hay traducciones en el frontend? | **No.** Español escrito directo en las plantillas. | El nombre "Caja Inteligente" irá en una **constante JS** única para poder renombrarlo. |
| J | ¿El proyecto tiene precedente de módulo aislado? | **Sí**: `packages/ai-agent` (paquete Composer por `path` + symlink). | Replico ese patrón para el módulo nuevo. |
| K | ¿La suite de pruebas está en verde? | **No**: 419 pruebas, **24 fallos preexistentes**, 395 en verde, ~53 s. | Tomo esa lista como línea base de regresión (§15). |

---

## 1. Entorno y versiones

| Componente | Versión real (verificada en ejecución) | Evidencia |
|---|---|---|
| PHP | **8.3.37** (CLI) | `php -v` |
| Laravel | **12.63.0** | `php artisan --version` |
| Base de datos | **MySQL 8.0.39**, base `ezyventas2` (driver `mysql`) | `.env` (`DB_CONNECTION`, `DB_DATABASE`) |
| Node | **v26.5.0** | `node -v` |
| Gestores JS | **npm y pnpm conviven** (`package-lock.json` + `pnpm-lock.yaml`); `composer dev` invoca `npm` | `composer.json:76-79` |
| Frontend | Inertia 2.0.24 + Vue 3 (Composition API) + PrimeVue 4.5.5 + Tailwind 3.4 + Vite 7 | `package.json:9-46` |
| Permisos | spatie/laravel-permission 6.25.0 con tablas custom | `composer.json:34`; migración `2026_08_22_000001…:25-33` |
| Pruebas | PHPUnit 11.5.56, SQLite `:memory:` | `phpunit.xml:20-33` |
| Interprete Python | **No hay Python del sistema**; el runtime trae Python **3.12.14 con pip 26.2.1** | `python --version` falla; ruta del runtime DSH responde |
| Salida a internet | **Sí**: PyPI, Hugging Face y GitHub responden 200 | `Invoke-WebRequest` HEAD a `pypi.org`, `huggingface.co`, `raw.githubusercontent.com` |

> Nota para la Fase 3: el proceso de modelos (sección 4 de la especificación) **sí puede ejecutarse en esta máquina**;
> el venv de `tools/ml/` habrá que crearlo con el Python del runtime (3.12.14), no hay Python global.

### Archivos que documentan el proyecto (útiles, con matices)

`docs/context/` tiene 21 archivos de contexto generado (overview, esquema de BD y un archivo por módulo).
Son útiles como mapa, pero **se han desviado del código** en 5 puntos, así que en este documento mando yo con
evidencia del código:

| Dice `docs/context` | Realidad en código |
|---|---|
| Permisos en kebab-case (`00-overview.md:81`) | `recurso.accion` en minúsculas con punto (`PermissionSeeder.php:20-166`) |
| "23 enums, 61 modelos" | 28 enums, 74 archivos en `app/Models` |
| Impresión "WebUSB o red" | Plugin local HTTP `localhost:8000` o Web Bluetooth |
| `CheckSuperAdmin` = rol super-admin | No existe ese rol: es `branch->subscription_id === 1` |
| `SettingValue` con relación `settable` | Se llama `configurable` |

---

## 2. Estrategia multi-tenant

**Base de datos única, aislamiento por columna.** No hay `stancl/tenancy` ni base por tenant.

```
subscriptions (tenant raíz)
   └── branches.subscription_id        (sucursal)
         └── users.branch_id           (usuario, FK obligatoria)
```

| Ámbito | Columna de aislamiento | Dónde |
|---|---|---|
| Catálogo y negocio | `subscription_id` | `brands`, `categories`, `providers`, `promotions`, `bank_accounts`, `orders`, `store_configs` |
| Operación | `branch_id` | `products`, `transactions`, `customers`, `branch_product`, `payments` (vía transacción) |
| Catálogo base global | *sin tenant* | `global_products` (compartido a propósito) |

Mecanismos reales:

- **Trait** `app/Traits/HasSubscription.php`: aporta `getSubscriptionId()` (`:25-48`) y `scopeBySubscription()` (`:53-66`). **No es un GlobalScope**: no filtra nada por sí solo.
- **Middleware global** `EnsureSubscriptionScope` (registrado en el grupo `web`, `bootstrap/app.php:31`): valida **solo parámetros de ruta con model binding** de modelos que usan el trait (`EnsureSubscriptionScope.php:40-60`). No protege ids que vengan en el body.
- **El tenant se deriva siempre del usuario autenticado**, nunca de la petición (`$user->branch_id`, `$user->branch->subscription_id`).

**Consecuencia para el módulo nuevo:** cada modelo nuevo debe (a) declarar `use HasSubscription`, (b) incluir la columna en `$fillable`, (c) y **acotar cada consulta a mano**. Para datos de caja/pesaje la columna correcta es `branch_id`; para configuración del negocio, `subscription_id`.

---

## 3. Frontend: stack, build y carga perezosa

- Punto de entrada único: `resources/js/app.js`; páginas resueltas con `import.meta.glob` → **un chunk por vista** (`app.js:25`).
- Sin Pinia, sin Vuex, sin i18n, sin Web Workers, **sin ningún `import()` dinámico dentro del POS**.
- `vite.config.js` no define `manualChunks`, ni `define`, ni alias (el alias `@` lo inyecta `laravel-vite-plugin`). Auto-import de componentes PrimeVue vía `unplugin-vue-components` (`vite.config.js:23-27`); los componentes propios **se importan a mano**.
- Precedentes de carga diferida en el repo: `defineAsyncComponent` (`Welcome.vue:7-9`) e imports dinámicos de Echo/Pusher (`bootstrap.js:12-16`).
  → El visor de cámara y el motor de visión **entran con `defineAsyncComponent`/`import()`**, sin tocar el arranque del POS.

---

## 4. El módulo "Punto de venta"

### 4.1 Rutas

Prefijo `/pos`, nombre `pos.*`, archivo `routes/web/POS.php:17-28`:

| Método | URI | Nombre | Middleware efectivo |
|---|---|---|---|
| GET | `/pos` | `pos.index` | `web`, `auth`, `can:pos.access` |
| POST | `/pos/checkout` | `pos.checkout` | `web`, `auth`, `can:pos.create_sale` |
| POST | `/pos/layaway` | `pos.layaway` | `web`, `auth` (**sin permiso**) |
| GET | `/pos/pos/customers/search` | `pos.customers.search` | `web`, `auth`, `can:pos.access` |
| GET | `/pos/check-entity` | `pos.check-entity` | `web`, `auth`, `can:pos.access` |
| GET | `/pos/online-orders` | `pos.online-orders` | `web`, `auth`, `can:pos.access` |
| POST | `/pos/store-order` | `pos.store-order` | `web`, `auth` (**sin permiso**), resuelto en `TransactionController` |
| POST | `/api/v1/pos/checkout` | `api.v1.pos.checkout` | `api`, `auth:sanctum`, `EnsureApiSubscriptionIsActive` |

Nomenclatura del repo: un archivo por feature en `routes/web/kebab-case.php` cargado con `require` desde `routes/web.php:45-89`. Nombres `modulo.accion`.

### 4.2 Vistas

| Archivo | Rol |
|---|---|
| `resources/js/Pages/POS/Index.vue` (564 líneas) | POS de escritorio |
| `resources/js/Pages/POS/IndexMobile.vue` (616 líneas) | POS móvil: **copia** de la lógica del carrito con otra maquetación |
| `Partials/PosLeftPanel.vue` | Panel de productos (barra de acciones, buscador, tarjetas, carritos en espera). **Compartido por ambas páginas** |
| `Partials/ShoppingCart.vue` | Carrito, totales, promociones de carrito, CTAs "Pedido"/"Cobrar" |
| `Partials/CartItem.vue` | Línea de carrito: cantidad, edición de precio, eliminar |
| `Partials/ProductCard.vue`, `ProductDetailModal.vue`, `CategoryFilters.vue`, `PendingCartsPopover.vue`, `OrderFormModal.vue`, `OnlineOrdersModal.vue` | Resto de la UI |

La elección escritorio/móvil se hace **en el servidor por user-agent** (`PointOfSaleController.php:131-134`), no por CSS. Es la causa de que la lógica del carrito esté duplicada.

### 4.3 Estado del carrito (crítico)

| Aspecto | Realidad |
|---|---|
| Declaración | `const cartItems = ref([])` — `Index.vue:35`, `IndexMobile.vue:35` |
| Store global | **No existe** (sin Pinia, sin `provide/inject`, sin `useCart`) |
| Persistencia | `localStorage`, clave `pos_data_{userId}_{branchId}_cart` + `_client` + `_pending` (`Index.vue:62-98`) |
| Carritos en espera | Array en `localStorage`, sin tabla (`Index.vue:258-290`) |
| Servidor | No hay tabla ni endpoint de carrito (`grep cart database/migrations` → 0 resultados) |

Estructura de una línea: el payload completo del producto (`id, name, sku, selling_price, price, original_price, price_tiers, stock, is_bulk, measure_unit, promotions, variants…`) **más** los campos de carrito `cartItemId, quantity, price_qty_1_promo, price, original_price, isTierPrice, isManualPrice` (y `product_attribute_id, selectedVariant` si es variante) — `Index.vue:169-188`.

Al cobrar se envía una versión reducida: `{ id, product_attribute_id, quantity, unit_price, description, discount, discount_reason }` (`Index.vue:338-356`).

### 4.4 **La acción que agrega un producto al carrito** (punto de entrada a reutilizar)

```js
// resources/js/Pages/POS/Index.vue:133  (idéntica en IndexMobile.vue:135)
const addToCart = (data) => {
    const { product, variant } = data;
    const cartItemId = variant ? `prod-${product.id}-variant-${variant.id}` : `prod-${product.id}`;
    const existingItem = cartItems.value.find(item => item.cartItemId === cartItemId);
    const quantityToAdd = 1;                       // ← FIJO A 1 (:138)
    // fusiona líneas, valida stock, recalcula precio con getPriceForQuantity() (mayoreo) :141-167
    // o crea la línea nueva                                                                 :168-197
};
```

- **Cómo se invoca hoy:** no se importa; se ejecuta por evento.
  `ProductCard`/`ProductDetailModal` → `emit('addToCart', { product, variant })` → `PosLeftPanel.vue:28` (declara el evento) y `:405` lo reenvía → `Index.vue:472` `@add-to-cart="addToCart"`.
  Es decir: **un componente hijo nuevo dentro de `PosLeftPanel` puede emitir `addToCart` y queda integrado sin tocar la página** (solo hay que añadir el `@add-to-cart` del componente nuevo en el mismo sitio).
- **El escáner de código de barras no agrega nada hoy:** solo escribe en el buscador tras un buffer de 200 ms (`PosLeftPanel.vue:171-208`); el cajero debe pulsar la tarjeta.
- **No acepta cantidad.** Para la báscula hace falta pasar de `quantityToAdd = 1` a una cantidad opcional (ver §16, D4).
- `getPriceForQuantity()` (`Index.vue:101-131`) aplica `price_tiers` (mayoreo) en el cliente; el backend no revalida precios.

### 4.5 Cobro

`ShoppingCart` → `handleCheckout` → `useForm().post(route('pos.checkout'))` (`Index.vue:437`); apartados a `pos.layaway`, pedidos a `pos.store-order`.

Backend: `PointOfSaleController::checkout()` valida **inline** (`:224-246`) y delega todo en
`TransactionPaymentService::handleNewSale()` (`:251-258`), que dentro de una transacción:

1. **Recalcula** los totales desde el carrito (`cartTotals()`, `TransactionPaymentService.php:139-161`):
   `subtotal = Σ((unit_price + discount) × quantity)`, `total = subtotal − total_discount + shipping_cost`.
2. Crea `transactions` (folio `V-###` por sucursal, `channel = POS`, `total_tax = 0`).
3. Crea `transactions_items` y **mueve stock** (`deductStock`/`reserveStock`).
4. Aplica saldo a favor y pagos (`PaymentService`), actualiza bancos.
5. Si queda saldo, exige cliente con crédito y genera deuda.
6. Marca `completado` si queda pagada.

**Existen dos entradas de venta** que comparten ese servicio: el POS web (Inertia) y la API móvil (JSON). Cualquier lógica de cobro nueva debe vivir en `TransactionPaymentService`, nunca en el controlador.

### 4.6 Edición de precio, eliminación, cantidades, promociones, impuestos

| Concepto | Dónde se calcula | Evidencia |
|---|---|---|
| Editar cantidad | `updateCartQuantity({ itemId, quantity })` | `Index.vue:200-227` |
| Editar precio (solo esta venta) | `updateCartPrice({ itemId, price })` marca `isManualPrice` | `Index.vue:229-236`; UI `CartItem.vue:227` |
| Editar precio (permanente en catálogo) | `POST products/update-price-pos` (`products.edit`) | `CartItem.vue:72-94`; `ProductController.php:484-501` |
| Eliminar línea / vaciar | `removeCartItem(itemId)` / `clearCart()` | `Index.vue:238-246` |
| Promoción de producto | **Backend**: `ProductCatalogService::pricing()` viaja ya aplicada como `price`/`original_price` | `ProductCatalogService.php:135-203` |
| Precio escalonado (mayoreo) | **Solo frontend** (`price_tiers`) | `Index.vue:101-131` |
| Promoción de carrito (BOGO / paquete) | **Solo frontend** → viaja en `total_discount` | `ShoppingCart.vue:107-156` |
| Impuestos | **No hay motor**: `total_tax = 0`, `tax_amount = 0` | `TransactionPaymentService.php:64`; migración `…000003…:179` |
| Impresión | `PrintModal` + `PrintEncoderService` (ESC/POS, TSPL, HTML) | `app/Services/PrintEncoderService.php` |

**Riesgo conocido (no lo tocamos):** el backend confía en el `unit_price` que envía el navegador, y el pivote
`promotion_transaction` nunca se escribe (los reportes de promoción salen vacíos). El módulo nuevo **no debe**
cambiar ese comportamiento: solo alimenta el mismo carrito.

### 4.7 Inventario y caja

- Stock por sucursal en pivotes `branch_product` / `branch_product_attribute`, `decimal(10,3)`; se descuenta en el cobro vía `Product::deductStock()` → `processStockChange()` → `applyDirectStockChange()` (permite negativos, registra bitácora con Spatie Activity).
- Toda venta se ata a `transactions.cash_register_session_id`; el arqueo se calcula agregando pagos de la sesión (`CashRegisterSession::closeSession()`).

---

## 5. Granel, unidad de medida y precio por kg: **ya existe** (y dónde se rompe)

Esto cambia el plan de la báscula: **no hace falta marcar productos como pesables con una columna nueva**.

| Pieza | Estado | Evidencia |
|---|---|---|
| `products.is_bulk` (boolean, default `false`) | **Existe** | migración `2026_08_22_000002_create_catalog_tables.php:79` |
| `products.measure_unit` (string libre: `Pza`, `Kg`, `Grs`, `Lts`, `Mts`, `Cm`) | **Existe** | migración `:77`; UI `Product/Partials/Inventory.vue:400-402` |
| `product_type = 'bulk'` en el formulario → `is_bulk` | **Existe** | `app/Actions/Product/CreateProduct.php:26`; `UpdateProduct.php:27` |
| Precio por unidad de medida | `selling_price` es el precio de **1 `measure_unit`** ("si vendes 0.250 cobrará una cuarta parte") | `Product/Partials/Pricing.vue:136-140` |
| El POS expone ambos campos al frontend | **Existe** | `ProductCatalogService.php:105-106` |
| La línea de carrito admite decimales (mín. 0.001, 3 decimales, step 0.25, sufijo de unidad) | **Existe** | `CartItem.vue:31-44`, `:241-254` |
| `is_weighted`, `weight_unit`, `allow_decimal`, `unit_id`, `pesable` | **No existen** (búsqueda global: 0 coincidencias) | — |
| `products.barcode` | **No existe**: el código escaneable del sistema es el **SKU** | `PrintEncoderService.php:475-476`, `:504` |

> ⚠️ Ojo: `products.weight` (gramos) y `length/width/height` existen, pero son **logística de envío**, no unidad de venta. No confundir con `measure_unit`.

### Dónde se rompe hoy el cobro a granel (bloqueante de la Fase 1)

| # | Punto | Problema | Evidencia |
|---|---|---|---|
| 1 | Validación del checkout web | `cartItems.*.quantity => min:1` **rechaza 0.750 kg** | `PointOfSaleController.php:229` |
| 2 | Validación del checkout/layaway de la API | mismo `min:1` | `app/Http/Requests/Api/V1/Pos/RegisterSaleRequest.php:48` |
| 3 | Cast de cantidad | columna `decimal(10,3)` pero cast **`decimal:2`**: un `0.001` se guarda, y al releer vale `"0.00"` (rompe devoluciones y reposición de stock) | `TransactionItem.php:32`; verificado en runtime |
| 4 | Cast de stock | `decimal:2` sobre columnas `(10,3)` | `BranchProduct.php:21-26` |
| 5 | Tienda en línea | `order_items.quantity` es entero | migración `…000007…:110` |

El camino de **pedido** sí acepta fracciones (`CreateStoreOrderRequest.php:30`, `min:0.01`), lo que demuestra que la arquitectura admite el caso.

---

## 6. Permisos y roles

- Librería: **spatie/laravel-permission 6.25.0**, con tablas y modelo `Permission` custom (añaden `description` y `module`).
- Fuente autoritativa: **`database/seeders/PermissionSeeder.php`** → 117 permisos agrupados por módulo legible en español.
- **Formato real del nombre: `<recurso>.<accion>` en minúsculas con punto** (y tres niveles cuando aplica): `pos.access`, `pos.create_sale`, `pos.edit_prices`, `products.see_cost_price`, `invoices.settings.upload_csd`, `online_store.orders.change_status`.
- Permisos del POS: `pos.access`, `pos.create_sale`, `pos.edit_prices` (`PermissionSeeder.php:21-25`).
- **Roles por sucursal** (`roles.branch_id`), no por suscripción. No existe rol "super-admin": `CheckSuperAdmin` compara `branch->subscription_id === 1`.
- **Dos trampas importantes:**
  1. `PermissionSeeder::run()` hace `Permission::query()->delete()` (`:17`): **re-ejecutarlo borra las asignaciones** (cascade). Los permisos nuevos se añaden al array del seeder, pero se crean en runtime (botón de la UI de roles o un comando/migración de datos), **no re-ejecutando el seeder**.
  2. El **dueño sin roles pasa cualquier permiso** (`Gate::before` en `AppServiceProvider.php:63-76`; `User::isOwner()` = sin roles). Por eso el gating real de una feature debe ser **módulo/plan + flag**, no solo permiso.
- Frontend: `usePermissions().hasPermission('x')` lee `auth.permissions` de los props compartidos (`Composables/usePermissions.js:3-11`, `HandleInertiaRequests.php:33-46`). Sin directivas ni helpers globales.

---

## 7. Planes y feature flags (dónde van `smart_checkout_enabled` y `scale_enabled`)

No hay tabla `plans/features`. Hay:

| Pieza | Qué es | Evidencia |
|---|---|---|
| `plan_items` | Catálogo de ítems contratables: `key` único, `type` (`module`\|`limit`), `name`, `monthly_price`, `meta` (icon, quantity) | migración `…000001…:170-180`; `PlanItem.php:14-29` |
| `subscription_versions` + `subscription_items` | Versiones congeladas de lo contratado (`item_key`, `item_type`, `quantity`, `unit_price`) | `Subscription.php:418` |
| API de evaluación | `getActiveModuleKeys()`, `getAvailableModuleNames()`, `billingEnabled()`, `hasAiAgentModule()`, `hasReached*Limit()` | `Subscription.php:99-204`, `:542-545` |
| Settings polimórficos | `setting_definitions` + `setting_values` (`configurable_type/id`) leídos con `Subscription::getSettingValue()` | `Subscription.php:524-537` |

Módulos ya existentes (13): `module_pos`, `module_products`, `module_transactions`, `module_billing`, `module_ai_agent`, `module_online_store`, …
Uso real del mismo patrón en el POS: `hasOnlineStore` = `in_array('module_online_store', getActiveModuleKeys())` (`PointOfSaleController.php:128`).

**Tres opciones para los flags nuevos** (decisión D2, §16):

| Opción | Cómo | Pros | Contras |
|---|---|---|---|
| **A. Módulos de plan** `module_smart_checkout`, `module_scale` | Alta por `/admin/plan-items` y asignación a la versión | Cero migraciones; gating ya resuelto (Gate + menú + props) | Semántica "módulo contratado", no toggle fino |
| **B. Settings por suscripción** `smart_checkout.enabled`, `scale.enabled` | `SettingDefinition` `level='subscription'` + `SettingValue` | Es exactamente "flag por tenant", apagado por defecto | El `SettingDefinitionSeeder` borra definiciones al correr |
| **C. Columnas en `subscriptions`** | Migración `add_..._to_subscriptions` | Explícito | Rompe el patrón; ya hay un flag legacy (`facturacion_habilitada`) declarado deuda técnica |

Combinación que recomiendo: **A + B** (módulo = derecho comercial; setting = interruptor operativo por tenant). Sin tocar tablas existentes.

---

## 8. Menú, traducciones, iconos, notificaciones

| Pieza | Dónde | Nota |
|---|---|---|
| Menú lateral | `resources/js/Layouts/AppMenu.vue` (array declarativo con `permission` y `module`), renderizado por `AppMenuItem.vue` → `AppSidebar.vue` | El POS ya es un ítem con `pos.access`; **no hace falta entrada nueva** porque la feature vive dentro del POS |
| Traducciones backend | `lang/es.json` (50 KB), `lang/es/*.php` | Solo Laravel; el frontend no las usa |
| Traducciones frontend | **No existen** | Español literal en plantillas |
| Iconos | **PrimeIcons** (`<i class="pi pi-*">`, global) en >95 % del POS; `@heroicons/vue/24/solid` solo para `FireIcon`/`StarIcon` | Sin wrapper propio |
| Toasts | PrimeVue `useToast()` + `<Toast>` global en `AppLayout.vue:231`; backend: `->with('success'\|'error')` | No añadir toasts locales si la acción recarga |
| Confirmaciones | `useConfirm` + `<ConfirmDialog>` (`AppLayout.vue:232`) | |

---

## 9. Guía de diseño actual (para la UI nueva)

**Todo esto ya existe: la UI nueva lo reutiliza, no inventa un estilo.**

| Token | Valor | Fuente |
|---|---|---|
| Primario | `#F68C0F` (escala 50→950 en el preset) | `tailwind.config.js:32`; `presets/ezyventas.js:6-18` |
| Secundario | `#E9A527` · `primarylight #FCDCB5` · `redDanger #F80505` | `tailwind.config.js:33-36` |
| Superficies oscuras (de facto) | fondo `#232323`, panel `#1a1a1a`, borde `#3a3a3a` | uso masivo en el POS; preset `ezyventas.js:52-65` |
| Tipografía | **Figtree** (400/500/600, bunny.net); base `html { font-size: 14px }` | `app.blade.php:18`; `layout/_core.scss:1-16` |
| Radios | tarjetas `rounded-3xl` (24 px), líneas/inputs `rounded-2xl` (16 px), botones `!rounded-xl` (12 px) | patrón en `ProductCard.vue:198`, `CartItem.vue:177`, `ShoppingCart.vue:389` |
| Botón de barra de acciones | `Button rounded !w-10 !h-10 !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] !text-gray-600 dark:!text-gray-300 hover:!border-primary-500` | `PosLeftPanel.vue:319-321` |
| Modo oscuro | por clase (`[class*="app-dark"]`), no por `prefers-color-scheme` | `tailwind.config.js:8`; `app.js:61` |
| Micro-etiquetas | `text-[10px] uppercase tracking-widest font-bold text-gray-500` | `Inventory.vue:400`, `CartItem.vue:253` |
| Tono de textos | es-MX, directo, imperativo, sin exclamaciones: "Añadir", "Cobrar", "Tu orden está vacía.", "Stock insuficiente" | `PosLeftPanel.vue:378`, `ShoppingCart.vue:334`, `Index.vue:166` |
| Formato de dinero | `new Intl.NumberFormat('es-MX', { style:'currency', currency:'MXN' })` por componente | `ShoppingCart.vue:175-177` |

**Ubicación del botón nuevo:** la barra de acciones del header del panel de productos (`PosLeftPanel.vue:300-368`), que es la misma en escritorio y móvil.

---

## 10. Servicio local / plugin (precedente para hardware)

- **Impresión desde el navegador**: plugin local por **HTTP plano a `http://localhost:8000`** (constante fija): `GET /impresoras` y `POST /imprimir` con `{nombreImpresora, operaciones, anchoImpresora}` (`Composables/usePrintPlugin.js:5,19,59-67`). El payload ESC/POS lo genera Laravel (`POST /print/payload`).
- **Móvil**: se sustituye por **Web Bluetooth** (`Composables/useBluetoothPrinter.js`, chunks de 20 bytes).
- **No existe Web Serial en el repo**: la báscula Web Serial será lo primero de ese tipo. El patrón a copiar es el de un composable (`use*`) que encapsula la API del navegador y expone estado + callbacks.
- **No existe ningún Web Worker** en el proyecto: el motor de visión será el primero.

---

## 11. Convenciones observadas

| Capa | Convención real |
|---|---|
| Rutas | un archivo por feature en `routes/web/kebab-case.php` con `require` en `routes/web.php`; nombres `modulo.accion` |
| Controladores | `app/Http/Controllers/{Module}/…Controller.php`, finos, con `HasMiddleware` + `can:` (el POS actual incumple: valida inline y hace consultas) |
| Actions | `app/Actions/{Module}/VerboEntidadAction.php` con un único `execute()` |
| Services | `app/Services/{Module}/EntidadService.php`, inyectados por constructor |
| FormRequests | `app/Http/Requests/{Module}/Store*Request.php` con `authorize()` y mensajes en español (solo la API móvil los usa hoy) |
| Modelos | `$fillable` explícito, casts a Enums, scopes, sin `$guarded = []` |
| Enums | `app/Enums`, `enum X: string` |
| Pruebas | `tests/Feature`, `tests/Unit`, `RefreshDatabase` (SQLite `:memory:`), atributo `#[Test]`, nombres descriptivos en inglés |
| Estilo | **Pint está configurado pero no se cumple** (`vendor/bin/pint --test app` → 525 archivos, 395 issues). **No ejecutar Pint sobre todo el repo**: solo sobre archivos nuevos |
| Empaquetado aislado | `packages/ai-agent`: `repositories: path + symlink`, provider con `mergeConfigFrom` + `loadMigrationsFrom` + `publishes`, y rutas cargadas desde `routes/web.php:89` (no desde el provider, para heredar el grupo `web`) |

---

## 12. Línea base de pruebas (regresión obligatoria)

Ejecutado hoy con `php artisan test` (SQLite `:memory:`):

```
Tests:    24 failed, 395 passed (2460 assertions)
Duration: 53.07s
```

Los 24 fallos son **preexistentes y ajenos** a esta funcionalidad:

| # | Pruebas | Causa exacta |
|---|---|---|
| 16 | `AiAgentToolsTest` | Clases/factories inexistentes (`PromotionFactory`, `TransactionItemFactory`, `App\Models\Invoice`) y `DATE_FORMAT` no soportado por SQLite |
| 3 | `PosHelpArticlesSeedTest` | La migración `2026_10_18_000001_seed_pos_help_articles.php` **no existe** en el repo |
| 6 | `*ControllerTest :: it denies access without permissions` | Falso positivo por diseño: quitar roles convierte al usuario en **dueño**, y el dueño pasa cualquier permiso |

**Criterio de regresión para las fases siguientes:** el número de fallos no debe crecer; los 395 verdes deben seguir verdes.

Factories disponibles y relevantes: `ProductFactory` (con estado `withStock`), `TransactionFactory`, `SubscriptionFactory`, `BranchFactory`, `UserFactory`, `CustomerFactory`, `CashRegisterSessionFactory`, `PaymentFactory`. **No existen** `PromotionFactory` ni `TransactionItemFactory`.

---

## 13. Arquitectura propuesta (derivada de lo anterior)

```
POS existente (Index.vue / IndexMobile.vue)  ← sin cambios de lógica
  │  [botón "Caja Inteligente" ON/OFF]        ← enganche: PosLeftPanel + prop del controlador
  ▼  import() dinámico SOLO al activar
recursos del módulo (nuevos, aislados)
  cámaras 1..N → Web Worker → RecognitionEngine (Mock primero, ONNX después)
  → emit('addToCart', { product })            ← MISMA acción del POS

Báscula (independiente)
  ScaleDriver (simulado | manual | Web Serial) → panel de pesado
  → emit('addToCart', { product, quantity })  ← MISMA acción, con cantidad
```

Decisiones de diseño ya cerradas por evidencia:

1. **Nada de módulo aparte en el menú**: todo vive dentro de `/pos`.
2. **Nada de backend de carrito**: la detección reutiliza `addToCart` por evento (patrón ya soportado por `PosLeftPanel.emits`).
3. **Paquete PHP aislado** `packages/smart-checkout` con el patrón de `packages/ai-agent` (tablas, provider, config, rutas propias); el frontend, en `resources/js/Pages/POS/SmartCheckout/` como componentes asíncronos.

   > **Corrección tras la Fase 1:** se descartó el paquete Composer. El módulo vive en `app/Services/SmartCheckout`, `app/Http/Controllers/SmartCheckout`, `app/Http/Requests/SmartCheckout`, `routes/web/smart-checkout.php` y `resources/js/{Composables,Pages/POS}/SmartCheckout`. Motivo: un paquete por `path` obliga a tocar `composer.json` + `composer dump-autoload` en cada despliegue y el repo ya resuelve el aislamiento con carpetas por módulo (`app/Services/{Modulo}`); el paquete no aporta nada aquí porque el código no se distribuye a otros proyectos.
4. **Modelos nuevos** con `branch_id` (operación) o `subscription_id` (configuración), `HasSubscription` y filtrado manual.
5. **Flags**: módulos de plan (`module_smart_checkout`, `module_scale`) + settings por suscripción, apagados por defecto.
6. **Permisos**: `smart_checkout.use`, `smart_checkout.settings`, `scale.use`, `scale.settings`, en un grupo nuevo "Caja Inteligente" del `PermissionSeeder`, creados en runtime (no re-ejecutando el seeder).

---

## 14. Puntos de enganche: archivos **existentes** que habría que tocar

Lista mínima prevista. Cualquier archivo adicional que aparezca se reportará antes de tocarlo.

### Fase 1 — Báscula

| Archivo | Cambio (mínimo) |
|---|---|
| `app/Http/Controllers/PointOfSaleController.php` | Añadir props nuevas al `index()` (`scaleEnabled`, ajustes de báscula) y hacer condicional la validación de `cartItems.*.quantity` (D1 aprobado: solo `is_bulk` + `scale_enabled`) |
| `resources/js/Pages/POS/Index.vue` | Pasar las props al panel, interceptar la selección de producto pesable y pasar `quantity` al agregar |
| `resources/js/Pages/POS/IndexMobile.vue` | Idem (D5: el alcance incluye móvil) |
| `resources/js/Pages/POS/Partials/PosLeftPanel.vue` | Contenedor + evento del panel de pesado y del acceso rápido "Granel" |
| `routes/web.php` | Un `require` del archivo de rutas nuevo |
| `database/seeders/PermissionSeeder.php` | Añadir el grupo "Caja Inteligente" con los 4 permisos (sin re-ejecutar el seeder) |
| `app/Models/TransactionItem.php` y `BranchProduct*` | Cambiar el cast de `decimal:2` a `decimal:3` (D1 aprobado) |

### Fase 2 — Esqueleto del Modo Caja Inteligente

| Archivo | Cambio (mínimo) |
|---|---|
| `resources/js/Pages/POS/Index.vue` / `IndexMobile.vue` | Botón ON/OFF + montaje perezoso del panel |
| `app/Http/Controllers/PointOfSaleController.php` | Props de los flags |
| `routes/web.php` | `require` de `/pos/smart-checkout` |

Todo lo demás (motor simulado, UI de cajas/semáforo, barra de indicadores, tablas `smartpos_*`, rutas de API, métricas) va en archivos **nuevos**.

---

## 15. Riesgos detectados

| # | Riesgo | Impacto | Mitigación prevista |
|---|---|---|---|
| R1 | El cobro web **rechaza cantidades < 1** y el cast redondea a 2 decimales | La báscula no puede cobrar 0.750 kg | Decisión D1; regla condicional por `is_bulk` **y** flag `scale_enabled` para no alterar el POS con el flag apagado |
| R2 | `addToCart` no acepta cantidad y está **duplicada** en dos páginas | Divergencia entre escritorio y móvil | Cambio aditivo (parámetro opcional) en ambas, o refactor a composable (D4) |
| R3 | El backend no revalida precios (`unit_price` viene del navegador) | Un cliente manipulado podría fijar precios | No se empeora: el módulo solo llama a la misma acción. Se registra como deuda existente |
| R4 | El dueño de la suscripción **pasa cualquier permiso** | Los permisos solos no restringen la feature | Gating principal por módulo/flag; permisos como capa adicional |
| R5 | `PermissionSeeder` borra permisos al correr | Se perderían asignaciones | Añadir al array, crear en runtime; **nunca** re-ejecutar el seeder |
| R6 | Sin Web Serial ni Web Workers en el repo | Todo el hardware/inferencia es código nuevo sin precedente | Interfaces `ScaleDriver` / `RecognitionEngine` y driver simulado primero; pruebas con el simulador |
| R7 | Web Serial exige HTTPS o `localhost` | `APP_URL` es `https://ezyventas2.test` ✅, pero el despliegue real debe ir por HTTPS | Documentar requisito; captura manual siempre disponible |
| R8 | `Index.vue` e `IndexMobile.vue` duplican el carrito | Coste doble en cada cambio | Alcance definido en D5 |
| R9 | La suite tiene 24 fallos preexistentes | Se pueden confundir con regresiones | Línea base fijada (§12) |
| R10 | SQLite en pruebas no soporta funciones MySQL (`DATE_FORMAT`) | Pruebas nuevas no deben usar SQL específico de MySQL | Usar Eloquent/`whereDate`, no SQL crudo |
| R11 | `docs/context` está desactualizado en 5 puntos | Decisiones basadas en documentación falsa | Este documento manda; usar el código |
| R12 | No hay Python del sistema | La preparación de modelos (Fase 3) requiere venv | Crear `tools/ml/.venv` con el Python 3.12.14 del runtime; internet verificado |

---

## 16. Dudas que necesitan tu decisión (bloquean la Fase 1)

> **Estado: D1–D6 resueltas el 2026-11-27.** Las respuestas y su traducción a diseño están en
> [`decisiones.md`](decisiones.md). Se conserva la tabla original como registro de lo que estaba abierto.

| # | Duda | Por qué no puedo decidirlo yo |
|---|---|---|
| **D1** | **Cobro de cantidades fraccionarias.** Hoy `pos/checkout` exige `quantity >= 1` y `TransactionItem` castea a 2 decimales. Para cobrar 0.750 kg hay que modificar dos reglas existentes del POS. ¿Autorizas el cambio condicionado (solo productos `is_bulk` **y** con `scale_enabled` activo), o prefieres otra vía? | La regla 1 de la especificación prohíbe cambiar el POS; la 6 prohíbe tocar tablas existentes. Esto no es tabla, pero sí validación y casts del POS |
| **D2** | **Dónde viven los flags.** ¿Módulos de plan (`module_smart_checkout`/`module_scale`), settings por suscripción, ambos, o columnas nuevas? | Cambia el modelo de datos y el gating. La especificación pide usar lo que ya exista |
| **D3** | **Marcar productos como pesables.** Ya existe `is_bulk` + `measure_unit`. ¿Confirmas usarlos como disparador del panel de pesado (sin estructuras nuevas)? ¿Y cómo interpretamos `measure_unit = 'Grs'` (¿precio por gramo)? | La especificación prohíbe crear estructuras sin preguntar y aquí hay un campo que puede significar dos cosas |
| **D4** | **Cómo agregar cantidad al carrito.** ¿Extiendo `addToCart(data)` con `quantity` opcional en ambas páginas (cambio mínimo) o refactorizo a un composable `usePosCart` compartido (más limpio, más riesgo)? | La especificación dice detenerse si no hay punto de entrada reutilizable, y aquí no lo hay tal cual |
| **D5** | **Alcance de la báscula: ¿escritorio y móvil, o solo escritorio en la Fase 1?** | Duplica trabajo y superficie de regresión |
| **D6** | **Rama base.** Hay `develop`, `Miguel` (actual) y `main`. ¿De cuál creo `feature/modo-caja-inteligente`? | No debo elegir la base del trabajo de otro |

Pendientes para la Fase 3 (no bloquean ahora, pero conviene saberlo ya):
- **D7**: dónde se alojarán los modelos ONNX en producción (carpeta pública del dominio, almacenamiento de objetos o CDN), y si se acepta Git LFS para versionarlos.
- **D8**: token de Hugging Face — **no es necesario** para estos dos modelos (son públicos); solo se pediría si apareciera un límite de descarga.

### Resoluciones (2026-11-27)

| # | Resuelto como | Detalle |
|---|---|---|
| D1 | **Aprobado condicionado**: `min:0.001` solo si `is_bulk` **y** `scale_enabled`; casts a `decimal:3` | [`decisiones.md`](decisiones.md) §D1 |
| D2 | **Tabla nueva `smartpos_settings`** por suscripción (preferencia del negocio) + `localStorage` por terminal para el ON/OFF del cajero. Sin módulos de plan (no es de pago), sin columnas nuevas | [`decisiones.md`](decisiones.md) §D2 |
| D3 | Pesable = `is_bulk` **y** `measure_unit ∈ {Kg, Grs}` | [`decisiones.md`](decisiones.md) §D3 |
| D3b | Precio por la unidad indicada en `measure_unit` | [`decisiones.md`](decisiones.md) §D3b |
| D4 | `addToCart` con `quantity` opcional (aditivo), en ambas páginas | [`decisiones.md`](decisiones.md) §D4 |
| D5 | Báscula en escritorio **y** móvil | [`decisiones.md`](decisiones.md) §D5 |
| D6 | Rama `feature/modo-caja-inteligente` desde `Miguel` | [`decisiones.md`](decisiones.md) §D6 |

---

## 17. Verificación realizada (comandos)

```powershell
php -v                                        # PHP 8.3.37
php artisan --version                         # Laravel Framework 12.63.0
node -v                                       # v26.5.0
php artisan test                              # 24 failed, 395 passed (2460 assertions) 53.07s
git status --short                            # limpio
git branch -a                                 # Miguel (actual), develop, main + remotos
git switch -c feature/modo-caja-inteligente   # D6: rama creada desde Miguel
# Conectividad para la Fase 3 (los tres responden 200)
Invoke-WebRequest -Method Head https://pypi.org/simple/onnxruntime/
Invoke-WebRequest -Method Head https://huggingface.co/api/models/facebook/dinov2-small
Invoke-WebRequest -Method Head https://raw.githubusercontent.com/Megvii-BaseDetection/YOLOX/main/README.md
```

Ningún archivo del proyecto fue modificado en esta fase. Los únicos artefactos creados son este documento,
[`decisiones.md`](decisiones.md) y los tres informes de apoyo en `docs/caja-inteligente/_research/`.

---

## 18. Confirmación de cierre de fase

Con el Modo Caja Inteligente apagado, la báscula deshabilitada y los flags por defecto:
**el módulo Punto de venta no ha sido modificado en la Fase 0** (no se ha tocado una sola línea de código).

D1–D6 quedaron resueltas el 2026-11-27 y están registradas en [`decisiones.md`](decisiones.md).
Rama de trabajo: `feature/modo-caja-inteligente` (desde `Miguel`), sin commits todavía.

**Detenido al final de la Fase 0**, a la espera de la confirmación para comenzar la **Fase 1 (báscula en el POS)**.
