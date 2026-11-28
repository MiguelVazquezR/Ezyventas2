# HANDOFF — Fases 4 y 5 de "Modo Caja Inteligente"

> Documento de traspaso para **continuar en una sesión nueva** sin volver a descubrir el proyecto.
> Léelo completo antes de tocar código. Si algo aquí contradice al repositorio, **manda el repositorio** y
> actualiza este archivo.
>
> Estado: **Fases 0–5 implementadas y verificadas**. Quedan sólo las verificaciones que necesita el humano y su equipo:
> latencia real con cámara, comparación de proponedores con fotos reales y la prueba en navegador del aprendizaje (§9).
> Rama `feature/modo-caja-inteligente`, **sin commits**.

---

## 1. Arranque rápido (5 minutos)

```powershell
# Rama y estado
git branch --show-current          # feature/modo-caja-inteligente (creada desde Miguel)
git status --short                 # todo sin commitear: es lo esperado

# Verificación base (debe dar EXACTAMENTE esto)
php artisan test                   # 24 failed, 495 passed (3668 assertions)  ← los 24 son preexistentes
php artisan test --filter SmartCheckout   # 100 passed (1208 assertions)
node --test tests/js               # 238 passed, 0 failed
node node_modules/vite/bin/vite.js build   # ✓ built, exit 0

# Segunda corrida de seguridad del backend (a veces aparece 1 fallo intermitente preexistente:
# Api\V1\CustomerApiTest > it searches customers by name or phone — Faker genera "Ana"; no es nuestro)
```

**Nunca uses `npm run build`** en esta máquina: el `npm.ps1` está bloqueado por la política de PowerShell. Usa
`node node_modules/vite/bin/vite.js build` o `npm.cmd run build`.

---

## 2. Contexto del proyecto en 10 líneas

Ezyventas es un SaaS multi-tenant de punto de venta (Laravel 12.63 + PHP 8.3.37 + MySQL 8 `ezyventas2` + Inertia 2 +
Vue 3 + PrimeVue 4.5.5 + Tailwind 3.4 + Vite 7, Node 26.5). Se está añadiendo **"Modo Caja Inteligente"** (reconocimiento
por cámara) y **báscula de granel** dentro del módulo Punto de venta, sin crear módulos nuevos ni duplicar la lógica del
carrito. El carrito vive **solo en el frontend** (`addToCart` en `resources/js/Pages/POS/Index.vue` y su copia en
`IndexMobile.vue`); el backend solo lo conoce en el POST de cobro (`TransactionPaymentService::handleNewSale`).

Documentos de referencia (ya escritos, no hay que releerlos enteros):
- `docs/caja-inteligente/00-descubrimiento.md` — arquitectura real, multi-tenancy, permisos, convenciones.
- `docs/caja-inteligente/decisiones.md` — D1…D8 y **«Sin permisos propios»** (decisión del humano).
- `docs/caja-inteligente/fase-1-reporte.md`, `fase-2-reporte.md`, `fase-3-reporte.md` — qué se hizo en cada fase.
- `docs/caja-inteligente/modelos.md` — pipeline de modelos, licencias, latencias, protocolo de fotos reales.
- `docs/caja-inteligente/_research/{01,02,03}-*.md` — informes largos del descubrimiento (solo si hace falta el detalle).

---

## 3. Reglas inviolables (vienen de la especificación original + decisiones del humano)

1. **Con el modo apagado y la báscula deshabilitada, el POS se comporta igual que antes.** Sin excepciones.
2. **Solo cambios aditivos.** Tablas, archivos, rutas y permisos nuevos. En archivos existentes, enganches mínimos
   (está documentado cuáles en los reportes de fase).
3. **No dupliques la lógica del POS**: lo que agregue al carrito debe llamar a `addToCart`; el cobro, a
   `TransactionPaymentService`.
4. **Carga perezosa**: nada del módulo se descarga si el modo/la báscula están apagados (ya se cumple: chunks aparte).
5. **Sin permisos nuevos** para estas funciones: las rutas usan `auth` + `can:pos.access`. El gating real es la
   preferencia del negocio (`smartpos_settings`) y el estado por terminal (`localStorage`).
6. **Nunca re-ejecutes `PermissionSeeder`** (hace `Permission::query()->delete()` y arrastra asignaciones).
7. **No ejecutes migraciones ni comandos destructivos sin confirmar con el humano**, aunque esta sesión pidió correrlas.
8. **No hagas commit ni push** salvo que el humano lo pida.
9. **Pint solo sobre archivos nuevos**: el repo tiene ~395 issues de estilo preexistentes.
10. El dueño de la suscripción (usuario sin roles) **pasa cualquier permiso** vía `Gate::before`
    (`app/Providers/AppServiceProvider.php:55-94`): no sirve como control de acceso.

---

## 4. Mapa del código construido (fases 1–4)

### Backend (todo en `app/`, sin paquete Composer)
| Archivo | Rol |
|---|---|
| `Models/SmartPosSetting.php` | Fila por suscripción: flags + JSON de ajustes |
| `Models/SmartPosDetectionEvent.php` | Métricas (análisis y ventas), sin imágenes |
| `Models/SmartPosProductSample.php` | Galería: vector float32, `source`, `crop_path`, `quality` |
| `Services/SmartCheckout/SmartPosSettingsService.php` | Settings, flags, defaults, degradación si falta la tabla |
| `Services/SmartCheckout/SmartCheckoutAnalyticsService.php` | `recordEvent()` y `todayMetrics()` |
| `Services/SmartCheckout/SmartCheckoutGalleryService.php` | Galería: `galleryFor`, `storeSample`, `deleteSample`, rotación 10/producto, recortes |
| `Services/SmartCheckout/SmartCheckoutCatalogGalleryService.php` | **(F4)** candidatos del catálogo (keyset, imágenes, `total_pending`) y muestras por producto |
| `Http/Controllers/SmartCheckout/ScaleController.php` | Báscula + interruptores |
| `Http/Controllers/SmartCheckout/SmartCheckoutController.php` | Ajustes del modo, eventos, métricas |
| `Http/Controllers/SmartCheckout/GalleryController.php` | Galería (ETag/cursor), muestras |
| `Http/Controllers/SmartCheckout/GalleryCatalogController.php` | **(F4)** los dos endpoints de catálogo |
| `Http/Requests/SmartCheckout/*.php` | 4 FormRequests con 422 JSON en español |
| `routes/web/smart-checkout.php` | 13 rutas bajo `/pos/smart-checkout` (`auth` + `can:pos.access`) |

### Frontend
| Archivo | Rol |
|---|---|
| `Composables/smartCheckout/scaleMath.js`, `drivers/*` | Báscula (Fase 1): 3 drivers, matemática pura |
| `Composables/smartCheckout/recognition/{confidence,RecognitionEngine,MockRecognitionEngine,index}.js` | Semáforo, fusión multi-cámara, motor simulado |
| `Composables/smartCheckout/inference/{tensorUtils,detectorPostprocess,frameStability,diffProposer,gallery,barcode,cameraSource}.js` | Inferencia pura (Fase 3) |
| `Composables/smartCheckout/inference/inference.worker.js` | Worker: modelos, propuestas, embeddings, códigos, galería |
| `Composables/smartCheckout/inference/OnnxWebRecognitionEngine.js` | Motor ONNX, misma interfaz que el simulado (+ buffer de fotograma y `captureCrop`, F4) |
| `Composables/smartCheckout/learning/{samplePayload,cropUtils,galleryCache,idbDriver,seedGallery}.js` | **(F4)** empaquetado de muestras, recorte, caché IndexedDB con ETag y orquestador del sembrado |
| `Pages/POS/SmartCheckout/{useSmartCheckout.js,SmartCheckoutPanel.vue,SmartCheckoutIndicators.vue,ScalePanel.vue,ScaleSettingsModal.vue,ScalePreferencesModal.vue}` | UI del modo y de la báscula |

### Enganches en archivos existentes (no tocar más allá de esto)
`app/Http/Controllers/PointOfSaleController.php` (props `scale` y `smartCheckout`, validación condicional de cantidad) ·
`app/Models/TransactionItem.php` + `BranchProduct*.php` (casts `decimal:3`) · `routes/web.php` (un `require`) ·
`resources/js/Pages/POS/{Index,IndexMobile}.vue` (sesiones perezosas, indicadores, panel, `quantity` en `addToCart`,
`reportCartRemoval` en `removeCartItem` y `subscriptionId` de la sesión — F4) ·
`Partials/PosLeftPanel.vue` (2–3 botones) · `package.json` (script `test:js` + 2 deps) · `vite.config.js`
(`worker.format: 'es'`) · `.gitignore` (modelos y venv).

---

## 5. Contratos que hay que respetar (no reinventar)

### Props que recibe el POS
```php
'scale'         => ['enabled'=>bool, 'can_use'=>true, 'can_configure'=>true, 'settings'=>array|null, 'preferences'=>['scale'=>bool,'smart_checkout'=>bool]],
'smartCheckout' => ['enabled'=>bool, 'settings'=>array|null],   // thresholds, auto_accept, cameras, model_version
```
`settings` es `null` cuando el flag está apagado.

### Endpoints (todos `/pos/smart-checkout`, `auth` + `can:pos.access`, 403 si el flag del modo está apagado)
```
PUT    /preferences                  excepción: es el interruptor, nunca se bloquea por el flag
GET    /settings   PUT /settings     ajustes del modo (umbrales, auto_accept, cámaras)
POST   /events                       throttle:120,1
GET    /metrics/today                ← Fase 5: le falta throttle
GET    /gallery                      ETag/304 + paginación por cursor (~4 MiB por página)   ← Fase 5: le falta throttle
POST   /samples                      throttle:60,1
DELETE /samples/{sample}
GET    /gallery/candidates           (F4) throttle:120,1 · keyset after_id · limit ≤50 · filter=pending|all
GET    /gallery/products/{product}   (F4) throttle:120,1 · muestras de un producto (404 si es de otra sucursal)
GET    /scale/settings  PUT          báscula
GET    /scale/weighable-products
```
Contratos completos (JSON de ejemplo, fórmulas de las métricas, rotación, formato base64 float32) en
`fase-2-reporte.md` §2/§4, `fase-3-reporte.md` §2 y `fase-4-reporte.md` §2.2.

### Motor de reconocimiento (interfaz estable, dos implementaciones)
```ts
init({galleryVersion}); analyze(frames); addSample(productId, crop, source); dispose();
// + bus: onDetection/onStatus/onError, start/pause/resume, setProducts, setGallery, setThresholds
// + Fase 4 (sólo ONNX): rememberFrame(), frameInfo(cameraId), captureCrop(cameraId, box), captureDetectionCrop(detection)
```
`MockRecognitionEngine` (Fase 2) y `OnnxWebRecognitionEngine` (Fase 3). La UI solo habla con esta interfaz. El motor
simulado **no** tiene píxeles: `persistSample()` lo detecta y no guarda muestra (sí cuenta el evento).

### Worker de inferencia (`inference.worker.js`)
Mensajes: `init` · `gallery` · `calibrate` · `analyze` · `sample` · `dispose`.
**Crítico:** todo lo que se envía por `postMessage` debe ser estructura clonable — **nunca un proxy de Vue**. Usa
`plainThresholds()` / `plainGallery()` de `OnnxWebRecognitionEngine.js` (hay prueba de regresión en
`tests/js/smart-checkout/workerPayload.test.mjs`).

### Modelos ONNX (publicados en `public/models`, gitignored)
`index.json` → `{gallery_model_version: "dinov2-small-onnx-2026-11", models:[{role:'embeddings'|'detector', file, sha256, …}]}`.
- DINOv2-small fp32 84,3 MB: entrada `pixel_values [1,3,224,224]`; resize lado corto 256 + center crop 224; media/desv
  ImageNet; salida `embedding [1,384]` (CLS, sin L2 → normaliza la app).
- RT-DETR-R18vd fp32 77,4 MB: entrada `pixel_values [1,3,640,640]` **sin normalizar** (solo /255); salidas
  `logits [1,300,80]` y `pred_boxes [1,300,4]` cxcywh; sigmoid + top-k, sin NMS (si hay duplicados, IoU 0.55).

---

## 6. FASE 4 — Aprendizaje con el uso · ✅ CERRADA

> **Implementada y verificada el 2026-11-27.** Reporte completo: [`fase-4-reporte.md`](fase-4-reporte.md).
> Decisiones aplicadas: **D9–D14** de [`decisiones.md`](decisiones.md). Sin migraciones nuevas.
> Lo que sigue se conserva como registro de lo que se planificó; **no** hay que reimplementarlo.

### Lo que quedó construido (resumen para no repetirlo)
- `POST /samples` se usa de verdad: escaneo, alta rápida y corrección del cajero guardan muestra del **recorte del
  último fotograma** de la cámara (D12). Sin fotograma reciente no se guarda muestra.
- `corrected` se registra por dos vías: confirmar un candidato que no es el primero y borrar del carrito una línea que
  puso el modo (`reportCartRemoval` desde `removeCartItem` de las dos páginas del POS).
- Dos endpoints nuevos: `GET /gallery/candidates` (keyset, ≤50, `filter=pending|all`, `total_pending`) y
  `GET /gallery/products/{product}` (muestras de un producto). 13 rutas en total bajo `/pos/smart-checkout`.
- Sembrado del catálogo en lotes de 50 con reanudación (`learning/seedGallery.js`), progreso y cancelación en el panel.
- Galería cacheada en IndexedDB con `ETag`/`If-None-Match` (304 sin descargar) y degradación a memoria.
- Vista mínima de gestión: ver las muestras de un producto y borrar una.
- 19 pruebas PHP y 39 JS nuevas.

### Criterios de aceptación — cumplidos
- Resolver un desconocido **persiste** una muestra y la galería del motor la incluye sin recargar la página (refresh
  coalescido de la galería, no de la página).
- Corregir un auto-agregado registra `corrected` y guarda una muestra `cashier_correction`.
- El tope de 10 por producto se respeta desde el cliente (siembra y escaneo; las correcciones rotan en el servidor).
- Una tienda sin muestras sigue funcionando: todo cae a código de barras o búsqueda manual.
- `php artisan test` sin fallos nuevos y `node --test tests/js` en verde.

### Decide después (si el humano lo pide)
- Sembrado automático **también** con el análisis en marcha (hoy sólo con la caja en pausa): `maybeAutoSeed()` del panel.
- Tope de 10 **absoluto** también para correcciones: una línea en `useSmartCheckout.persistSample()`.

---

## 6.b Antecedentes del plan original de la Fase 4 (histórico)

### Objetivo (especificación §12)
> Cada escaneo de código, alta rápida o corrección del cajero guarda una muestra nueva (con límite por producto).
> Sincronización incremental de la galería. Una tienda sin muestras debe funcionar igual (todo cae a código de barras
> o búsqueda manual) y mejorar sola con el uso.

### Lo que YA existe (reutilízalo, no lo reescribas)
- Tabla `smartpos_product_samples` y servicio completo: `storeSample` valida producto de la sucursal, normaliza L2,
  guarda recorte si `save_crops`, y rota a **10 muestras por producto** (expulsa la de menor `quality`, si no hay
  `quality` la más antigua; **la nueva siempre entra**).
- `GET /samples` no existe: se usa `GET /gallery` (ETag + cursor) y `POST /samples` / `DELETE /samples/{id}`.
- Worker: mensaje `sample` → devuelve `{productId, source, dim, vector(base64)}` calculado con DINOv2.
- Motor: `addSample(productId, bitmap, source)` ya hace ese viaje al worker.
- Panel: flujo de **producto desconocido** (buscar por código/nombre, alta rápida, descartar) y
  `session.resolveUnknown(row, product, {source})`.
- Sesión: `fetchGallery()` (paginado) + `resolveGalleryModelVersion()` (usa `index.json`) + `setGallery()` en el motor.

### Huecos concretos que hay que cerrar (esto es la Fase 4)
1. **El vector se descarta.** `useSmartCheckout.resolveUnknown()` llama a `engine.addSample()` y **no hace POST**.
   Hay que: obtener el `ImageBitmap` del recorte, pedir el vector al worker, `POST /samples` con
   `{product_id, model_version, dim, vector (base64), source, quality?, crop?}` y refrescar la galería del motor.
2. **No hay corrección del cajero.** Cuando el cajero corrige una identificación (confirma otro candidato, o borra una
   línea auto-agregada), no se registra `corrected` ni se guarda una muestra `cashier_correction`. Los contadores
   `counters.corrected` y `pendingTotals.corrected` existen pero nunca se incrementan (hoy se envía `corrected: 0`).
3. **No hay constructor de galería desde el catálogo.** Falta: endpoint que liste productos con imagen de la sucursal
   (paginado) + acción en la UI que, por producto, descargue la imagen, calcule el embedding en el worker y haga POST
   como `catalog_image`. Es lo que hace el sistema útil **antes** de tener cámara.
4. **Sincronización incremental real.** El servidor devuelve `ETag`; el cliente **no envía `If-None-Match`** ni guarda
   la última `gallery_version`. Con la galería en `localStorage`/`sessionStorage` se evita bajar MB en cada activación.
5. **Fuera de alcance de esta fase** (dejar documentado): entrenamiento de modelos propios, migración de `model_version`
   (regenerar embeddings si cambia el modelo) más allá de rechazar dimensiones distintas.

### Decisiones pendientes del humano — **RESUELTAS** (ver D9–D14 en `decisiones.md`)
1. Imágenes para sembrar → **principal si el producto no tiene variantes; las de las variantes si las tiene** (D9).
2. Cómo se dispara → **botón en el panel + automático al detectar pendientes**, sin comando Artisan (D10).
3. Tope por lote → **50 con reanudación** (D11).
4. Píxeles de la muestra → **último fotograma recortado por el box** (D12).
5. Persistencia de la galería → **IndexedDB con ETag** (D13).
6. Gestión → **vista mínima con ver/borrar** (D14).

### Criterios de aceptación sugeridos
- Escanear/buscar/alta rápida de un producto desconocido **persiste** una muestra y la galería del motor la incluye
  sin recargar la página (o con recarga parcial).
- Corregir un auto-agregado registra `corrected` y (si aplica) una muestra `cashier_correction`.
- Límite de 10 por producto respetado desde el cliente (no depende solo del servidor).
- Una tienda sin muestras sigue funcionando: todo cae a código de barras / búsqueda manual (ya ocurre).
- `php artisan test` sin fallos nuevos y `node --test tests/js` en verde.

### Pruebas a escribir
- Feature: POST de muestra desde el flujo de desconocido (validación, aislamiento, 422 con producto de otra sucursal).
- Feature: constructor de galería (si se hace endpoint): solo productos con imagen, paginado, por sucursal.
- Node: helper de empaquetado del vector (base64) y de calidad; cliente de galería con ETag (304 → no re-descarga).

---

## 7. FASE 5 — Métricas y endurecimiento · ✅ CERRADA

> **Implementada y verificada el 2026-11-27.** Reporte completo: [`fase-5-reporte.md`](fase-5-reporte.md).
> Documentación de usuario y de instalación: [`uso.md`](uso.md) y [`despliegue.md`](despliegue.md).

### Lo que quedó cerrado (los 7 huecos)
1. **`corrected` real** (se cerró en la Fase 4; ahora además se muestra en la barra de indicadores y en el pie del panel).
2. **Límites de tasa completos**: `GET /gallery` 30/min, `GET /metrics/today` 120/min, `DELETE /samples/{id}` 60/min.
   Las 6 rutas de configuración siguen sin límite a propósito (`PUT /preferences` es el interruptor del negocio y no
   puede bloquearse). El test transversal fija el inventario exacto, así que una ruta nueva sin límite rompe la suite.
3. **Retención programada**: `php artisan smartpos:purge` (eventos + recortes, `--dry-run`, `--days=`, `--subscription=`),
   programado a las 02:30 en `routes/console.php`. En producción exige el cron de `schedule:run` (está en `despliegue.md`).
   Los **vectores no se borran nunca** por edad.
4. **Test transversal de aislamiento**: `SmartCheckoutTenantIsolationTest`, 5 pruebas / 245 aserciones que recorren las
   13 rutas con dos negocios y fijan el inventario de middleware y de límites.
5. **Documentación**: `uso.md` (cajero/admin) y `despliegue.md` (instalación y mantenimiento), verificadas contra el código.
6. **Caminos de error**: revisados y cubiertos (9 pruebas nuevas del motor); se corrigió un fallo real del alta rápida que
   agregaba el producto **dos veces** al carrito.
7. **Rendimiento**: latencias y protocolo de medición documentados en `despliegue.md` §8.

### Huecos concretos del plan original (histórico)
1. ~~**`corrected` nunca se envía distinto de 0**~~ **CERRADO en la Fase 4.**
2. ~~**Límites de tasa** en `GET /gallery` y `GET /metrics/today`~~ **CERRADO.**
3. ~~**Retención** de `smartpos_detection_events`~~ **CERRADO** con comando + tarea programada.
4. ~~**Test transversal de aislamiento** entre tenants~~ **CERRADO.**
5. ~~**Documentación** `uso.md` y `despliegue.md`~~ **CERRADO.**
6. ~~**Manejo de errores**: pasada de revisión~~ **CERRADO.**
7. ~~**Medición de rendimiento** documentada~~ **CERRADO.**

### Criterios de aceptación sugeridos
- Todas las rutas nuevas con límite de tasa razonable y 403/404 coherentes.
- Test transversal de aislamiento verde con dos suscripciones.
- `uso.md` y `despliegue.md` completos y verificados contra el código.
- Suite completa sin fallos nuevos; indicadores del día cuadrando con los eventos registrados.

---

## 8. Gotchas ya pagadas (no vuelvas a tropezar)

| # | Trampa | Solución |
|---|---|---|
| 1 | `postMessage` con objetos reactivos de Vue → **«#<Object> could not be cloned»** | Convertir a objeto plano (`plainGallery`/`plainThresholds`); hay test de regresión |
| 2 | Vite: worker con code splitting exige `worker.format: 'es'` | Ya está en `vite.config.js` |
| 3 | ONNX Runtime Web con hilos requiere COOP/COEP (rompería impresión/pagos) | Se usa **monohilo** (`numThreads = 1`); no añadir cabeceras |
| 4 | RT-DETR se exportó **sin normalización** | Solo `/255`; no aplicar media/desv de ImageNet |
| 5 | DINOv2 espera resize 256 + center crop 224 | Implementado en `cropToModelInput()` del worker |
| 6 | `ref(objeto)` de Vue convierte a proxy reactivo | Cuidado al pasar a workers o a `structuredClone` |
| 7 | `npm.ps1` bloqueado | Usa `node node_modules/vite/bin/vite.js build` o `npm.cmd` |
| 8 | El POS web rechazaba cantidades <1 y los casts redondeaban a 2 decimales | Ya resuelto: regla condicional + `decimal:3` (solo aplica con la báscula activa) |
| 9 | Permisos filtrados por **nombre de módulo contratado** (`HandleInertiaRequests`) | Los permisos de este módulo son de `Punto de Venta`… pero **no hay permisos nuevos** (decisión del humano) |
| 10 | Bucket de pruebas en SQLite no soporta `DATE_FORMAT` | Usa `whereBetween`/`COALESCE`/`CASE WHEN` |
| 11 | `RefreshDatabase` comparte el proceso: el throttle por usuario filtraba entre tests | Desactivar `ThrottleRequests` en `setUp` |
| 12 | Cámara: `getUserMedia` solo en HTTPS/localhost | Hay mensajes por causa (`cameraSource.describeCameraError`) |
| 13 | Sin cámara, el motor usa cámara simulada autocalibrada con 3 productos | La UI avisa con banda ámbar; no lo presentes como cámara real |
| 14 | En rutas `/pos/*` un `$request->validate()` **redirige 302** aunque el cliente pida JSON (`ApiExceptionRenderer` sólo devuelve JSON bajo `/api/*`) | Los controladores capturan `ValidationException` y devuelven `{message, errors}` 422 a mano (lo hacen los dos de la Fase 4) |
| 15 | `GET /gallery/candidates` con `filter=pending` puede devolver una **página vacía con `has_more: true`** (descarta en PHP los ya sembrados) | El sembrado avanza con `next_cursor` del servidor, no con el último id; hay prueba de regresión en `seedGallery.test.mjs` |
| 16 | El `ImageBitmap` de un análisis se transfiere al worker y queda **detached** | El motor copia el fotograma a un canvas (960 px) antes de transferir; sin esa copia no hay píxeles para la muestra |
| 17 | `throttle:60,1` en `POST /samples` ⇒ ~1 muestra/segundo | El sembrado va a 1,1 s por muestra y reintenta tras un 429 con el `Retry-After`; un lote de 50 tarda ~1 minuto |
| 18 | `pack('g*', array_fill(...))` **sin `...`** empaqueta 4 bytes en vez de 1536 y la galería descarta la fila (`total=1, count=0`) | Usar `pack('g*', ...array_fill(...))`. Pasó en dos tests de la Fase 4 y se corrigió en la Fase 5 |
| 19 | SQLite **ignora** `DELETE … ORDER BY … LIMIT`: el borrado por trozos de `smartpos:purge` sólo se ejercita en MySQL | Verificado a mano contra MySQL con datos de prueba (1 evento y 1 recorte caducados); en tests se prueba la lógica, no el troceado |
| 20 | `Storage::fake()` usa una raíz fija: dos suites PHPUnit a la vez en el mismo checkout se borran los archivos entre sí | Ejecutar las suites **en serie** (no en paralelo sobre el mismo checkout) |
| 21 | El alta rápida del panel agregaba el producto **dos veces** al carrito (el panel lo añade al resolver el desconocido y la página volvía a añadirlo desde `product-created`) | `handleSmartProductCreated` de `Index.vue`/`IndexMobile.vue` ahora sólo refresca el catálogo, no vuelve a llamar a `addToCart` |
| 22 | `max_cameras_per_cycle` se guarda y se valida, pero el motor **no** lo usa todavía | Está documentado así en el servicio: es la palanca para el round-robin si la latencia real supera 1 s |

---

## 9. Pendientes que sólo el humano puede cerrar

1. **Latencia real < 1 s** en su equipo (i5 8.ª gen, sin GPU): el panel muestra los ms por análisis en la esquina de la
   vista previa. Si supera 1 s: bajar `intervalMs`, analizar una cámara por ciclo (round-robin, palanca ya persistida en
   `max_cameras_per_cycle`) o evaluar WebGPU. El protocolo está en [`despliegue.md`](despliegue.md) §8.3.
2. **Comparación de proponedores con fotos reales**: cuando tenga cámara, seguir el protocolo de
   `docs/caja-inteligente/modelos.md` §9 y correr
   `tools/ml/.venv\Scripts\python.exe tools/ml/evaluate_proposers.py --from-photos <carpeta>`.
3. **Cuantización INT8** descartada con datos (coseno 0.979 / IoU 0.17). Reintentar con set de calibración real.
4. **Prueba en navegador del aprendizaje** (Fase 4): sembrar el catálogo, resolver un desconocido, corregir y borrar una
   muestra con cámara real. Los pasos están en [`fase-4-reporte.md`](fase-4-reporte.md) §4 y en [`uso.md`](uso.md).
5. **Prueba en navegador del alta rápida**: tras la corrección del doble alta (gotcha 21), confirmar que el producto
   entra una sola vez al carrito y que la tarjeta nueva aparece en el catálogo.

---

## 10. Comandos útiles

```powershell
# Backend
php artisan test                                  # suite completa (~55 s)
php artisan test --filter SmartCheckout           # solo este módulo (100 pruebas)
php artisan route:list --path=smart-checkout -v   # 13 rutas y middleware
php artisan migrate                               # 000001, 000003 y 000004 ya aplicadas en local

# Retención (Fase 5)
php artisan smartpos:purge --dry-run              # qué borraría, sin borrar
php artisan smartpos:purge                        # eventos + recortes caducados
php artisan schedule:list                         # comprueba la tarea de las 02:30

# Frontend
node --test tests/js                              # 238 pruebas
node node_modules/vite/bin/vite.js build          # build de producción

# Modelos (solo si hay que regenerarlos)
.\tools\ml\.venv\Scripts\python.exe tools\ml\prepare_models.py --verify-only
.\tools\ml\.venv\Scripts\python.exe tools\ml\verify_models.py

# Ver el estado en la base local
php artisan tinker      # SmartPosSetting::first(), DB::table('smartpos_detection_events')->count()
```

`tools/ml/.venv` y `public/models/*.onnx` están **gitignored**; los `manifest.json` e `index.json` sí se versionan.

---

## 11. Prompt para pegar al inicio de la sesión nueva

```text
Vas a continuar el proyecto "Modo Caja Inteligente" de Ezyventas2.

Repositorio: C:\Users\windows 11\Desktop\Proyectos web\Ezyventas2
Rama: feature/modo-caja-inteligente (sin commits). No hagas commit ni push.

Lee COMPLETO y primero: docs/caja-inteligente/HANDOFF-fase-4-5.md
Consulta después, solo si necesitas detalle: docs/caja-inteligente/decisiones.md,
fase-4-reporte.md, fase-5-reporte.md, uso.md, despliegue.md y modelos.md.

Estado: las fases 0 a 5 están implementadas y verificadas. Lo que queda son las
verificaciones que necesitan tu equipo (§9 del handoff: latencia real con cámara,
comparación de proponedores con fotos reales, y la prueba en navegador del aprendizaje
y del alta rápida).

Reglas clave: cambios aditivos; con el modo y la báscula apagados el POS se comporta igual;
no dupliques la lógica del carrito (usa addToCart) ni la del cobro (TransactionPaymentService);
no re-ejecutes PermissionSeeder; Pint solo sobre archivos nuevos; no ejecutes migraciones,
ni smartpos:purge en real, sin confirmarme.

Verifica siempre con: php artisan test (base: 24 fallos preexistentes, 495 pasadas),
php artisan test --filter SmartCheckout (100), node --test tests/js (238 en verde) y
node node_modules/vite/bin/vite.js build. Corre las suites EN SERIE (ver gotcha 20).
```

---

*Última actualización: 2026-11-27. **Fases 0–5 cerradas.** Lo único pendiente son las verificaciones del §9, que
necesitan el equipo y la cámara del humano. Documentación de usuario y de instalación: `uso.md` y `despliegue.md`.*
