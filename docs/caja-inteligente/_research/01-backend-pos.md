# 01 — Backend del módulo Punto de venta (POS)

> Investigación de **solo lectura** sobre el repositorio `Ezyventas2`.
> Fecha de la investigación: 2026-11-27.
> Stack verificado en ejecución: `Laravel Framework 12.63.0`, `PHP 8.3.37` (`composer.json` exige `php: ^8.2`, `composer.json:18`).
> Ningún archivo del proyecto fue modificado; este informe es el único artefacto creado.

---

## 0. Resumen ejecutivo (lo que decide el diseño de "Caja inteligente")

| # | Pregunta | Respuesta verificada | Evidencia |
|---|---|---|---|
| A | ¿Existe un endpoint backend que agregue **UNA** partida al carrito? | **NO.** No hay carrito persistido ni endpoint de "add item". El backend solo conoce el carrito en el `POST` de checkout/layaway/order. | `routes/web/POS.php:17-28`, `routes/api/v1/pos.php:16-23`, `database/migrations/` (0 coincidencias de `cart`) |
| B | ¿Dónde vive el carrito? | **100 % en el frontend Vue**, en memoria (`ref([])`) y espejado en `localStorage`. Los "carritos en espera" también son `localStorage`, sin tabla. | `resources/js/Pages/POS/Index.vue:35`, `:62-98`, `:258-290` |
| C | ¿Existe el concepto de producto pesable / unidad de medida? | **SÍ, parcialmente y ya cableado en UI y en el read-model**: `products.is_bulk` (boolean) y `products.measure_unit` (string). **NO** existen `is_weighted`, `weight_unit`, `allow_decimal`, `unit_id`, `decimal_qty`, `pesable`. | `database/migrations/2026_08_22_000002_create_catalog_tables.php:77,79`; `app/Services/Catalog/ProductCatalogService.php:105-106`; `resources/js/Pages/POS/Partials/CartItem.vue:31-44` |
| D | ¿Se puede cobrar hoy una cantidad fraccionaria (ej. 0.250 kg)? | **En el POS web NO**: la validación exige `min:1` en `cartItems.*.quantity`, aunque la BD y la UI admiten 3 decimales. La tienda en línea y el endpoint de *pedido* sí admiten `min:0.01`. | `app/Http/Controllers/PointOfSaleController.php:229` vs `app/Http/Requests/Api/V1/Pos/CreateStoreOrderRequest.php:30` y `app/Http/Controllers/Store/PublicStoreController.php:245` |
| E | Multi-tenant | Columna **`subscription_id`** en tablas de catálogo/promos/cuentas bancarias; **`branch_id`** en tablas operativas (ventas, clientes, pivotes de stock). `users.branch_id → branches.subscription_id`. Middleware `EnsureSubscriptionScope`. | `database/migrations/0001_01_01_000000_create_users_table.php:28`; `app/Http/Middleware/EnsureSubscriptionScope.php:26-60`; `app/Traits/HasSubscription.php:25-66` |
| F | Tipo de dato de cantidades | **`decimal(10,3)`** en `transactions_items.quantity`, `branch_product.current_stock` y `branch_product_attribute.current_stock`. Pero el cast Eloquent es **`decimal:2`** (redondea al leer). | `database/migrations/2026_08_22_000003_create_inventory_and_pos_tables.php:175,38,52`; `app/Models/TransactionItem.php:32` |
| G | Dinero | Todo `decimal(N,2)` en BD + cast `decimal:2` en modelos. Cálculos en `float` PHP. Nada de `Money`/enteros de centavos. | `app/Models/Transaction.php:38-41`, `app/Services/TransactionPaymentService.php:139-161` |

---

## 1. Método y alcance

Inspección estática con `read`, `grep`, `glob` y comandos de solo lectura (`php artisan route:list`, `php artisan --version`). No se ejecutaron migraciones, seeds ni escrituras a BD.

Rutas del POS verificadas en caliente con `php artisan route:list --path=pos -v`:

| Método | URI | Nombre | Acción | Middleware efectivo |
|---|---|---|---|---|
| GET | `pos` | `pos.index` | `PointOfSaleController@index` | `web`, `auth`, `can:pos.access` |
| POST | `pos/checkout` | `pos.checkout` | `PointOfSaleController@checkout` | `web`, `auth`, `can:pos.create_sale` |
| POST | `pos/layaway` | `pos.layaway` | `PointOfSaleController@createLayaway` | `web`, `auth` **sin permiso** |
| GET | `pos/pos/customers/search` | `pos.customers.search` | `PointOfSaleController@searchCustomers` | `web`, `auth`, `can:pos.access` |
| GET | `pos/check-entity` | `pos.check-entity` | `PointOfSaleController@checkEntity` | `web`, `auth`, `can:pos.access` |
| GET | `pos/online-orders` | `pos.online-orders` | `PointOfSaleController@getOnlineOrders` | `web`, `auth`, `can:pos.access` |
| PUT | `pos/online-orders/{order}/status` | `pos.online-orders.update-status` | `PointOfSaleController@updateOnlineOrderStatus` | `web`, `auth`, `can:pos.access` |
| POST | `pos/store-order` | `pos.store-order` | **`TransactionController@storeOrder`** (¡no el POS controller!) | `web`, `auth` **sin permiso** |
| POST | `api/v1/pos/checkout` | `api.v1.pos.checkout` | `Api\V1\Pos\PointOfSaleController@checkout` | `api`, `auth:sanctum`, `EnsureApiSubscriptionIsActive` |
| POST | `api/v1/pos/layaway` | `api.v1.pos.layaway` | idem `layaway` | ídem |
| POST | `api/v1/pos/store-order` | `api.v1.pos.store-order` | idem `storeOrder` | ídem |
| POST | `products/update-price-pos` | `products.update-price-pos` | `ProductController@updatePriceFromPOS` | `web`, `auth`, `can:products.edit` |

Definición web en `routes/web/POS.php:17-28`; definición API en `routes/api/v1/pos.php:16-23`; `pos.store-order` está declarado en un archivo distinto (`routes/web/transactions.php:41-42`) y se resuelve contra `TransactionController`.

Detalles a tener en cuenta:

- `pos/pos/customers/search` tiene el prefijo `pos` **duplicado** (`routes/web/POS.php:17` + `:22`).
- `pos.layaway` y `pos.store-order` **no comprueban permiso** a nivel de ruta (solo `auth`), a diferencia de `pos.checkout` (`app/Http/Controllers/PointOfSaleController.php:36-38`).

---

## 2. Controlador del POS

Archivo: `app/Http/Controllers/PointOfSaleController.php` (491 líneas). Es un controlador "gordo": además de orquestar, contiene consultas y armado de payload (contra la convención documentada en `.clinerules/ezyventas-rules.md:7-22`).

### 2.1 Dependencias inyectadas

```php
// app/Http/Controllers/PointOfSaleController.php:41-44
public function __construct(
    protected TransactionPaymentService $transactionPaymentService,
    private readonly ProductCatalogService $productCatalog,
) {}
```

Permisos por middleware de controlador (`HasMiddleware`), no por FormRequest:

```php
// app/Http/Controllers/PointOfSaleController.php:33-39
new Middleware('can:pos.access', only: ['index','searchCustomers','checkEntity','getOnlineOrders','updateOnlineOrderStatus']),
new Middleware('can:pos.create_sale', only: ['checkout']),
```

### 2.2 `index()` — props Inertia

Firma: `public function index(Request $request): Response` (`:46`).

Contexto que arma antes de las props:

1. `$branchId = $user->branch_id` (`:49`); `$isOwner = !$user->roles()->exists()` (`:50`) — el "propietario" se define por **no tener roles**, no por un rol `owner`.
2. `$activeSession`: sesión de caja **OPEN** del usuario, de una caja de su sucursal, con `cashRegister`, `users`, `opener`, `transactions` (+`customer`,`user`), `cashMovements` y `payments.transaction` (`:52-70`).
3. Si no hay sesión activa: `joinableSessions` (sesiones abiertas de la sucursal), `availableCashRegisters` (`is_active` y `in_use=false`) y `userBankAccounts` (del owner vía `branch->bankAccounts()`, o del usuario) (`:72-93`).
4. Totales por método de pago de la sesión activa: `cash/card/transfer/balance` filtrando `status = 'completado'` y agrupando por `payment_method.value` (`:95-107`).
5. Plantillas de impresión de la sucursal (`SALE_TICKET`, `LABEL` × `POS`, `GENERAL`) (`:111-114`).

Props entregadas (`:116-129`):

| Prop | Origen exacto | Línea |
|---|---|---|
| `products` | `$this->getProductsData($search, $categoryId)` → `ProductCatalogService::queryForBranch(...)->cursorPaginate(20)` + `payload()` por producto | `:316-330` |
| `categories` | `Category::getPosCategories($subscriptionId, $branchId)` (incluye pseudo-categoría `Todos` con conteo) | `app/Models/Category.php:25-46` |
| `customers` | `getCustomersData()`: primeros 20 clientes de la sucursal (`id,name,phone,balance,credit_limit`, +`available_credit`) | `:332-348` |
| `defaultCustomer` | `['id'=>null,'name'=>'Público en General', ...]` | `:350-353` |
| `filters` | `$request->only(['search','category'])` | `:121` |
| `activePromotions` | `ProductCatalogService::activePromotions($subscriptionId)` — promociones activas **que no son `ITEM_DISCOUNT`** | `app/Services/Catalog/ProductCatalogService.php:117-128` |
| `activeSession` | sesión abierta + `totals` inyectado | `:52-70`, `:95-107` |
| `joinableSessions` | sesiones abiertas de la sucursal | `:77-80` |
| `availableCashRegisters` | cajas libres de la sucursal | `:82-86` |
| `availableTemplates` | plantillas de la sucursal | `:111-114` |
| `userBankAccounts` | cuentas del owner o del usuario | `:88-92` |
| `hasOnlineStore` | `module_online_store` dentro de `getActiveModuleKeys()` | `:128` |

**No hay prop `branch`/`sucursal`**: la sucursal llega por las props compartidas de Inertia (`auth.current_branch`, `app/Http/Middleware/HandleInertiaRequests.php:50`), y también `auth.user`, `auth.permissions`, `auth.is_subscription_owner`, `auth.active_modules` (`:44-52`).

Vista según dispositivo (`:131-134`): `POS/IndexMobile` si `Agent::isMobile() || isTablet()`, si no `POS/Index`.

### 2.3 `checkout()` — venta de contado/crédito

Firma: `public function checkout(Request $request)` (`:222`). Validación **inline** (no FormRequest), `:224-246`:

| Campo | Regla | Línea |
|---|---|---|
| `cash_register_session_id` | `required\|exists:cash_register_sessions,id` — **no** valida estado abierto ni sucursal | `:225` |
| `cartItems` | `required\|array\|min:1` | `:226` |
| `cartItems.*.id` | `required\|exists:products,id` | `:227` |
| `cartItems.*.product_attribute_id` | `nullable\|exists:product_attributes,id` | `:228` |
| `cartItems.*.quantity` | **`required\|numeric\|min:1`** | `:229` |
| `cartItems.*.unit_price` | `required\|numeric\|min:0` | `:230` |
| `cartItems.*.description` | `required\|string` | `:231` |
| `cartItems.*.discount` | `required\|numeric` (puede ser negativo = "aumento manual") | `:232` |
| `cartItems.*.discount_reason` | `nullable\|string\|max:255` | `:233` |
| `customerId` | `nullable\|exists:customers,id` — **sin filtrar por sucursal** | `:234` |
| `guest_name` | `nullable\|string\|max:255` | `:235` |
| `subtotal` / `total_discount` / `total` | `required\|numeric` (se reciben pero **se recalculan**) | `:236-238` |
| `payments.*.amount` | `min:0.01` | `:240` |
| `payments.*.method` | `Rule::in(PaymentMethod::cases())` = `efectivo,tarjeta,transferencia,saldo,intercambio` | `:241` |
| `payments.*.bank_account_id` | `nullable\|exists:bank_accounts,id` | `:242` |
| `use_balance` | `required\|boolean` | `:244` |
| `layaway_expiration_date` | `nullable\|date` | `:245` |

Flujo (`:248-265`):

1. `$user = Auth::user()`, `$customer = Customer::find($validated['customerId'])` si viene (`:248-249`).
2. `try { $transaction = $this->transactionPaymentService->handleNewSale($validated, $user, $customer, TransactionStatus::PENDING, CustomerBalanceMovementType::CREDIT_SALE); }` (`:251-258`). **Toda la lógica vive en el servicio**; el controlador solo valida y redirige.
3. Respuesta: `redirect()->route('pos.index')->with('success', ...)->with('print_data', ['type'=>'pos','id'=>$transaction->id])` (`:260-262`); en error `redirect()->back()->with('error', ...)` (`:263-265`). Es **Inertia + flash**, no JSON.

`createLayaway()` (`:268-314`) es idéntico pero con `layaway_expiration_date` `required|date|after:today` (`:291`), estado inicial `TransactionStatus::ON_LAYAWAY` y deuda `CustomerBalanceMovementType::LAYAWAY_DEBT` (`:300-306`).

### 2.4 `TransactionPaymentService::handleNewSale()` — el corazón del cobro

Archivo: `app/Services/TransactionPaymentService.php`. Todo ocurre dentro de `DB::transaction` (`:37`).

| Paso | Qué hace | Línea |
|---|---|---|
| 0 | Recalcula totales desde el carrito con `cartTotals()` (ignora `subtotal`/`total` del cliente) | `:45-50` |
| 1 | `Transaction::create([...])` con `folio` de `Transaction::generateFolio($branch_id)`, `channel = POS`, `subtotal`, `total_discount`, **`total_tax = 0`**, `currency = 'MXN'`, `status_changed_at` | `:53-68` |
| 2 | `createTransactionItems()`: crea partidas y mueve stock | `:71` |
| 3 | Saldo a favor: solo si `use_balance` y el cliente tiene `balance > 0`; `min(total, balance)` y pago con método `saldo` | `:76-81`, `:371-380` |
| 4 | Pagos directos saneados con `paymentsWithinBalance()` (el efectivo puede exceder = cambio; tarjeta/transferencia/saldo que exceden lanzan `PaymentExceedsPendingBalanceException`) | `:84-89`, `:496-532` |
| 5 | Si queda `remaining_due > 0.01`: exige cliente y crédito disponible, y `customer->addDebt(...)` | `:92-109` |
| 6 | Si `isFullyPaid()` → `status = COMPLETED`; si era apartado, `finalizeTransactionStock()` | `:112-117` |
| 7 | `annotateSaleKindOnStockMovements()` reescribe la bitácora de stock ("Venta de contado"/"Venta a crédito") | `:120`, `:452-482` |

**Regla de totales (una sola, compartida web + móvil)** — `cartTotals()` (`:139-161`):

```
subtotal       = Σ((unit_price + discount) * quantity)      // discount por unidad; negativo = aumento
total_discount = lo que envía el cliente (ítems + promo de carrito)
total          = subtotal - total_discount + shipping_cost
```
Redondeo a 2 decimales con `round(..., 2)` (`:153-159`). El comentario del propio servicio advierte que el precio de lista de la línea es `unit_price + discount` (`:130-134`).

**Creación de partidas y stock** — `createTransactionItems()` (`:387-433`):

- Resuelve el ítem polimórfico: `itemable_type = ProductAttribute::class` si viene `product_attribute_id`, si no `Product::class` (`:392-394`).
- Reconstruye la descripción desde el catálogo (nombre del producto, o `producto - atributos`) (`:396-404`).
- Crea la fila (`:406-415`):

```php
$transaction->items()->create([
    'itemable_id' => ..., 'itemable_type' => ...,
    'description' => $itemDescription,
    'quantity'    => $item['quantity'],
    'unit_price'  => $item['unit_price'],
    'discount_amount' => $item['discount'] ?? 0,
    'discount_reason' => $item['discount_reason'] ?? null,
    'line_total'  => $item['quantity'] * $item['unit_price'],
]);
```

- Stock (`:417-431`): `reserveStock` para `ON_LAYAWAY`/`TO_DELIVER`, `deductStock` en el resto, delegando en el modelo (`Product::deductStock` → `processStockChange` → `applyDirectStockChange`).

**Impuestos:** no hay motor de impuestos. `total_tax` se crea en `0` (`:64`) y `transactions_items.tax_amount` tiene default `0` (`database/migrations/2026_08_22_000003_create_inventory_and_pos_tables.php:179`). El desglose fiscal real vive en el módulo de facturación (`database/migrations/2026_08_22_000008_create_fiscal_tables.php`), no en la venta.

**Pagos:** `applyDirectPayments()` → `PaymentService::processPayments()` (`app/Services/PaymentService.php:22-49`), que:
- exige que la sesión exista y esté `abierta` **solo si** `$sessionId` viene (`:24-29`);
- crea `payments` con `status = COMPLETED`, `payment_date = now()`, `cash_register_session_id` y `bank_account_id` (`:32-40`);
- incrementa `bank_accounts.balance` para `tarjeta`/`transferencia` (`:42-47`).

**Caja:** la sesión se asocia al `transactions.cash_register_session_id` (`:54`). No hay arqueo por venta; el corte se calcula agregando `payments` de la sesión (`app/Models/CashRegisterSession.php:222-260`).

### 2.5 Endpoints auxiliares del POS

| Método | Comportamiento | Evidencia |
|---|---|---|
| `searchCustomers` | JSON: busca por `name`/`phone` en la sucursal, límite 20, devuelve `balance`, `credit_limit`, `available_credit` como `float` | `PointOfSaleController.php:137-165` |
| `checkEntity` | JSON: detecta si el texto es folio de venta, folio de orden de servicio o cliente; devuelve `{found,type,id,label,message}` | `:167-220` |
| `getOnlineOrders` | JSON paginado (15) de `orders` por `subscription_id`, con conteos por status y links de WhatsApp | `:358-417` |
| `updateOnlineOrderStatus` | Cambia status, restaura stock si se cancela, registra bitácora | `:422-461`, `:466-490` |

### 2.6 API móvil (paralela, no duplicada)

`app/Http/Controllers/Api/V1/Pos/PointOfSaleController.php` reutiliza **los mismos servicios** (`TransactionPaymentService`, `CreateStoreOrderAction`, `TransactionReadService`) y añade lo que el POS web no valida: sesión de caja abierta de la sucursal (`:75-77`, trait en `app/Http/Controllers/Api/V1/Concerns/ResolvesOpenCashRegisterSession.php:19-26`) y permiso vía FormRequest (`app/Http/Requests/Api/V1/Pos/RegisterSaleRequest.php:29-32`). Devuelve JSON con `transaction`, `change`, `print` (`:117-121`).

> Conclusión de diseño: **existen dos entradas de venta** (web Inertia y API JSON) que comparten servicio de dominio. Cualquier lógica nueva de cobro debe ir en `TransactionPaymentService` para no divergir.

---

## 3. Estado del carrito (hallazgo crítico)

### 3.1 El carrito es 100 % frontend

- Estado reactivo en memoria: `const cartItems = ref([])` (`resources/js/Pages/POS/Index.vue:35`; equivalente en `IndexMobile.vue`).
- Persistencia **solo navegador**: clave `pos_data_{userId}_{branchId}_cart` (`Index.vue:62-66`, `:86-88`), más `_client` (`:90-93`) y `_pending` (`:95-98`). Restauración en `onMounted` (`:68-84`).
- Al cobrar, se serializa y se envía completo en el POST (`Index.vue:338-356`, `:404-449`).
- El backend **no** guarda líneas sueltas: no hay tabla `carts`, `cart_items`, `pending_carts` ni similar (`grep` de `cart` en `database/migrations/` → **0 coincidencias**).

### 3.2 "Carritos en espera" (apartados de carrito, no apartados de venta)

Son un `array` en `localStorage`, no una entidad backend:

```js
// resources/js/Pages/POS/Index.vue:258-269
pendingCarts.value.push({
    id: uuidv4(),
    client: selectedClient.value || props.defaultCustomer,
    items: JSON.parse(JSON.stringify(cartItems.value)),
    time: new Date().toLocaleTimeString('es-MX', {...}),
    total: payload.total,
});
```
Reanudar/eliminar: `:270-290`. UI: `resources/js/Pages/POS/Partials/PendingCartsPopover.vue:37-94`.

⚠️ No confundir con el **apartado real** (`pos.layaway`), que sí crea `Transaction` con `status = apartado` y reserva stock (`PointOfSaleController.php:268-314`).

### 3.3 ¿Existe endpoint backend que agregue UNA partida? — **NO**

Búsquedas realizadas y resultado:

| Búsqueda | Resultado |
|---|---|
| `grep "cart\|Cart" database/migrations` | 0 coincidencias |
| `grep "cart\|Cart" routes` | Solo `routes/tienda.php:27-28` (`GET /cart` de la tienda pública) |
| `grep "addToCart\|add-to-cart\|addProduct" resources/js` | Solo handlers/emits de Vue (`Index.vue:133`, `IndexMobile.vue:135`, `ProductCard.vue:132-141`, `ProductDetailModal.vue:162-175`) |
| `php artisan route:list --path=pos` | Solo `index`, `checkout`, `layaway`, `customers/search`, `check-entity`, `online-orders*`, `store-order` |

Por lo tanto, **la "acción que agrega un producto al carrito" hoy no existe en backend**: es la función `addToCart` de Vue.

### 3.4 Firma exacta de la acción frontend (para replicarla en backend)

```js
// resources/js/Pages/POS/Index.vue:133
const addToCart = (data) => {
    const { product, variant } = data;
    const cartItemId = variant ? `prod-${product.id}-variant-${variant.id}` : `prod-${product.id}`;
    const existingItem = cartItems.value.find(item => item.cartItemId === cartItemId);
    const stock = variant ? variant.stock : product.stock;
    const quantityToAdd = 1;                       // :138  → siempre suma 1
    const targetQuantity = existingItem ? existingItem.quantity + quantityToAdd : 1;
    ...
    // recalcula precio (tier/promo) con getPriceForQuantity()  :141-146
    // si existe: valida stock y actualiza quantity/price        :148-167
    // si no:      push de un objeto "producto + metadatos"     :168-197
};
```

Puntos clave:

- Entrada: `{ product, variant }` donde `product` es el payload de `ProductCatalogService::payload()` (`app/Services/Catalog/ProductCatalogService.php:83-112`) y `variant` una combinación de `variant_combinations` (`:270-308`).
- `quantityToAdd` está **hardcodeado a 1** (`Index.vue:138`); no hay input de cantidad en el modal de detalle (`ProductDetailModal.vue:162-175`). La cantidad se ajusta después, en el carrito.
- El precio se decide en el cliente con `getPriceForQuantity()` (`Index.vue:100-131`): escala por `price_tiers` (mayoreo) y usa `product.price` ya promocionado.

### 3.5 Punto de entrada reutilizable **más cercano** (lo que sí existe en backend)

| Candidato | Firma | ¿Sirve para "agregar 1 partida"? |
|---|---|---|
| `TransactionPaymentService::cartTotals(array $cartItems, float $totalDiscount = 0.0, float $shippingCost = 0.0): array` | `app/Services/TransactionPaymentService.php:139` | **Sí, para el dinero**: recalcula `subtotal/total_discount/total` a partir de líneas. Es público y ya lo consumen web y móvil. |
| `TransactionPaymentService::createTransactionItems(Transaction $transaction, array $cartItems, TransactionStatus $status): void` | `:387` | **Sí, pero persiste**: crea `transactions_items` y mueve stock. Requiere una `Transaction` ya existente. |
| `ProductCatalogService::findForBranch(int $productId, int $branchId): ?Product` / `payload()` | `app/Services/Catalog/ProductCatalogService.php:64`, `:83` | **Sí**: resuelve producto de la sucursal y arma precio/stock/variantes. Es la pieza que falta en un endpoint de "add item". |
| `Product::deductStock()/reserveStock()` | `app/Models/Product.php:206-210` | Solo para movimiento de inventario (no aplica al carrito, sí al cobro). |
| `ProcessProductExchange::execute(User, Transaction, array)` | `app/Actions/Transactions/ProcessProductExchange.php:29` | **No**: es post-venta (crea otra `Transaction` y marca `cambiado`). |

**Conclusión operativa:** para "agregar una partida al carrito" hay que **crear** esa capacidad. Lo natural, siguiendo las convenciones del repo, es una `Action` (`app/Actions/Pos/AddCartItemAction.php`) o un servicio de carrito que reutilice `ProductCatalogService::payload()/findForBranch()` para precio+stock y `TransactionPaymentService::cartTotals()` para los totales; y decidir si el carrito se persiste (tabla nueva) o se mantiene en cliente. Hoy no hay ninguna tabla ni endpoint que reutilizar.

---

## 4. Modelo de datos de venta

### 4.1 `transactions`

Modelo: `app/Models/Transaction.php`. Tabla creada en `database/migrations/2026_08_22_000003_create_inventory_and_pos_tables.php:136-166`.

| Columna | Tipo | Nota | Línea migración |
|---|---|---|---|
| `id` | bigint PK | | `:137` |
| `folio` | string | `V-###` por sucursal (`Transaction::generateFolio`, modelo `:124-133`) | `:138` |
| `customer_id` | FK nullable | `nullOnDelete` | `:139` |
| `contact_info` | json | nombre/teléfono/tipo (`pedido`/`comanda`) | `:140` |
| `branch_id` | FK | **tenant operativo** | `:141` |
| `user_id` | FK nullable | cajero | `:142` |
| `cash_register_session_id` | FK nullable | **caja** | `:143` |
| `transactionable_type/id` | morph | origen (p. ej. orden de tienda) | `:144-145` |
| `status` | string | enum `TransactionStatus` | `:146` |
| `delivery_status` | string nullable | `pending,in_transit,delivered,failed` | `:147` |
| `layaway_expiration_date` | date nullable | | `:148` |
| `delivery_date` | datetime nullable | | `:149` |
| `shipping_address` | text nullable | | `:150` |
| `channel` | string | enum `TransactionChannel` (`POS`, tienda, abono…) | `:151` |
| `subtotal` | **decimal(10,2)** | | `:152` |
| `shipping_cost` | decimal(10,2) default 0 | | `:153` |
| `total_discount` | decimal(10,2) default 0 | **total**, no por línea | `:154` |
| `total_tax` | decimal(10,2) default 0 | siempre 0 en POS | `:155` |
| `currency` | char(3) default MXN | | `:156` |
| `notes`, `status_changed_at`, `invoiced` | | | `:157-159` |

Casts (`app/Models/Transaction.php:33-48`): `status => TransactionStatus::class`, `channel => TransactionChannel::class`, `subtotal/shipping_cost/total_discount/total_tax => decimal:2`, `contact_info => array`, `invoiced => boolean`, `layaway_expiration_date => date`, `delivery_date => datetime`.

**No hay columna `total`**: es un accesor calculado y añadido con `$appends` (`:68`):

```php
// app/Models/Transaction.php:70-75
total = (subtotal - total_discount) + total_tax + (shipping_cost ?? 0)
// :78-83  total_paid    = Σ payments.amount
// :87-92  remaining_due = isVoided() ? 0 : max(0, total - total_paid)
// :104-107 isFullyPaid() = remaining_due <= 0.01
```

Relaciones: `transactionable()` morph (`:151`), `customer`, `branch`, `user`, `cashRegisterSession`, `items()` (`TransactionItem`), `payments()`, `invoice()` (`latestOfMany`), `promotions()` (`belongsToMany` con pivote `promotion_transaction.discount_applied`), `customerBalanceMovements()` (`:151-167`).

### 4.2 `transactions_items`

Modelo: `app/Models/TransactionItem.php`. Tabla: `database/migrations/...000003...:169-184`.

| Columna | Tipo | Nota | Línea |
|---|---|---|---|
| `transaction_id` | FK cascade | | `:171` |
| `itemable_type` / `itemable_id` | morph | `Product` o `ProductAttribute` | `:172-173` |
| `description` | string | snapshot del nombre al vender | `:174` |
| `quantity` | **decimal(10,3)** | 3 decimales en BD | `:175` |
| `unit_price` | **decimal(10,2)** | precio ya con descuento aplicado | `:176` |
| `discount_amount` | decimal(10,2) default 0 | **descuento POR UNIDAD** (semántica confirmada en `TransactionPaymentService.php:130-134,150`) | `:177` |
| `discount_reason` | string nullable | `Descuento manual`, `Aumento manual`, `Precio de mayoreo`, `Promoción de producto` (`Index.vue:346-354`) | `:178` |
| `tax_amount` | decimal(10,2) default 0 | sin uso real | `:179` |
| `line_total` | decimal(10,2) | `quantity * unit_price` | `:180` |

Modelo: tabla explícita `transactions_items` (`app/Models/TransactionItem.php:14`); `$fillable` en `:16-27`; casts `quantity/unit_price/discount_amount/tax_amount/line_total => decimal:2` (`:29-38`); `itemable()` morph (`:57-60`).

> ⚠️ **Desajuste de precisión (verificado en runtime)**: la columna es `decimal(10,3)` pero el cast es `decimal:2` (`TransactionItem.php:32`). Laravel aplica el cast **al leer** vía `BigDecimal::toScale(2)` y **no** al escribir (`vendor/laravel/framework/src/Illuminate/Database/Eloquent/Concerns/HasAttributes.php:857-858`, `:1512-1516`; `setAttribute()` en `:1066-1117` no convierte decimales).
> Prueba ejecutada sobre un `TransactionItem` sin guardar (`$i->quantity = 0.001`): `getAttributes()['quantity']` → `float(0.001)`, pero `$i->quantity` → **`string(4) "0.00"`**.
> Consecuencia: una cantidad `0.001` se guarda bien en BD, pero al releerla vale `"0.00"` → devoluciones/cancelaciones (`TransactionCancellationService.php:102-113`) y `finalizeTransactionStock` (`TransactionPaymentService.php:435-445`) repondrían 0.

### 4.3 `payments`

Modelo `app/Models/Payment.php`; tabla en `database/migrations/...000003...:199-212`.

| Columna | Tipo | Línea |
|---|---|---|
| `transaction_id` | FK cascade | `:201` |
| `cash_register_session_id` | FK nullable | `:202` |
| `bank_account_id` | FK nullable | `:203` |
| `amount` | decimal(10,2) | `:204` |
| `payment_method` | string (enum `PaymentMethod`) | `:205` |
| `status` | string (enum `PaymentStatus`) | `:206` |
| `payment_date` | timestamp | `:207` |
| `notes` | text | `:208` |

Casts: `amount => decimal:2`, `payment_method => PaymentMethod::class`, `status => PaymentStatus::class` (`app/Models/Payment.php:26-34`).

Enum de métodos (`app/Enums/PaymentMethod.php:5-12`): `efectivo`, `tarjeta`, `transferencia`, `saldo`, `intercambio`.

### 4.4 Dinero: decimal en BD, float en cálculo

- BD: `decimal(10,2)` en todos los importes de venta/caja; `decimal(15,2)` en `bank_accounts.balance` (`database/migrations/...000003...:29`).
- Modelos: cast `decimal:2`, que devuelve **string** ("150.00").
- Cálculo: el servicio castea a `float` y usa `round(x, 2)` en cada frontera (`TransactionPaymentService.php:139-161`, `:496-532`). Comparaciones con tolerancia `0.01` (`:95`, `:112`, `Transaction.php:106`).
- **No** hay value object de dinero, ni centavos enteros, ni `bcmath`. Riesgo aceptado hoy.

### 4.5 Cómo queda cada concepto en la venta

| Concepto | Dónde se guarda | Fórmula |
|---|---|---|
| Cantidad | `transactions_items.quantity` decimal(10,3) | tal cual la envía el cliente (validada `min:1` en checkout) |
| Precio unitario | `transactions_items.unit_price` decimal(10,2) | precio final de la línea (con promo/tier/edición ya aplicados) |
| Descuento | `transactions_items.discount_amount` (**por unidad**) + `transactions.total_discount` (**total**) | `subtotal = Σ((unit_price+discount)*qty)` |
| Impuesto | `transactions.total_tax` = 0, `transactions_items.tax_amount` = 0 | no existe cálculo |
| Total | **no persistido**; accesor `Transaction::total` | `subtotal - total_discount + total_tax + shipping_cost` |
| Total línea | `transactions_items.line_total` | `quantity * unit_price` |

⚠️ `Σ line_total` **no cuadra** con `Transaction::total` cuando hay una promoción de carrito (BOGO/BUNDLE): esas promos solo viven en `total_discount` (`ShoppingCart.vue:107-163`), no se prorratean a las líneas.

---

## 5. Producto, variantes y "pesable"

### 5.1 `products`

Modelo `app/Models/Product.php`; tabla `database/migrations/2026_08_22_000002_create_catalog_tables.php:61-101`.

| Columna | Tipo | Nota | Línea |
|---|---|---|---|
| `name`, `description` | string/text | | `:63-64` |
| `sku` | string nullable (**no único global**, único por sucursal en validación) | `StoreProductRequest.php:24-31` | `:65` |
| `sat_product_code` / `sat_unit_code` | string(8)/(10) | claves SAT, no unidad operativa | `:66-67` |
| `category_id`, `brand_id`, `branch_id`, `global_product_id`, `provider_id` | FK nullable | `brand` **es FK**, no columna de texto | `:68-72` |
| `selling_price` | **decimal(10,2)** | precio unitario (por `measure_unit`) | `:73` |
| `price_tiers` | **json nullable** | escalones `[{min_quantity, price}]` | `:74` |
| `cost_price` | decimal(10,2) nullable | | `:75` |
| `currency` | char(3) default MXN | | `:76` |
| `measure_unit` | **string nullable** | comentario "ej. pz, kg, lt" | `:77` |
| `show_in_pos` | boolean default true | filtro del POS | `:78` |
| `is_bulk` | **boolean default false** | "producto se vende fraccionado a granel" | `:79` |
| `show_online`, `online_price`, `slug`, `delivery_days`, `tags` | | tienda | `:80-84` |
| `is_featured`, `store_sort_order`, `is_on_sale`, `sale_price`, `sale_start_date`, `sale_end_date` | | | `:85-90` |
| `requires_shipping`, `weight`(g), `length/width/height`(cm) | | logística, **no** unidad de venta | `:91-95` |
| `view_count`, `purchase_count` | | | `:96-97` |

**No existe `current_stock` en `products`**: el stock vive en pivotes por sucursal:

| Tabla | Columnas de stock | Tipo | Línea |
|---|---|---|---|
| `branch_product` | `current_stock`, `reserved_stock`, `min_stock`, `max_stock`, `location` | **decimal(10,3)** | `database/migrations/...000003...:34-45` |
| `branch_product_attribute` | idem por variante | **decimal(10,3)** | `:48-59` |

Modelo: `branches()` con `withPivot(...)` (`app/Models/Product.php:229-235`); `loadStockForBranch()` copia el pivote a atributos dinámicos `current_stock/reserved_stock/available_stock` (`:250-267`). El pivote `BranchProduct` castea esos campos a `decimal:2` (`app/Models/BranchProduct.php:21-26`) — otro punto donde se pierde el tercer decimal.

### 5.2 ¿Existe producto pesable / unidad de medida / precio por kg?

**Sí, versión mínima ya implementada (flag + unidad), sin lógica de negocio en backend.**

| Término buscado | ¿Existe? | Evidencia |
|---|---|---|
| `is_bulk` | **SÍ** — boolean en `products` | migración `:79`; fillable `Product.php:30`; casts no lo castea (queda 0/1); `ProductCatalogService.php:105` |
| `measure_unit` | **SÍ** — string libre (`Pza`, `Kg`, `Grs`, `Lts`, `Mts`, `Cm` en UI) | migración `:77`; `resources/js/Pages/Product/Partials/Inventory.vue:401` |
| `product_type = 'bulk'` | **SÍ** — en creación/edición se traduce a `is_bulk` | `app/Actions/Product/CreateProduct.php:26`; `UpdateProduct.php:27`; UI `Inventory.vue:291` |
| `unit`, `unit_id`, tabla de unidades | **NO** — solo `sat_unit_code` (fiscal) y `measure_unit` (texto) | `grep "unit" database/migrations` → `sat_unit_code`, `measure_unit`, `unit_price` |
| `is_weighted`, `weight_unit`, `allow_decimal`, `decimal_qty`, `pesable`, `granel` (columna) | **NO** | `grep -i "is_weighted\|weight_unit\|allow_decimal\|decimal_qty\|pesable"` en todo el repo → **0 coincidencias** (solo aparece la palabra "granel" en comentarios/UI) |
| `barcode` / `codigo_barras` | **NO** — la tabla no tiene columna de código de barras | `PrintEncoderService.php:475-476` lo dice explícitamente; `{{p.codigo_barras}}` cae a `sku` (`:504`); `grep barcode database/migrations` → 0 |

#### Lo que YA funciona a granel (frontend + read-model)

- El payload del POS expone `is_bulk` y `measure_unit` (`app/Services/Catalog/ProductCatalogService.php:105-106`).
- La tarjeta de carrito trata a granel con mínimo `0.001`, hasta 3 decimales y `step 0.25`:

```js
// resources/js/Pages/POS/Partials/CartItem.vue:31-44
const minQty = props.item.is_bulk ? 0.001 : 1;
const validQuantity = Math.max(minQty, Number(newQuantity) || minQty);
// plantilla: :min="item.is_bulk ? 0.001 : 1"  :maxFractionDigits="item.is_bulk ? 3 : 0"  (:241-243)
// sufijo de unidad: "/ {{ item.measure_unit || 'UD' }}"                                    (:253-254)
```
- El formateo de stock muestra 3 decimales si es granel (`resources/js/Pages/POS/Partials/ProductCard.vue:187`, `:210`, `:227-229`).
- La creación de producto guía al usuario: "ingresa el precio que equivalga a 1 unidad completa; si vendes 0.250 cobrará una cuarta parte" (`resources/js/Pages/Product/Partials/Pricing.vue:136-140`).

#### Dónde se ROMPE hoy el flujo a granel en el POS

| # | Punto | Problema | Evidencia |
|---|---|---|---|
| 1 | Validación de checkout | `cartItems.*.quantity => min:1` rechaza `0.250` | `app/Http/Controllers/PointOfSaleController.php:229` |
| 2 | Validación API checkout/layaway | `min:1` (mismo problema en móvil) | `app/Http/Requests/Api/V1/Pos/RegisterSaleRequest.php:48` |
| 3 | Cast de cantidad | `decimal:2` al leer redondea `0.001` a `0.00` | `app/Models/TransactionItem.php:32` |
| 4 | Cast de stock | `decimal:2` en pivotes, aunque la columna es (10,3) | `app/Models/BranchProduct.php:21-26`; `BranchProductAttribute.php:22` |
| 5 | Tienda en línea → order items | `order_items.quantity` es **integer**; la validación acepta `min:0.01` → se trunca | `database/migrations/2026_08_22_000007...:110` vs `app/Http/Controllers/Store/PublicStoreController.php:245`, `:290` |
| 6 | Stock en tienda | `(int)` sobre `current_stock` | `app/Http/Controllers/Store/PublicStoreController.php:92-93`, `:178` |

> **Sí** existe el concepto (flag + unidad) y **sí** la UI/BD soportan decimales; lo que **no** existe es la aceptación backend en el POS ni la precisión en los casts. El camino de *pedido* (`pos/store-order`, `min:0.01`) demuestra que la arquitectura ya admite cantidades fraccionarias.

### 5.3 Variantes (`product_attributes`)

Tabla `database/migrations/2026_08_22_000002_create_catalog_tables.php:104-112`:

| Columna | Tipo | Línea |
|---|---|---|
| `product_id` | FK cascade | `:106` |
| `attributes` | **json** (`{"Talla":"M","Color":"Rojo"}`) | `:107` |
| `selling_price_modifier` | decimal(10,2) default 0 | `:108` |
| `sku_suffix` | string nullable | `:109` |
| `global_product_id` | FK nullable | `:110` |

Modelo `app/Models/ProductAttribute.php`: `$table = 'product_attributes'` (`:15`), casts `attributes => array`, `selling_price_modifier => decimal:2` (`:25-28`), stock propio por sucursal vía `branch_product_attribute` y **espejo al padre** en cada movimiento (`:48-99`), wrappers `reserveStock/deductStock/restock/...` (`:144-148`), `updatePriceFromTotal()` (`:191-198`).

En la venta, la variante se identifica con `cartItems.*.product_attribute_id` y `itemable_type = ProductAttribute` (`TransactionPaymentService.php:392-394`).

### 5.4 Kits / combos

`product_components` (`database/migrations/...000002...:127-134`): `composite_product_id`, `componentable_type/id`, `quantity` decimal(10,2). Al vender un kit se descuenta el stock de sus componentes multiplicando (`app/Models/Product.php:97-116`).

### 5.5 Precios escalonados (mayoreo)

- Persistencia: `products.price_tiers` **json** (`database/migrations/...000002...:74`), cast `array` (`app/Models/Product.php:38`).
- Contrato del JSON: `[{ "min_quantity": number, "price": number }]`, con `min_quantity >= 2` y `price >= 0.01` (`app/Http/Requests/StoreProductRequest.php:45-56`; `UpdateProductRequest.php:49-60`).
- **Cálculo: solo frontend** (`resources/js/Pages/POS/Index.vue:100-131`; misma lógica en `updateCartQuantity` `:200-227`). El backend únicamente transporta el JSON (`ProductCatalogService.php:100`) y **no** lo aplica al cobrar: confía en el `unit_price` enviado.
- El descuento resultante se manda como `discount` por unidad con razón `Precio de mayoreo` (`Index.vue:345-354`).

---

## 6. Promociones, descuentos y edición de precio

### 6.1 Tablas

| Tabla | Contenido | Evidencia |
|---|---|---|
| `promotions` | `subscription_id`, `name`, `type`, `start_date`, `end_date`, `is_active`, `usage_limit`, `priority`, `is_exclusive` | `database/migrations/2026_08_22_000004...:18-31` |
| `promotion_rules` | `type`, `value` (string), `itemable_*` | `:34-44` |
| `promotion_effects` | `type`, `value` decimal(10,2), `itemable_*` | `:47-57` |
| `promotion_transaction` | pivote `discount_applied` | `:60-66` |

Enums: `app/Enums/PromotionType.php`, `PromotionRuleType.php`, `PromotionEffectType.php`.

### 6.2 Dónde se calcula cada descuento

| Descuento | Dónde se calcula | Línea | ¿Se revalida en backend? |
|---|---|---|---|
| Promoción de producto (`ITEM_DISCOUNT`: fijo, %, precio fijo) | **Backend** (`ProductCatalogService::pricing()`) → se envía como `price`/`original_price` en el payload | `ProductCatalogService.php:135-203` | **No**: el checkout confía en `unit_price` |
| Precio de mayoreo (`price_tiers`) | **Frontend** | `Index.vue:100-131` | No |
| Promos de carrito `BOGO` / `BUNDLE_PRICE` | **Frontend** (`cartLevelDiscounts`) → viajan en `total_discount` | `ShoppingCart.vue:107-156` | No (solo se resta el total) |
| Descuento/aumento manual de línea | **Frontend** (`updateCartPrice`, modal "solo esta venta" / "permanente") | `CartItem.vue:46-94`; `Index.vue:229-236` | No |
| Descuento manual del carrito | Existe el `ref manualDiscount` pero **no está conectado a la UI** (código muerto) | `ShoppingCart.vue:161-162` | — |

> Consecuencia: el backend **no es la fuente de verdad del precio**. Solo garantiza que `total = Σ(unit_price+discount)*qty − total_discount`, por lo que un cliente manipulado puede fijar cualquier `unit_price`. Está documentado como decisión consciente en el propio servicio (`TransactionPaymentService.php:42-44`).

### 6.3 El pivote `promotion_transaction` nunca se escribe

`Transaction::promotions()` (`app/Models/Transaction.php:163-166`) y `Promotion::transactions()` (`app/Models/Promotion.php:73-78`) existen, y `PromotionReportService.php:63` los lee, pero **ningún punto del flujo de venta hace `attach()`** (búsqueda de `promotion_transaction|promotions()->` en todo el repo: solo modelos + migración). Es decir: **el reporte de promociones no tiene datos**.

### 6.4 Permisos implicados

| Permiso | Descripción | Se comprueba en |
|---|---|---|
| `pos.access` | Acceder al POS | `PointOfSaleController.php:36` (middleware `can:`) |
| `pos.create_sale` | Crear ventas | `PointOfSaleController.php:37`; `RegisterSaleRequest.php:31`; `CreateStoreOrderRequest.php:15` |
| `pos.edit_prices` | Editar precios en el carrito | **Solo UI**: `CartItem.vue:117-120` (`hasPermission('pos.edit_prices')`); **no** hay comprobación backend |
| `products.edit` | Editar producto | `ProductController.php:38` (protege `updatePriceFromPOS`) |

Definición de permisos: `database/seeders/PermissionSeeder.php:20-25` (módulo "Punto de Venta").

**No existe** un permiso `pos.apply_discount` ni similar: los descuentos no están autorizados por backend.

### 6.5 Edición de precio permanente desde el POS

- Frontend: `CartItem.vue:72-94` hace `axios.post(route('products.update-price-pos'), {product_id, product_attribute_id, new_price})`.
- Backend: `ProductController::updatePriceFromPOS()` (`app/Http/Controllers/ProductController.php:484-501`): valida y actualiza `products.selling_price` o `ProductAttribute::updatePriceFromTotal()` (`app/Models/ProductAttribute.php:191-198`). Devuelve **JSON** `{success:true}`.
- Permiso: `products.edit` (`ProductController.php:38`).

---

## 7. Inventario y caja

### 7.1 Descuento de stock al cobrar

Cadena completa:

1. `TransactionPaymentService::createTransactionItems()` decide reserva vs baja (`:417-431`).
2. `Product::deductStock($branchId,$qty,$user,$note,$ctx)` (`app/Models/Product.php:207`) → `processStockChange()` (`:97-116`) → si es kit recorre componentes; si no `applyDirectStockChange()`.
3. `applyDirectStockChange()` (`:121-203`) actualiza **con query builder** `branch_product` (`:123-125`) y **no** valida stock disponible: `decrement('current_stock', $quantity)` puede dejar negativos (`:150-153`).
4. Registra bitácora Spatie `stock_update` con `quantity_changed`, `stock_before/after`, `current_*`, `reserved_*`, `available_*` y `transaction_id` (`:179-202`).
5. Variantes: `ProductAttribute::applyDirectStockChange()` (`app/Models/ProductAttribute.php:48-139`) mueve la variante **y** el padre.

Acciones soportadas (`Product.php:145-167`): `reserve` (sube `reserved_stock`), `deduct` (baja `current_stock`), `restock` (sube), `finalize_reserve` (baja reservado y físico), `release_reserve` (baja reservado).

Reversos: cancelación/reembolso devuelve stock (`app/Services/Transactions/TransactionCancellationService.php:102-113`); cancelación de pedido online (`PointOfSaleController.php:466-490`).

### 7.2 Relación con `cash_register_sessions`

- Tabla: `database/migrations/...000003...:81-95` (`cash_register_id`, `user_id`, `opened_at`, `closed_at`, `status`, `opening_cash_balance`, `opening_bank_balances` json, `closing_cash_balance`, `calculated_cash_total`, `cash_difference`, `notes`).
- Estado: enum `abierta`/`cerrada` (`app/Enums/CashRegisterSessionStatus.php:5-8`).
- Vínculos: `transactions.cash_register_session_id` (`:143`), `payments.cash_register_session_id` (`:202`), pivote `cash_register_session_user` (cajeros que comparten caja, `:98-103`).
- Arqueo: `CashRegisterSession::closeSession()` (`app/Models/CashRegisterSession.php:222-260`) → `calculado = opening_cash + ventas efectivo + ingresos − egresos`, diferencia contra el contado, concilia bancos y cierra caja (`cash_registers.in_use = false`, `:259`).
- En el POS web: el `cash_register_session_id` lo pone el frontend desde `props.activeSession.id` (`resources/js/Pages/POS/Index.vue:415`) y el backend **solo** valida `exists` (`PointOfSaleController.php:225`). El chequeo de "abierta y de mi sucursal" **solo existe en la API móvil** (`ResolvesOpenCashRegisterSession.php:19-26`) y, parcialmente, en `PaymentService` cuando hay pagos (`PaymentService.php:24-29`).

### 7.3 Dónde habría que intervenir para partidas por peso

| Capa | Archivo / campo | Acción necesaria |
|---|---|---|
| Validación | `PointOfSaleController.php:229` (`cartItems.*.quantity`) y `RegisterSaleRequest.php:48` | `min:1` → regla condicional por `is_bulk` (`min:0.001` + `decimal:0,3`). El patrón ya existe en `CreateStoreOrderRequest.php:30` (`min:0.01`). |
| Precisión de lectura | `TransactionItem.php:32` (`quantity => decimal:2`) | `decimal:3` (la columna ya es `decimal(10,3)`). |
| Precisión de stock | `BranchProduct.php:22-25`, `BranchProductAttribute.php:22` | `decimal:3` (columnas ya son `decimal(10,3)`). |
| Precio por kg | `ProductCatalogService::pricing()` / `cartTotals()` | Nada que cambiar si `selling_price` es "precio por 1 `measure_unit`": `qty * unit_price` ya da el importe correcto. |
| UI de cantidad | `CartItem.vue:31-44`, `:241-243` | **Ya implementado** (mínimo 0.001, 3 decimales, step 0.25). |
| Etiqueta de unidad | `CartItem.vue:215-216`, `:253-254` | **Ya implementado** (`/ {{ measure_unit }}`). |
| Ticket/impresión | `PrintEncoderService` / plantillas | Revisar formato de cantidad a 3 decimales. |
| Tienda en línea | `order_items.quantity` (integer), `PublicStoreController.php:92` | Migrar a decimal y quitar `(int)`. |

---

## 8. Migraciones y seeds relevantes

### 8.1 Migraciones (todas las del repo, con foco POS/ventas/productos)

| Archivo | Contenido POS/ventas/productos |
|---|---|
| `2026_08_22_000001_create_core_platform_tables.php` | Spatie (permissions/roles/pivotes), `personal_access_tokens`, `media`, `activity_log`, `setting_definitions/values`, `plan_items`, `onboarding_tours`, `waitlists` |
| **`2026_08_22_000002_create_catalog_tables.php`** | `brands`, `categories`, `global_products`, `providers`, **`products`** (`:61-101`), **`product_attributes`** (`:104-112`), `product_reviews`, `product_components` (`:127-134`), `services`, `service_variants` |
| **`2026_08_22_000003_create_inventory_and_pos_tables.php`** | `bank_accounts`, **`branch_product`** (`:34-45`), **`branch_product_attribute`** (`:48-59`), `branch_service`, **`cash_registers`** (`:71-78`), **`cash_register_sessions`** (`:81-95`), `cash_register_session_user` (`:98-103`), `session_cash_movements` (`:106-114`), `customers` (`:117-133`), **`transactions`** (`:136-166`), **`transactions_items`** (`:169-184`), `customer_balance_movements` (`:187-196`), **`payments`** (`:199-212`), bancos |
| **`2026_08_22_000004_create_promotions_quotes_services_tables.php`** | `promotions`, `promotion_rules`, `promotion_effects`, **`promotion_transaction`** (`:60-66`), `quotes`, `quote_items`, servicios |
| `2026_08_22_000006_create_subscription_and_referral_tables.php` | planes/suscripciones (con `unit_price` de ítems de plan) |
| `2026_08_22_000007_create_storefront_and_releases_tables.php` | `store_configs`, **`orders`** (`:80-101`), **`order_items`** (`:104-113`, `quantity` integer), `order_status_logs`, `release_notes` |
| `2026_08_22_000008_create_fiscal_tables.php` | facturación (incluye `unit_price` decimal(12,4)/(10,4) — el único lugar con 4 decimales) |
| `2026_08_28_120000_add_closing_bank_balances_to_cash_register_sessions_table.php` | `closing_bank_balances` en sesiones |
| **`2026_09_12_000001_sync_parent_stock_with_variants.php`** | Reparación de datos: recalcula `branch_product` como suma de variantes (`:14-38`) |

> El esquema está **consolidado**: no hay una migración por tabla, sino 10 archivos "paraguas" `2026_08_22_000001` … `000010` (los dos últimos: `000009_create_suggestions_table.php` y `000010_drop_provider_model_from_ai_conversations.php`).

### 8.2 Seeds

| Seeder | Rol |
|---|---|
| **`database/seeders/PermissionSeeder.php`** | Crea **todos** los permisos Spatie por módulo; POS en `:20-25` (`pos.access`, `pos.create_sale`, `pos.edit_prices`). Borra y recrea permisos (`:17`). |
| `database/seeders/DatabaseSeeder.php` | Orquestador: llama a `BusinessTypeSeeder`, `SettingDefinitionSeeder`, **`PermissionSeeder`**, `PlanItemSeeder` y `AiAgentSettingsSeeder` (`:42-47`) y luego genera datos demo |
| `database/seeders/PlanItemSeeder.php` | Ítems de planes/módulos |
| `database/seeders/BusinessTypeSeeder.php` | Giros de negocio |
| `database/seeders/ProductSeeder.php` | Productos demo |
| `database/seeders/SettingDefinitionSeeder.php` | Definiciones de settings |
| `database/seeders/StampPricingTierSeeder.php` | Precios de timbres |
| `database/seeders/AiAgentSettingsSeeder.php` | Config del agente IA |

**No hay seed específico de roles del POS**; los roles se crean por sucursal (`app/Models/Role.php`) y el "propietario" se detecta por ausencia de roles (`PointOfSaleController.php:50`, `HandleInertiaRequests.php:27`).

---

## 9. Convenciones backend observadas

### 9.1 Capas (lo declarado vs lo real)

Lo declarado en `.clinerules/ezyventas-rules.md:7-22`:

```
Request → Controller (thin) → Action (un caso de uso) → Service (lógica reusable) → Model (dominio)
                          ↘ FormRequest (toda la validación)
```

Lo que realmente hace el POS:

| Capa | Estado en el módulo POS |
|---|---|
| Controller | `PointOfSaleController` es **gordo**: valida inline, consulta (`getProductsData`, `getCustomersData`), arma props y hasta restaura stock (`:466-490`). |
| Action | Existe `app/Actions/Pos/CreateStoreOrderAction.php` (pedido) — única action del módulo POS. `execute(array $data, User $user): Transaction` (`:23`). |
| Service | `app/Services/TransactionPaymentService.php` (orquestador real), `app/Services/PaymentService.php`, `app/Services/Catalog/ProductCatalogService.php`, `app/Services/Transactions/*`, `app/Services/CashRegisters/*`. |
| Model | Modelos ricos: stock, saldo de cliente, folios, totales calculados (`Product`, `Customer`, `Transaction`, `CashRegisterSession`). |
| FormRequest | **Solo en la API móvil** (`app/Http/Requests/Api/V1/Pos/*`). El POS web usa `$request->validate()` inline (`PointOfSaleController.php:224`, `:270`; `TransactionController.php:569`), en contra de la regla `.clinerules/ezyventas-rules.md:87`. |

### 9.2 Estructura de carpetas

- `app/Services/{Modulo}/...` — p. ej. `Catalog/ProductCatalogService.php`, `Transactions/TransactionCancellationService.php`, `CashRegisters/CashRegisterSessionLifecycleService.php`, `Dashboard/DashboardMetricsService.php`.
- `app/Actions/{Modulo}/{Verbo}{Entidad}Action.php` — p. ej. `Pos/CreateStoreOrderAction.php`, `Transactions/ProcessProductExchange.php`, `Product/CreateProduct.php` (estos últimos **sin** sufijo `Action`).
- `app/Traits/` — `HasSubscription.php`, `OptimizeMediaLocal.php`, `OptimizeMediaWithTinify.php`, y el trait-concern de controlador `app/Http/Controllers/Api/V1/Concerns/ResolvesOpenCashRegisterSession.php`.
- `app/Enums/` — un enum por concepto (`TransactionStatus`, `PaymentMethod`, `CashRegisterSessionStatus`…).

### 9.3 Registro de rutas

- `routes/web.php` es un índice de `require __DIR__ . '/web/{modulo}.php'` (`routes/web.php:40-89`). El POS se carga con `require __DIR__ . '/web/POS.php';` (`:45`) — nota: el archivo **no** es kebab-case, contra la convención `.clinerules/ezyventas-rules.md:117`.
- `routes/api.php` hace lo propio con `routes/api/v1/*.php` (`:29-46`) bajo `auth:sanctum` + `EnsureApiSubscriptionIsActive`.
- Patrón de grupo: `Route::middleware('auth')->prefix('pos')->as('pos.')->group(...)` (`routes/web/POS.php:17`).

### 9.4 Validación

- Web: `$request->validate([...])` inline (POS, `TransactionController`), y `StoreProductRequest`/`UpdateProductRequest` para productos.
- API: FormRequest con `authorize()` basado en Spatie y `messages()`/`attributes()` en español (`app/Http/Requests/Api/V1/Pos/RegisterSaleRequest.php:29-91`).
- Hay una clase base para permisos múltiples: `app/Http/Requests/Api/V1/Concerns/AuthorizesAnyPermission.php`.

### 9.5 Respuestas

| Contexto | Tipo | Ejemplo |
|---|---|---|
| Vistas POS / acciones de formulario | **Inertia**: `Inertia::render($view, $props)` y `redirect()->route(...)->with('success'\|'error', ...)` | `PointOfSaleController.php:134`, `:260-265` |
| Datos para modales / autocompletado | **JSON** (`response()->json`) | `PointOfSaleController.php:143-164`, `:379-416`; `ProductController.php:500` |
| API móvil | **JSON** con códigos 201/422 y `code` de error | `Api/V1/Pos/PointOfSaleController.php:59-62`, `:109-115` |
| Errores globales | Renderer Inertia `Error` (web) y `ApiExceptionRenderer` (api) | `bootstrap/app.php:39-76` |

### 9.6 Middleware del POS

Grupo `web` (`bootstrap/app.php:26-32`) + `auth` + `can:`:

- Globales web: `HandleInertiaRequests`, `AddLinkHeadersForPreloadedAssets`, `CheckOnboardingStatus`, `CheckSubscriptionStatus`, `EnsureSubscriptionScope`.
- `auth` en el archivo de rutas (`routes/web/POS.php:17`).
- `can:pos.access` / `can:pos.create_sale` por controlador (`PointOfSaleController.php:33-39`).
- `EnsureSubscriptionScope` protege **solo parámetros de ruta con model binding** que usen `HasSubscription` (`app/Http/Middleware/EnsureSubscriptionScope.php:40-60`): no protege los `id` que vienen en el body (`customerId`, `cash_register_session_id`, `cartItems.*.id`).

### 9.7 Multi-tenant observado

| Nivel | Columna | Dónde |
|---|---|---|
| Tenant raíz | `subscriptions.id` | `database/migrations/0001_01_00_000000_create_subscriptions_table.php` |
| Sucursal | `branches.subscription_id` | `0001_01_00_000001_create_branches_table.php` |
| Usuario | `users.branch_id` (FK obligatoria) | `0001_01_01_000000_create_users_table.php:28` |
| Catálogo | `brands.subscription_id`, `categories.subscription_id`, `providers.subscription_id`, `promotions.subscription_id`, `bank_accounts.subscription_id`, `orders.subscription_id` (`global_products` **no** tiene columna de tenant: es catálogo base global, `2026_08_22_000002...:35-46`) | migraciones 000002/000003/000004/000007 |
| Operación | `transactions.branch_id`, `customers.branch_id`, `products.branch_id`, `branch_product.branch_id` | 000002/000003 |

Resolución del tenant: siempre **derivada del usuario autenticado** (`$user->branch_id`, `$user->branch->subscription_id`), nunca de un `tenant_id` en la petición. Helper transversal: `app/Traits/HasSubscription.php` (`getSubscriptionId()` con fallback a `branch.subscription_id`, `:25-48`; `scopeBySubscription`, `:53-66`).

> **Riesgo detectado**: `checkout` acepta `cash_register_session_id` y `customerId` con `exists` simple (`PointOfSaleController.php:225`, `:234`), y `cartItems.*.id` con `exists:products,id` (`:227`) — sin filtrar por sucursal/suscripción. La API móvil sí lo hace (`RegisterSaleRequest.php:39-43`).

---

## 10. Riesgos y recomendaciones para "Caja inteligente"

| # | Hallazgo | Impacto | Recomendación |
|---|---|---|---|
| 1 | No existe backend de carrito ni endpoint de "agregar 1 partida" | Hay que **crear** la capacidad | Nueva `Action` + (opcional) tabla `pos_carts/pos_cart_items` con `branch_id`, `user_id`, `status`; reutilizar `ProductCatalogService` + `cartTotals()` |
| 2 | `pos.edit_prices` solo se valida en Vue | Cualquiera puede enviar `unit_price` arbitrario | Validar precio contra catálogo en backend o registrar `discount_reason` obligatorio + permiso |
| 3 | `quantity min:1` en checkout vs UI/BD que admiten 3 decimales | Granel inoperante en POS | Regla condicional por `is_bulk`; `decimal:3` en casts |
| 4 | Casts `decimal:2` sobre columnas `(10,3)` | Pérdida silenciosa de precisión en relecturas (devoluciones, stock) | Cambiar casts a `decimal:3` en `TransactionItem`, `BranchProduct`, `BranchProductAttribute` |
| 5 | `promotion_transaction` nunca se escribe | Reportes de promoción vacíos, sin trazabilidad de descuentos | Persistir `attach()` al cobrar (con `discount_applied`) |
| 6 | `total_tax`/`tax_amount` siempre 0 | No se puede auditar IVA en venta | Definir si el POS calculará impuestos o los delega a facturación |
| 7 | Sesión de caja no validada en web | Se puede cobrar contra una caja cerrada o de otra sucursal | Reutilizar `ResolvesOpenCashRegisterSession` en el controlador web |
| 8 | `pos.layaway` y `pos.store-order` sin permiso | Escalada de privilegios dentro del negocio | Añadir `can:pos.create_sale` |
| 9 | `customerId` sin filtro de sucursal | Fuga entre sucursales del mismo tenant | Usar `Rule::exists(...)->where('branch_id', ...)` como en la API |
| 10 | `line_total` no reconcilia con `total` si hay promo de carrito | Cuadres y reportes inconsistentes | Prorratear promo de carrito a líneas o documentar el criterio |

---

## 11. Anexo — comandos de verificación usados

```powershell
php artisan --version                       # Laravel Framework 12.63.0
php artisan route:list --name=pos           # 13 rutas (web + api)
php artisan route:list --path=pos -v        # middleware efectivo por ruta
```

Búsquedas negativas (evidencia de "no existe"):

```powershell
# Carrito persistido
Get-ChildItem -Recurse database\migrations | Select-String -Pattern "cart"
#   → 0 coincidencias

# Endpoint de agregar partida
Select-String -Path routes\**\*.php -Pattern "cart"
#   → solo routes/tienda.php:27-28 (GET /cart de la tienda pública)

# Código de barras
Select-String -Path database\**\*.php -Pattern "barcode"
#   → solo seeders de iconos; la tabla products NO tiene columna
#   (confirmado por app/Services/PrintEncoderService.php:475-476)

# Pesable / decimales
Select-String -Recurse -Pattern "is_weighted|weight_unit|allow_decimal|decimal_qty|pesable"
#   → 0 coincidencias; sí existe is_bulk / measure_unit
```
