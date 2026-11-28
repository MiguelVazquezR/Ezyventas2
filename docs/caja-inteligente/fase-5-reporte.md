# Fase 5 — Métricas y endurecimiento · Reporte

> Rama `feature/modo-caja-inteligente` · Fecha: 2026-11-27 · **Sin commits**.
> Decisiones aplicadas: retención por `retention_days` del negocio con purga diaria, el mismo comando purga también los
> recortes, documentación sólo texto y purga probada en local (acordado con el humano al abrir la fase).
> **Migraciones nuevas: ninguna.**

---

## 1. Estado

| Pieza | Estado |
|---|---|
| Límites de tasa en todas las lecturas caras y escrituras destructivas | ✅ |
| Retención programada (eventos + recortes) | ✅ Comando + tarea diaria, probada contra MySQL real |
| Test transversal de aislamiento entre negocios (13 rutas) | ✅ 5 pruebas / 245 aserciones |
| Caminos de error (worker, cámara, API, caché, catálogo) | ✅ Revisados; 9 pruebas nuevas del motor |
| Documentación de uso y de despliegue | ✅ `uso.md` y `despliegue.md`, verificadas contra el código |
| Rendimiento medido y protocolo de medición | ✅ Documentado en `despliegue.md` §8 |
| Correcciones reales visibles en los indicadores | ✅ «Corregidos» y «Corregidos hoy» |
| Bug del alta rápida (producto agregado dos veces) | ✅ **Encontrado y corregido** |

---

## 2. Qué se hizo, hueco por hueco

### 2.1 Límites de tasa que faltaban

| Ruta | Antes | Ahora | Por qué ese número |
|---|---|---|---|
| `GET /gallery` | sin límite | **30/min** | Es la lectura más cara del módulo (hasta ~4 MiB por página). Una activación usa 1–3 páginas y un `304` no descarga nada |
| `GET /metrics/today` | sin límite | **120/min** | El panel la consulta tras cada envío de eventos (cada 5 s por terminal) |
| `DELETE /samples/{sample}` | sin límite | **60/min** | Es la única escritura destructiva del módulo |

Las seis rutas de configuración (`GET/PUT /settings`, `GET/PUT /scale/settings`, `GET /scale/weighable-products` y
`PUT /preferences`) **siguen sin límite a propósito**: son pequeñas, por usuario, y el interruptor del negocio no puede
quedar bloqueado por un limitador. El test transversal fija ese mapa exacto, así que una ruta nueva sin límite (o con
otro) rompe la suite.

### 2.2 Retención programada (`smartpos:purge`)

Comando nuevo `app/Console/Commands/PurgeSmartPosData.php`:

```text
php artisan smartpos:purge                 # todos los negocios con ajustes
php artisan smartpos:purge --dry-run       # enseña lo que borraría, sin borrar
php artisan smartpos:purge --days=7        # fuerza 7 días para eventos y recortes
php artisan smartpos:purge --subscription=12
```

- **Eventos** de `smartpos_detection_events` anteriores a la retención, borrados en trozos de 1 000 filas (MySQL:
  `DELETE … ORDER BY id LIMIT 1000`) para no bloquear una tabla grande.
- **Recortes**: se delega en `SmartCheckoutGalleryService::purgeExpiredCrops()`, que ya era el único lugar que decide
  qué archivo se borra. Se le añadió un **parámetro opcional** de días para que `--days` mueva también esa fecha: una
  sola invocación no deja nada atrás y el criterio sigue viviendo en un solo sitio.
- **Los vectores no se borran nunca por edad** (la especificación lo prohíbe): solo el archivo del recorte, y
  `crop_path` queda en `null` sin tocar `updated_at` (de ahí se deriva el `gallery_version` que cachean los navegadores).
- **Degradación**: si falta `smartpos_settings` avisa y sale con 0; si falta la tabla de eventos avisa una vez y sigue
  con los recortes. Una migración pendiente no puede tumbar la tarea programada.
- Programado en `routes/console.php`: `Schedule::command('smartpos:purge')->dailyAt('02:30')`.

### 2.3 Test transversal de aislamiento

`tests/Feature/SmartCheckout/SmartCheckoutTenantIsolationTest.php` — 5 pruebas, 245 aserciones:

1. **Recorrido de las 13 rutas con un segundo negocio**: no lee ni escribe nada del primero (galería en 0, candidatos
   sin sus productos, `404` al borrar su muestra o ver sus muestras, `422` al guardar una muestra de un producto ajeno,
   `200` con ceros en métricas, ajustes y báscula intactos).
2. **Flag apagado**: las 12 rutas con gate responden `403` (nunca 500 ni 200) y `PUT /preferences` sigue funcionando
   para volver a encenderlo.
3. **Estructural**: inventario de las 13 rutas **por URI**; cada una debe tener `auth`, `can:pos.access` y exactamente
   el `throttle` esperado (o ninguno, en las 6 excepciones). Ninguna puede exigir un permiso distinto de `pos.access`:
   el módulo no introduce permisos.
4. **ETag**: dos negocios nunca comparten validador (revalidar con el ETag del otro da `200`, nunca `304` cruzado).
5. **Control positivo**: el dueño sí puede leer su producto, ver sus muestras y borrar la suya (`204`).

### 2.4 Caminos de error

Revisión de todo lo que puede fallar sin que el cajero pueda hacer nada, y su comportamiento final:

| Fallo | Qué pasa | Dónde se garantiza |
|---|---|---|
| El worker de inferencia no contesta | La muestra no se guarda; la promesa se resuelve con `null` a los 20 s | `addSample()` con temporizador (probado) |
| El worker muere a mitad de un análisis | Se emite `error`, el panel lo muestra y el POS sigue cobrando | `wireEngine()` + `activate()` |
| No hay píxeles (modo simulado, sin fotograma fresco) | No se guarda muestra; el evento sí se cuenta | `persistSample()` (probado) |
| No hay modelos publicados | Cae al motor simulado con aviso ámbar; el sembrado se desactiva con mensaje | `buildEngine()`, panel |
| La red falla al leer la galería | Se usa la copia guardada del terminal y el modo sigue reconociendo | `fetchGallery()` |
| IndexedDB bloqueada o llena (modo privado) | Caché en memoria para esa sesión; nunca lanza | `galleryCache` + `idbDriver` (probado) |
| El catálogo no responde durante el sembrado | El lote se detiene con mensaje y el cursor queda donde iba | `seedGallery.run()` (probado) |
| Una imagen del catálogo no se puede descargar (CORS, 404) | Ese producto cuenta como «omitido» y el lote sigue | `seedItem()` |
| El servidor responde `429` guardando muestras | Se espera el `Retry-After` y se reintenta una vez | `seedGallery` (probado) |
| El servidor responde `4xx/5xx` | Se muestra el mensaje del API en el panel; el carrito no se toca | `startSeeding()`, panel |
| Falta una tabla del módulo | El módulo se comporta como apagado y el POS sigue igual | `SmartPosSettingsService` (ya existía) |

### 2.5 Bug real encontrado y corregido: el alta rápida agregaba el producto dos veces

El panel, al resolver un producto desconocido con «Alta rápida», llamaba a `session.resolveUnknown()` (que agrega el
producto al carrito) **y además** emitía `product-created`, que en el POS ejecutaba `handleProductCreatedAndAddToCart()`
→ `addToCart()` otra vez. Resultado: el cajero daba de alta 1 producto y al cliente se le cobraban 2 unidades.

Corregido en `resources/js/Pages/POS/Index.vue` e `IndexMobile.vue`: ese manejador ahora **sólo refresca el catálogo**
(la tarjeta nueva), porque el carrito ya lo tocó `resolveUnknown`. Era un defecto de la Fase 3 que salió a la luz al
revisar los caminos de error de esta fase.

### 2.6 Indicadores del día completos

`SmartCheckoutIndicators.vue` muestra ahora **«Corregidos»** (venta en curso) y **«Corregidos hoy»** (del día), y el
panel muestra «N corregidos» en su pie. Hasta la Fase 4 el contador de correcciones no se enviaba; ahora que se envía,
tenerlo oculto dejaba la «Precisión» sin explicación.

### 2.7 Documentación

- **`docs/caja-inteligente/uso.md`** (363 líneas): qué es y qué no hace, encender el modo, estados del panel, semáforo,
  producto desconocido, correcciones, calibración, aprendizaje (sembrado y muestras), báscula, problemas frecuentes y qué
  datos se guardan con qué límites.
- **`docs/caja-inteligente/despliegue.md`** (557 líneas): requisitos, instalación, modelos ONNX (tamaños, sha256,
  verificación y regeneración), fotos y recortes, tareas programadas y cron, degradación y diagnóstico (403/404/422/429 y
  los límites reales de las 13 rutas), aislamiento multi-tenant, rendimiento y checklist final.

Ambos se verificaron contra el código: las 84 etiquetas de interfaz citadas existen literalmente en los `.vue`, las 13
rutas y el comando de purga existen, y los hashes de los modelos salen de `index.json`.

---

## 3. Migraciones

**Ninguna.** La purga usa las tablas existentes y un comando; no hizo falta esquema nuevo.

---

## 4. Archivos

| Archivo | Novedad |
|---|---|
| `app/Console/Commands/PurgeSmartPosData.php` | **Nuevo.** Comando `smartpos:purge` |
| `routes/console.php` | Una entrada de schedule (02:30) |
| `app/Services/SmartCheckout/SmartCheckoutGalleryService.php` | `purgeExpiredCrops()` acepta un plazo opcional; el docblock decía «no hay tarea programada» y ya no es cierto |
| `app/Services/SmartCheckout/SmartPosSettingsService.php` | Comentario honesto de `max_cameras_per_cycle` (se guarda, el motor aún no lo usa) |
| `routes/web/smart-checkout.php` | `throttle:30,1` en `/gallery`, `throttle:120,1` en `/metrics/today`, `throttle:60,1` en `DELETE /samples` |
| `resources/js/Pages/POS/Index.vue` · `IndexMobile.vue` | **Corrección del doble alta** en `handleSmartProductCreated` |
| `resources/js/Pages/POS/SmartCheckout/SmartCheckoutIndicators.vue` | «Corregidos» y «Corregidos hoy» |
| `resources/js/Pages/POS/SmartCheckout/SmartCheckoutPanel.vue` | «N corregidos» en el pie |
| `tests/Feature/SmartCheckout/SmartPosPurgeCommandTest.php` | **Nuevo.** 9 pruebas |
| `tests/Feature/SmartCheckout/SmartCheckoutTenantIsolationTest.php` | **Nuevo.** 5 pruebas / 245 aserciones |
| `tests/js/smart-checkout/engineLearning.test.mjs` | **Nuevo.** 9 pruebas de los caminos de error del motor |
| `tests/Feature/SmartCheckout/SmartCheckoutGallery{Candidates,ProductSamples}Test.php` | Corregido `pack('g*')` sin `...` (empaquetaba 4 bytes en vez de 1536) |
| `docs/caja-inteligente/uso.md` · `despliegue.md` | **Nuevos** |
| `docs/caja-inteligente/HANDOFF-fase-4-5.md` · `decisiones.md` | Actualizados (estado, gotchas 18–22, comandos, pendientes) |

---

## 5. Verificación

| Comprobación | Antes (fin de Fase 4) | Ahora |
|---|---|---|
| `php artisan test` | 24 fallos / 481 pasadas | **24 fallos / 495 pasadas** (3668 aserciones) |
| `php artisan test --filter SmartCheckout` | 86 | **100** (1208 aserciones) |
| `node --test tests/js` | 229 | **238** |
| `node node_modules/vite/bin/vite.js build` | exit 0 | **exit 0** |

Los 24 fallos siguen siendo exactamente los preexistentes (15 de `AiAgentToolsTest`, 3 de `PosHelpArticlesSeedTest` y 6
de «denies access without permissions»), ninguno del módulo.

### Purga probada contra MySQL real (lo que SQLite no puede demostrar)

Las pruebas PHPUnit corren en SQLite, y el grammar de SQLite **ignora** `DELETE … ORDER BY … LIMIT`: el borrado por
trozos sólo se ejercita en MySQL. Se ejecutó a mano contra la base local con datos de prueba (un evento de hace 40 días,
otro de hace 1 día y una muestra con recorte de hace 40 días):

```text
[simulación] Suscripción 1 · retención 30 días · eventos a borrar: 1 · recortes a purgar: 1
Modo simulación (--dry-run): no se borró nada.

[purga] Suscripción 1 · retención 30 días · eventos borrados: 1 · recortes purgados: 1

evento viejo: borrado        (esperado: borrado)
evento reciente: SIGUE       (esperado: SIGUE)
muestra: SIGUE               (esperado: SIGUE)
crop_path de la muestra: NULL
archivo del recorte existe: no (correcto)
```

Después se borraron los datos de prueba: la base quedó con los mismos conteos que antes (17 eventos, 9 muestras).
`php artisan schedule:list` confirma la tarea: `30 2 * * * php artisan smartpos:purge`.

---

## 6. Riesgos y límites conocidos

1. **El troceado de 1 000 filas** está verificado funcionalmente, pero no medido con una tabla de millones de filas: el
   diseño evita el `DELETE` gigante, no hay prueba de carga.
2. **`retention_days` no tiene pantalla**: se ajusta en la base (`smartpos_settings.smart_checkout_settings`) y está
   documentado. Si el negocio quiere 365 días, hoy es un cambio manual.
3. **El ETag de la galería** sigue siendo por suscripción, no por sucursal: dos sucursales del mismo negocio comparten
   vectores (decisión de la Fase 3, documentada en `decisiones.md`). El test transversal usa dos suscripciones, así que
   ese caso queda fuera de su alcance a propósito.
4. **`save_crops` tiene dos fuentes**: la columna de la tabla y la clave del JSON que realmente lee el servicio. Manda el
   JSON; la columna no la edita nadie. Documentado; no se tocó para no romper la compatibilidad de datos.
5. **`config('smartpos.gallery.max_page_bytes')`** se menciona en el servicio, pero el repositorio no trae
   `config/smartpos.php`: se usa el valor por defecto de 4 MiB. Documentado.
6. **Las suites en paralelo se pisan** (`Storage::fake()` usa una raíz fija): hay que correrlas en serie. Queda como
   gotcha 20 para la próxima sesión.

---

## 7. Lo que no pude verificar aquí

1. **Navegador**: cámara, Web Serial, IndexedDB real, el `304` de la galería y el flujo completo de aprendizaje.
2. **La corrección del doble alta** (2.5): el razonamiento es sólido y el build pasa, pero se confirma de verdad en el
   POS del navegador (pasos en `uso.md` §5).
3. **Latencias en el equipo del cliente**: las documentadas son las medidas en este equipo de desarrollo.
4. **Cron de producción**: el `schedule:run` cada minuto es una instrucción de despliegue; no hay servidor aquí.

---

## 8. Confirmación explícita

> **Con el Modo Caja Inteligente apagado y la báscula deshabilitada, el módulo Punto de venta se comporta igual que
> antes.**

Lo de esta fase no cambia esa garantía: los límites de tasa sólo actúan sobre rutas del módulo, el comando de purga es
una tarea de consola y los cambios de interfaz viven dentro del panel del modo. La corrección del alta rápida toca al POS,
pero **sólo reduce una llamada duplicada a `addToCart`** en un flujo que ya existía y que sólo se alcanza con el modo
encendido; con el modo apagado ese manejador no se registra.

La suite mantiene los mismos 24 fallos preexistentes y el aislamiento entre negocios está ahora fijado por un test
transversal que recorre las 13 rutas.
