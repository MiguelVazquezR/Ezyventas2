# Modo Caja Inteligente — despliegue y mantenimiento

> Para quien instala y mantiene el sistema. Cubre migraciones, modelos ONNX, permisos de archivos, HTTPS, tareas
> programadas, retención de datos, rendimiento y diagnóstico.
>
> Estado: fases 0–4 implementadas (27 de noviembre de 2026). Arquitectura y contratos:
> [`HANDOFF-fase-4-5.md`](HANDOFF-fase-4-5.md). Pipeline de modelos y licencias: [`modelos.md`](modelos.md).
> Guía del cajero: [`uso.md`](uso.md).

---

## 1. Requisitos

| Pieza | Requisito | Nota |
|---|---|---|
| PHP | 8.3 (probado con 8.3.37) | `composer.json` acepta `^8.2`; el equipo de desarrollo corre 8.3.37 |
| Base de datos | MySQL 8 (probado con 8.0.39) | Las pruebas corren en SQLite; el módulo usa SQL portable |
| Node.js | El equipo de desarrollo usa 26.5.0 con Vite 7 | Hace falta para compilar los assets |
| HTTPS | **Obligatorio** | La cámara (`getUserMedia`) y Web Serial sólo funcionan en contexto seguro; `localhost` es la excepción |
| Navegador | Chrome o Edge actualizados para Web Serial | La cámara funciona en los navegadores modernos; el lector de códigos usa la API nativa o `zxing-wasm` |

No hace falta nada más: el módulo no añade paquetes Composer, ni servicios externos, ni colas.

---

## 2. Instalación

Ejecuta desde la raíz del repositorio. Los comandos van en PowerShell (la máquina de desarrollo es Windows).

### 2.1 Dependencias y configuración

```powershell
composer install

# Crea el .env a partir del ejemplo y genera la clave
Copy-Item .env.example .env
php artisan key:generate
```

Revisa en `.env` estas claves (valores del entorno de desarrollo, **sin secretos**):

| Clave | Valor en desarrollo | Para qué |
|---|---|---|
| `APP_URL` | `https://ezyventas2.test` | Debe ser la URL pública con HTTPS; el disco `public` arma sus URLs con ella |
| `APP_ENV` / `APP_DEBUG` | `local` / `true` | En producción: `production` / `false` |
| `DB_CONNECTION` | `mysql` | El módulo usa MySQL 8 |
| `DB_DATABASE` | `ezyventas2` | Base del sistema |
| `FILESYSTEM_DISK` | `local` | El disco por defecto; los recortes usan el disco `local` explícitamente |
| `SESSION_DRIVER` | `database` | No lo toca el módulo |
| `QUEUE_CONNECTION` | `database` | El módulo **no** encola nada |
| `CACHE_STORE` | `database` | No lo toca el módulo |
| `LOG_CHANNEL` / `LOG_STACK` | `stack` / `single` | Los avisos del módulo caen en `storage/logs/laravel.log` |

`MEDIA_DISK` no está definido en el `.env` de desarrollo: las fotos de producto usan el disco `public`
(`config/media-library.php` tiene `'disk_name' => env('MEDIA_DISK', 'public')`).

No hay variables de entorno propias del Modo Caja Inteligente.

### 2.2 Migraciones

```powershell
php artisan migrate
```

Son **tres** migraciones nuevas, todas aditivas (no modifican tablas existentes):

| Archivo | Tabla | Contenido |
|---|---|---|
| `2026_11_27_000001_create_smartpos_settings_table.php` | `smartpos_settings` | Una fila por suscripción: `smart_checkout_enabled` y `scale_enabled` (por defecto `false`), `scale_settings` y `smart_checkout_settings` (JSON), `retention_days` (por defecto `30`), `save_crops` (por defecto `true`). `subscription_id` es único y borra en cascada. |
| `2026_11_27_000003_create_smartpos_detection_events_table.php` | `smartpos_detection_events` | Métricas: `subscription_id`, `branch_id`, `user_id` (nullable), `session_id` (UUID), `event_type` (`analysis` o `sale`), contadores `objects`/`auto_accepted`/`confirmed`/`corrected`/`unknown`, `latency_ms`, `model_version`, `sale_id` (nullable), `duration_seconds`, `occurred_at`. Índices por `subscription_id`, `branch_id`, `session_id`, `occurred_at` y `(branch_id, occurred_at)`. |
| `2026_11_27_000004_create_smartpos_product_samples_table.php` | `smartpos_product_samples` | Galería: `subscription_id`, `branch_id`, `product_id`, `model_version`, `embedding` (binario, float32 little-endian), `dim` (por defecto `384`), `source`, `crop_path` (nullable), `quality` (nullable). Índices por `subscription_id`, `branch_id`, `product_id`, `(subscription_id, model_version)` y `(branch_id, product_id)`. |

No hay migración de datos ni seed que ejecutar. **No ejecutes `PermissionSeeder`** (ver §7).

Comprueba las rutas:

```powershell
php artisan route:list --path=smart-checkout     # debe mostrar 13 rutas
```

### 2.3 Assets

```powershell
npm install
node node_modules/vite/bin/vite.js build
```

En esta máquina `npm.ps1` está bloqueado por la política de PowerShell, así que `npm run build` falla. Usa
`node node_modules/vite/bin/vite.js build` o `npm.cmd run build`. El `build` de producción es el mismo que ya usa el
resto del POS; no hay un build aparte para el módulo.

### 2.4 Enlace de almacenamiento

```powershell
php artisan storage:link
```

Hace falta para las **fotos de producto** (disco `public`), que se sirven en `APP_URL/storage`. En este checkout
`public/storage` todavía no existe, así que el enlace hay que crearlo en cada instalación. Los recortes del módulo
**no** pasan por este enlace: viven en el disco privado (ver §4).

### 2.5 Orden recomendado

1. `composer install`
2. `.env` y `php artisan key:generate`
3. `php artisan migrate`
4. `php artisan storage:link`
5. `npm install` y el build
6. Modelos ONNX en `public/models` (§3)
7. Cron de `schedule:run` (§5)
8. Verificación (§9)

---

## 3. Modelos ONNX

### 3.1 Qué archivos van en `public/models`

| Rol | Archivo | Bytes | Tamaño | SHA-256 |
|---|---|---:|---:|---|
| Embeddings (DINOv2-small, fp32) | `dinov2-small-86e875b45969.onnx` | 88 428 110 | ≈84,3 MiB (88,43 MB) | `86e875b45969bf0d827e7c869aa2325fa5759451c2eb8d3173acdc84b1f57fe1` |
| Detector (RT-DETR-R18vd, fp32) | `rtdetr-r18vd-fe0e4d6583e1.onnx` | 81 130 202 | ≈77,4 MiB (81,13 MB) | `fe0e4d6583e1efe34587220220cff59192e44bda5c75133846ee7406e2476aba` |

Los tamaños en MB son los que reporta el pipeline; en MiB son los dos valores de ~84 MB y ~77 MB que verás en el
disco.

Junto a los binarios van, **versionados en el repositorio**:

- `public/models/index.json` — `schema: "smartpos.models_index.v1"`, `gallery_model_version:
  "dinov2-small-onnx-2026-11"` y la lista de modelos con `role`, `file`, `sha256`, `bytes` y `variant`.
- `public/models/dinov2-small.manifest.json` — licencia `apache-2.0`, commit de origen, entrada `pixel_values
  [1,3,224,224]`, salida `embedding [1,384]`, sin normalización L2.
- `public/models/rtdetr-r18vd.manifest.json` — licencia `apache-2.0`, entrada `pixel_values [1,3,640,640]`,
  salidas `logits [1,300,80]` y `pred_boxes [1,300,4]`.

Los `.onnx` **no están en el repositorio**: están en `.gitignore` junto con el venv y los intermedios.

```gitignore
/tools/ml/.venv/
/tools/ml/models_src/
/tools/ml/models_build/
/tools/ml/synthetic/
/tools/ml/.install.log
/public/models/*.onnx
/public/models/*.onnx.data
/public/models/*.bin
```

### 3.2 Verificarlos

Desde la raíz del repositorio, con el venv del pipeline:

```powershell
.\tools\ml\.venv\Scripts\python.exe tools\ml\prepare_models.py --verify-only
.\tools\ml\.venv\Scripts\python.exe tools\ml\verify_models.py
```

`verify_models.py` comprueba hash, tamaño, carga con onnxruntime y shapes, y termina con:

```text
RESULTADO: OK — 2 modelo(s) verificados
```

`prepare_models.py --verify-only` hace la verificación integrada del pipeline. Ninguno de los dos descarga nada si los
archivos ya están.

### 3.3 Regenerarlos

```powershell
# Pipeline completo (idempotente: reutiliza descargas y ONNX intermedios)
.\tools\ml\.venv\Scripts\python.exe tools\ml\prepare_models.py

# Un solo modelo
.\tools\ml\.venv\Scripts\python.exe tools\ml\prepare_models.py --models embeddings
.\tools\ml\.venv\Scripts\python.exe tools\ml\prepare_models.py --models detector

# Reexportar y recuantizar desde cero
.\tools\ml\.venv\Scripts\python.exe tools\ml\prepare_models.py --force
```

Opciones reales del script: `--models {all,embeddings,detector}`, `--force`, `--no-quant`, `--no-venv`,
`--verify-only`, `--runs` (por defecto 15) y `--samples` (por defecto 8, mínimo 5).

El pipeline descarga los checkpoints fijados por commit desde Hugging Face (`PekingU/rtdetr_r18vd` y
`facebook/dinov2-small`, ambos públicos y `apache-2.0`), exporta a ONNX, valida contra PyTorch, mide variantes y
publica el fp32 en `public/models`. Necesita Python 3.10+ y las versiones de `tools/ml/requirements.txt`. La descarga
desde Hugging Face ocurre sólo aquí: **el navegador nunca descarga de Hugging Face**, consume `/models/*.onnx`.

### 3.4 Qué pasa si faltan

- Si `GET /models/index.json` no responde 200, el POS usa el **motor simulado**: la interfaz lo indica («Motor
  simulado») y el POS funciona igual. Es la degradación prevista.
- Si `index.json` existe pero falta un `.onnx`, el motor real intenta cargarlo, falla y el panel queda en **Error** con
  el aviso «No se pudo activar». El resto del POS sigue funcionando. Para volver al simulado, retira el `index.json`
  hasta tener los binarios.
- Si los modelos se publican con otra versión (`gallery_model_version` distinta), las muestras viejas quedan intactas
  pero no se usan: hay que volver a sembrar la galería.

### 3.5 Cómo servirlos

Recomendaciones de [`modelos.md`](modelos.md):

- Sirve `/models/*.onnx` como `application/octet-stream` (o `application/onnx`), con `Accept-Ranges: bytes`.
- Los nombres llevan hash, así que pueden ir con `Cache-Control: public, max-age=31536000, immutable`.
- `index.json` y los manifiestos, sin caché larga (o con `ETag`), para detectar un cambio de versión.
- No bloquees archivos de ~80 MB en el proxy ni en el límite de subida: el navegador los descarga una vez y los cachea.

---

## 4. Fotos de producto y recortes

### 4.1 Fotos de producto (disco `public`)

- Configuración: `config/media-library.php` → `'disk_name' => env('MEDIA_DISK', 'public')`.
- Raíz: `storage/app/public`; URL: `APP_URL/storage`.
- Requiere `php artisan storage:link`.
- El sembrado del catálogo descarga estas imágenes desde el navegador con `fetch`, así que deben ser del **mismo
  origen** y no de un CDN sin CORS. Si una imagen no se puede leer, ese producto se cuenta como omitido y el lote
  continúa.

### 4.2 Recortes de las muestras (disco privado)

- Disco: `SmartCheckoutGalleryService::CROP_DISK = 'local'`, que apunta a `storage/app/private`. **No** es público y no
  pasa por `storage:link`.
- Ruta: `storage/app/private/smartpos/crops/{subscription_id}/{product_id}/{uuid}.jpg`. En la base sólo se guarda la
  ruta relativa (`crop_path`).
- Formato: JPEG de 224×224 px como máximo y 80 KB como máximo (81920 bytes). El MIME se detecta de los bytes, no se
  confía en el cliente.
- Sólo se escriben si el negocio tiene `save_crops` encendido. La preferencia se lee de
  `smartpos_settings.smart_checkout_settings` (JSON), **no** de la columna `smartpos_settings.save_crops`, que es el
  valor por defecto de la Fase 1 y no la edita nadie.
- No hay pantalla en esta fase para cambiar `save_crops` ni `retention_days`; se ajustan en la base.

### 4.3 Permisos

| Ruta | Permiso |
|---|---|
| `storage/app/private` | Escritura por el usuario de PHP (ahí van los recortes) |
| `storage/app/public` | Escritura por el usuario de PHP (fotos de producto) |
| `public/models` | Lectura por el servidor web |
| `storage/logs` | Escritura por el usuario de PHP |

En Linux, lo habitual es que el usuario del servicio web (`www-data` o el que uses) sea dueño de `storage` y
`bootstrap/cache`. En Windows no hace falta tocar permisos si el servicio corre con la misma cuenta que desplegó.

### 4.4 Espacio estimado

- Un vector de 384 dimensiones ocupa 1 536 bytes. Con el tope de 10 muestras por producto, un producto guarda como
  máximo 15 360 bytes de vectores.
- Un recorte ocupa como máximo 80 KB. Con 10 muestras por producto, el máximo teórico por producto es **800 KB** de
  recortes; en la práctica es menos, porque las fotos de catálogo suelen pesar mucho menos.
- Los modelos ocupan unos 170 MB entre los dos archivos.

### 4.5 Retención

- `retention_days` es una columna de `smartpos_settings`, por defecto **30**. Si la fila no existe o el valor no es
  positivo, se usa el valor por defecto del servicio (30).
- La purga de recortes borra el archivo y deja `crop_path` en `NULL`, sin tocar `updated_at` (para no invalidar la
  caché de la galería) y **sin tocar el vector**.
- Corre por dos vías: al guardar una muestra y a diario con `smartpos:purge` (§5).
- Los vectores **nunca** se borran por antigüedad; sólo desaparecen si se borra la muestra a mano o si la rotación de
  10 la expulsa.

---

## 5. Tareas programadas

### 5.1 El comando de purga

```powershell
php artisan smartpos:purge                 # todos los negocios con ajustes
php artisan smartpos:purge --dry-run       # enseña lo que se borraría, sin borrar
php artisan smartpos:purge --days=7        # fuerza 7 días para eventos y recortes de todos los negocios
php artisan smartpos:purge --subscription=12
```

| Opción | Qué hace |
|---|---|
| `--dry-run` | Cuenta lo que se borraría y no modifica nada |
| `--days=` | Fuerza el plazo de retención de los **eventos y los recortes** para todos los negocios (entero ≥ 1) |
| `--subscription=` | Limita la purga a una suscripción; funciona aunque esa suscripción no tenga fila de ajustes |

Qué borra:

- **Eventos** de `smartpos_detection_events` anteriores a la retención, en trozos de 1 000 filas.
- **Recortes** de `smartpos_product_samples` más antiguos que la retención del negocio. Los vectores no se tocan.

Detalles que conviene saber:

- Sin `--days`, cada negocio usa su `retention_days` (o 30 si no tiene fila o el valor es 0).
- Con `--days`, el mismo plazo forzado se aplica a los eventos y a los recortes de todos los negocios.
- Si falta `smartpos_settings`, avisa y sale con código 0: una migración pendiente no puede tumbar la tarea programada.
  Si falta la tabla de eventos, avisa una vez y sigue con los recortes.

### 5.2 La programación

En `routes/console.php` está programado a diario a las **02:30**:

```php
Schedule::command('smartpos:purge')->dailyAt('02:30');
```

Compruébalo con:

```powershell
php artisan schedule:list
```

### 5.3 El cron obligatorio

Laravel no ejecuta nada por sí solo. Hace falta un cron del sistema que corra **cada minuto**:

```cron
* * * * * cd /ruta/al/proyecto && php artisan schedule:run >> /dev/null 2>&1
```

Sin ese cron, la purga de las 02:30 **no se ejecuta nunca**. En Windows, el equivalente es una tarea programada cada
minuto que ejecute `php artisan schedule:run` en el directorio del proyecto.

### 5.4 Antes de la primera purga en producción

Corre una vez en simulación y revisa los números:

```powershell
php artisan smartpos:purge --dry-run
```

Verás una línea por negocio, del tipo `[simulación] Suscripción 1 · retención 30 días · eventos a borrar: 0 · recortes a
purgar: 0`, y un resumen al final. Compara esos números con lo que esperas. Si el plazo no es el que quieres, ajusta
`retention_days` antes de dejarlo a la tarea diaria. `--days=` mueve el plazo de esa corrida para eventos y recortes,
pero no cambia la configuración guardada.

---

## 6. Degradación y diagnóstico

### 6.1 Qué pasa si falta una tabla

| Falta | Efecto |
|---|---|
| `smartpos_settings` | Las lecturas devuelven los valores por defecto (todo apagado) y registran un `warning`. El POS funciona igual y el módulo aparece apagado. |
| `smartpos_detection_events` | El registro de eventos y las métricas pueden fallar con 500; el cliente ignora esos errores y la venta no se detiene. La galería y los ajustes siguen. |
| `smartpos_product_samples` | La galería se sirve vacía y las muestras no se guardan. La venta sigue. |

El servicio de ajustes y el de la galería degradan a propósito; los eventos no tienen esa red, pero tampoco son
necesarios para cobrar.

### 6.2 Códigos HTTP

| Código | Significado | Mensaje o causa |
|---|---|---|
| 403 | La preferencia del negocio está apagada | «El Modo Caja Inteligente no está habilitado para este negocio.» |
| 403 | La báscula está apagada | «La báscula no está habilitada para este negocio.» |
| 403 | Modelo enlazado en la ruta de otra suscripción | «Acceso denegado. Este recurso no pertenece a tu suscripción.» (middleware `EnsureSubscriptionScope`) |
| 404 | Producto de otra sucursal | «El producto indicado no existe en la sucursal del cajero.» |
| 404 | Muestra inexistente o de otro negocio | «La muestra indicada no existe en la galería de tu negocio.» (segunda línea de defensa; con el middleware activo, un id de otra suscripción da 403) |
| 422 | Cuerpo inválido | Mensaje en español con el detalle del campo (vector, recorte, umbrales…) |
| 429 | Límite de tasa | Espera el `Retry-After`; el cliente lo respeta al sembrar |

### 6.3 Límites de tasa de las 13 rutas

Todas viven bajo `/pos/smart-checkout`, con `auth` + `can:pos.access`.

| Método | Ruta | Nombre | Límite |
|---|---|---|---|
| PUT | `/preferences` | `pos.smart-checkout.preferences.update` | Sin límite (es el interruptor) |
| GET | `/scale/settings` | `pos.smart-checkout.scale.settings` | Sin límite |
| PUT | `/scale/settings` | `pos.smart-checkout.scale.settings.update` | Sin límite |
| GET | `/scale/weighable-products` | `pos.smart-checkout.scale.products` | Sin límite |
| GET | `/settings` | `pos.smart-checkout.settings` | Sin límite |
| PUT | `/settings` | `pos.smart-checkout.settings.update` | Sin límite |
| POST | `/events` | `pos.smart-checkout.events.store` | 120 por minuto |
| GET | `/metrics/today` | `pos.smart-checkout.metrics.today` | 120 por minuto |
| GET | `/gallery` | `pos.smart-checkout.gallery` | 30 por minuto |
| POST | `/samples` | `pos.smart-checkout.samples.store` | 60 por minuto |
| DELETE | `/samples/{sample}` | `pos.smart-checkout.samples.destroy` | 60 por minuto |
| GET | `/gallery/candidates` | `pos.smart-checkout.gallery.candidates` | 120 por minuto |
| GET | `/gallery/products/{product}` | `pos.smart-checkout.gallery.product` | 120 por minuto |

El límite de 60 por minuto en `POST /samples` es el que marca el ritmo del sembrado: unas 60 imágenes por minuto, es
decir, un lote de 50 en alrededor de un minuto. La galería se limita a 30 por minuto porque cada página puede llevar
hasta ~4 MiB; una activación usa una o tres, y un `304` no descarga nada.

El tope de página (4 MiB) se puede bajar con la clave `config('smartpos.gallery.max_page_bytes')`, pero este repositorio
**no trae** `config/smartpos.php`: sin ese archivo se usa el valor por defecto del servicio (4 MiB).

### 6.4 Consultas de estado con tinker

```powershell
php artisan tinker
```

```php
// ¿Hay negocios con el módulo configurado y cuántos lo tienen encendido?
App\Models\SmartPosSetting::query()->count();
App\Models\SmartPosSetting::query()->where('smart_checkout_enabled', true)->count();

// Galería: total y por versión de modelo
App\Models\SmartPosProductSample::query()->count();
App\Models\SmartPosProductSample::query()
    ->selectRaw('model_version, COUNT(*) as total')
    ->groupBy('model_version')
    ->get();

// Eventos de hoy
App\Models\SmartPosDetectionEvent::query()->whereDate('occurred_at', today())->count();

// Retención configurada
App\Models\SmartPosSetting::query()->pluck('retention_days', 'subscription_id');
```

Si una consulta falla con «table doesn't exist», la migración está pendiente.

### 6.5 Logs y mensajes

Canal `single` por defecto en desarrollo: `storage/logs/laravel.log`. Busca estos textos:

| Texto | Qué indica |
|---|---|
| «No se pudo leer la configuración de Caja Inteligente; se usan los valores por defecto (todo apagado).» | Falta la tabla de ajustes o la consulta falló |
| «No se pudo leer la galería de vectores de Caja Inteligente; se devuelve una galería vacía.» | Falta la tabla de muestras o la consulta falló |
| «No se pudo calcular la versión de la galería de Caja Inteligente.» | Mismo caso, al derivar la versión |
| «No se pudo borrar el recorte de una muestra de Caja Inteligente.» | El archivo no se pudo borrar; la referencia a `crop_path` se limpia igualmente |

En el navegador, los avisos útiles son: «No se pudo activar» (el motor no arrancó), «Sin modelos publicados»,
«No se pudo calibrar», «No se pudieron leer las muestras» y «No se pudo borrar».

### 6.6 Comprobaciones rápidas

```powershell
php artisan route:list --path=smart-checkout -v
php artisan schedule:list
php artisan smartpos:purge --dry-run
```

---

## 7. Aislamiento multi-tenant

- La suscripción y la sucursal salen **siempre** del usuario autenticado (`$request->user()->branch->subscription_id` y
  `$request->user()->branch_id`), nunca del cuerpo de la petición. Los servicios descartan el `subscription_id` que
  llegue en el payload (la galería descarta también `branch_id`).
- Las 13 rutas llevan `auth` + `can:pos.access`. Además, el middleware global `EnsureSubscriptionScope`
  (`bootstrap/app.php`) devuelve 403 cuando un modelo enlazado en la ruta pertenece a otra suscripción; los tres modelos
  del módulo usan `HasSubscription`.
- La galería se lee por suscripción; una muestra sólo se escribe si el producto pertenece a la **sucursal** del cajero
  (mismo criterio que el catálogo del POS: `branch_product`).
- Un producto de otra **sucursal** (mismo negocio) responde 404, nunca 403: el endpoint no confirma que exista. Uno de
  otra **suscripción** lo corta antes el middleware con 403.
- La preferencia es por suscripción; el estado encendido/apagado de cada terminal vive en `localStorage` y no autoriza
  nada en el servidor.

**Nunca re-ejecutes `PermissionSeeder`.** Hace `Permission::query()->delete()` y arrastra las asignaciones de permisos
del sistema. El módulo no creó permisos nuevos y no necesita ninguno; si algo te pide un permiso `smart_checkout.*` o
`scale.*`, es una instalación vieja, no este código.

---

## 8. Rendimiento

### 8.1 Latencias medidas

Medidas en CPU en el equipo de desarrollo, `batch=1`, onnxruntime 1.23.2, 3 repeticiones de calentamiento y 30
mediciones (15 en el detector). Son de esa máquina: **no son una promesa** para el equipo del cliente.

| Modelo | Variante | Latencia media | p95 |
|---|---|---:|---:|
| DINOv2-small (embeddings) | fp32 | 67,44 ms | 85,70 ms |
| RT-DETR-R18vd (detector) | fp32 | 176,68 ms | 182,33 ms |

El pipeline probó INT8 y fp16 y los rechazó por calidad o portabilidad; se publica fp32. El detalle está en
[`modelos.md`](modelos.md).

### 8.2 Qué corre en el navegador

- Los modelos se ejecutan con **ONNX Runtime Web en WASM monohilo**: `ort.env.wasm.numThreads = 1` y
  `executionProviders: ['wasm']`.
- Es monohilo **a propósito**: el modo multihilo exige cabeceras COOP/COEP (aislamiento de origen cruzado) que romperían
  la impresión y los pagos del POS. No añadas esas cabeceras para «acelerar» el módulo.
- El motor analiza cada **1 500 ms** (`intervalMs: 1500` en `useSmartCheckout.js`).
- El panel muestra los milisegundos del último análisis en la esquina de la vista previa, y la barra de indicadores
  muestra «Latencia» y el promedio del día.

### 8.3 Protocolo para medir en el equipo del cliente

1. Abre el POS en el equipo del cliente, con la cámara y la iluminación reales.
2. Enciende el modo y déjalo analizar 2–3 minutos con productos en el mostrador.
3. Anota los ms de la esquina inferior derecha del panel (último análisis) y el indicador «Latencia».
4. Contrasta con el promedio del día: `avg_latency_ms` de `GET /pos/smart-checkout/metrics/today` o la consulta de
   §6.4.
5. Registra equipo, navegador, resolución de la cámara y número de cámaras.
6. Si supera 1 segundo de forma sostenida, el handoff propone bajar `intervalMs`, analizar una cámara por ciclo o
   evaluar WebGPU. Ojo: `max_cameras_per_cycle` se guarda y se valida (1 a 4), pero el motor de esta fase **no lo lee**;
   limitar el ciclo exige un cambio de código.

### 8.4 Validar la detección con fotos reales

Cuando el cliente tenga cámara, el protocolo de [`modelos.md`](modelos.md) permite comparar los proponedores con fotos
del mostrador real. Los pares van en `tools/ml/synthetic/real_raw/` como `<id>_empty` y `<id>_scene`, a 1280×720, sin
mover la cámara entre las dos tomas. El comando es:

```powershell
.\tools\ml\.venv\Scripts\python.exe tools\ml\evaluate_proposers.py `
  --from-photos tools\ml\synthetic\real_raw `
  --out tools\ml\synthetic\real `
  --force
```

La salida queda en `tools/ml/synthetic/real/` (gitignored). Esto es una medición de calidad, no un requisito del
despliegue.

---

## 9. Checklist final de despliegue

- [ ] `php artisan migrate` termina sin errores y existen `smartpos_settings`, `smartpos_detection_events` y
      `smartpos_product_samples`.
- [ ] `php artisan route:list --path=smart-checkout` muestra 13 rutas.
- [ ] `public/models/index.json` responde 200 y los dos `.onnx` se descargan completos.
- [ ] `verify_models.py` dice `RESULTADO: OK — 2 modelo(s) verificados`.
- [ ] `php artisan storage:link` hecho y una foto de producto carga en `APP_URL/storage/...`.
- [ ] `storage/app/private` es escribible: se guarda un recorte y aparece en `smartpos/crops/...`.
- [ ] `storage/logs` es escribible y `storage/logs/laravel.log` no repite los avisos de tablas.
- [ ] `php artisan schedule:list` muestra `smartpos:purge` a las 02:30 y el cron de cada minuto está activo.
- [ ] `php artisan smartpos:purge --dry-run` termina sin errores y con números razonables.
- [ ] La URL es HTTPS válida (certificado sin avisos).
- [ ] La preferencia «Modo Caja Inteligente» está encendida y el botón del chip aparece en el POS.
- [ ] Con cámara real, el panel llega a «Listo» y muestra milisegundos.
- [ ] Si hay báscula, conecta por Web Serial o funciona la captura manual.
- [ ] Un usuario sin roles especiales (solo `pos.access`) puede usar el modo y la báscula.

**Cómo comprobar que quedó bien, en 5 minutos:** enciende el modo, pasa un producto conocido por la cámara, verifica
que salga verde y que entre al carrito; resuelve un desconocido, ciérralo y comprueba que aparece en «Productos con
muestras»; pesa un producto a granel si hay báscula. Si esas tres cosas funcionan, el despliegue está completo.

---

## 10. Lo que no requiere

- **No usa colas.** No hay jobs ni workers del módulo; `QUEUE_CONNECTION` no influye.
- **No crea permisos nuevos.** Las rutas usan `pos.access`, que ya existía. No hay `smart_checkout.*` ni `scale.*`.
- **No hay migraciones de datos.** Las tres migraciones son de esquema y aditivas; no hay seed que ejecutar.
- **No hay servicios externos en tiempo de ejecución.** Los modelos se sirven desde `public/models`; Hugging Face sólo
  se usa si regeneras los modelos.
- **No hay caché obligatoria ni Redis.** El módulo no lo necesita.
- **No hay que tocar las tablas existentes.** Ni columnas, ni índices, ni datos.

---

## 11. Documentos relacionados

- [`uso.md`](uso.md): guía del cajero y del administrador (encender, mostrador, báscula, aprendizaje).
- [`HANDOFF-fase-4-5.md`](HANDOFF-fase-4-5.md): arquitectura, contratos y trampas conocidas.
- [`decisiones.md`](decisiones.md): D1 a D14, incluida la decisión de no crear permisos.
- [`modelos.md`](modelos.md): pipeline, licencias, latencias y protocolo de fotos reales.
- [`fase-4-reporte.md`](fase-4-reporte.md): qué se construyó en el aprendizaje y cómo probarlo.
