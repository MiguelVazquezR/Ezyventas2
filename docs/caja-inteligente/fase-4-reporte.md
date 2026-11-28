# Fase 4 — Aprendizaje con el uso · Reporte

> Rama `feature/modo-caja-inteligente` · Fecha: 2026-11-27 · **Sin commits**.
> Decisiones aplicadas: **D9 a D14** (ver [`decisiones.md`](decisiones.md)), acordadas con el humano al abrir esta sesión.
> **Migraciones nuevas: ninguna.** El módulo no necesitó tocar el esquema.

---

## 1. Estado

| Pieza | Estado |
|---|---|
| Persistencia de la muestra (escaneo, alta rápida, corrección) | ✅ Completa — el vector ya no se descarta |
| Corrección del cajero (otro candidato y borrado de línea auto-agregada) | ✅ Completa — `corrected` se envía de verdad |
| Constructor de galería desde el catálogo (endpoints + sembrado por lotes) | ✅ Completo — 50 por lote, reanudable, con progreso |
| Sincronización incremental (ETag + `If-None-Match` + caché persistente) | ✅ Completa — IndexedDB con degradación a memoria |
| Gestión mínima de la galería (ver y borrar muestras) | ✅ Completa en el panel del modo |
| Verificación automática (PHP + JS + build) | ✅ Sin fallos nuevos |
| Prueba en navegador con cámara real | ⏳ Requiere tu equipo (ver §7) |

---

## 2. Qué se construyó

### 2.1 Los cinco huecos del handoff, cerrados

| Hueco (§6 del handoff) | Cómo quedó |
|---|---|
| **1. El vector se descartaba** | `resolveUnknown()` ya no llama a `engine.addSample(..., null, ...)`: pide el recorte del fotograma real, obtiene el vector de DINOv2 en el worker, arma el cuerpo con `buildSampleRequest()` y hace `POST /samples`. Cada decisión del cajero es ahora una fila en `smartpos_product_samples`. |
| **2. No había corrección del cajero** | Dos caminos, ambos registran `corrected` y guardan una muestra `cashier_correction`: (a) confirmar un candidato que **no** es el primero de la lista, y (b) borrar del carrito una línea que puso el modo. `corrected` viaja en los eventos de análisis y de venta. |
| **3. Faltaba el constructor de galería** | Dos endpoints nuevos + sembrado por lotes de 50 con reanudación y progreso en el panel. Regla de imágenes: principal para el producto sin variantes, una por **variante con foto** cuando las tiene. |
| **4. No había sincronización incremental real** | La galería se guarda en **IndexedDB** con su `ETag`; la primera página se revalida con `If-None-Match` y un `304` evita descargar nada. Si falla la red, se usa la copia guardada (el modo sigue reconociendo). |
| **5. Alcance fuera de la fase** | Documentado en §7: no hay entrenamiento propio ni migración de `model_version`; sólo se rechazan dimensiones distintas, como antes. |

### 2.2 Backend — dos endpoints nuevos (sin migraciones)

| Ruta | Nombre | Para qué |
|---|---|---|
| `GET /pos/smart-checkout/gallery/candidates` | `pos.smart-checkout.gallery.candidates` | Productos de la sucursal con foto real, sus imágenes y cuántas muestras tienen. Paginado **keyset** por `after_id` (un `offset` se rompería: el catálogo cambia mientras se siembra), `limit` ≤ 50, `filter=pending\|all`, más `total_pending` en una sola consulta agregada. |
| `GET /pos/smart-checkout/gallery/products/{product}` | `pos.smart-checkout.gallery.product` | Las muestras guardadas de un producto (`id`, `source`, `quality`, `dim`, `model_version`, `has_crop`, `created_at`) para listarlas y borrarlas desde el panel. |

Ambos: `auth` + `can:pos.access` + `throttle:120,1`, 403 con el modo apagado, 404 (nunca 403) para un producto de otra
sucursal, 422 JSON en español, y tenant/sucursal **siempre** desde el usuario autenticado.

Detalle relevante: en las rutas `/pos/*` un `$request->validate()` **redirigía 302** aunque el cliente pidiera JSON
(`ApiExceptionRenderer` sólo devuelve JSON bajo `/api/*`). Los dos controladores nuevos construyen el 422 a mano, igual
que `GalleryController::store()`. No se tocó el manejador global.

### 2.3 Frontend — aprendizaje y sincronización

| Archivo | Rol |
|---|---|
| `Composables/smartCheckout/learning/samplePayload.js` | Empaquetado y validación del cuerpo de `/samples` (vector float32 → base64, `dim` coherente, `source` del enum, recorte ≤ 80 KB) y `estimateQuality()`. Puro. |
| `Composables/smartCheckout/learning/cropUtils.js` | `box` normalizada → recorte cuadrado en píxeles, con margen y acotado al fotograma. |
| `Composables/smartCheckout/learning/galleryCache.js` | Caché de la galería con `ETag`, caducidad y tope de tamaño. **Nunca lanza**: la galería es una optimización. |
| `Composables/smartCheckout/learning/idbDriver.js` | Driver IndexedDB (base `ezyventas-smartpos`) con degradación a memoria si el navegador no la da. |
| `Composables/smartCheckout/learning/seedGallery.js` | Orquestador del sembrado: lotes, cursor keyset, reanudación, ritmo de 60/min, reintento tras 429 y cancelación. |
| `inference/OnnxWebRecognitionEngine.js` | Guarda el último fotograma por cámara (960 px de ancho) y expone `captureCrop()` / `frameInfo()`; `addSample()` ahora tiene tiempo máximo de espera. |
| `Pages/POS/SmartCheckout/useSmartCheckout.js` | Sincronización con `ETag`, persistencia de muestras, corrección del cajero y coordinación del sembrado. |
| `Pages/POS/SmartCheckout/SmartCheckoutPanel.vue` | Sección «Aprendizaje con el uso»: contador pendiente, sembrado con progreso y cancelación, y diálogo de muestras por producto con borrado. |

**Por qué el fotograma se guarda en un canvas:** el `ImageBitmap` de un análisis se **transfiere** al worker y queda
detached, así que no hay píxeles que recortar después. Se copia a un canvas de 960 px antes de transferir (~2 MB por
cámara). Un fotograma con más de 8 s se descarta: mejor no guardar muestra que guardar la de un producto que ya se fue.

### 2.4 Enganches en archivos existentes (mínimos)

| Archivo | Enganche |
|---|---|
| `resources/js/Pages/POS/Index.vue` y `IndexMobile.vue` | `removeCartItem()` avisa a la sesión antes de borrar (borrar una línea del modo = corrección), y la sesión recibe `subscription_id` de `auth.current_branch` para la clave de caché. |
| `routes/web/smart-checkout.php` | Las dos rutas nuevas y su comentario. |
| `tests/js/index.js` | Dos líneas: importa las dos suites nuevas. |

`useSmartCheckout.js` ya era de este módulo; el resto del POS, sus props, el cobro y la báscula **no se tocaron**.

---

## 3. Migraciones

**Ninguna.** El diseño acordado («detectar pendientes al abrir el panel») evita la tabla de pendientes: el servidor
calcula qué productos tienen menos muestras que imágenes y el navegador los siembra por lotes. No hay columnas nuevas,
ni índices nuevos, ni datos que migrar.

La comprobación contra **MySQL real** (las pruebas PHPUnit corren en SQLite) se hizo con un script temporal ya borrado:

```
driver=mysql db=ezyventas2
candidates(pending, limit=5): 5 items, total_pending=7, has_more=true, next_cursor=16, 35 ms
  #1 Computadora portatil -> 2 imágenes (samples=0, pending=2)
     [variant] Windows 10  …/storage/22/10.png
     [variant] Windows 11  …/storage/23/11.png
  #2 Funda Iphone -> 1 imágenes (samples=0, pending=1)
     [general] - …/storage/19/iphn2.jfif
candidates(all, limit=50): 7 items, total_pending=7, 6 ms
otro tenant (sub 2): 5 items, total_pending=5      ← aislamiento correcto
```

Es decir: el producto **con** variantes devuelve una imagen por variante con su etiqueta y **sin** la general; el
**sin** variantes devuelve una sola imagen principal; los 9 productos sin foto real no aparecen.

---

## 4. Cómo probarlo

### 4.1 Sembrado del catálogo (no necesita cámara)

1. Entra al POS con un negocio que tenga el modo habilitado y **modelos publicados** (`public/models/index.json`).
2. Enciende el modo. En la cabecera del panel, pulsa el icono de **base de datos**: verás «N sin muestras».
3. Pulsa **«Sembrar catálogo»**. La barra avanza; el análisis se pausa mientras dura y se reanuda al terminar.
   Cada imagen tarda ~1 s (límite del endpoint: 60 muestras/minuto), así que un lote de 50 ronda el minuto.
4. Al terminar: «X imágenes guardadas · Y omitidas · Z con error» y el contador de la galería del panel sube.
5. Cierra la pestaña a mitad y vuelve: el sembrado **reanuda** donde se quedó (cursor guardado en el terminal).

### 4.2 Que la galería no se vuelva a descargar

1. Con el modo encendido y la galería ya descargada, apágalo y vuelve a encenderlo.
2. En el panel, «muestras en la galería · **desde este terminal**» = respondió `304` y no se bajó nada.
   (En las herramientas del navegador: `GET /pos/smart-checkout/gallery` → 304, sin cuerpo.)

### 4.3 Resolver un producto desconocido (necesita cámara)

1. Pon un producto que no esté en la galería en el mostrador hasta que salga en rojo.
2. Escanéalo o búscalo y agrégalo: el panel lo añade al carrito **y** guarda una muestra `barcode_scan` /
   `quick_register` del recorte real.
3. En la sección de aprendizaje, el producto aparece en «Productos con muestras»: pulsa el ojo, revisa las muestras y
   borra la que esté mal con la papelera.

### 4.4 Corrección del cajero

1. Con `auto_accept` apagado, espera una fila amarilla y elige un candidato que **no** sea el primero.
   El contador de la venta en curso no sube en «Confirmados» sino en correcciones, y se guarda una muestra
   `cashier_correction`.
2. Alternativa: deja que el modo agregue un producto y **quítalo del carrito**. También es una corrección.

### 4.5 Comandos

```powershell
php artisan test                                   # 24 fallos preexistentes, 481 pasadas
php artisan test --filter SmartCheckout            # 86 en verde
node --test tests/js                               # 229 en verde
node node_modules/vite/bin/vite.js build           # exit 0
php artisan route:list --path=smart-checkout -v    # 13 rutas
```

---

## 5. Resultados de la verificación

| Comprobación | Antes (Fase 3) | Ahora | Veredicto |
|---|---|---|---|
| `php artisan test` | 24 fallos / 462 pasadas | **24 fallos / 481 pasadas** (3380 aserciones) | Sin fallos nuevos; +19 pruebas |
| `php artisan test --filter SmartCheckout` | 67 pasadas | **86 pasadas** (920 aserciones) | Todo en verde |
| `node --test tests/js` | 190 pasadas | **229 pasadas**, 0 fallos | Todo en verde; +39 pruebas |
| `vite build` | exit 0 | **exit 0** (19 s) | Sin errores |
| Pruebas de navegador (IndexedDB, canvas, webcam) | — | ⏳ | Sólo en tu equipo |

Pruebas nuevas: 19 PHP (`SmartCheckoutGalleryCandidatesTest` 11, `SmartCheckoutGalleryProductSamplesTest` 8) y 39 JS
(`learning.test.mjs` 21 de caché/empaquetado/recorte/calidad, `seedGallery.test.mjs` 18 de lotes, reanudación, ritmo,
429, cancelación y páginas vacías).

---

## 6. Riesgos y límites conocidos

1. **Rendimiento de `total_pending`**: es una consulta agregada con subconsultas por producto, y el emparejamiento de
   fotos de variante mira `custom_properties` (no indexable). Medido en 35 ms con 16 productos; en catálogos de miles
   puede crecer. Mitigación si hiciera falta: cachear el contador unos segundos o mover el cálculo a un comando.
2. **Divergencia posible con datos dañados**: si un producto tiene fotos de variante cuyas propiedades no emparejan con
   ninguna variante (valor renombrado a mano), el `pending` de cada fila es exacto pero `total_pending` puede sobrecontar
   ese producto. Se descarta en el listado, así que no se siembra dos veces; sólo el contador del panel quedaría alto.
3. **Imágenes de catálogo remotas**: si algún día los medios viven en un CDN sin CORS, el `fetch()` de la imagen falla y
   ese producto se cuenta como «omitido» sin romper el lote. Hoy los medios están en el disco `public` (mismo origen).
4. **Recortes y privacidad**: se sigue la preferencia ya existente `save_crops`. Con ella apagada se guarda el vector y
   **no** la imagen; el recorte que se envía es de 224×224 y ≤ 80 KB.
5. **Tope de 10 por producto desde el cliente**: se respeta para sembrado y escaneo. Las **correcciones** sí se envían
   aunque el producto ya tenga 10, porque el servidor rota la muestra más débil y esa señal es la más valiosa. Si
   prefieres que el tope sea absoluto también para correcciones, es un cambio de una línea.
6. **Fuera de alcance, como pedía el handoff**: entrenamiento de modelos propios y migración de `model_version`
   (regenerar los embeddings al cambiar de modelo). Hoy un cambio de modelo deja la galería del modelo viejo intacta y
   las nuevas muestras van al nuevo; el cliente pide siempre la versión de `index.json`.
7. **Sembrado automático**: se dispara solo cuando la caja está **en pausa** (o al pulsar el botón). En marcha sólo se
   avisa con el contador, para no quitarle el worker a una venta en curso. Si prefieres que arranque siempre, se cambia
   en `maybeAutoSeed()`.

---

## 7. Lo que no pude verificar en este entorno

1. **Navegador real**: IndexedDB (apertura, cuota, `onblocked` entre pestañas), `createImageBitmap` con recorte,
   `toDataURL` de un canvas y el `fetch` de las imágenes del catálogo. La lógica está probada en Node con dobles y con
   degradación a memoria, pero el camino real necesita tu equipo.
2. **Cámara real**: el flujo de corrección y de resolución de desconocidos sólo se puede ejercitar con hardware y
   modelos cargados (requiere HTTPS o localhost).
3. **Latencia del sembrado** con el catálogo grande del cliente: el límite de 60 muestras/minuto del endpoint es el
   cuello de botella real (≈1 s por imagen), no el embedding (~70 ms).

---

## 8. Confirmación explícita

> **Con el Modo Caja Inteligente apagado y la báscula deshabilitada, el módulo Punto de venta se comporta igual que
> antes.**

Sin `smart_checkout_enabled` no se monta el panel, no se descarga ningún chunk del módulo (ni los del aprendizaje), no
se consulta la galería, no se piden permisos de cámara y el carrito y el cobro funcionan igual: los dos únicos enganches
en el POS (`removeCartItem` y el `subscription_id` de la sesión) son llamadas a un objeto que **es `null`** mientras el
modo no se enciende. La suite mantiene los mismos 24 fallos preexistentes.

Las pruebas de propiedad de la Fase 2 (`SmartCheckoutDataEquivalenceTest`, `SmartCheckoutPropsTest`) siguen en verde, y
no se modificó ninguna migración, modelo ni servicio de fases anteriores.
