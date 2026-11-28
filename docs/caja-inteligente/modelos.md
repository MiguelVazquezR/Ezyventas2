# Fase 3 — Modelos ONNX del Modo Caja Inteligente · Pipeline, validación y contrato

> Rama `feature/modo-caja-inteligente` (desde `Miguel`) · Fecha: 2026-11-27 · **Sin commits**.
> Decisiones aplicadas: **D7** (modelos en la carpeta pública del dominio, sin Git LFS) y **D8**
> (sin token de Hugging Face; ambos repositorios son públicos).
> Todo se ejecuta en desarrollo, en CPU y fuera del navegador del cliente.

---

## 1. Resumen ejecutivo

| Rol | Modelo | Variante publicada | Archivo | Tamaño | Latencia CPU (media / p95) |
|---|---|---|---|---|---|
| Embeddings | **DINOv2-small** (CLS, 384) | fp32 | `public/models/dinov2-small-86e875b45969.onnx` | 88.43 MB | 67.44 / 85.70 ms |
| Detector | **RT-DETR-R18vd** (300 queries, COCO-80) | fp32 | `public/models/rtdetr-r18vd-fe0e4d6583e1.onnx` | 81.13 MB | 176.68 / 182.33 ms |

- Los dos ONNX se cargan con `onnxruntime.InferenceSession` (CPU) y una inferencia de humo
  devuelve exactamente `[1,384]` para embeddings y `[1,300,80]` + `[1,300,4]` para el detector.
- **INT8 y fp16 se midieron y se rechazaron** con los criterios de la fase; el detalle está en §7.
- El navegador **nunca** descarga de Hugging Face: consume `/models/*.onnx` y los manifiestos
  versionados en `public/models/`.
- `tools/ml/synthetic/` contiene 20 escenas sintéticas con su referencia vacía y `ground_truth.json`
  para comparar proponedores (ver §9).

---

## 2. Fuentes, commits y licencias

| Modelo | Repositorio | Commit fijado | Licencia | `gated` | Archivo fuente |
|---|---|---|---|---|---|
| Detector RT-DETR-R18vd | `PekingU/rtdetr_r18vd` | `ac77a11ff0170a41b771c03264987f8ce2b0d753` | **apache-2.0** | `false` | `model.safetensors` (80.9 MB) |
| Embeddings DINOv2-small | `facebook/dinov2-small` | `ed25f3a31f01632728cabb09d1542f84ab7b0056` | **apache-2.0** | `false` | `model.safetensors` (88.2 MB) |

- Fuentes: <https://huggingface.co/PekingU/rtdetr_r18vd> y
  <https://huggingface.co/facebook/dinov2-small>.
- La descarga usa `snapshot_download(repo_id=..., revision=<sha>)` con `allow_patterns`
  (`config.json`, `preprocessor_config.json`, `model.safetensors`, `README.md`).
- **No se usó token ni cuenta**: D8 confirmó que ambos son públicos. Si un `snapshot_download`
  devolviera `GatedRepoError`, `prepare_models.py` se detiene y pide intervención; no rodea la restricción.
- **No se usó Ultralytics YOLO** (AGPL). El export directo de RT-DETR funcionó, así que **tampoco se
  usó el fallback YOLOX-S** ni hizo falta verificar su licencia.
- Las licencias quedan registradas literalmente en cada manifiesto (`license: "apache-2.0"`) junto con
  `source_url`, `source_commit` y `license_url`.

---

## 3. Entorno e instalación reproducible

No hay Python del sistema. El venv se creó con el intérprete del runtime de DSH:

```powershell
$py = "C:\Users\windows 11\.dsh\dsh-runtimes\dsh-primary-runtime\dependencies\python\python.exe"
& $py --version          # Python 3.12.14 (Windows 11 x64)
& $py -m venv tools\ml\.venv
.\tools\ml\.venv\Scripts\python.exe -m pip install --upgrade pip
.\tools\ml\.venv\Scripts\python.exe -m pip install -r tools\ml\requirements.txt `
  --extra-index-url https://download.pytorch.org/whl/cpu
```

En otra máquina basta con **Python 3.10+** en el `PATH`:

```powershell
python -m venv tools/ml/.venv
tools/ml/.venv/Scripts/python.exe -m pip install -r tools/ml/requirements.txt --extra-index-url https://download.pytorch.org/whl/cpu
```

En Linux/macOS el ejecutable del venv es `tools/ml/.venv/bin/python`.

### Versiones exactas instaladas y verificadas

| Paquete | Versión | Nota |
|---|---|---|
| torch | **2.9.1+cpu** | rueda CPU; `torch.cuda.is_available() == False` |
| transformers | **4.57.6** | última 4.x; `RTDetrForObjectDetection` y `Dinov2Model` |
| onnx | **1.19.1** | inspección y metadatos |
| onnxruntime | **1.23.2** | `CPUExecutionProvider` |
| huggingface_hub | **0.36.2** | compatible con transformers 4.57 |
| safetensors | **0.8.0** | carga de pesos |
| numpy | **2.3.5** | |
| pillow | **12.3.0** | generación sintética y preprocesado |

`onnxruntime.get_available_providers()` devuelve
`['AzureExecutionProvider', 'CPUExecutionProvider']`; la sesión se crea explícitamente con
`CPUExecutionProvider`. **No se instaló CUDA ni torchvision.**

Archivo: [`tools/ml/requirements.txt`](../../tools/ml/requirements.txt).

---

## 4. Estructura y regeneración

```
tools/ml/
  requirements.txt          versiones fijas
  prepare_models.py         descarga → export → valida → cuantiza → publica → manifiestos
  verify_models.py          verificación independiente de lo publicado
  evaluate_proposers.py     escenas sintéticas / ingestión de fotos reales
  .venv/                    gitignored
  models_src/               checkpoints descargados (gitignored)
  models_build/             fp32/int8/fp16 intermedios y muestras (gitignored)
  synthetic/                escenas, frames y ground truth (gitignored)

public/models/
  dinov2-small-<sha>.onnx           gitignored (binario)
  rtdetr-r18vd-<sha>.onnx           gitignored (binario)
  dinov2-small.manifest.json        versionable
  rtdetr-r18vd.manifest.json        versionable
  index.json                        versionable
```

### Comandos

```powershell
# Ayuda y opciones
.\tools\ml\.venv\Scripts\python.exe tools\ml\prepare_models.py --help

# Pipeline completo (idempotente: reutiliza descargas y ONNX intermedios)
.\tools\ml\.venv\Scripts\python.exe tools\ml\prepare_models.py

# Un solo modelo
.\tools\ml\.venv\Scripts\python.exe tools\ml\prepare_models.py --models embeddings
.\tools\ml\.venv\Scripts\python.exe tools\ml\prepare_models.py --models detector

# Reexportar y recuantizar desde cero
.\tools\ml\.venv\Scripts\python.exe tools\ml\prepare_models.py --force

# Solo verificar hashes, carga y shapes de lo publicado
.\tools\ml\.venv\Scripts\python.exe tools\ml\prepare_models.py --verify-only
.\tools\ml\.venv\Scripts\python.exe tools\ml\verify_models.py

# Escenas sintéticas
.\tools\ml\.venv\Scripts\python.exe tools\ml\evaluate_proposers.py --scenes 20

# Fotos reales (cuando existan)
.\tools\ml\.venv\Scripts\python.exe tools\ml\evaluate_proposers.py `
  --from-photos tools\ml\synthetic\real_raw --out tools\ml\synthetic\real --force
```

- `prepare_models.py` se re-ejecuta dentro de `tools/ml/.venv` si detecta que el intérprete activo
  no es el del venv; si faltan dependencias, ejecuta la instalación de `requirements.txt`.
- Los nombres de los artefactos incluyen los primeros 12 caracteres del **SHA-256 del archivo final**:
  `dinov2-small-86e875b45969.onnx`, `rtdetr-r18vd-fe0e4d6583e1.onnx`.
- Al publicar se eliminan los artefactos anteriores del mismo prefijo para no acumular binarios.
- Los `manifest.json` y `index.json` **sí** se versionan; los `.onnx` no.

### `.gitignore` aplicado

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

### Despliegue

`public/models/` es la única ubicación de producción. El servidor debe:

1. Servir `/models/*.onnx` con `Content-Type: application/octet-stream` (o `application/onnx`) y
   `Accept-Ranges: bytes`; el nombre con hash permite `Cache-Control: public, max-age=31536000, immutable`.
2. Servir los manifiestos e `index.json` sin caché larga (o con `ETag`), para que un cambio de
   `model_version` se detecte.
3. No bloquear archivos de 80 MB en el `upload_max_filesize`/proxy; el cliente los descarga una vez.

---

## 5. Decisiones de diseño

### 5.1 D7 — Alojamiento y versionado

- **Carpeta pública del dominio** (`public/models/`), no CDN ni almacenamiento de objetos en esta fase.
- **Sin Git LFS.** Los `.onnx` no entran al repositorio; se regeneran con `prepare_models.py`.
- Los manifiestos con hash y versión sí se versionan, de modo que la app sepa qué binario pedir y
  pueda invalidar la galería cuando cambie.

### 5.2 D8 — Token de Hugging Face

- **No se usa.** Ambos checkpoints son públicos (`gated: false`) y la descarga se hizo sin cuenta.
- Si en el futuro un modelo exigiera aceptar términos, el script se detiene con un mensaje explícito.

### 5.3 Embeddings: token CLS frente al promedio de parches

DINOv2 devuelve `last_hidden_state` de forma `[batch, 1 + 256, 384]` para 224×224:

- posición `0`: **token CLS** (representación global de la imagen);
- posiciones `1..256`: 256 parches de 14×14.

Se exporta **solo el CLS** (`hidden[:, 0, :]`) por estas razones:

1. Es la representación global con la que DINOv2 se entrena (objetivo DINO/iBOT) y la que se usa
   convencionalmente para clasificación y recuperación de imagen completa.
2. El promedio de parches mezcla objeto y fondo; en una foto de mostrador el fondo suele ocupar más
   área que el producto, así que diluye la señal del producto. El promedio es preferible para tareas
   densas (segmentación), no para “una imagen → un vector”.
3. Un único vector de 384 simplifica el contrato, la galería y la comparación por coseno.
4. **No se normaliza L2 dentro del grafo**: así la cuantización no altera la norma y la app decide
   cuándo normalizar (siempre antes de coseno/producto punto).

La elección queda documentada en el manifiesto: `output.name = "embedding"`,
`normalized_l2: false`, `embedding_dim: 384`.

### 5.4 Preprocesado de DINOv2 (exacto)

Tomado de `preprocessor_config.json` del commit fijado (`BitImageProcessor`):

1. Convertir el recorte a **RGB**.
2. Redimensionar el **lado corto a 256** conservando aspecto, **bicúbica**.
3. **Center crop 224×224**.
4. `valor/255`, luego `(valor - mean) / std` con
   `mean = [0.485, 0.456, 0.406]`, `std = [0.229, 0.224, 0.225]`.
5. Layout **NCHW**, `float32`.

Ejemplo: un recorte 1280×720 → 455×256 → centro 224×224. Si el recorte es menor que 224 en algún
lado, la app debe ampliarlo antes (el modelo no acepta otra resolución sin reexportar).

En JS, `createImageBitmap` + `OffscreenCanvas` con `imageSmoothingQuality = "high"` es una
aproximación razonable a la bicúbica de PIL; la diferencia es despreciable para coseno (el modelo es
robusto a interpolación). Si se busca exactitud, hacer el resize en el worker con el mismo algoritmo
y comparar contra `tools/ml/models_build/samples/`.

### 5.5 Preprocesado del detector (exacto y crítico)

El `preprocessor_config.json` de `PekingU/rtdetr_r18vd` trae **`do_normalize: false`**. Por tanto:

1. Convertir el frame completo a **RGB**.
2. Redimensionar **exactamente a 640×640** (sin conservar aspecto; **se deforma**), interpolación
   **bilineal**.
3. `valor/255` → rango `[0,1]`.
4. **No** restar media ni dividir por desviación. Si la app normaliza con ImageNet, el detector
   fallará silenciosamente.
5. Layout **NCHW**, `float32`.

Como el resize es exacto (no letterbox), al volver a píxeles del frame original basta multiplicar
`x` por `frameWidth` e `y` por `frameHeight`; no hay offsets de padding que restar.

### 5.6 Elección de variante

Se probaron tres variantes por modelo. La decisión se basa en los umbrales de la fase y en que el
destino es **CPU + navegador**:

| Modelo | fp32 | INT8 | fp16 | Publicada |
|---|---|---|---|---|
| Embeddings | coseno 1.0 | coseno 0.9789 (p5 0.9559) ❌ | coseno 0.999997, pero 88 ms vs 67 ms y fp16 no portable a WASM ❌ | **fp32** |
| Detector | IoU 0.99999 | IoU top-100 0.1735 ❌ | IoU top-100 0.5899; en score≥0.3 0.9982, pero 226 ms vs 177 ms ❌ | **fp32** |

- No se adopta INT8 porque **degrada de forma apreciable**.
- fp16 se rechaza por dos motivos: en CPU no aporta velocidad (de hecho es más lento por los casts) y
  `onnxruntime-web`/WASM no tiene soporte fiable de fp16. Publicar fp16 rompería el contrato del
  navegador.
- El detalle completo y la metodología están en §7.

### 5.7 Versión interna

`model_version = "<modelo>-onnx-<AAAA-MM>"`:

- `dinov2-small-onnx-2026-11`
- `rtdetr-r18vd-onnx-2026-11`

Se incrementa al reexportar con otro commit, otro opset o al cambiar el preprocesado. La app guarda
`gallery_model_version` junto a la galería y la invalida cuando cambia.

### 5.8 Batch

Ambos ONNX tienen **batch dinámico** (`dynamic_axes` en el eje 0). La app puede usar `batch = 1`
para el detector y lotes pequeños para embeddings (por ejemplo, todos los recortes de una escena).
La validación y las latencias se midieron con `batch = 1`.

---

## 6. Validación PyTorch frente a ONNX Runtime

Método: 8 imágenes de ejemplo (mínimo exigido: 5), preprocesadas con el processor oficial del
checkpoint; se compara la salida del wrapper PyTorch en `eval()` con `onnxruntime` CPU.

Imágenes usadas (quedan registradas en el manifiesto):

- `public/images/banner_forgot_password.webp`, `banner_register.webp`, `error.png`,
  `Mercado_Pago_logo.webp` (4 imágenes reales del repositorio);
- `tools/ml/models_build/samples/synthetic-00..03.png` (4 escenas sintéticas deterministas).

| Modelo | Métrica | Resultado | Umbral | Estado |
|---|---|---|---|---|
| DINOv2-small | coseno medio | **1.00000000** | > 0.99 | ✅ |
| DINOv2-small | coseno mínimo por muestra | **1.00000000** | > 0.99 | ✅ |
| DINOv2-small | coseno p5 | **1.00000000** | — | ✅ |
| DINOv2-small | diferencia absoluta máxima | 3.624e-05 | — | ✅ |
| RT-DETR-R18 | IoU medio (300 queries) | **0.99998863** | ≥ 0.99 | ✅ |
| RT-DETR-R18 | IoU mínimo | **0.99873447** | ≥ 0.99 | ✅ |
| RT-DETR-R18 | diferencia máxima de score (sigmoid) | **1.732e-05** | < 1e-3 | ✅ |
| RT-DETR-R18 | discrepancias (IoU < 0.99 o score > 1e-3) | **0** | 0 | ✅ |

La comparación del detector es **query a query** (300 queries) y además se calcula el subconjunto
top-100 por score: IoU medio `0.99999656`, mínimo `0.99984068`, acuerdo de clase `1.0`.
No hay cajas ni scores discrepantes que listar.

> **Matiz importante:** el corpus de validación incluye logotipos/banners del repositorio, que no son
> escenas de mostrador. Sirven para verificar equivalencia numérica, no para afirmar precisión de
> detección sobre productos. La validación con fotos reales de mostrador es el protocolo de §9 y queda
> pendiente de que el humano aporte las fotos.

### Verificación de humo (obligatoria)

```powershell
.\tools\ml\.venv\Scripts\python.exe -c "import onnxruntime; print(onnxruntime.__version__, onnxruntime.get_available_providers())"
.\tools\ml\.venv\Scripts\python.exe tools\ml\verify_models.py
```

Salida real:

```
onnxruntime 1.23.2
providers disponibles: ['AzureExecutionProvider', 'CPUExecutionProvider']
DINOv2-small: sha256=OK bytes=OK input=pixel_values[1, 3, 224, 224] OK salidas=['embedding'] [(1, 384)] OK
RT-DETR-R18vd: sha256=OK bytes=OK input=pixel_values[1, 3, 640, 640] OK salidas=['logits', 'pred_boxes'] [(1, 300, 80), (1, 300, 4)] OK
RESULTADO: OK — 2 modelo(s) verificados
```

---

## 7. Cuantización y latencias

### Método

- **INT8 dinámico** con `onnxruntime.quantization.quantize_dynamic`, pesos `QInt8` **por canal**,
  `reduce_range=False`.
- Solo se cuantizan **MatMul y Gemm**. Las convoluciones se dejan en fp32 porque el EP CPU de
  onnxruntime no implementa `ConvInteger` (el intento con Conv produjo
  `NOT_IMPLEMENTED: Could not find an implementation for ConvInteger`). Por eso la reducción de
  tamaño del detector es moderada (81.1 → 68.3 MB).
- No se usó cuantización **estática** (QDQ con calibración) porque no hay fotos reales de mostrador
  para calibrar; calibrar con logotipos o sintéticas sería engañoso. Queda como mejora futura (§10).
- **fp16** con `onnxruntime.transformers.float16.convert_float_to_float16`, `keep_io_types=True` y
  `op_block_list=["Resize", "Pow"]` (los kernels CPU de Resize cúbico y Pow no aceptan fp16).

### Resultados

Latencias medidas en CPU, `batch=1`, `intra_op_num_threads` por defecto de onnxruntime, 3 de
calentamiento y 30 repeticiones (15 en el detector y en fp16). Son de esta máquina; el navegador
tendrá otros números.

| Modelo | Variante | Tamaño | Δ vs fp32 | Latencia media | p95 | Calidad frente a la referencia | Decisión |
|---|---|---:|---:|---:|---:|---|---|
| DINOv2 | fp32 | 88.43 MB | — | **67.44 ms** | 85.70 ms | coseno 1.0 / min 1.0 / p5 1.0 | **publicada** |
| DINOv2 | INT8 | 25.11 MB | −71.6 % | 59.18 ms | 64.55 ms | coseno medio **0.97894** / p5 **0.95595** / min 0.95284 | rechazada: < 0.99 / 0.98 |
| DINOv2 | fp16 | 44.40 MB | −49.8 % | 88.38 ms | 92.67 ms | coseno medio 0.999997 / p5 0.999995 | rechazada: más lenta y no portable a WASM |
| RT-DETR | fp32 | 81.13 MB | — | **176.68 ms** | 182.33 ms | IoU 0.999989 / score diff 1.7e-05 | **publicada** |
| RT-DETR | INT8 | 68.30 MB | −15.8 % | 167.23 ms | 175.14 ms | IoU top-100 **0.17350**; score≥0.3 **0.72409**; caída media de score 0.0222 (0.0813 en score≥0.3) | rechazada |
| RT-DETR | fp16 | 41.01 MB | −49.5 % | 225.50 ms | 232.87 ms | IoU top-100 **0.58994**; score≥0.3 **0.99817**; caída media 0.00242 | rechazada: +28 % latencia, fp16 no portable |

**Interpretación del detector:** el `IoU top-100` es deliberadamente conservador: incluye queries de
score muy bajo cuyas cajas no son significativas y que cambian con cualquier cuantización. Aun así,
INT8 tampoco pasa el subconjunto de detecciones confiables (`score≥0.3`: 0.724) y su caída de score
es de 8 puntos porcentuales. fp16 es numéricamente bueno en las detecciones confiables, pero en las
imágenes fuera de dominio (banners/logos) colapsa scores y cajas, no aporta velocidad en CPU y no es
un formato fiable para WASM. Por eso se publica fp32.

### Tamaños y hashes

| Archivo | Bytes | SHA-256 |
|---|---:|---|
| `dinov2-small-86e875b45969.onnx` | 88 428 110 | `86e875b45969bf0d827e7c869aa2325fa5759451c2eb8d3173acdc84b1f57fe1` |
| `rtdetr-r18vd-fe0e4d6583e1.onnx` | 81 130 202 | `fe0e4d6583e1efe34587220220cff59192e44bda5c75133846ee7406e2476aba` |

---

## 8. Contrato exacto para la app JS (implementación literal)

### 8.1 Índice y manifiestos

```
GET /models/index.json
GET /models/dinov2-small.manifest.json
GET /models/rtdetr-r18vd.manifest.json
GET /models/dinov2-small-86e875b45969.onnx
GET /models/rtdetr-r18vd-fe0e4d6583e1.onnx
```

`index.json`:

```json
{
  "schema": "smartpos.models_index.v1",
  "gallery_model_version": "dinov2-small-onnx-2026-11",
  "models": [
    { "role": "embeddings", "name": "DINOv2-small", "model_version": "dinov2-small-onnx-2026-11",
      "manifest": "dinov2-small.manifest.json", "file": "dinov2-small-86e875b45969.onnx",
      "sha256": "86e8…7fe1", "bytes": 88428110, "variant": "fp32" },
    { "role": "detector", "name": "RT-DETR-R18vd", "model_version": "rtdetr-r18vd-onnx-2026-11",
      "manifest": "rtdetr-r18vd.manifest.json", "file": "rtdetr-r18vd-fe0e4d6583e1.onnx",
      "sha256": "fe0e…6aba", "bytes": 81130202, "variant": "fp32" }
  ]
}
```

- `gallery_model_version` es la versión del modelo de **embeddings**. La app debe guardarla junto a la
  galería vectorial y **recalcular la galería** si cambia.
- El nombre del archivo ya contiene el hash corto; para una verificación de integridad completa usar
  `crypto.subtle.digest("SHA-256", arrayBuffer)` sobre el `ArrayBuffer` descargado y comparar con
  `sha256`. Es opcional pero recomendable en la primera carga; no repetir en cada arranque.
- Cargar con `ort.InferenceSession.create(url, { executionProviders: ["wasm"] })` (o `webgpu` si se
  habilita). El modelo está exportado con opset 17 y operadores estándar.

### 8.2 Embeddings — DINOv2-small

| Campo | Valor |
|---|---|
| Nombre de entrada | `pixel_values` |
| Shape | `[N, 3, 224, 224]` (`N` = batch, dinámico) |
| dtype | `float32` |
| Nombre de salida | `embedding` |
| Shape de salida | `[N, 384]` |
| dtype de salida | `float32` |
| Normalización L2 | **no** incluida en el grafo |
| Semántica | token CLS de la última capa |

Preprocesado **paso a paso** (idéntico a §5.4):

```js
// 1. crop RGB de la imagen (producto o propuesta del detector)
// 2. resize del lado corto a 256, conservando aspecto, bicúbico
//    (OffscreenCanvas + imageSmoothingQuality = "high")
// 3. center crop 224x224: sx = floor((w - 224) / 2), sy = floor((h - 224) / 2)
// 4. por píxel y canal:
//      v = pixel / 255
//      v = (v - mean[c]) / std[c]
//    mean = [0.485, 0.456, 0.406]
//    std  = [0.229, 0.224, 0.225]
// 5. layout NCHW float32: data[c * 224 * 224 + y * 224 + x]
```

Pseudocódigo del tensor:

```js
const MEAN = [0.485, 0.456, 0.406];
const STD  = [0.229, 0.224, 0.225];
function toNCHW(rgba, w = 224, h = 224) {
  const out = new Float32Array(3 * w * h);
  for (let c = 0; c < 3; c++) {
    for (let y = 0; y < h; y++) {
      for (let x = 0; x < w; x++) {
        const i = (y * w + x) * 4 + c;
        out[c * w * h + y * w + x] = (rgba[i] / 255 - MEAN[c]) / STD[c];
      }
    }
  }
  return out;
}
```

Normalización y comparación en la app:

```js
function l2normalize(v) {
  let norm = 0;
  for (let i = 0; i < v.length; i++) norm += v[i] * v[i];
  norm = Math.sqrt(norm) || 1;
  const out = new Float32Array(v.length);
  for (let i = 0; i < v.length; i++) out[i] = v[i] / norm;
  return out;
}
const similarity = dot(a, b); // ambos L2-normalizados => coseno en [-1, 1]
```

La galería guarda `Float32Array(384)` **ya normalizado** para ahorrar trabajo; el vector de consulta
se normaliza después de la inferencia.

### 8.3 Detector — RT-DETR-R18vd

| Campo | Valor |
|---|---|
| Nombre de entrada | `pixel_values` |
| Shape | `[N, 3, 640, 640]` (`N` dinámico) |
| dtype | `float32` |
| Salida 1 | `logits`, `[N, 300, 80]`, `float32` |
| Salida 2 | `pred_boxes`, `[N, 300, 4]`, `float32` |
| Cajas | `cx, cy, w, h` **normalizadas a [0,1]** respecto a 640×640 |
| Clases | COCO-80 en el orden del manifiesto (`output.classes[0] = "person"`, … `[79] = "toothbrush"`) |

Preprocesado (idéntico a §5.5):

```js
// 1. frame completo RGB
// 2. resize exacto a 640x640 (estirar; NO letterbox), bilineal
// 3. v = pixel / 255; NO restar media ni dividir por std
// 4. NCHW float32
```

Postprocesado **literal** (sigmoid + top-k; sin NMS obligatorio):

```js
const CONF = 0.30;        // umbral de visualización
const AUTO = 0.50;        // umbral para auto-agregar en la caja
const TOP_K = 100;

function sigmoid(x) { return 1 / (1 + Math.exp(-x)); }

function postprocess(logits, predBoxes, frameW, frameH) {
  const dets = [];
  for (let q = 0; q < 300; q++) {
    let bestClass = 0;
    let bestScore = -Infinity;
    for (let c = 0; c < 80; c++) {
      const p = sigmoid(logits[q * 80 + c]);
      if (p > bestScore) { bestScore = p; bestClass = c; }
    }
    if (bestScore < CONF) continue;

    const cx = predBoxes[q * 4 + 0];
    const cy = predBoxes[q * 4 + 1];
    const w  = predBoxes[q * 4 + 2];
    const h  = predBoxes[q * 4 + 3];

    // cxcywh normalizado -> xyxy normalizado
    const x1n = cx - w / 2, y1n = cy - h / 2;
    const x2n = cx + w / 2, y2n = cy + h / 2;

    // normalizado -> píxeles del frame original
    // (resize exacto: solo escala; no hay padding)
    const x1 = Math.max(0, Math.min(frameW, x1n * frameW));
    const y1 = Math.max(0, Math.min(frameH, y1n * frameH));
    const x2 = Math.max(0, Math.min(frameW, x2n * frameW));
    const y2 = Math.max(0, Math.min(frameH, y2n * frameH));

    if (x2 - x1 < 2 || y2 - y1 < 2) continue;
    dets.push({ query: q, classId: bestClass, score: bestScore, box: [x1, y1, x2, y2] });
  }
  dets.sort((a, b) => b.score - a.score);
  return dets.slice(0, TOP_K);
}
```

- **Sigmoid**: sí, los logits están en escala focal loss; hay que aplicarla.
- **Top-k**: 300 queries ya vienen sin duplicados por diseño (RT-DETR es end-to-end). Se ordenan por
  score y se corta en 100. **NMS no es necesario**; si en pruebas aparecen duplicados por clase, se
  puede aplicar un NMS por clase con IoU 0.55, pero no forma parte del contrato validado.
- **cxcywh → xyxy** y escalado: como el preprocesado es un resize exacto (sin letterbox), no hay
  offsets; multiplicar por `frameW`/`frameH` es suficiente. Si en el futuro se cambia a letterbox,
  hay que restar el padding antes de escalar y revalidar.
- **Batch**: `[N,3,640,640]`; para una cámara usar `N=1`. Si se procesan varias cámaras, concatenar.
- **Uso de clases**: las 80 clases son COCO y **no** representan el catálogo de la tienda. Para
  identificar producto se usan las cajas + embeddings + galería; la clase solo puede servir como pista.

### 8.4 Flujo recomendado en el worker

```
frame → detector ONNX → cajas (score >= 0.30)
      → recorte con 8–10 % de margen (clamp al frame)
      → DINOv2 ONNX (batch de recortes)
      → L2 normalize
      → coseno contra galería (vectores normalizados)
      → top-k + umbrales del semáforo (Fase 2)
      → addToCart / candidatos / desconocido
```

- El detector trabaja sobre el frame completo; DINOv2 sobre **recortes** (no el frame completo), para
  que el producto ocupe la imagen.
- Guardar en la galería `{productId, vector: Float32Array(384), modelVersion}`; si
  `modelVersion !== index.gallery_model_version`, reconstruir.
- El motor de Fase 2 (`RecognitionEngine`) no cambia: este contrato se implementa detrás de la misma
  interfaz.

---

## 9. Protocolo para repetir la comparación de proponedores con fotos reales

Objetivo: comparar `EmptyCounterDiff` contra rejilla/saliencia con datos reales del mostrador, usando
el mismo formato que el set sintético.

### 9.1 Qué fotos tomar

- **20–30 escenas** (recomendado 30–50). Cada escena = **un par**: mostrador vacío y el mismo
  mostrador con 1–6 productos.
- **Cámara fija** (trípode o soporte), misma altura, distancia, orientación y zoom entre el par.
  No mover la cámara entre la foto vacía y la cargada.
- **1280×720 exactos** (16:9). Sin recortes distintos entre el par. Desactivar HDR, flash y zoom
  digital; evitar blur de movimiento.
- Iluminación **tal como está en la tienda**: incluir al menos 3 condiciones (luz natural, artificial
  cálida, mixta) y 2–3 texturas de mostrador (madera, mármol, concreto, laminado).
- La foto vacía se toma **primero**, sin productos, manos ni objetos personales. Después se colocan
  los productos y se toma la cargada sin mover la cámara.
- Incluir casos difíciles: botellas brillantes/transparentes, empaques oscuros, sombras fuertes,
  productos que se tocan u ocluyen, 1 producto grande y 6 pequeños.
- No incluir personas, caras, documentos ni datos de clientes. Estas fotos son datos del negocio y
  **no se versionan**.

### 9.2 Cómo nombrarlas

```
tools/ml/synthetic/real_raw/
  s001_empty.jpg
  s001_scene.jpg
  s001.json          # opcional pero necesario para IoU
  s002_empty.jpg
  s002_scene.jpg
  s002.json
  ...
```

- `s001`, `s002`, … con tres dígitos; extensión `.jpg`, `.jpeg`, `.png` o `.webp`.
- Las dos fotos del par deben existir y medir 1280×720.
- Anotación (`s001.json`): cajas axis-aligned en píxeles de la foto cargada.

```json
{
  "objects": [
    { "bbox_xyxy": [312, 288, 498, 512], "type": "botella", "label": "producto", "occluded": false },
    { "bbox_xyxy": [640, 330, 820, 470], "type": "caja", "label": "producto", "occluded": true }
  ]
}
```

Si no hay `.json`, el set se genera igual y sirve para el diff contra el vacío, pero **no** para
calcular IoU/recall de propuestas.

### 9.3 Qué comando correr

```powershell
# Convierte los pares reales al mismo formato de frames que el set sintético
.\tools\ml\.venv\Scripts\python.exe tools\ml\evaluate_proposers.py `
  --from-photos tools\ml\synthetic\real_raw `
  --out tools\ml\synthetic\real `
  --force
```

Salida:

```
tools/ml/synthetic/real/
  frames/s001.rgb.bin
  frames/s001.empty.rgb.bin
  frames/index.json
  ground_truth.json
  previews/s001.png ...
```

`frames/index.json` tiene el mismo esquema (`smartpos.proposers.frames.v1`), `source: "real_photos"`,
`width/height`, `bytes_per_frame = 2764800`, `paths_relative_to = "tools/ml/synthetic/real"` y la
lista de frames con su `empty_rgb` y hashes. El arnés JS de propuestas debe apuntar a ese índice y
usar `ground_truth.json` para las métricas (IoU de la mejor propuesta, recall a IoU 0.5, número de
propuestas por escena y latencia por escena).

### 9.4 Cómo comparar (arnés JS, Fase 4)

Para cada escena y cada estrategia (`EmptyCounterDiff`, rejilla, saliencia, etc.):

1. Leer `frames/<id>.rgb.bin` y `frames/<id>.empty.rgb.bin` como `Uint8Array` (1280·720·3).
2. Ejecutar el proponedor y obtener cajas `[x1,y1,x2,y2]` en píxeles.
3. Emparejar con `ground_truth[].objects[].bbox_xyxy` (IoU ≥ 0.5) y calcular:
   - **recall** (productos cubiertos / total),
   - **IoU medio** de la mejor propuesta por producto,
   - **propuestas por escena** (coste),
   - **latencia** por escena (ms).
4. Reportar media y p95 por estrategia, separando por tema/iluminación y por número de productos.
5. Repetir el mismo arnés sobre el set sintético como línea base; las conclusiones solo se aceptan si
   se mantienen en ambos.

El comando exacto del arnés se definirá cuando exista (Fase 4): hoy el contrato de entrada es
`tools/ml/synthetic/real/frames/index.json`. El set sintético ya está listo para ese arnés.

---

## 10. Limitaciones y pendientes

1. **No hay fotos reales de mostrador todavía.** La validación numérica usó 8 imágenes mixtas
   (4 del repositorio y 4 sintéticas). La precisión real de detección/embeddings se debe medir con el
   protocolo de §9.
2. **Cuantización estática INT8 no probada.** No hay set de calibración real; la dinámica solo
   cuantiza MatMul/Gemm y deja las convoluciones en fp32. Una QDQ estática con fotos reales podría
   reducir más el detector, pero exige recalibrar y volver a medir.
3. **fp16 no es opción para el navegador** en esta fase: WASM no lo soporta de forma fiable y en CPU
   es más lento.
4. **Latencia medida en esta máquina**, con `intra_op_num_threads` por defecto. El worker del
   navegador tendrá otra latencia; hay que medirla en el equipo del cliente.
5. **DINOv2 con center crop** puede perder productos en el borde si se le pasa el frame completo. El
   flujo correcto es recortar cada propuesta del detector antes de embedding.
6. **El detector es COCO-80**, no un catálogo de productos. No usar `classId` para decidir el
   producto; usar la galería de embeddings y el catálogo.
7. **Los `.onnx` no están en el repo.** El despliegue debe ejecutar el pipeline (o copiar los
   artefactos ya generados) y asegurarse de servir `public/models/` con el MIME correcto. Los
   manifiestos e `index.json` sí están versionados y son la fuente de verdad del hash.
8. **Verificación de integridad en el navegador** es opcional; si se implementa, comparar el SHA-256
   completo del manifiesto antes de crear la sesión de onnxruntime.

---

## 11. Evidencia de verificación ejecutada

```powershell
# 1. Versiones y providers
.\tools\ml\.venv\Scripts\python.exe -c "import onnxruntime; print(onnxruntime.__version__, onnxruntime.get_available_providers())"
# onnxruntime 1.23.2 ['AzureExecutionProvider', 'CPUExecutionProvider']

# 2. Carga + shapes + hashes de los ONNX publicados
.\tools\ml\.venv\Scripts\python.exe tools\ml\verify_models.py
# RESULTADO: OK — 2 modelo(s) verificados

# 3. Set sintético
.\tools\ml\.venv\Scripts\python.exe -c "import json; i=json.load(open('tools/ml/synthetic/frames/index.json')); print(i['frame_count'], i['bytes_per_frame'])"
# 20 2764800
```

- `tools/ml/synthetic/frames/index.json`: **20 escenas válidas**, 40 frames (escena + vacío),
  `bytes_per_frame = 2764800`, PNG de vista previa solo de 3 escenas.
- `tools/ml/synthetic/ground_truth.json`: 20 escenas, 51 objetos anotados con caja exacta.
- Los frames son deterministas para `--seed 20261127`; `--force` regenera todo.
