# Fase 2 — Esqueleto del Modo Caja Inteligente (sin IA real) · Reporte

> Rama `feature/modo-caja-inteligente` (desde `Miguel`) · Fecha: 2026-11-27 · Sin commits.
> Decisiones aplicadas: D2 (preferencia del negocio + estado por terminal) y «Sin permisos propios»
> ([`decisiones.md`](decisiones.md)). Motor de reconocimiento **simulado**: la IA real llega en la Fase 3.

---

## 1. Qué se hizo

- **Botón ON/OFF** «Caja Inteligente» en la barra de acciones del POS (`pi-microchip-ai`), visible solo si el negocio
  habilitó el modo. Al activarlo se **importa dinámicamente** el módulo: con el modo apagado no se descarga nada nuevo.
- **Motor simulado** (`MockRecognitionEngine`) detrás de la interfaz `RecognitionEngine`, con semáforo por reglas de
  confianza configurables, cajas, candidatos top-3, códigos de barras ocasionales y la fusión multi-cámara
  (`zone` suma, `overlap` toma el máximo y marca discrepancia).
- **Panel de vista previa colapsable** con cajas y badges verde/amarillo/rojo (**color + icono + texto**), selector de
  los 3 mejores candidatos, flujo de **producto desconocido** (buscar por código/nombre, alta rápida, descartar) y
  contadores de la venta en curso.
- **Barra de indicadores** (visible solo con el modo activo): estado de cámara y modelo, báscula si está habilitada,
  detectados / automáticos / confirmados / desconocidos, tiempo de la venta, latencia, y del día: precisión,
  desconocidos, ventas con el modo y promedio de segundos por ticket.
- **Todo pasa por la acción existente del POS**: lo detectado llama a la misma `addToCart` con la cantidad que entrega
  la fusión de cámaras; los productos **a granel** se derivan al panel de pesado de la Fase 1.
- **Métricas**: cada análisis se agrega y se envía cada 5 s (y al cerrar), la venta se cierra con su `sale_id` y el
  resumen del día se refresca solo. Si el registro de métricas falla, **la venta no se bloquea**.
- **Interruptores**: «Preferencias de caja» ahora tiene también el interruptor del Modo Caja Inteligente.
- **Estado recordado por terminal** (`localStorage smartpos_checkout_{userId}_{branchId}`): si el cajero lo dejó
  activo, al volver al POS se restablece solo.

---

## 2. Archivos nuevos (21)

### Backend (12)
| Archivo | Contenido |
|---|---|
| `database/migrations/2026_11_27_000003_create_smartpos_detection_events_table.php` | Tabla `smartpos_detection_events` (métricas, **sin imágenes**) |
| `app/Models/SmartPosDetectionEvent.php` | Modelo con scopes (`forBranch`, `betweenDates`, `ofType`) |
| `app/Services/SmartCheckout/SmartCheckoutAnalyticsService.php` | `recordEvent()` + `todayMetrics()` |
| `app/Http/Controllers/SmartCheckout/SmartCheckoutController.php` | Ajustes, ajustes (PUT), evento, métricas del día |
| `app/Http/Requests/SmartCheckout/StoreDetectionEventRequest.php` | Validación del evento (422 JSON) |
| `app/Http/Requests/SmartCheckout/UpdateSmartCheckoutSettingsRequest.php` | Validación de umbrales/cámaras (422 JSON) |
| `tests/Feature/SmartCheckout/SmartCheckoutTestCase.php` | Base común de las pruebas nuevas (abstracta) |
| `tests/Feature/SmartCheckout/SmartCheckoutSettingsTest.php` | Flag, defaults, validación, aislamiento (6) |
| `tests/Feature/SmartCheckout/SmartCheckoutEventsTest.php` | Eventos: validación, tenant, venta ajena (6) |
| `tests/Feature/SmartCheckout/SmartCheckoutMetricsTest.php` | Agregados del día y de la sucursal (5) |
| `tests/Feature/SmartCheckout/SmartCheckoutDataEquivalenceTest.php` | **Criterio de la fase**: la venta no cambia (1) |
| `tests/Feature/SmartCheckout/SmartCheckoutPropsTest.php` | Prop `smartCheckout` apagada/encendida (3) |

### Frontend (9)
| Archivo | Contenido |
|---|---|
| `resources/js/Composables/smartCheckout/recognition/confidence.js` | Umbrales, semáforo, top-3, etiquetas/iconos, fusión multi-cámara |
| `resources/js/Composables/smartCheckout/recognition/RecognitionEngine.js` | Clase base + bus de eventos + `normalizeDetection` |
| `resources/js/Composables/smartCheckout/recognition/MockRecognitionEngine.js` | Motor simulado determinista (semilla), latencia, `forceDetection` |
| `resources/js/Composables/smartCheckout/recognition/index.js` | `createRecognitionEngine`, catálogo de motores |
| `resources/js/Pages/POS/SmartCheckout/useSmartCheckout.js` | Sesión del modo: motor, decisiones, contadores, eventos, métricas |
| `resources/js/Pages/POS/SmartCheckout/SmartCheckoutPanel.vue` | Panel de vista previa, candidatos, producto desconocido, ajustes |
| `resources/js/Pages/POS/SmartCheckout/SmartCheckoutIndicators.vue` | Barra de indicadores |
| `tests/js/smart-checkout/confidence.test.mjs` | 42 pruebas de reglas y fusión |
| `tests/js/smart-checkout/recognition.test.mjs` | 37 pruebas del motor base y el simulado |

---

## 3. Archivos existentes modificados (8)

| Archivo | Cambio |
|---|---|
| `app/Http/Controllers/PointOfSaleController.php` | Lee el flag de la fila que **ya** consultaba y añade la prop `smartCheckout` (sin consultas extra) |
| `app/Services/SmartCheckout/SmartPosSettingsService.php` | Añade `smartCheckoutSettingsFor()`, `updateSmartCheckout()`, defaults y normalización del modo (no toca la báscula) |
| `routes/web/smart-checkout.php` | 4 rutas nuevas del modo (`settings`, `settings.update`, `events.store` con `throttle:120,1`, `metrics.today`) |
| `resources/js/Pages/POS/Index.vue` | Prop `smartCheckout`, sesión perezosa, intercepción de lo detectado, indicadores, panel, cierre de venta |
| `resources/js/Pages/POS/IndexMobile.vue` | Idéntico (D5) |
| `resources/js/Pages/POS/Partials/PosLeftPanel.vue` | Botón ON/OFF del modo + prop/evento |
| `resources/js/Pages/POS/SmartCheckout/ScalePreferencesModal.vue` | Segundo interruptor: Modo Caja Inteligente |
| `tests/js/index.js` | Registra las dos suites nuevas del motor |

**No se tocó**: tablas existentes, `.env`, `composer.json`, `PermissionSeeder`, `vite.config.js`, ni ninguna dependencia.

---

## 4. Migraciones nuevas (una, solo aditiva)

`smartpos_detection_events`: **CREATE TABLE** con FKs (`subscription_id`, `branch_id`, `user_id`, `sale_id`), `session_id`,
contadores, `latency_ms`, `model_version`, `duration_seconds`, `occurred_at` e índices (incluido `branch_id + occurred_at`).
Ninguna columna ni tabla existente se modifica. `down()` solo elimina la tabla.

> **Pendiente de ejecutar en tu base local**: `php artisan migrate` (aplica solo esta; la de la Fase 1 ya está aplicada).

---

## 5. Cómo probarlo

```powershell
php artisan migrate        # crea smartpos_detection_events
npm.cmd run build          # ya compilado
```

1. POS → **Preferencias de caja** → activar **Modo Caja Inteligente** → guardar.
2. Aparece el botón <kbd>pi-microchip-ai</kbd> en la barra; al pulsarlo se monta el panel y la barra de indicadores.
3. El motor simulado emite detecciones: verdes se agregan solas al carrito, amarillas muestran los 3 candidatos
   (atajos **1/2/3**), rojas abren el flujo de producto desconocido. **Esc** colapsa el panel.
4. En el panel, <kbd>pi-sliders-h</kbd> ajusta los umbrales del semáforo y el auto-agregado.
5. Cobrar: la venta se registra igual y al cerrar se envía el evento con su `sale_id`; los indicadores del día se actualizan.
6. Apagar el modo: el panel desaparece y el POS queda como siempre.

---

## 6. Resultados de pruebas

| Prueba | Comando | Resultado |
|---|---|---|
| Suite completa PHP | `php artisan test` | **24 fallos preexistentes, 441 pasadas** (2954 aserciones), 59 s |
| Base al empezar la fase | — | 24 fallos, 420 pasadas → **+21 nuevas en verde, 0 fallos nuevos** |
| Pruebas del módulo | `php artisan test --filter SmartCheckout` | **46 passed** (494 aserciones) |
| Pruebas JS | `node --test tests/js` | **165 passed**, 0 fallos, 30 suites (86 de la Fase 1 + 79 nuevas) |
| Build | `node node_modules/vite/bin/vite.js build` | ✓ exit 0 |
| Code splitting | inspección de `public/build/assets` | `SmartCheckoutPanel` 16.4 KB, `SmartCheckoutIndicators` 4.4 KB y `useSmartCheckout`+motor 6.5 KB **en chunks propios** |
| Rutas | `php artisan route:list --path=smart-checkout` | 8 rutas con `auth` + `can:pos.access` |

**Criterio de la fase cumplido por prueba**: `SmartCheckoutDataEquivalenceTest` registra eventos de análisis y de venta
alrededor de un cobro y verifica que la venta resultante (transacción, partidas y stock) es **idéntica** a una venta
manual equivalente.

---

## 7. Riesgos, dudas y decisiones pendientes

1. **Falta correr la migración** en tu base: mientras no se aplique, el modo funciona visualmente pero los eventos
   responden 500 y la barra de indicadores se queda en ceros (el registro es «best effort»: nunca bloquea la venta).
2. **Motor simulado**: no hay cámara ni modelo real todavía; las cajas son sintéticas. La Fase 3 sustituye el motor
   detrás de la misma interfaz, sin tocar la UI.
3. **Candidatos de la ventana cargada**: el motor simulado elige candidatos entre los productos ya cargados en el POS
   (paginado de 20). En la Fase 3 el reconocimiento resolverá contra la galería y el catálogo completo del servidor.
4. **Venta desde el motor**: si el producto detectado está fuera de esas 20 filas, el candidato no tendrá datos de precio;
   hoy no ocurre por diseño del mock, pero conviene un endpoint de producto por id cuando llegue la galería.
5. **Sin verificación visual automatizada** (no hay navegador headless en el proyecto): la revisión visual final es tuya.
6. **`Api\V1\CustomerApiTest > it searches customers by name or phone`** es un fallo **preexistente e intermitente**
   (Faker genera nombres que contienen «Ana»); no lo toqué.

---

## 8. Confirmación explícita

> **Con el Modo Caja Inteligente apagado y la báscula deshabilitada, el módulo Punto de venta se comporta igual que antes.**

Evidencia: sin `smart_checkout_enabled` el botón no se renderiza, no se monta ningún componente del modo, no se
descargan sus chunks, no se ejecuta ningún `import()` en `onMounted` y no se envía ni una petición a las rutas nuevas.
La suite mantiene los mismos 24 fallos preexistentes.
