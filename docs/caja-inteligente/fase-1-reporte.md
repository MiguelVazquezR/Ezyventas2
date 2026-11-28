# Fase 1 — Báscula para productos a granel · Reporte

> Rama `feature/modo-caja-inteligente` (desde `Miguel`) · Fecha: 2026-11-27 · Sin commits.
> Decisiones aplicadas: D1 (cantidades fraccionarias condicionadas), D2 (preferencia por negocio + terminal),
> D3 (pesable = `is_bulk` + unidad de peso), D3b (precio por unidad de medida), D4 (`quantity` opcional), D5 (escritorio y móvil)
> y **sin permisos propios** (decisión posterior del humano: «son funciones para cualquier usuario sin restricción»).

---

## 1. Qué se hizo

Se implementó la báscula de granel **dentro del Punto de venta existente**, sin tocar la lógica del carrito más allá de
un parámetro opcional:

- **Tres drivers** detrás de la interfaz `ScaleDriver`: simulador (obligatorio para pruebas), captura manual y
  **Web Serial** con perfiles configurables (baudios, bits, paridad, parada, terminador y expresión regular) y
  **modo diagnóstico** con las líneas crudas del puerto.
- **Panel de pesado** en el POS (escritorio y móvil): peso en vivo, indicador estable/inestable con icono y color,
  **Tara**, precio por unidad, **total calculado** y botón Agregar (o Enter).
- **Acceso rápido «Granel»** en la barra de acciones: lista buscable de productos pesables de la sucursal.
- **Interruptor por negocio** dentro del propio POS («Preferencias de caja») y ajustes de báscula (perfiles,
  estabilidad, diagnóstico) accesibles para cualquier usuario del POS.
- **Enganche mínimo**: al tocar un producto pesable se abre el panel; al confirmar se llama a la **misma** `addToCart`
  del POS con la cantidad decimal. El resto del carrito (precios escalonados, ediciones, promociones, cobro) no se tocó.
- **Validación condicional (D1)**: el cobro acepta cantidades ≥ 0.001 **solo** para productos pesables y **solo** con la
  báscula activa; con la báscula apagada el POS valida exactamente como antes.

---

## 2. Archivos nuevos (26)

### Backend (12)
| Archivo | Contenido |
|---|---|
| `database/migrations/2026_11_27_000001_create_smartpos_settings_table.php` | Tabla `smartpos_settings` (una fila por suscripción) |
| `app/Models/SmartPosSetting.php` | Modelo con `HasSubscription`, casts y scopes |
| `app/Services/SmartCheckout/SmartPosSettingsService.php` | Lectura/escritura de preferencias, defaults, normalización, unidades de peso |
| `app/Http/Controllers/SmartCheckout/ScaleController.php` | Preferencias, ajustes y productos pesables |
| `app/Http/Requests/SmartCheckout/UpdateScaleSettingsRequest.php` | Validación de perfiles y umbrales (422 JSON) |
| `app/Http/Requests/SmartCheckout/UpdatePreferencesRequest.php` | Validación de los interruptores (422 JSON) |
| `routes/web/smart-checkout.php` | 4 rutas bajo `/pos/smart-checkout` (middleware `auth` + `can:pos.access`) |
| `tests/Feature/SmartCheckout/ScaleSettingsTest.php` | Ajustes: flag del negocio, validación, aislamiento entre negocios |
| `tests/Feature/SmartCheckout/ScaleProductsTest.php` | Productos pesables por sucursal y búsqueda |
| `tests/Feature/SmartCheckout/ScaleCartValidationTest.php` | Cobro de 0.750 kg y de 1 unidad; regresión con el flag apagado |
| `tests/Feature/SmartCheckout/ScalePreferencesTest.php` | Interruptor on/off, aislamiento, persistencia de perfiles |
| `tests/Feature/SmartCheckout/SmartPosSettingsDegradationTest.php` | Sin tabla migrada el POS sigue funcionando |

### Frontend (14)
| Archivo | Contenido |
|---|---|
| `resources/js/Composables/smartCheckout/scaleMath.js` | Matemática pura: unidades, estabilidad, importes, formato, parseo y buffer serial |
| `resources/js/Composables/smartCheckout/useScaleSession.js` | Sesión de báscula: drivers, lectura, tara, línea y total; recuerda el driver por terminal |
| `resources/js/Composables/smartCheckout/drivers/ScaleDriver.js` | Clase base (eventos `reading`/`error`/`status`/`raw`) |
| `resources/js/Composables/smartCheckout/drivers/SimulatedScaleDriver.js` | Simulador (pesos estables e inestables) |
| `resources/js/Composables/smartCheckout/drivers/ManualScaleDriver.js` | Captura manual |
| `resources/js/Composables/smartCheckout/drivers/WebSerialScaleDriver.js` | Web Serial + perfiles + diagnóstico + tara software |
| `resources/js/Composables/smartCheckout/drivers/index.js` | `createScaleDriver`, etiquetas |
| `resources/js/Pages/POS/SmartCheckout/ScalePanel.vue` | Panel de pesado y selector de producto a granel |
| `resources/js/Pages/POS/SmartCheckout/ScaleSettingsModal.vue` | Perfiles, estabilidad y modo diagnóstico |
| `resources/js/Pages/POS/SmartCheckout/ScalePreferencesModal.vue` | Interruptor de la báscula por negocio |
| `tests/js/smart-checkout/scaleMath.test.mjs` | Estabilidad, unidades, redondeo, parseo serial |
| `tests/js/smart-checkout/drivers.test.mjs` | Simulador, manual y factory |
| `tests/js/smart-checkout/webSerial.test.mjs` | Conexión, lectura, tara, diagnóstico, desconexión |
| `tests/js/index.js` | Entrada de la suite (Node 26 no expande directorios en `--test`) |

---

## 3. Archivos existentes modificados (9) y qué cambió en cada uno

| Archivo | Cambio (mínimo) |
|---|---|
| `app/Http/Controllers/PointOfSaleController.php` | (+140) Prop `scale` en `index()`; validación de `cartItems.*.quantity` sin `min` y tres métodos privados nuevos (`assertWeighableQuantities`, `assertMinimumQuantity`, `weighableProductIds`); inyección del servicio |
| `app/Models/TransactionItem.php` | Cast `quantity` de `decimal:2` a `decimal:3` (D1) |
| `app/Models/BranchProduct.php` | Casts de stock a `decimal:3` (D1) |
| `app/Models/BranchProductAttribute.php` | Casts de stock a `decimal:3` (D1) |
| `routes/web.php` | Una línea: `require __DIR__ . '/web/smart-checkout.php';` |
| `resources/js/Pages/POS/Index.vue` | (+135) Prop `scale`, `quantity` en `addToCart`, intercepción de pesables, panel/ajustes/preferencias con import dinámico |
| `resources/js/Pages/POS/IndexMobile.vue` | (+134) Idéntico a escritorio (D5) |
| `resources/js/Pages/POS/Partials/PosLeftPanel.vue` | (+14) Prop `scale`, botones «Productos a granel» y «Preferencias de caja», dos eventos nuevos |
| `package.json` | Un script: `"test:js": "node --test tests/js"` |

**No se tocó**: ninguna tabla existente, ni `PermissionSeeder`, ni `.env`, ni `vite.config.js`, ni `composer.json`,
ni ninguna dependencia (las pruebas JS usan el runner nativo de Node).

---

## 4. Migraciones nuevas (una, solo aditiva)

`smartpos_settings`: **CREATE TABLE** con FK `subscription_id` único, dos booleanos (`smart_checkout_enabled`,
`scale_enabled`), `scale_settings`/`smart_checkout_settings` (JSON), `retention_days`, `save_crops` y timestamps.
`down()` solo elimina la tabla.

Se eliminó antes de migrar la segunda migración (la que insertaba los 4 permisos), así que **nunca llegaron a la base**:
`Permission::where('name', 'like', 'scale.%')` → 0 filas. Ninguna migración modifica o elimina columnas o tablas existentes.

---

## 5. Cómo probarlo

```powershell
# 1. Migración (ya ejecutada en la base local ezyventas2)
php artisan migrate

# 2. Compilar el frontend (ya compilado)
npm.cmd run build

# 3. Entrar al POS (cualquier usuario con acceso al Punto de venta)
#    → botón "Preferencias de caja" (pi pi-sliders-h) → activar "Báscula para productos a granel"
# 4. Tocar un producto marcado como granel con unidad Kg o Grs → se abre el panel de pesado
#    - Driver "Báscula simulada": botones 0.250 / 0.750 / 1.500 / Inestable / Cero
#    - Driver "Captura manual": escribir 0.750 y Enter → Agregar
#    - Driver "Báscula USB": Configurar → perfil (9600 8N1, terminador LF) → "Modo diagnóstico" para ver las tramas
# 5. Cobrar y verificar la línea con cantidad 0.750 y el importe correcto
# 6. Apagar la báscula y comprobar que el POS vuelve a su comportamiento normal
```

---

## 6. Resultados de pruebas

| Prueba | Comando | Resultado |
|---|---|---|
| Suite completa PHP | `php artisan test` | **24 fallos, 420 pasadas** (2681 aserciones) |
| Línea base (Fase 0) | `php artisan test` | 24 fallos, 395 pasadas (2460) → **+25 nuevas en verde, 0 fallos nuevos** |
| Pruebas de la báscula | `php artisan test --filter SmartCheckout` | **25 passed** (225 aserciones) |
| Pruebas JS | `node --test tests/js` | **86 passed**, 0 fallos, 16 suites |
| Build | `node node_modules/vite/bin/vite.js build` | ✓ built, exit 0 |
| Code splitting | inspección de `public/build/assets` | `ScalePanel` 12.7 KB, `ScaleSettingsModal` 16 KB, `ScalePreferencesModal` 4.7 KB y `useScaleSession` 20 KB **en chunks propios** |
| Rutas | `php artisan route:list --path=smart-checkout` | 4 rutas con `auth` + `can:pos.access` |
| Base local | consulta directa | `smartpos_settings` existe con 0 filas (todo apagado), sin permisos `scale.*` |

Los 24 fallos son los 24 preexistentes de la Fase 0 (15 `AiAgentToolsTest`, 3 `PosHelpArticlesSeedTest`,
6 `*ControllerTest > it denies access without permissions`).

**Cobertura de la lógica exigida**: estabilidad (umbral en gramos y lecturas mínimas), tara (simulada y serial),
unidades (kg↔g y alias `Grs`/`kgs`, conversión a la unidad del producto), redondeo (`0.333 kg × 89.99 = 29.97`,
`0.1+0.2`), parseo de tramas reales (`ST,GS,+ 1.250 kg`, coma decimal, terminadores LF/CR/CRLF) y los tres drivers.

---

## 7. Riesgos, dudas y decisiones pendientes

1. **Sin permisos propios** (decisión del humano): cualquier usuario con acceso al POS puede encender, configurar y usar
   la báscula. El aislamiento entre negocios se apoya en el usuario autenticado, no en permisos.
2. **No hay verificación visual automatizada** en este entorno (no hay navegador headless en el proyecto).
   La UI está compilada y los contratos cubiertos por pruebas, pero la revisión visual final es manual.
3. **Consumidores del cambio de casts** (cantidades ahora con 3 decimales) que quedaron **fuera de alcance**:
   el ticket ESC/POS (`PrintEncoderService`, `str_pad` de ancho 5 desalinea desde 10 unidades), el export
   `SalesSheet` (`"2.000 x …"`) y `ProductController::show` (apartados). Se documentan para decidir si se normalizan.
4. **Fuera de alcance de la Fase 1, sin tocar**: `pos/layaway` y la API móvil siguen con `min:1`, así que no se puede
   apartar ni vender por peso desde la app móvil. El método `assertWeighableQuantities` es reutilizable cuando se pida.
5. **Web Serial** solo funciona en Chrome/Edge de escritorio sobre HTTPS o `localhost`; el panel lo avisa y ofrece la
   captura manual como respaldo. La tara del perfil serial es un **offset software**, no un comando al equipo.
6. **Estado del driver** recordado por terminal en `localStorage` (`smartpos_scale_{userId}_{branchId}`);
   perfiles y umbrales guardados por negocio en `smartpos_settings`.

---

## 8. Confirmación explícita

> **Con el Modo Caja Inteligente apagado y la báscula deshabilitada, el módulo Punto de venta se comporta igual que antes.**

Evidencia: sin fila en `smartpos_settings` la prop `scale.enabled` es `false`, no se monta ningún componente de la báscula
(sus chunks no se descargan), no hay consultas nuevas en el cobro (el mínimo `1` se valida en memoria con el mensaje
idéntico al anterior) y la suite de regresión mantiene los mismos 24 fallos preexistentes con 25 pruebas nuevas en verde.
