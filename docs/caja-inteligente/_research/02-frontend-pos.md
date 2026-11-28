# 02 — Frontend del módulo Punto de venta (POS)

> **Alcance:** solo lectura sobre el repositorio `Ezyventas2` (Laravel 12 + Inertia + Vue 3 + PrimeVue 4 + Tailwind 3). Todas las referencias son `archivo:línea` del estado actual del repo. No se ejecutó build, dev server ni se modificó código.
>
> **Objetivo del documento:** servir de base para añadir una **caja inteligente con detección por cámara** sin romper el flujo actual del carrito.

---

## 0. Resumen ejecutivo (lo que hay que saber antes de tocar nada)

| Pregunta | Respuesta corta | Evidencia |
|---|---|---|
| ¿Dónde vive el carrito? | `const cartItems = ref([])` **dentro de cada página** (`Index.vue` y `IndexMobile.vue`). No hay Pinia, ni store, ni `provide/inject`, ni composable. | `resources/js/Pages/POS/Index.vue:35`, `resources/js/Pages/POS/IndexMobile.vue:35` |
| ¿Función que agrega al carrito? | `addToCart(data)` con `data = { product, variant? }`. Está **duplicada** en las dos páginas. | `Index.vue:133`, `IndexMobile.vue:135` |
| ¿Es reutilizable/importable? | **No.** Es una función local de `<script setup>`, no se exporta. Solo se puede invocar por el evento `addToCart` que las páginas ya cablean. | `Index.vue:472`, `PosLeftPanel.vue:28`, `PosLeftPanel.vue:405` |
| ¿Cómo se agrega hoy por código de barras? | **No se agrega.** El escáner funciona como teclado: llena la búsqueda y el cajero debe hacer clic en la tarjeta. No existe `addToCart` por escaneo ni API de cámara. | `PosLeftPanel.vue:167-218` |
| ¿Checkout? | `useForm(...).post(route('pos.checkout'))` (Inertia), sin axios. | `Index.vue:437`, `IndexMobile.vue:449` |
| ¿Notificaciones? | PrimeVue **Toast** (`useToast()`), renderizado global en `AppLayout`. | `AppLayout.vue:231`, `app.js:65` |
| ¿Traducciones frontend? | **No existe i18n en el frontend.** Español hardcodeado en las plantillas. | `package.json` (sin `vue-i18n`), `lang/*` es solo backend |
| ¿Permisos? | Composable `usePermissions().hasPermission('x')` sobre `usePage().props.auth.permissions`. | `Composables/usePermissions.js:3-10` |
| ¿Tokens clave? | Primario `#F68C0F`, radios Tailwind `rounded-2xl/3xl` en tarjetas, `rounded-xl` en botones, fuente Figtree. | `tailwind.config.js:29-32`, `presets/ezyventas.js:12` |

---

## 1. Vistas del POS y estructura de la página

### 1.1 Inventario real de archivos

| Archivo | Líneas | Rol |
|---|---|---|
| `resources/js/Pages/POS/Index.vue` | 564 | Página **escritorio**: dos paneles lado a lado (productos 2/3, carrito 1/3). |
| `resources/js/Pages/POS/IndexMobile.vue` | 618 | Página **móvil/tablet**: panel de productos a pantalla completa + FAB + `Drawer` con el carrito. |
| `resources/js/Pages/POS/Partials/PosLeftPanel.vue` | 505 | Panel izquierdo: header, barra de acciones, buscador, filtros de categoría, grid de productos, modales hijos. |
| `resources/js/Pages/POS/Partials/ShoppingCart.vue` | 461 | Panel derecho: cliente/comanda, lista de líneas, totales, botones Pedido/Cobrar, `PaymentModal`. |
| `resources/js/Pages/POS/Partials/CartItem.vue` | 337 | Una línea del carrito: precio editable, cantidad, descuento por línea, eliminar. |
| `resources/js/Pages/POS/Partials/ProductCard.vue` | 317 | Tarjeta de producto con variantes, badge de stock, badge "en carrito", popover de promos. |
| `resources/js/Pages/POS/Partials/ProductDetailModal.vue` | 388 | `Dialog` de detalle: galería, variantes, precio/stock, botón "Añadir al carrito". |
| `resources/js/Pages/POS/Partials/CategoryFilters.vue` | 92 | Carrusel horizontal de categorías con drag-scroll. |
| `resources/js/Pages/POS/Partials/OrderFormModal.vue` | 218 | `Dialog` para crear pedido (contacto, entrega, envío, notas). |
| `resources/js/Pages/POS/Partials/PendingCartsPopover.vue` | 94 | Popover de carritos en espera (reanudar/eliminar). |
| `resources/js/Pages/POS/Partials/OnlineOrdersModal.vue` | 274 | `Dialog` de pedidos de tienda en línea (solo si `hasOnlineStore`). |

### 1.2 Layout

Ambas páginas envuelven todo en `AppLayout` (`Index.vue:5` y `:463`; `IndexMobile.vue:5` y `:474`), que aporta topbar, sidebar, `Toast` y `ConfirmDialog` globales (`Layouts/AppLayout.vue:197-232`).

**Escritorio** (`Index.vue:466-491`):

```
<div class="flex flex-col lg:flex-row gap-4 h-[calc(100vh-98px)] max-w-[1920px] mx-auto">
  <div class="lg:w-2/3 xl:w-3/4 h-full ...">   <PosLeftPanel ... />  </div>
  <div class="lg:w-1/3 xl:w-1/4 h-full ...">   <ShoppingCart ... />  </div>
</div>
```

- `PosLeftPanel` recibe `products, categories, pendingCarts, filters, activeSession, cartItems, posMode, hasOnlineStore` (`Index.vue:469-477`).
- `ShoppingCart` recibe `items, client, customers, defaultCustomer, activePromotions, loading, paymentModalVisible, posMode` (`Index.vue:480-488`).

**Móvil** (`IndexMobile.vue:475-536`):

- `PosLeftPanel` ocupa todo (`:479-486`).
- FAB flotante con badge de unidades (`:489-500`).
- `Drawer` de PrimeVue `position="right"` con `!w-full md:!w-[450px]`, título "Resumen de venta", contiene el mismo `ShoppingCart` (`:503-536`).

### 1.3 ¿Cómo se decide `Index` vs `IndexMobile`?

**En el backend**, por user-agent, no por CSS ni por media query:

```php
$agent = new Agent();                                    // PointOfSaleController.php:131
$view = ($agent->isMobile() || $agent->isTablet())
    ? 'POS/IndexMobile' : 'POS/Index';                   // PointOfSaleController.php:132
return Inertia::render($view, $props);                   // PointOfSaleController.php:134
```

`Agent` es `Laravel\Jetstream\Agent` (`PointOfSaleController.php:29`). Consecuencia importante: **el POS de escritorio y el móvil son dos copias independientes de la lógica**, incluido el carrito (ver §2).

### 1.4 Estado sin sesión de caja

Si `activeSession` es `null`, ambas páginas muestran una tarjeta central ("Terminal bloqueada" / "Abrir sesión de caja") y **no renderizan el POS** (`Index.vue:493-546`; `IndexMobile.vue:540-593`). Modales: `StartSessionModal`, `JoinSessionModal`, `CloseSessionModal`, `SessionHistoryModal`, `PrintModal` (`Index.vue:549-558`).

### 1.5 ¿Dónde está la barra de acciones para un botón nuevo?

Hay **tres** ubicaciones candidatas, en orden de idoneidad:

| # | Ubicación | Evidencia | Cuándo usarla |
|---|---|---|---|
| 1 | **Barra de acciones del panel de productos** (header). Es una fila `flex items-center gap-2` con botones `rounded` de `!w-10 !h-10`: tienda online, modo retail/comandas, teclado virtual, agregar producto, resumen de sesión, historial, carritos en espera, cerrar caja. | `PosLeftPanel.vue:300-368` (contenedor `:301`) | **Recomendado** para "Escanear con cámara": aparece igual en escritorio y móvil porque `PosLeftPanel` se usa en ambas páginas. |
| 2 | **FAB móvil**, junto al botón flotante del carrito. | `IndexMobile.vue:489-500` | Si se quiere un botón flotante solo en móvil. |
| 3 | **Header del carrito** (`pi-bookmark` / `pi-trash`) y **footer de CTAs** (`Pedido` / `Cobrar`). | `ShoppingCart.vue:202-211`, `:374-391` | Si la acción pertenece al carrito y no al catálogo. |

Patrón visual exacto de un botón de la barra (copiar para que combine):

```html
<Button icon="pi pi-plus" rounded v-tooltip.bottom="'Agregar producto'"
    class="!w-10 !h-10 !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] !text-gray-600 dark:!text-gray-300 hover:!border-primary-500 transition-colors" />
```
(`PosLeftPanel.vue:319-321`)

Además, `PosLeftPanel` ya declara el evento `addToCart` en su lista de `emits` (`PosLeftPanel.vue:28`), por lo que **un componente hijo nuevo puede emitirlo y la página lo ejecutará sin tocar nada más** (ver §2.7).

---

## 2. ESTADO DEL CARRITO (sección crítica)

### 2.1 ¿Dónde se declara el carrito?

**No hay store.** Búsqueda de `pinia`, `provide(`, `inject(`, `useCart` en `resources/js/Pages/POS/**` → **0 resultados**. `package.json` no incluye Pinia.

Cada página declara su propio estado:

| Estado | Declaración | Evidencia |
|---|---|---|
| `cartItems` | `const cartItems = ref([])` | `Index.vue:35`, `IndexMobile.vue:35` |
| `selectedClient` | `const selectedClient = ref(null)` | `Index.vue:36`, `IndexMobile.vue:36` |
| `isPaymentModalVisible` | `ref(false)` | `Index.vue:37`, `IndexMobile.vue:37` |
| `isOrderModalVisible` | `ref(false)` | `Index.vue:38`, `IndexMobile.vue:38` |
| `pendingCarts` | `ref([])` | `Index.vue:95`, `IndexMobile.vue:95` |
| `posMode` | `ref(localStorage.getItem('pos_mode') \|\| 'retail')` | `Index.vue:41`, `IndexMobile.vue:51` |
| Formulario de checkout | `useForm({ cartItems, customerId, subtotal, ... })` | `Index.vue:323-336`, `IndexMobile.vue:331-344` |

**Persistencia**: `localStorage` con clave por usuario+sucursal `pos_data_${u.id}_${u.branch_id}` y sufijos `_cart`, `_client`, `_pending` (`Index.vue:63-98`). Se restaura en `onMounted` (`:68-84`) y se guarda con `watch(..., { deep: true })` (`:86-98`). *No* se persiste en servidor.

Único `provide` del layout: `provide('activeSession', ...)` (`AppLayout.vue:48`) — no tiene relación con el carrito.

### 2.2 Función que AGREGA UN PRODUCTO AL CARRITO

| Dato | Valor |
|---|---|
| Nombre | `addToCart` |
| Archivo / línea | `resources/js/Pages/POS/Index.vue:133` (desktop) y `resources/js/Pages/POS/IndexMobile.vue:135` (móvil, copia idéntica) |
| Firma exacta | `const addToCart = (data) => { const { product, variant } = data; ... }` |
| Firma efectiva | `addToCart({ product: ProductPayload, variant?: VariantCombination })` → `void` |
| Retorno | `undefined` (muta `cartItems.value`; no devuelve la línea creada) |

Contrato de `product` (payload canónico del backend, `app/Services/Catalog/ProductCatalogService.php:83-112`): `id`, `name`, `sku`, `description`, `category` (string), `image`, `general_images`, `selling_price`, `price`, `original_price`, `price_tiers`, `stock`, `reserved_stock`, `show_in_pos`, `is_bulk`, `measure_unit`, `promotions`, `variants`, `variant_combinations`, `components`.

Contrato de `variant` (una combinación, `ProductCatalogService.php:298-306`): `id`, `attributes`, `price_modifier`, `price`, `stock`, `reserved_stock`, `sku_suffix`, `image_url`.

**Qué hace, paso a paso** (`Index.vue:133-198`):

1. **Clave de merge**: `cartItemId = variant ? 'prod-{id}-variant-{variantId}' : 'prod-{id}'` (`:135`). Busca línea existente por esa clave (`:136`).
2. **Stock de referencia**: `variant ? variant.stock : product.stock` (`:137`).
3. **Cantidad**: siempre suma **1** (`quantityToAdd = 1`, `:138`); `targetQuantity = existingItem ? existingItem.quantity + 1 : 1` (`:139`).
4. **Precio por volumen**: llama `getPriceForQuantity({selling_price, price, price_tiers}, targetQuantity)` (`:141-146`).
5. **Si la línea ya existe** (`:148-167`):
   - Solo incrementa si `targetQuantity <= stock || stock < 0` (`:149`); si no, **toast warn "Stock insuficiente"** y no cambia nada (`:166`).
   - Recalcula precio **salvo** que `isManualPrice === true` (`:151`): usa `price_qty_1_promo` como base + tiers, y suma `variantModifier = (original_price ?? selling_price) - selling_price` si es variante (`:152-163`).
6. **Si es nueva** (`:169-197`): construye la línea con `...product` + campos de carrito (`:170-188`), aplica `variant.price_modifier` al precio y al `original_price` (`:180-181`), cambia `sku` a `"{sku}-{sku_suffix}"`, `stock`, `selectedVariant`, `product_attribute_id` e `image` de la variante (`:182-186`).
7. **Push** a `cartItems.value` (`:193`).
8. **Validación de stock al crear**: si `stock <= 0` **igual agrega** y muestra toast warn "Sin stock" (`:194-196`). Es decir, **no bloquea** la venta sin stock; solo avisa.

> ⚠️ La rama `stock < 0` de la línea `:149` es prácticamente inalcanzable con datos del backend porque `payload()` fuerza `max(0, ...)` en `stock` (`ProductCatalogService.php:102`). Solo se dispara con objetos fabricados a mano (p. ej. `handleProductCreatedAndAddToCart`, `Index.vue:301-320`).

### 2.3 ¿Clic en producto y código de barras usan la misma función?

**Sí para el clic. No existe agregado por escaneo.**

| Vía | Qué ocurre | Evidencia |
|---|---|---|
| **Clic en tarjeta** | `ProductCard.handlePrimaryAction()` → `emit('addToCart', { product, variant? })` → `PosLeftPanel` re-emite → `Index.addToCart` | `ProductCard.vue:132-142`, `PosLeftPanel.vue:403-405`, `Index.vue:472` |
| **Clic en "Añadir al carrito" del modal** | `ProductDetailModal.addProductToCart()` → `emit('addToCart', { product, variant })` → `PosLeftPanel.vue:423-424` → página | `ProductDetailModal.vue:162-175` |
| **Escáner de código de barras (lector físico)** | **No agrega nada.** Se captura el teclado en un buffer de 200 ms; al `Enter` intenta detectar entidad (folio/teléfono/cliente) y, si no, **escribe el código en el buscador**. El usuario debe hacer clic en la tarjeta resultante. | `PosLeftPanel.vue:167-218` |
| **Cámara** | **No existe** ningún uso de `getUserMedia`, `BarcodeDetector`, `html5-qrcode` ni similares en todo `resources/js`. | grep sin resultados |
| **Crear producto y agregarlo** | `handleProductCreatedAndAddToCart` fabrica un `formattedProduct` y llama `addToCart({ product: formattedProduct })` | `Index.vue:301-320` |

Detalle del flujo de escaneo (`PosLeftPanel.vue:171-208`):

- Ignora el buffer si hay un input distinto al buscador enfocado o un `.p-dialog-mask` visible (`:173-179`).
- `barcodeBuffer` acumula teclas; se reinicia a los **200 ms** (`:204-207`).
- `Enter` con buffer > 2: `sanitizeInput` → `checkAndRedirect` (`:184-186`); si no hubo entidad, `searchTerm.value = cleanQuery` (`:187`).
- `watch(searchTerm, debounce(applyFilters, 300))` (`:109`) → `router.get(route('pos.index'), {search, category}, {only: ['products']})` (`:96-107`).
- `checkAndRedirect` consulta `axios.get(route('pos.check-entity'), { params: { query } })` (`:133`) que busca **folio de venta**, **folio de orden de servicio** y **cliente por teléfono/nombre** (`PointOfSaleController.php:167-219`). **No busca productos**.

> **Implicación para la caja inteligente:** hoy un código escaneado solo filtra el catálogo. Para "detectar y agregar" hay que (a) resolver el código a un producto con payload completo y (b) invocar `addToCart`. Ninguna de las dos piezas existe como API reutilizable (ver §8).

### 2.4 Editar precio, eliminar línea y cambiar cantidad

| Acción | Función en la página | Firma | Evidencia |
|---|---|---|---|
| **Cambiar cantidad** | `updateCartQuantity` | `({ itemId, quantity }) => void` | `Index.vue:200-227` |
| **Editar precio manual** | `updateCartPrice` | `({ itemId, price }) => void` | `Index.vue:229-236` |
| **Eliminar línea** | `removeCartItem` | `(itemId: string) => void` | `Index.vue:238-240` |
| **Vaciar carrito** | `clearCart` | `() => void` (vacía carrito, cliente y cierra modal de pago) | `Index.vue:242-246` |

Cadena de eventos (nombres exactos):

```text
CartItem  --emit('updateQuantity', { itemId: item.cartItemId, quantity })-->  ShoppingCart
ShoppingCart --$emit('updateQuantity', $event)-->  Index  --@update-quantity--> updateCartQuantity
CartItem  --emit('updatePrice',    { itemId, price })-------------------->  ShoppingCart
ShoppingCart --$emit('updatePrice', $event)-->      Index  --@update-price-->    updateCartPrice
CartItem  --emit('removeItem', item.cartItemId)-------------------------->  ShoppingCart
ShoppingCart --$emit('removeItem', $event)-->       Index  --@remove-item-->     removeCartItem
```
Evidencia: `CartItem.vue:19`, `CartItem.vue:38`, `CartItem.vue:66`, `CartItem.vue:170`; `ShoppingCart.vue:337-339`; `Index.vue:483-484`.

**Cantidad** (`CartItem.vue:32-44`): `InputNumber` con `showButtons`, `min = item.is_bulk ? 0.001 : 1`, `maxFractionDigits = is_bulk ? 3 : 0`, `step = is_bulk ? 0.25 : 1` (`:240-251`). Un `watch(quantity)` emite solo si cambió y nunca baja del mínimo. En la página, `updateCartQuantity` hace `Math.max(1, quantity || 1)` (`Index.vue:203`) y **recalcula el precio con tiers** salvo `isManualPrice` (`:207-225`); si faltan datos base, revierte y avisa (`:221-224`).

**Precio** (`CartItem.vue:47-94`): al confirmar el input se abre un `Dialog` "Cambio de precio" con dos salidas:
- **"Solo para esta venta"** → `emit('updatePrice', ...)` (`:65-70`).
- **"Actualizar catálogo permanente"** → `axios.post(route('products.update-price-pos'), { product_id, product_attribute_id, new_price })` y luego aplica al carrito (`:72-94`). Requiere `hasPermission('products.edit')` (`:321`).

`updateCartPrice` fija `price = Math.max(0, price || 0)`, `isManualPrice = true`, `isTierPrice = false` (`Index.vue:229-236`). Consecuencia: **una línea con precio manual deja de recalcularse al cambiar cantidad**.

**Eliminar**: botón `pi-times` con confirmación por `ConfirmPopup` grupo `cart-item-delete` (`CartItem.vue:161-173`, `:286-290`, `:294`). El vaciado total usa `ConfirmPopup` grupo `cart-actions` (`ShoppingCart.vue:28-38`, `:182`).

### 2.5 Estructura exacta de una línea del carrito

Una línea = **payload del producto (`...product`) + campos propios del carrito** (`Index.vue:169-188`).

| Campo | Tipo | Origen | Significado / uso |
|---|---|---|---|
| `id` | number | payload | ID del producto (se envía al backend como `cartItems.*.id`). |
| `name` | string | payload | Nombre mostrado y `description` enviada al server. |
| `sku` | string | payload (o `{sku}-{suffix}` en variante) | Identificador visible. |
| `description` | string | payload | Descripción. |
| `category` | string | payload | Nombre de categoría. |
| `image` | string | payload (o `variant.image_url`) | Miniatura en `CartItem`. |
| `general_images` | string[] | payload | Galería del modal de detalle. |
| `selling_price` | number | **sobrescrito** = `original_price_base` | Precio de lista **absoluto**; base para recalcular tiers. |
| `price_qty_1_promo` | number | `product.price` | Precio con promoción de artículo a cantidad 1; base para recalcular tiers. |
| `price` | number | calculado | **Precio unitario efectivo cobrado** (tier, promo o manual). |
| `original_price` | number | calculado | Precio de lista de referencia (con `price_modifier` si es variante). Base del descuento. |
| `isTierPrice` | boolean | calculado | `true` si el precio vino de `price_tiers` (muestra estrella ámbar). |
| `isManualPrice` | boolean | carrito | `true` si el cajero editó el precio → bloquea recálculo. |
| `quantity` | number | carrito | 1..n; hasta 3 decimales si `is_bulk`. |
| `cartItemId` | string | carrito | **Clave de identidad/merge**: `prod-{id}` o `prod-{id}-variant-{variantId}`. |
| `price_tiers` | array | payload | `[{ min_quantity, price }]` para mayoreo. |
| `stock` | number | payload (o `variant.stock`) | Stock disponible de la variante/producto. |
| `reserved_stock` | number | payload | Apartado. |
| `is_bulk` | boolean | payload | Venta a granel (mínimo 0.001). |
| `measure_unit` | string\|null | payload | Unidad mostrada (`/ Kg`). |
| `promotions` | array | payload | Promos que aplican al producto (popover y `isCartPromoAppliedToItem`). |
| `variants` | object | payload | Opciones disponibles (solo tarjeta/modal). |
| `variant_combinations` | array | payload | Combinaciones válidas. |
| `components` | array | payload | Componentes si es combo. |
| `show_in_pos` | boolean | payload | Visible en POS. |
| **Solo variante** | | | |
| `product_attribute_id` | number | `variant.id` | ID de la combinación; se envía al backend. |
| `selectedVariant` | object | `variant.attributes` | `{ Talla: 'M', Color: 'Rojo' }`; se concatena en `description`. |

Ejemplo (construido a partir del código, no capturado en runtime):

```js
{
  id: 1024, name: "Playera básica", sku: "PLY-001-M-ROJO", description: "...",
  category: "Ropa", image: "https://.../playera-roja.jpg", general_images: ["..."],
  selling_price: 250, price_qty_1_promo: 250, price: 225, original_price: 250,
  isTierPrice: true, isManualPrice: false, quantity: 3,
  cartItemId: "prod-1024-variant-88", price_tiers: [{ min_quantity: 3, price: 225 }],
  stock: 12, reserved_stock: 0, is_bulk: false, measure_unit: null,
  promotions: [], variants: {...}, variant_combinations: [...], components: [],
  show_in_pos: true,
  product_attribute_id: 88, selectedVariant: { Talla: "M", Color: "Rojo" }
}
```

**Campos que el backend recibe** (no la línea completa) — `mapCartItems()` (`Index.vue:338-356`):

```js
{ id, product_attribute_id, quantity, unit_price: item.price,
  description: name + " (M/Rojo)", discount: (original_price ?? price) - price,
  discount_reason: 'Descuento manual' | 'Aumento manual' | 'Precio de mayoreo'
                 | 'Promoción de producto' | null }
```

### 2.6 `getPriceForQuantity` — precio escalonado (función auxiliar)

`Index.vue:101-131` (copia en `IndexMobile.vue:101-133`). Firma:

```js
const getPriceForQuantity = (productData, quantity) =>
    ({ price, original_price_base, isTierPrice })
```

- Toma `selling_price` como "original absoluto" y `price` como "precio base tras promo directa" (`:102-103`).
- Sin tiers o `quantity <= 1` → devuelve el precio con promo (`:105-111`).
- Ordena tiers **descendente** por `min_quantity` y toma el primero que cumpla `quantity >= min_quantity` (`:112-125`).
- **No acumula** descuento de promo + tier: el tier **sustituye** el precio.

### 2.7 ¿Es reutilizable la función desde un componente nuevo?

**No directamente.** `addToCart` es una función local de `<script setup>` (`Index.vue:133`), no está exportada, no vive en un composable y no se inyecta. `resources/js/Composables/` **no tiene ningún composable de carrito** (solo `usePermissions`, `useSatCatalogs`, `useSupportWhatsApp`, `useAiChat`, `useBluetoothPrinter`, `useInvoiceTaxes`, `usePrintPlugin`, `useScrollspy`, `useTemplateRenderer`, `useTemplateVariables`, `useVirtualNumpad`, `useWhatsAppTicket` + `index.js`).

**Sí es alcanzable por evento**, que es el patrón actual del proyecto:

```
Componente nuevo (dentro de PosLeftPanel)  --emit('addToCart', { product, variant? })-->
PosLeftPanel (declara 'addToCart' en emits, línea 28)  --$emit('addToCart', $event) (línea 405)-->
Index.vue  --@add-to-cart="addToCart" (línea 472)-->  addToCart(data)
```

Es exactamente lo que hacen `ProductCard` (`:405`) y `ProductDetailModal` (`:424`). **Por tanto, para la cámara basta con emitir `addToCart` con un `product` que tenga el payload completo.** No hace falta tocar la función.

**Recomendación (no implementada):** extraer a `resources/js/Composables/usePosCart.js` con `cartItems`, `addToCart`, `updateCartQuantity`, `updateCartPrice`, `removeCartItem`, `clearCart`, `mapCartItems`, `currentCartTotal`, `cartSubtotal` y consumirlo desde ambas páginas. Hoy hay **duplicación total** entre `Index.vue` e `IndexMobile.vue` (misma lógica, líneas casi idénticas), lo que ya provocó divergencias menores (p. ej. `IndexMobile.handleCheckout` hace `form.reset()` en `:421` y `Index.handleCheckout` no).

### 2.8 `Index` vs `IndexMobile`: diffs funcionales del carrito

| Aspecto | `Index.vue` | `IndexMobile.vue` |
|---|---|---|
| `addToCart` | `:133-198` | `:135-202` (idéntico) |
| Recarga tras venta | `router.get(route('pos.index'), props.filters, { only:['products'], preserveState:true })` (`:442`) | `router.reload({ only:['products'], preserveState:true })` (`:453`) — **pierde filtros** |
| `form.reset()` en checkout | No | Sí (`:421`) |
| Presentación carrito | Panel fijo | `Drawer` (`:503`) + FAB (`:489`) |
| `isCartDrawerVisible` | No existe | `:40`, se cierra al guardar carrito (`:277`) |

---

## 3. Promociones, precios escalonados, descuentos e impuestos

### 3.1 ¿Se calculan en el frontend? ¿Dónde?

| Concepto | ¿Frontend? | Dónde | Notas |
|---|---|---|---|
| **Precio con promoción de artículo** (`ITEM_DISCOUNT`) | **No se calcula**: llega ya resuelto | `ProductCatalogService::pricing()` (`:140-201`) → `product.price` vs `product.original_price` | El frontend solo compara `price < original_price` (`CartItem.vue:108`). |
| **Precio escalonado / mayoreo** | **Sí** | `getPriceForQuantity` (`Index.vue:101-131`) | Recalcula en alta (`:146`) y en cambio de cantidad (`:214`). |
| **Descuento por línea** | **Sí (derivado)** | `itemsDiscount` (`ShoppingCart.vue:99-105`) = `Σ (original_price - price) × qty` | Si `price > original_price`, el descuento es **negativo** (aumento manual) y la UI muestra "Ajuste manual" (`:361-364`). |
| **Promos a nivel carrito** (`BOGO`, `BUNDLE_PRICE`) | **Sí, simulación** | `cartLevelDiscounts` (`ShoppingCart.vue:107-156`) | Ver §3.2. |
| **Descuento manual global** | **Placeholder** | `manualDiscount = ref(0)` (`ShoppingCart.vue:161`) | **Nunca se asigna** (grep: solo declaración y suma). Siempre 0. |
| **Impuestos (IVA)** | **No** | — | No hay línea de impuestos, ni `tax`, ni `iva` en `Pages/POS/**`. Los impuestos existen solo en facturación (`Composables/useInvoiceTaxes.js:24`). |
| **Total** | **Sí** | `total = subtotal - totalDiscount` (`ShoppingCart.vue:163`) | Sin redondeo explícito (solo formateo con `Intl.NumberFormat`). |

### 3.2 `cartLevelDiscounts` en detalle (`ShoppingCart.vue:107-156`)

Se recorre `props.activePromotions` (prop inyectada por el backend, `PointOfSaleController.php:122` → `ProductCatalogService::activePromotions`, que excluye `ITEM_DISCOUNT` en `:125`):

- **BOGO** (`:112-128`): busca la regla `REQUIRES_PRODUCT_QUANTITY` y el efecto `FREE_ITEM`; si el producto regalado está en el carrito, calcula `floor(qty/regla) × efecto.value`, limitado al stock del artículo gratis; importe = `price × unidades gratis`.
- **BUNDLE_PRICE** (`:129-153`): calcula cuántas veces cabe el paquete (`min` de `floor(qty/req)`), suma el precio de lista de los componentes, resta el `SET_PRICE` y aplica el delta.

Estos descuentos son **solo visuales**: el backend recalcula todo (`PointOfSaleController.php:252-258` → `TransactionPaymentService::handleNewSale`). El comentario del código lo dice: *"the server recalculates it anyway"* (`Index.vue:365-366`).

### 3.3 ¿Se recalculan al cambiar cantidad?

- **Precio de línea:** sí, `updateCartQuantity` → `getPriceForQuantity` (`Index.vue:207-225`), **excepto** si `isManualPrice`.
- **Promos de carrito:** sí, son `computed` sobre `props.items` (`ShoppingCart.vue:107`), reaccionan automáticamente a cualquier cambio de cantidad.
- **Subtotal/Total:** sí, `computed` (`ShoppingCart.vue:160-163`).
- **Precio dentro de `ProductCard`:** el badge "en carrito" se recalcula por `quantityInCart` (`ProductCard.vue:67-82`).

---

## 4. Comunicación con el backend, notificaciones y modales

### 4.1 Checkout (POST)

**No usa axios**: usa Inertia `useForm()` (`@inertiajs/vue3`) con rutas nombradas de Ziggy.

```js
const form = useForm({ cartItems: [], customerId: null, subtotal: 0, total_discount: 0,
    total: 0, payments: [], use_balance: false, cash_register_session_id: null,
    layaway_expiration_date: null, is_order: false, contact_info: null,
    delivery_date: null, shipping_address: null, shipping_cost: 0, notes: null,
    guest_name: null });                                   // Index.vue:323-336
...
form.post(route(routeName), { onSuccess, onError });       // Index.vue:437
```

| Tipo de transacción | Ruta nombrada | Controlador |
|---|---|---|
| `contado` / `credito` | `pos.checkout` | `PointOfSaleController::checkout` (`routes/web/POS.php:19`) |
| `apartado` | `pos.layaway` | `PointOfSaleController::createLayaway` (`routes/web/POS.php:21`) |
| Pedido | `pos.store-order` | `TransactionController::storeOrder` (`routes/web/transactions.php:41-42`) |

Payload (validado en `PointOfSaleController.php:224-246`): `cash_register_session_id`, `cartItems[]` (`id`, `product_attribute_id`, `quantity`, `unit_price`, `description`, `discount`, `discount_reason`), `customerId`, `guest_name`, `subtotal`, `total_discount`, `total`, `payments[]` (`amount`, `method`, `bank_account_id`, `notes`), `use_balance`, `layaway_expiration_date`.

`payments` se arma en `MultiPaymentProcessor` (`:262-266`): `{ id: crypto.randomUUID(), method: 'efectivo'|'tarjeta'|'transferencia', amount, bank_account_id, notes }` (`:163-169`, `:199-206`).

**Post-venta:** el server redirige a `pos.index` con `success` y `print_data` (`PointOfSaleController.php:260-262`). En el cliente:
- `clearCart()` + recarga parcial de productos (`Index.vue:438-443`).
- `watch(() => page.props.flash.print_data)` abre `PrintModal` (`Index.vue:54-60`).

**Auxiliares con axios** (configurado global en `bootstrap.js:1-5`, `withCredentials` + `X-Requested-With`):

| Uso | Llamada | Evidencia |
|---|---|---|
| Buscar clientes (autocomplete) | `axios.get(route('pos.customers.search'), { params: { query } })` | `ShoppingCart.vue:72`, `PaymentModal.vue:40` |
| Detectar entidad escaneada | `axios.get(route('pos.check-entity'), { params: { query } })` | `PosLeftPanel.vue:133` |
| Cambiar precio permanente | `axios.post(route('products.update-price-pos'), {...})` | `CartItem.vue:75-79` |
| Pedidos tienda online | `axios.get(route('pos.online-orders'))`, `axios.put(route('pos.online-orders.update-status', id))` | `OnlineOrdersModal.vue:30`, `:74` |
| Cuentas bancarias | `axios.get(route('branch-bank-accounts'))` | `PaymentModal.vue:82` |

**Ziggy**: helper global inyectado con `@routes` en el blade (`resources/views/app.blade.php:22`) y `ZiggyVue` en `app.js:7,34`. Se usa `route('nombre')` en todas las plantillas/JS sin importar nada.

### 4.2 Notificaciones / toasts

| Mecanismo | Detalle | Evidencia |
|---|---|---|
| **PrimeVue Toast** | `ToastService` registrado en la app; `<Toast :escape="false" />` una sola vez en el layout; se invoca con `useToast()` y `toast.add({ severity, summary, detail, life })` | `app.js:65`, `AppLayout.vue:231`, `Index.vue:4` |
| **Flash messages** | `router.on('success', ...)` en el layout convierte `flash.success/error/warning/info` en toasts (6000 ms) | `AppLayout.vue:68-88` |
| **SweetAlert** | **No se usa** en el proyecto (grep `swal|sweetalert` → 0 resultados) | — |
| **Confirmaciones** | PrimeVue `ConfirmationService` + `useConfirm()` + `<ConfirmDialog>` global y `<ConfirmPopup group="...">` locales | `app.js:66`, `AppLayout.vue:232`, `ShoppingCart.vue:182`, `CartItem.vue:294` |

Ejemplos reales de toasts del POS: `{ severity:'warn', summary:'Stock insuficiente', detail:'No puedes agregar más de N unidades.', life:3000 }` (`Index.vue:166`), `{ severity:'error', summary:'Caja cerrada', ... }` (`Index.vue:406`), `{ severity:'success', summary:'Cliente creado', ... }` (`Index.vue:255`).

### 4.3 Modales

| Patrón | Componente | ¿Se usa en POS? | Evidencia |
|---|---|---|---|
| **PrimeVue Dialog** (auto-importado) | `<Dialog v-model:visible modal header=... :pt="{...}">` | **Sí, es el estándar del POS** | `ProductDetailModal.vue:214`, `OrderFormModal.vue:78`, `CartItem.vue:297`, `ShoppingCart.vue:403`, `PosLeftPanel.vue:432` |
| **PrimeVue Drawer** | `<Drawer v-model:visible position="right">` | Sí (carrito móvil) | `IndexMobile.vue:503` |
| **PrimeVue Popover / Menu** | `<Popover ref>`, `<Menu :popup="true">` | Sí | `PosLeftPanel.vue:326,364`, `CartItem.vue:265` |
| **`Components/Modal.vue`** (Jetstream, `<dialog>` nativo) | `show`/`maxWidth`/`closeable` + slot | **No en POS** | `Components/Modal.vue:4-21` |
| **`DialogModal.vue` / `ConfirmationModal.vue`** | Envoltorios de `Modal.vue` con slots `title`/`content`/`footer` | **No en POS** | `Components/DialogModal.vue:1-47`, `Components/ConfirmationModal.vue:1-57` |

> **Regla para UI nueva:** usar `Dialog` de PrimeVue con `:pt` para el tema oscuro, no `Modal.vue`. El patrón de `:pt` se repite idéntico en todos los modales del POS (`root`, `header`, `title`, `content`, `footer`).

---

## 5. Sistema de diseño (crítico para UI nueva)

### 5.1 `tailwind.config.js`

| Token | Valor | Línea |
|---|---|---|
| `darkMode` | `['selector', '[class*="app-dark"]']` (modo oscuro por clase, no `prefers-color-scheme`) | `:8` |
| `content` | `resources/js/**/*.vue`, `resources/js/**/*.js`, blades | `:9-16` |
| `screens` | `sm 576`, `md 768`, `lg 992`, `xl 1200`, `3xl 1400`, `2xl 1920` (¡no son los defaults!) | `:18-25` |
| `fontFamily.sans` | `['Figtree', ...defaultTheme.fontFamily.sans]` (`Livvic` comentado) | `:27-30` |
| `colors.primary` | `#F68C0F` (color **plano**, no escala) | `:32` |
| `colors.primarylight` | `#FCDCB5` | `:33` |
| `colors.secondary` | `#E9A527` | `:34` |
| `colors.secondarylight` | `#F8E2BA` | `:35` |
| `colors.redDanger` | `#F80505` | `:36` |
| `colors.grayD9/99/9A/F2/66/77/37` | `#D9D9D9 / #999999 / #9A9A9A / #F2F2F2 / #666666 / #777777 / #373737` | `:37-43` |
| `colors.black1 / black2` | `#1A1A1A / #0D0D0D` | `:44-45` |
| `boxShadow` | **Sin extensión** → solo defaults de Tailwind | — |
| `borderRadius` | **Sin extensión** → solo defaults (`xl`=12px, `2xl`=16px, `3xl`=24px) | — |
| `keyframes/animation` | `swing`, `ping-pause`, `sparkle` | `:48-72` |
| `plugins` | `forms({strategy:'class'})`, `typography`, **`tailwindcss-primeui`** | `:85-89` |

> **Ojo con `primary-*`:** `tailwind.config.js:32` define `primary` como **color único** (`bg-primary`, `text-primary`), pero el POS usa masivamente `bg-primary-500`, `text-primary-500`, `border-primary-200`, etc. Esa escala la aporta el plugin **`tailwindcss-primeui`** (`:88`) puenteando los tokens de PrimeVue (`--p-primary-500`), cuyo valor efectivo es `#F68C0F` (`presets/ezyventas.js:12`). Para UI nueva, `primary-500` es el uso correcto y consistente.

### 5.2 CSS global

`resources/css/app.css` (48 líneas) **no define tokens ni utilidades propias**: solo `@tailwind base/components/utilities`, `[x-cloak]` y estilos de scrollbar delgados (`:9-47`).

`resources/js/app.js` importa además `primeicons/primeicons.css` (`:12`), `quill` (`:13`) y `@/assets/styles.scss` (`:15`), que a su vez carga `primeicons`, `layout.scss` y `demo.scss` (`assets/styles.scss:2-4`).

Tokens CSS reales (puente SCSS → PrimeVue) en `assets/layout/variables/_common.scss:1-20`:

```scss
:root {
  --primary-color: var(--p-primary-color);
  --primary-contrast-color: var(--p-primary-contrast-color);
  --text-color: var(--p-text-color);
  --surface-border: var(--p-content-border-color);
  --surface-card: var(--p-content-background);
  --content-border-radius: var(--p-content-border-radius);
  ...
}
```

Tipografía base: `html { font-size: 14px; line-height: 1.2 }` y `body { font-family: 'Lato', sans-serif }` (`assets/layout/_core.scss:1-16`). **`Lato` no se carga en ninguna parte** → el body cae al `sans-serif` del sistema; en cambio `font-sans` (clase del `<body>`, `app.blade.php:31`) sí resuelve a **Figtree**.

Fuentes cargadas en el blade:
- **Figtree** (`fonts.bunny.net`, pesos 400/500/600) → `app.blade.php:18`.
- **Krub** (Google Fonts, 200–700) → `app.blade.php:19`, usada sobre todo en landings (`Welcome.vue:189`, `WelcomeMobile.vue:189`, `ModernFooter.vue:118`).

### 5.3 `resources/js/presets/ezyventas.js` (preset PrimeVue sobre Aura)

| Grupo | Valores | Línea |
|---|---|---|
| Base | `definePreset(Aura, {...})` | `:4` |
| `primary` 50→950 | `#FEF4E7, #FDE6C8, #FBD3A0, #F9B96F, #F79F43, **#F68C0F (500)**, #E47909, #BB610A, #984E0F, #7C4112, #422007` | `:6-18` |
| `colorScheme.light.surface` | 0 `#ffffff`, 50-900 = `{gray.*}`, 950 `#373737` | `:20-36` |
| `colorScheme.dark.primary` | misma escala naranja | `:39-51` |
| `colorScheme.dark.surface` | 50 `#4a4a4a`, 100 `#5a5a5a`, 200 `#6b6b6b`, 300 `#7c7c7c`, 400 `#8d8d8d`, 500 `#9e9e9e`, 600 `#afafaf`, 700 `#c0c0c0`, 800 `#3a3a3a`, **900 `#232323`**, **950 `#1a1a1a`** | `:52-65` |

**No se sobrescriben** `borderRadius`, `focusRing`, `formField`, `typography` → todo eso queda en los defaults de Aura. Registro en la app: `theme.preset = Ezyventas`, `darkModeSelector: '.app-dark'` (`app.js:58-63`).

### 5.4 Radios, sombras y superficies observados en el POS

| Elemento | Clases | Valor efectivo | Evidencia |
|---|---|---|---|
| Contenedor de panel / tarjeta grande | `rounded-3xl` | 24 px | `PosLeftPanel.vue:279`, `ShoppingCart.vue:184`, `Index.vue:468` |
| Tarjeta de producto / línea de carrito | `rounded-3xl` / `rounded-2xl` | 24 / 16 px | `ProductCard.vue:198`, `CartItem.vue:177` |
| Botones PrimeVue | `!rounded-xl` (en CTAs del carrito, `!rounded-2xl`) | 12 px (16 px) | `ProductCard.vue:311`, `ProductDetailModal.vue:381-384`, `ShoppingCart.vue:389` |
| Inputs / buscador | `!rounded-2xl`, contenedor `rounded-3xl` | 16 / 24 px | `PosLeftPanel.vue:372-379` |
| Chips de categoría / badges | `rounded-full` | ∞ | `CategoryFilters.vue:60`, `ProductCard.vue:208` |
| Botones icono de la barra | `rounded` (PrimeVue `rounded` prop) | 50 % | `PosLeftPanel.vue:302-324` |
| Sombra de tarjeta en hover | `hover:shadow-xl` | Tailwind default | `ProductCard.vue:198` |
| Sombra del CTA "Cobrar" | `shadow-[0_4px_14px_rgba(246,140,15,0.4)]` | custom arbitraria | `ShoppingCart.vue:389` |
| Glow de badge "en carrito" | `shadow-[0_0_10px_rgba(246,140,15,0.4)]` | custom arbitraria | `ProductCard.vue:208` |
| Radios de modales PrimeVue | `rounded-3xl` vía `:pt.root` | 24 px | `ProductDetailModal.vue:218` |

Paleta oscura de facto (hardcodeada, no tokenizada): fondo `dark:bg-[#232323]`, superficie secundaria `dark:bg-[#1a1a1a]`, bordes `dark:border-[#3a3a3a]`, texto `dark:text-white` / `dark:text-gray-300/400`.

### 5.5 Iconos

| Librería | Uso en el POS | Importación |
|---|---|---|
| **PrimeIcons** (principal) | `<i class="pi pi-shopping-cart">`, `<i class="pi pi-plus !text-[10px]">` y la prop `icon="pi pi-*"` de PrimeVue | Global: `primeicons/primeicons.css` en `app.js:12` (y `assets/styles.scss:2`). **No requiere import por componente.** |
| **@heroicons/vue** (puntual) | `FireIcon` (promos) y `StarIcon` (mayoreo) | `import { FireIcon } from '@heroicons/vue/24/solid'` (`ProductCard.vue:3`); `import { FireIcon, StarIcon } from '@heroicons/vue/24/solid'` (`CartItem.vue:4`) |

El POS usa **PrimeIcons en >95 % de los casos**; Heroicons solo donde no hay equivalente (`FireIcon`, `StarIcon`). No existe un wrapper `Icon.vue` ni un set propio (sí `Components/Icons/*.vue` para íconos biométricos/landing, no usados en POS).

### 5.6 Componentes reutilizables (props/slots reales)

| Componente | Props | Slots / eventos | Uso en POS |
|---|---|---|---|
| **`PrimaryButton.vue`** | `type` (default `'submit'`) | slot default | ❌ No se usa en POS (botones PrimeVue). Estilo gris `bg-gray-800`, ajeno al tema naranja. |
| **`SecondaryButton.vue`** | `type` (default `'button'`) | slot default | ❌ No se usa en POS. |
| **`DangerButton.vue`** | `type` | slot | ❌ No se usa en POS. |
| **`TextInput.vue`** | `modelValue: String`; expone `focus()` | `update:modelValue` | ❌ No se usa en POS (se usa `InputText` de PrimeVue). |
| **`Modal.vue`** | `show`, `maxWidth` (sm, md, lg, xl, 2xl), `closeable` | `close`, slot default | ❌ No en POS. |
| **`DialogModal.vue`** | `show`, `maxWidth`, `closeable` | `close`, slots `title`/`content`/`footer` | ❌ No en POS (`Components/DialogModal.vue:1-47`). |
| **`ConfirmationModal.vue`** | `show`, `maxWidth`, `closeable` | `close`, slots `title`/`content`/`footer` | ❌ No en POS (`Components/ConfirmationModal.vue:1-57`). |
| **`VirtualNumpad.vue`** | ninguna (singleton) | — | ✅ `Index.vue:562`, `IndexMobile.vue:616`, `OrderFormModal.vue:216`, `MultiPaymentProcessor.vue:430` |
| **`ProductCard.vue`** | `product: Object`, `cartItems: Array` | `showDetails`, `addToCart` | ✅ |
| **`PaymentModal.vue`** | `visible`, `totalAmount` (req.), `client`, `customers`, `paymentMode` (`strict`), `allowCredit`, `allowLayaway`, `loading` | `update:visible`, `submit`, `update:client`, `customerCreated` | ✅ `ShoppingCart.vue:397-400` |
| **`CreateCustomerModal.vue`** | `visible` | `update:visible`, `created` | ✅ `ShoppingCart.vue:396` |
| **`CreateProductModal.vue`** | `visible` | `update:visible`, `created` | ✅ `PosLeftPanel.vue:425` |
| **`TutorialHelp.vue`** | `module: String` (`"pos"`) | — | ✅ `PosLeftPanel.vue:289` |

**Componentes PrimeVue usados sin import** (auto-import vía `unplugin-vue-components` + `PrimeVueResolver`, `vite.config.js:23-27`): `Button`, `Dialog`, `Drawer`, `Popover`, `Menu`, `Badge`, `InputText`, `InputNumber`, `AutoComplete`, `IconField`, `InputIcon`, `Avatar`, `Message`, `DatePicker`, `Textarea`, `Toast`, `ConfirmDialog`. Solo `MultiPaymentProcessor.vue:3-7` importa explícitamente (`Button`, `InputNumber`, `Select`, `DatePicker`, `InputSwitch`).

> Para un componente nuevo: **importar explícitamente** los componentes propios (no hay auto-import de `resources/js/Components`, porque el default de `unplugin-vue-components` es `src/components` y aquí están en `resources/js/Components`). Los de PrimeVue sí se auto-importan.

### 5.7 Tono y ejemplos reales de textos

Estilo: **español de México, directo, imperativo, sin signos de exclamación**, frases cortas. Micro-etiquetas en `text-[10px] uppercase tracking-widest font-bold`; títulos en `font-light tracking-tight`.

| Contexto | Texto literal | Evidencia |
|---|---|---|
| Título del panel | "Registrar ventas" | `PosLeftPanel.vue:286` |
| Placeholder buscador | "Escanear o buscar producto..." | `PosLeftPanel.vue:378` |
| Botón de ayuda | "Búsqueda inteligente" | `PosLeftPanel.vue:389` |
| Empty state productos | "Sin resultados" / "No se encontraron productos con esos filtros." | `PosLeftPanel.vue:413-414` |
| Botón tarjeta | "Añadir" / "Elegir opciones" | `ProductCard.vue:308` |
| CTA modal detalle | "Añadir al carrito" | `ProductDetailModal.vue:382` |
| Título carrito | "Carrito" / "Comanda" | `ShoppingCart.vue:194` |
| Etiqueta sección | "Artículos en orden" | `ShoppingCart.vue:327` |
| Empty state carrito | "Tu orden está vacía." | `ShoppingCart.vue:334` |
| Totales | "Subtotal", "Descuentos", "Ajuste manual", "Total" | `ShoppingCart.vue:346,352,362,367` |
| CTAs | "Pedido" / "Cobrar" / "Finalizar" | `ShoppingCart.vue:376,387` |
| Drawer móvil | "Resumen de venta" | `IndexMobile.vue:511` |
| Toast stock | "Stock insuficiente" / "No puedes agregar más de N unidades." | `Index.vue:166` |
| Toast sin stock | "Sin stock" / "El producto se agregó al carrito pero no tiene stock disponible." | `Index.vue:195` |
| Confirmación | "¿Estás seguro de que quieres eliminar este artículo?" / "Sí, eliminar" / "Cancelar" | `CartItem.vue:164-168` |
| Modal precio | "¿Deseas aplicar este precio solo para esta venta o actualizar el catálogo de forma permanente?" | `CartItem.vue:314` |
| Bloqueo de caja | "Terminal bloqueada" / "El sistema requiere inicializar una sesión de caja..." | `Index.vue:507-512` |

### 5.8 Idioma: ¿i18n en el frontend?

**No.** Evidencia:

- `package.json` **no** incluye `vue-i18n` ni `@inertiajs/...i18n`.
- Grep de `vue-i18n|useI18n|i18n.` en `resources/js` → **0 resultados**.
- No existe carpeta de traducciones JS (`resources/js/lang`, `locales`, `i18n.js`) → no hay archivo de traducciones frontend.
- `lang/es.json`, `lang/es/*.php`, `lang/en/*.php` son traducciones **de Laravel (backend)** para validación/mensajes; `lang/es.json` (50 KB, ~miles de claves) es el JSON de `__()` del framework. El frontend no las consume.
- Lo único "localizado" es la config regional de PrimeVue (fechas, `emptyMessage`, `accept`/`reject`) en `app.js:36-57`, y el formateo `Intl.NumberFormat('es-MX', { currency: 'MXN' })` repetido en cada componente (p. ej. `ShoppingCart.vue:175-177`).

➡️ **Para UI nueva: escribir los textos en español directamente en la plantilla**, siguiendo el tono de §5.7. Los archivos del POS están en UTF-8 correcto (verificado con lectura UTF-8; los acentos se ven bien en `PendingCartsPopover.vue:18-22`).

---

## 6. Carga perezosa y build

### 6.1 `vite.config.js`

| Elemento | Estado | Evidencia |
|---|---|---|
| Plugins | `laravel({ input: ['resources/js/app.js'], refresh: true })`, `vue({...})`, `Components({ resolvers: [PrimeVueResolver()] })` | `vite.config.js:8-27` |
| `build.rollupOptions.manualChunks` | **No existe** | `vite.config.js` (38 líneas, sin bloque `build`) |
| `define` | **No existe** | idem |
| `resolve.alias` | **No declarado en el archivo**, pero el alias `@` sí funciona: lo inyecta `laravel-vite-plugin` | `node_modules/laravel-vite-plugin/dist/index.js:32` (`"@": "/resources/js"`), merge en `:81-90` |
| `server` | Bloque comentado (TLS/HMR para `ezyventas2.test`) | `vite.config.js:30-37` |
| `base` / `outDir` | Defaults del plugin Laravel | — |

Es decir: **no hay code splitting manual, ni chunks nombrados, ni `define`**. Todo el splitting es automático (por página y por import dinámico).

### 6.2 ¿Cómo se hace el code splitting hoy?

1. **Por página (Inertia + glob)**: `resolve: (name) => resolvePageComponent('./Pages/${name}.vue', import.meta.glob('./Pages/**/*.vue'))` (`app.js:25`). `import.meta.glob` sin `eager` genera **un chunk por vista**, cargado bajo demanda al navegar. Por eso el POS (`POS/Index.vue`, 564 líneas) no pesa en el primer load de otras páginas.
2. **Componentes asíncronos**: único caso en `Welcome.vue:7-9`:
   ```js
   const DashboardGraph = defineAsyncComponent(() => import('@/Components/DashboardGraph.vue'));
   ```
3. **Imports dinámicos**: solo en `bootstrap.js:12-16` para `laravel-echo` y `pusher-js` (comentario explícito: *"Deferred WebSocket initialization... avoid shipping ~200 KB to unauthenticated landing page visitors"*, `:7-11`).
4. **En el POS no hay ningún `import()` dinámico**: `ProductDetailModal`, `PaymentModal`, `PrintModal`, `CreateProductModal`, etc. se importan estáticamente (`PosLeftPanel.vue:8-15`, `ShoppingCart.vue:4-6`, `Index.vue:8-14`). **Oportunidad**: el visor de cámara podría cargarse con `defineAsyncComponent(() => import('./CameraScanner.vue'))` para no penalizar el arranque del POS.

### 6.3 Web Workers

**No existe ningún Web Worker en el proyecto** (grep `new Worker|Worker(|worker` en `resources/js` → 0 resultados). No hay carpeta `workers/` ni `*.worker.js`.

### 6.4 Implicaciones para la cámara

- `getUserMedia` + decodificación de códigos es costoso en el hilo principal → si se usa una librería pesada (p. ej. ZXing/WASM), conviene `defineAsyncComponent` y, si se quiere, un Worker propio (no hay precedente en el repo).
- El proyecto ya tiene el patrón de "cargar solo cuando se necesita" en `Welcome.vue` y `bootstrap.js`.

---

## 7. Permisos en el frontend

### 7.1 Mecanismo (único)

Composable `usePermissions` → lee `usePage().props.auth.permissions` y expone `hasPermission(permission)`:

```js
// resources/js/Composables/usePermissions.js:3-11
export function usePermissions() {
    const permissions = usePage().props.auth.permissions || [];
    const hasPermission = (permission) => permissions.includes(permission);
    return { hasPermission };
}
```

Re-exportado desde el barrel: `resources/js/Composables/index.js:4`.

**No existen**: directiva `v-can`, helper global `can()`, `$can`, ni store de permisos. Grep `v-can|directive('can'` → 0 resultados.

### 7.2 Origen de los permisos

`HandleInertiaRequests::share()` (`app/Http/Middleware/HandleInertiaRequests.php:20-46`):
- Owner sin roles → **todos** los permisos de los módulos activos + módulo `Sistema` (`:33-37`).
- Usuario con rol → `$user->getAllPermissions()` filtrado por módulos activos (`:38-42`).
- Suscripción inactiva → `collect([])` (`:41-42`).

### 7.3 Uso típico en plantillas

```html
<!-- resources/js/Pages/POS/Partials/CartItem.vue:227 -->
<button v-if="hasPermission('pos.edit_prices')" @click="isEditingPrice = true"> ... </button>

<!-- resources/js/Pages/POS/Partials/CartItem.vue:321 -->
<Button v-if="hasPermission('products.edit')" label="Actualizar catálogo permanente" ... />
```

```js
import { usePermissions } from '@/Composables';      // CartItem.vue:3
const { hasPermission } = usePermissions();          // CartItem.vue:20
```

### 7.4 Permisos relevantes del POS

| Permiso | Dónde se comprueba | Evidencia |
|---|---|---|
| `pos.access` | Backend: middleware de `index`, `searchCustomers`, `checkEntity`, `getOnlineOrders`; también visible en menú | `PointOfSaleController.php:36`, `AppMenu.vue:10`, `AppMenu.vue:143-158` |
| `pos.create_sale` | Backend: middleware solo en `checkout` | `PointOfSaleController.php:37` |
| `pos.edit_prices` | Frontend: lápiz de edición de precio por línea | `CartItem.vue:117-120`, `:227` |
| `products.edit` | Frontend: opción "actualizar catálogo permanente" | `CartItem.vue:321`, `:333` |

Definición de los permisos en el seeder: `database/seeders/PermissionSeeder.php:22-24`.

> **Para el botón de cámara**: si debe restringirse, seguir el patrón `v-if="hasPermission('pos.access')"` (o crear un permiso nuevo en el seeder). Recordar que el backend debe validarlo también; el frontend es solo cosmético.

---

## 8. Brechas explícitas para la "caja inteligente" (detección por cámara)

| # | Brecha | Evidencia | Impacto |
|---|---|---|---|
| 1 | **No existe código de cámara** en el frontend (ni `getUserMedia`, ni `BarcodeDetector`, ni librería QR/barras). | grep sin resultados en `resources/js` | Hay que crear el componente desde cero. |
| 2 | **No existe columna `barcode`** en productos: el "código de barras" del sistema es el **SKU**. | Comentario explícito en `app/Services/PrintEncoderService.php:475` (*"The products table has no barcode column: the SKU is the code of..."*), `:504` (`{{p.codigo_barras}}` → `barcode ?? sku`); `Product::$fillable` sin `barcode` (`app/Models/Product.php:24-25`); migraciones sin `barcode` (grep 0) | Lo que se decodifique debe compararse contra `sku`. |
| 3 | **No hay endpoint que resuelva código → payload completo de producto POS.** `pos.check-entity` solo busca folios y clientes (`PointOfSaleController.php:167-219`). `transactions.search-products` devuelve un shape reducido: trae `selling_price`/`current_stock` pero **no** `price`, `original_price`, `price_tiers`, `promotions`, `image` ni `is_bulk` (`TransactionController.php:541-561`) → **no sirve tal cual** para alimentar `addToCart`. | `PointOfSaleController.php:167-219`, `TransactionController.php:524-565` | Opciones: (a) reutilizar `pos.index?search=SKU` y leer `products.data[0]` (payload completo, `ProductCatalogService.php:89-111`), o (b) añadir un endpoint `GET /pos/product-by-code`. |
| 4 | **`addToCart` no es exportable** ni hay composable de carrito; está duplicada en dos páginas. | `Index.vue:133`, `IndexMobile.vue:135` | Integrar por `emit('addToCart')` (mínimo cambio, ver §2.7) o refactorizar a `usePosCart`. |
| 5 | **No hay auto-add al escanear**: el escáner solo rellena la búsqueda. | `PosLeftPanel.vue:181-198` | La cámara debe llamar explícitamente a `addToCart`, no "simular teclas" (aunque simular `searchTerm` + click también funcionaría, es frágil). |
| 6 | **Stock no bloquea**: agregar sin stock solo muestra un toast. | `Index.vue:194-196` | Decidir si la cámara debe respetar esa regla (hoy sí, implícitamente). |
| 7 | **Búsqueda paginada de 20 productos** (`cursorPaginate(20)`): el POS no tiene el catálogo completo en memoria. | `PointOfSaleController.php:322` | Un lookup por código debe ser server-side, no filtrar el array local. |
| 8 | **Duplicación Index/IndexMobile**: cualquier cambio en el carrito debe replicarse en dos sitios. | `Index.vue:133-246` vs `IndexMobile.vue:135-256` | Riesgo alto de divergencia al añadir cámara. |
| 9 | **Modo oscuro por clase + hex hardcodeados**: no hay tokens semánticos para `#232323/#1a1a1a/#3a3a3a`. | `tailwind.config.js:8`, `app.js:61`, `PosLeftPanel.vue:279` | Copiar los hex existentes para que la UI nueva combine. |

### 8.1 Contrato mínimo sugerido para el componente de cámara (derivado del código actual)

```js
// Componente nuevo, p. ej. resources/js/Pages/POS/Partials/CameraScannerModal.vue
const emit = defineEmits(['update:visible', 'addToCart']);

// 1) decodificar -> code (string, en la práctica el SKU)
// 2) resolver a payload POS completo (server-side; ver brecha #3)
// 3) emitir con la MISMA forma que ProductCard:
emit('addToCart', { product: productPayload /*, variant: combination */ });
```

Y en `PosLeftPanel.vue`, dentro de la barra de acciones (`:300-368`):

```html
<Button icon="pi pi-camera" rounded v-tooltip.bottom="'Escanear con cámara'"
    class="!w-10 !h-10 !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] !text-gray-600 dark:!text-gray-300 hover:!border-primary-500 transition-colors"
    @click="isCameraModalVisible = true" />
```

Con `@add-to-cart="$emit('addToCart', $event)"` en el propio `PosLeftPanel` (mismo patrón que `:405`), el flujo llega intacto a `addToCart` de la página, con merge de líneas, tiers, promos, stock y persistencia en `localStorage` ya resueltos.

---

## 9. Apéndice — Mapa rápido de referencia

| Necesito… | Voy a… |
|---|---|
| Agregar un producto al carrito | `emit('addToCart', { product, variant? })` → `Index.vue:133` / `IndexMobile.vue:135` |
| Cambiar cantidad | `emit('updateQuantity', { itemId: item.cartItemId, quantity })` → `Index.vue:200` |
| Cambiar precio | `emit('updatePrice', { itemId, price })` → `Index.vue:229` |
| Quitar línea | `emit('removeItem', item.cartItemId)` → `Index.vue:238` |
| Ver totales | `ShoppingCart.vue:99-163` (`subtotal`, `totalDiscount`, `total`) |
| Enviar la venta | `form.post(route('pos.checkout'))` → `Index.vue:437` |
| Mostrar un toast | `useToast(); toast.add({ severity, summary, detail, life })` |
| Confirmar una acción | `useConfirm().require({ target, group, message, accept, reject })` + `<ConfirmPopup group="x">` |
| Abrir un modal | `<Dialog v-model:visible modal header="..." :pt="{...}">` |
| Comprobar permiso | `hasPermission('pos.access')` desde `@/Composables` |
| Formatear dinero | `new Intl.NumberFormat('es-MX', { style:'currency', currency:'MXN' }).format(v)` |
| Colores | `primary-500 #F68C0F`, `dark:bg-[#232323]`, `dark:bg-[#1a1a1a]`, `dark:border-[#3a3a3a]` |
| Iconos | `<i class="pi pi-*">` (PrimeIcons) y `@heroicons/vue/24/solid` si falta el equivalente |

**Archivos leídos para este informe:** `Pages/POS/{Index,IndexMobile}.vue`, `Pages/POS/Partials/*.vue` (10 archivos), `Layouts/AppLayout.vue`, `Components/{Modal,DialogModal,ConfirmationModal,PrimaryButton,SecondaryButton,DangerButton,TextInput,VirtualNumpad,PaymentModal}.vue`, `Components/PaymentModalPartials/MultiPaymentProcessor.vue`, `Composables/{usePermissions,useVirtualNumpad,index}.js`, `app.js`, `bootstrap.js`, `presets/ezyventas.js`, `assets/{styles.scss,layout/_core.scss,layout/_typography.scss,layout/variables/_common.scss}`, `css/app.css`, `tailwind.config.js`, `vite.config.js`, `jsconfig.json`, `package.json`, `resources/views/app.blade.php`, `routes/web/POS.php`, `routes/web/products.php`, `routes/web/transactions.php`, `app/Http/Controllers/{PointOfSaleController,TransactionController}.php`, `app/Http/Middleware/HandleInertiaRequests.php`, `app/Services/Catalog/ProductCatalogService.php`, `app/Models/Product.php`, `database/seeders/PermissionSeeder.php`.
