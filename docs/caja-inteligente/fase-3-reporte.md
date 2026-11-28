# Fase 3 — Modelos y reconocimiento real · Reporte (parcial, en curso)

> Rama `feature/modo-caja-inteligente` · Fecha: 2026-11-27 · Sin commits.
> Decisiones aplicadas: D7 (modelos en `public/models`, sin Git LFS), set sintético por falta de fotos reales,
> 1 y 2 cámaras simuladas por falta de hardware.

---

## 1. Estado

| Pieza | Estado |
|---|---|
| Galería de vectores (backend) | ✅ Completa, 21 pruebas |
| Núcleo de inferencia (worker, motor ONNX, cámaras, estabilidad, proponedores, códigos) | ✅ Completo, 187 pruebas JS |
| Integración en el POS (motor real con respaldo al simulado, vista previa, calibración) | ✅ Compila y está cableada |
| **Pipeline de modelos** (descarga, export ONNX, validación, cuantización, manifiestos) | ⏳ **Ejecutándose** (agente `ml-modelos`): instalación de PyTorch/ONNX en `tools/ml/.venv` |
| Comparación de proponedores con el set sintético | ⏳ Depende de los fixtures que genera el pipeline |
| Latencia real en navegador (< 1 s, i5 8ª gen) | ⏳ Requiere tu equipo: no hay navegador en este entorno |
| Protocolo de fotos reales | ✅ Documentado por el pipeline (pendiente de que aportes las fotos) |

**No cierro la fase todavía**: sin los artefactos ONNX no se puede medir la latencia ni comparar los proponedores,
que son los dos criterios de aceptación de la Fase 3.

---

## 2. Qué se construyó

### Backend — galería de vectores (6 archivos nuevos, 1 modificado)
- `smartpos_product_samples` (tabla nueva): vector float32 de 384 dimensiones en `blob`, `model_version`, `source`,
  `crop_path` opcional, `quality`, límite de 10 muestras por producto con rotación.
- `GET /pos/smart-checkout/gallery` con `ETag`/304 y paginación por cursor (~4 MiB por página).
- `POST /pos/smart-checkout/samples` (vector + recorte opcional) y `DELETE /pos/smart-checkout/samples/{id}`.
- Aislamiento por tenant derivado del usuario; validación de que el producto es de su sucursal.

### Frontend — inferencia en el navegador (10 archivos nuevos)
| Archivo | Contenido |
|---|---|
| `inference/tensorUtils.js` | NCHW + normalización ImageNet, L2, base64 float32 |
| `inference/detectorPostprocess.js` | sigmoid, IoU, NMS sin etiquetas, `cxcywh` → caja |
| `inference/frameStability.js` | firma 32×18, distancia, ventana de estabilidad |
| `inference/diffProposer.js` | `EmptyCounterDiffProposer` completo (máscara, dilatación, componentes) |
| `inference/gallery.js` | decodificación del blob, búsqueda top-k por producto |
| `inference/barcode.js` | `BarcodeDetector` nativo o `zxing-wasm` bajo demanda |
| `inference/cameraSource.js` | `getUserMedia` + fuente simulada + degradación con aviso |
| `inference/inference.worker.js` | worker: modelos, propuestas, embeddings, códigos, galería |
| `inference/OnnxWebRecognitionEngine.js` | motor ONNX con la misma interfaz que el simulado |
| `tests/js/smart-checkout/inference.test.mjs` | 22 pruebas de todo lo anterior |

### Integración
- El POS usa el **motor ONNX si hay modelos publicados** en `/models/index.json`; si no, cae al simulado sin romper nada.
- Vista previa real (`<video>`) con cajas encima, botón **«Recalibrar mostrador»** y contador de galería.
- WASM **monohilo**: no se añaden cabeceras COOP/COEP ni se toca nada del POS (impresión, pagos, scripts de terceros).
- `vite.config.js`: `worker: { format: 'es' }` (único cambio de configuración, necesario para el worker con code splitting).

---

## 3. Verificación

| Comprobación | Resultado |
|---|---|
| Suite PHP | **24 fallos preexistentes, 462 pasadas** (3236 aserciones) → +21 nuevas, 0 fallos nuevos |
| `--filter SmartCheckout` | 67 passed (776 aserciones) |
| Pruebas JS | **187 passed**, 0 fallos (86 + 79 de Fase 2 + 22 nuevas) |
| Build | ✓ exit 0 · worker `inference.worker` 88 kB · `OnnxWebRecognitionEngine` 9,8 kB · wasm ONNX 14,2 MB y zxing 0,9 MB **como assets aparte** |
| Carga perezosa | el wasm de ONNX solo se descarga cuando el modo arranca con modelos publicados |

---

## 4. Pendiente para cerrar la fase

1. **Artefactos ONNX** (agente en curso): `public/models/*.onnx` + `manifest.json` + `index.json` + `docs/caja-inteligente/modelos.md`.
2. **Comparación de proponedores** con el set sintético (`tools/ml/synthetic/`): precisión/recall/IoU por estrategia.
3. **Latencia en tu equipo**: con los modelos publicados, el panel muestra los ms por análisis; el criterio es < 1 s en un i5 de 8.ª generación sin GPU.
4. **Fotos reales**: falta el set del mostrador; el protocolo queda documentado y el comando listo.
5. **Poblar la galería**: hasta la Fase 4 (aprendizaje con el uso) la galería está vacía, así que el motor real marcará todo como
   «no reconocido». Es el comportamiento esperado de esta fase; el flujo de producto desconocido ya permite dar de alta y
   escanear, que es lo que alimentará la galería en la Fase 4.

---

## 5. Confirmación explícita

> **Con el Modo Caja Inteligente apagado y la báscula deshabilitada, el módulo Punto de venta se comporta igual que antes.**

Sin `smart_checkout_enabled` no se monta ningún componente, no se descarga ningún chunk (incluido el wasm de ONNX),
no se pide permiso de cámara y no se consulta la galería. La suite mantiene los mismos 24 fallos preexistentes.
