# Decisiones acordadas — Modo Caja Inteligente

> Registro de las decisiones humanas que la Fase 0 dejó abiertas ([`00-descubrimiento.md`](00-descubrimiento.md) §16).
> Este archivo es **vinculante** para las fases siguientes: si el código y este documento discrepan, manda este documento
> o se corrige aquí primero.
>
> Fecha: 2026-11-27 · Rama: `feature/modo-caja-inteligente` (creada desde `Miguel`) · Estado: Fase 0 cerrada.

---

## D1 · Cantidades fraccionarias en el cobro — **APROBADO (condicionado)**

**Decisión:** se modifica el POS **solo** para productos a granel y **solo** cuando la báscula está habilitada.

| Cambio | Archivo | Regla |
|---|---|---|
| Validación de cantidad | `app/Http/Controllers/PointOfSaleController.php:229` | `min:1` pasa a regla condicional: `0.001` mínimo **solo si** el producto tiene `is_bulk` **y** `scale_enabled` está activo; el resto sigue exigiendo `min:1` |
| Cast de cantidad | `app/Models/TransactionItem.php:32` | `decimal:2` → `decimal:3` (la columna ya es `decimal(10,3)`) |
| Cast de stock | `app/Models/BranchProduct.php:21-26`, `BranchProductAttribute.php:22` | `decimal:2` → `decimal:3` |

**Invariante que hay que poder demostrar en cada fase:** con `scale_enabled` apagado, el POS valida y cobra **exactamente**
como hoy (una cantidad `0.5` sigue siendo rechazada). La validación ampliada **no** puede depender solo de `is_bulk`,
porque eso cambiaría el comportamiento de hoy con los flags apagados.

Riesgo aceptado: la cantidad pasa a tener 3 decimales en la lectura. Verificación obligatoria en la prueba de regresión
(devoluciones y reposición de stock con cantidades fraccionarias).

---

## D2 · Dónde viven los flags — **RESUELTO CON CRITERIO DE NEGOCIO**

**Decisión del negocio (texto literal del humano):** «Estas funciones no se cobrarán extra, por lo tanto no se podrán
contratar. Son solo preferencias de acuerdo al tipo de negocio que pueden activar o desactivar en cualquier momento
desde el módulo "Punto de venta"». Debe recordarse el estado para no tener que activarlas cada vez que se entra al módulo.

**Consecuencia arquitectónica:** las opciones «módulo de plan» y «columna nueva en `subscriptions`» quedan **descartadas**
(la primera porque `plan_items` es catálogo comercial de pago; la segunda porque rompe el patrón del repo). El
`SettingDefinition`/`SettingValue` existente también queda descartado para estos dos flags porque su seeder borra las
definiciones al ejecutarse y la BD ya divergió del seeder.

**Diseño adoptado — dos capas, ninguna tabla existente modificada:**

### Capa 1 · Preferencia del negocio (persistente, por tenant)

Tabla **nueva** `smartpos_settings`, una fila por suscripción:

| Columna | Tipo | Notas |
|---|---|---|
| `id` | bigint PK | |
| `subscription_id` | FK `subscriptions`, **unique**, `cascadeOnDelete` | el tenant; ausencia de fila = todo apagado |
| `smart_checkout_enabled` | boolean, default `false` | permite mostrar el botón del Modo Caja Inteligente |
| `scale_enabled` | boolean, default `false` | permite la báscula/granel |
| umbrales, cámaras, perfil de báscula, retención | JSON / columnas propias | ver sección 8 de la especificación |
| `created_at` / `updated_at` | timestamps | |

Se edita **desde el módulo Punto de venta** (no desde el panel de administración) por un usuario con permiso
`scale.settings`, mediante `PUT /pos/smart-checkout/preferences` (ruta nueva, `pos.smart-checkout.preferences.update`).
**Esa ruta no comprueba el flag**: es el interruptor; si lo comprobara nunca se podría encender. Apagado por defecto =
sin fila o ambos booleanos en `false`. La UI lo expone como botón «Preferencias de caja» (`pi pi-sliders-h`) en la barra
de acciones del POS, visible solo con `scale.settings`, y el cambio refresca únicamente la prop `scale` de la página.

*Alcance:* nivel **suscripción** (es preferencia del negocio, como dijo el humano). Si más adelante una cadena necesita
activarlo por sucursal, se añade `branch_id` nullable a esta misma tabla nueva y se resuelve con fallback; no se toca nada
existente para hacerlo.

### Capa 2 · Estado del cajero en esta terminal (recordado, no persistente en servidor)

El ON/OFF del modo, las cámaras elegidas y la cámara activa se guardan en `localStorage`, siguiendo el patrón que ya usa
el POS para el carrito y el modo retail/comandas:

```
clave: smartpos_{userId}_{branchId}     → { mode: 'on'|'off', cameras: [...], ... }
```

> Implementado como `smartpos_checkout_{userId}_{branchId}` (`{active: bool}`) para el Modo Caja Inteligente y
> `smartpos_scale_{userId}_{branchId}` (`{driver}`) para el tipo de báscula de la terminal.

Ambos interruptores del negocio (báscula y Modo Caja Inteligente) viven en el modal **«Preferencias de caja»** del POS
(`PUT /pos/smart-checkout/preferences`), de modo que se encienden y apagan desde el propio módulo sin tocar la base.

Así «el cajero no tiene que activarlo cada vez que sale del módulo y vuelve a entrar», y una terminal no arrastra el
estado de otra. El backend **siempre** revalida la Capa 1 en sus rutas: el `localStorage` no autoriza nada.

**Por qué no `PlanItem`:** esos ítems son contratables y de pago (`module_billing`, `module_ai_agent`). El humano ha dicho
que estas funciones no se cobran; meterlas ahí las haría aparecer en el catálogo comercial y en las versiones de suscripción.

---

## D3 · Producto pesable — **is_bulk + unidad de peso**

**Decisión:** un producto es pesable cuando:

```php
products.is_bulk === true  &&  products.measure_unit ∈ { 'Kg', 'Grs' }
```

- No se crea ninguna columna ni tabla nueva para marcarlo.
- `Pza`, `Lts`, `Mts`, `Cm`: aunque tengan `is_bulk`, **no** abren el panel de pesado (se capturan como hoy: cantidad decimal manual).
- La comparación de `measure_unit` es insensible a mayúsculas/espacios (`kg`, `KG`, `Grs ` → normalizar).

---

## D3b · Unidad y precio — **precio por la unidad indicada**

`products.selling_price` es el precio de **1 `measure_unit`** (coherente con el texto actual del formulario de producto:
«el precio que equivalga a 1 unidad completa; si vendes 0.250 cobrará una cuarta parte»).

```
total = cantidad_en_la_unidad_del_producto × selling_price
```

- `measure_unit = 'Kg'`: la báscula entrega kg → se usa tal cual.
- `measure_unit = 'Grs'`: si la báscula entrega kg, se convierte a gramos (×1000) antes de multiplicar.
- `total` se redondea con la convención del sistema: `2` decimales, `Intl.NumberFormat('es-MX', { currency: 'MXN' })` en el cliente y `round(x, 2)` en el servidor.
- Se rechazan peso `<= 0` y lecturas negativas.

---

## D4 · Agregar cantidad al carrito — **firma extendida (aditiva)**

`addToCart(data)` pasa a aceptar `quantity` opcional, **sin cambiar el comportamiento actual** cuando no se envía:

```js
// resources/js/Pages/POS/Index.vue:133  y  IndexMobile.vue:135
const addToCart = (data) => {
    const { product, variant, quantity } = data;
    const quantityToAdd = Number(quantity) > 0 ? roundQuantity(Number(quantity)) : 1;  // ← único cambio de semántica
    // …resto idéntico: merge por cartItemId, price_tiers, stock, isManualPrice…
};
```

- Sin `quantity` → se comporta exactamente como hoy (`+1`).
- Con `quantity` (báscula) → crea o incrementa la línea con el peso, recalculando `price_tiers` igual que hoy.
- Se aplica en **las dos** páginas (escritorio y móvil) para no divergir.
- La cámara y la báscula usan **la misma** función, por evento (`emit('addToCart', …)`); no se crea una segunda vía de agregado.
- `roundQuantity(value)` redondea a 3 decimales para evitar `0.1 + 0.2 = 0.30000000000000004` en la cantidad de la línea.

---

## D5 · Alcance de la Fase 1 — **escritorio y móvil**

La báscula se implementa en `Index.vue` **y** `IndexMobile.vue`, con las pruebas correspondientes en ambas.
Cualquier cambio de la lógica del carrito se replica en los dos archivos (hasta que se decida un refactor a composable,
que **no** entra en esta fase).

---

## D6 · Rama de trabajo — **desde `Miguel`**

```
feature/modo-caja-inteligente   (creada desde Miguel, 2026-11-27)
```

Sin commits ni push hasta que se pida. Los cambios se entregan en el árbol de trabajo para revisión.

---

## Pendientes que no bloquean la Fase 1

| # | Duda | Fase |
|---|---|---|
| D7 | Dónde se alojan los modelos ONNX en producción: carpeta pública del dominio, almacenamiento de objetos o CDN. Y si se acepta Git LFS para versionarlos | 3 |
| D8 | Token de Hugging Face: **no necesario** (los dos modelos son públicos). Solo se pediría si apareciera un límite de descarga | 3 |
| D9 | Si el negocio quiere, más adelante, activar la báscula por sucursal en vez de por suscripción: se resuelve añadiendo `branch_id` a `smartpos_settings` (tabla nueva) | posterior |

---

## Sin permisos propios (decisión del humano, 2026-11-27)

**Las funciones no llevan permisos.** Texto literal de la decisión: «No necesito permisos para usar estas funciones.
Si ya las registraste, remuévelas. Son funciones para cualquier usuario sin restricción».

Consecuencias en el código:

| Antes (especificación §10) | Ahora |
|---|---|
| `smart_checkout.use`, `smart_checkout.settings`, `scale.use`, `scale.settings` | **No se crean**. Se eliminó la migración de datos que los insertaba y no se tocó `PermissionSeeder` |
| Rutas con `can:scale.use` / `can:scale.settings` | Rutas con `can:pos.access`: el único requisito es poder entrar al Punto de venta (permiso que ya existía) |
| `can_use` / `can_configure` en la prop `scale` | Siempre `true`; la UI los conserva para no cambiar el contrato |
| `authorize()` de los FormRequests por permiso | Solo exige usuario autenticado |

El aislamiento entre negocios **no depende de permisos**: la suscripción sale siempre del usuario autenticado
(`$request->user()->branch->subscription_id`), nunca del cuerpo de la petición.

Se mantiene el gate por la **preferencia del negocio** (`scale_enabled`): si está apagada, las rutas de la báscula
responden 403 y el panel no se monta. La ruta `PUT /preferences` es la excepción, porque es el interruptor.

### Nota sobre el dueño de la suscripción

Sigue vigente el hallazgo del descubrimiento: `Gate::before` (`AppServiceProvider.php:55-94`) solo autoriza al dueño
si el permiso **existe** y pertenece a un módulo contratado. Por eso `can:pos.access` funciona: es un permiso real del
módulo «Punto de Venta».

---

# Fase 4 · Decisiones del aprendizaje con el uso

> Acordadas con el humano al inicio de la sesión de la Fase 4 (2026-11-27). Vinculantes para el código ya escrito y
> para la Fase 5. El detalle de lo construido está en [`fase-4-reporte.md`](fase-4-reporte.md).

## D9 · Qué imágenes siembran la galería — **PRINCIPAL SIN VARIANTES, VARIANTES CON VARIANTES**

**Decisión (texto literal):** «Que sean las principales para productos sin variantes y para productos con variantes las
imágenes de las variantes».

| Caso | Imágenes que se siembran |
|---|---|
| Producto **sin** variantes | Solo la **imagen principal** (primera de `product-general-images`) |
| Producto **con** variantes y fotos de variante | Una por **variante con foto** (`product-variant-images`), con su etiqueta; **no** se incluye la principal |
| Producto con variantes pero **sin** fotos de variante | Cae a la principal (así nunca queda sin imagen) |

Consecuencia: un producto con 4 variantes con foto consume 4 de sus 10 muestras de golpe; el resto del cupo queda para
lo que aprenda del mostrador. Los productos sin foto real **no** son candidatos (el `placehold.co` que rellena el
catálogo del POS no cuenta como imagen).

## D10 · Cómo se dispara el sembrado — **BOTÓN EN EL PANEL + AUTOMÁTICO AL DETECTAR PENDIENTES**

**Decisión (texto literal):** botón en el panel del Modo Caja Inteligente **y** automático al guardar un producto con
foto, «que se controlen las altas masivas de la mejor manera y que sea cómodo para el usuario». Sin comando de Artisan.

**Cómo se implementó, y por qué así:** el embedding DINOv2 sólo se puede calcular en el navegador, así que el servidor
no puede sembrar nada al guardar un producto. En lugar de una tabla de pendientes (que exigiría migración y un enganche
en `CreateProduct`/`UpdateProduct`), el endpoint de candidatos **calcula** los pendientes: un producto con foto y menos
muestras que imágenes es pendiente.

- Al abrir la sección de aprendizaje el terminal pregunta cuántos hay y lo muestra. Las altas masivas se absorben solas
  porque el sembrado va por lotes y reanudable: no bloquea ningún guardado y no hay cola que se atasque.
- **Automático de verdad** cuando la caja está **en pausa**: ahí el terminal siembra solo. En marcha no se le quita el
  worker a una venta; sólo se muestra el contador.
- Si se quiere que arranque siempre (también con el análisis activo), se cambia en `maybeAutoSeed()` del panel.

## D11 · Tope por lote — **50 PRODUCTOS, CON REANUDACIÓN**

**Decisión:** 50 por lote, con la posibilidad de reanudar donde se quedó.

Un lote de 50 imágenes ronda el minuto (el endpoint `POST /samples` está limitado a 60 muestras/minuto, ≈1 s por
imagen; el embedding son ~70 ms y no es el cuello de botella). El cursor es **keyset por `id` de producto** y se guarda
en el terminal después de cada producto: cerrar la pestaña cuesta como mucho un producto de trabajo.

## D12 · Píxeles de la muestra — **ÚLTIMO FOTOGRAMA DE LA CÁMARA, RECORTADO POR EL BOX**

**Decisión:** la muestra sale de lo que la cámara vio de verdad, no de la foto del catálogo.

Consecuencias técnicas (todas implementadas):
- El motor guarda una copia del último fotograma de cada cámara (960 px de ancho) **antes** de transferir el bitmap al
  worker, porque `postMessage` lo deja detached.
- Un fotograma con más de **8 s** se descarta: mejor no guardar muestra que guardar la de un producto que ya se fue.
- Si no hay cámara (modo simulado) o no hay modelos publicados, **no se guarda muestra**: se sigue contando el evento,
  pero no se inventa un vector. La tienda sin muestras sigue funcionando con código de barras y búsqueda manual.

## D13 · Persistencia de la galería — **INDEXEDDB CON ETag**

**Decisión:** IndexedDB con revalidación por `ETag`, no `localStorage`.

Motivo: una galería de 4.000 muestras son ~6 MB de vectores; en base64 crece un 33 % y `localStorage` (≈5 MB) se queda
corto en cuanto el catálogo es mediano. El driver degrada a memoria si el navegador no ofrece IndexedDB (modo privado) y
la caché **nunca** puede romper el POS: si falla, se descarga de nuevo y punto.

## D14 · Gestión de la galería — **VISTA MÍNIMA EN EL PANEL**

**Decisión:** sí, una vista mínima dentro del panel (ver las muestras de un producto y borrar una).

Usa el `DELETE /samples/{id}` que ya existía sin consumidor. Sin ella no había forma de quitar una muestra mala, y una
muestra mala empeora el reconocimiento de ese producto para siempre.

---

## Nota de alcance de la Fase 4

Quedan **fuera** de esta fase, por acuerdo con el handoff: entrenamiento de modelos propios y migración de
`model_version` (regenerar los embeddings al cambiar de modelo) más allá de rechazar dimensiones distintas. Un cambio de
modelo hoy no re-embebe la galería: el cliente pide la versión de `index.json` y las muestras del modelo viejo quedan
intactas pero sin usar.
