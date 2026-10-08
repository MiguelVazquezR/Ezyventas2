# Prompt — Renderizar los campos personalizados según su tipo (órdenes de servicio)

> Pega el bloque de **§Prompt** completo en tu agente del proyecto Flutter y adjúntale el archivo
> `docs/mobile-app/04-campos-personalizados-ordenes-servicio.md` (o dale acceso a la carpeta
> `docs/mobile-app/`).

## Prompt

Eres el agente de la app móvil **EzyVentas (Flutter / Android)**. El backend **ya está terminado** y
expone todo lo necesario: **no** modifiques el backend, no agregues endpoints ni campos «por
conveniencia», no inventes rutas.

**Problema a resolver:** hoy el formulario de órdenes de servicio y su vista de detalle muestran los
campos personalizados como **texto plano** (o peor: como JSON crudo, del estilo
`{"type":"pattern","value":[1,2,3]}`). Deben renderizarse **según el `type` de su definición**: caja
de texto, caja de texto largo, campo numérico, switch, desplegable, selección múltiple y tablero de
desbloqueo 3×3.

### 1. Lee SOLO esto (el resto de la documentación no aplica en esta tarea)

`C:/Users/windows 11/Desktop/Proyectos web/Ezyventas2/docs/mobile-app/04-campos-personalizados-ordenes-servicio.md` ← **el contrato a implementar**
- Léelo **completo** (§1 a §13). Es el documento rector de esta tarea.
- En particular: **§4** catálogo de tipos y contrato de valor, **§5** valores iniciales,
  **§6** envío en JSON vs multipart, **§7** normalización de lectura, **§8** tipo `pattern`,
  **§9** renderizadores (editor y lectura), **§11** limitaciones y **§12** checklist de aceptación.

`C:/Users/windows 11/Desktop/Proyectos web/Ezyventas2/docs/mobile-app/01-contrato-api-v1.md`
- **§1 Convenciones generales** (formato de respuestas y de errores) — solo si tu capa de red aún no existe.
- **§2 Autenticación** — solo si tu capa de red aún no existe.
- Las secciones de **Órdenes de servicio** (detalle, alta y edición): para confirmar permisos
  (`services.orders.access`, `services.orders.see_details`, `services.orders.create`,
  `services.orders.edit`) y la forma exacta del payload.
- El **catálogo de códigos de error** — para manejar `403` y `422`.
- ❌ No leas las secciones de caja, POS, impresión, dashboard, cuenta ni suscripción.

`C:/Users/windows 11/Desktop/Proyectos web/Ezyventas2/docs/mobile-app/02-modelo-de-datos.md`
- **§ `custom_field_definitions`** y **§ `service_orders`** (fila `custom_fields`).

`C:/Users/windows 11/Desktop/Proyectos web/Ezyventas2/docs/mobile-app/03-design-system-tesla-ui.md`
- **§8** mapeo PrimeVue → Flutter, **§10** formatos obligatorios, **§11** microcopy y
  **§13** contraste (regla obligatoria).

`C:/Users/windows 11/Desktop/Proyectos web/Ezyventas2/docs/mobile-app/00-contexto-app-movil.md`
- **§2 Reglas de oro** y **§4 Stack y estructura** (respeta la estructura y las convenciones que ya
  existan en el proyecto: dio, riverpod/provider, etc.).

### 2. Alcance (implementa solo esto)

1. **Formulario de alta** de orden de servicio: pintar un editor por cada tipo definido.
2. **Formulario de edición**: precargar el valor guardado en el editor correcto y reenviar la bolsa
   `custom_fields` **completa** (el `PUT` reemplaza el JSON completo).
3. **Vista de detalle** de la orden: pintar cada valor según su tipo, nunca como texto plano ni JSON crudo.
4. **Widget de desbloqueo 3×3** con modos *Patrón* y *Contraseña*, en modo captura y en modo lectura.

Fuera de alcance: gestionar definiciones (crear/editar/borrar), tocar la impresión térmica, rediseñar
las pantallas, o hacer refactors no relacionados con campos personalizados.

### 3. Entregables

- **Modelos** con `fromJson`/`toJson` explícitos:
  - `CustomFieldDefinition` (`id`, `key`, `name`, `type`, `options`, `isRequired`).
  - Un tipo sellado/unión para el valor capturado: `TextValue`, `NumberValue`, `BoolValue`,
    `SingleOptionValue`, `MultiOptionValue`, `PatternValue` (según §8.4).
  - `CustomFieldsBag` (mapa `key → valor`) con serialización **idéntica** a la del contrato (§4).
- **Normalizadores de lectura** (§7.1) aplicados en un solo lugar, antes de decidir el widget:
  `normalizeBool`, `normalizeList`, `normalizeNumber`, `normalizePattern`, `isBlank`.
- **Cliente API**: `GET /service-orders/custom-fields` (solo definiciones) y lectura de
  `custom_field_definitions` + `custom_fields` dentro del detalle.
- **Fábrica de widgets** (una sola, usada por alta y edición):
  `buildCustomFieldEditor(def, rawValue, onChanged)` tal como describe §9.1, con `default` → texto
  para tipos desconocidos.
- **Widget de desbloqueo** `PatternInput` reutilizable con `readOnly` (§8.2, §8.5): 3×3 numerado
  1..9 fila por fila, trazo en orden de gesto, cambio de modo, limpiar.
- **Renderizador de lectura** (§9.2): una fila por definición, con el rótulo y el formato de cada tipo.
- **UI del formulario**: opcional pero recomendado, agrupar los campos personalizados en una sección
  titulada **«Detalles adicionales»** con el subtítulo **«Información personalizada del servicio»**,
  y pintarla **solo** si hay al menos un valor.
- **Tests**: unit de normalización (todos los casos de §4.1, §4.2 y §8.3), unit de serialización
  (`true` real para booleanos, `[]` para checkbox vacío, objeto completo para `pattern`), y widget
  tests del editor de cada tipo en modo **alta**, **edición** y **lectura**.

### 4. Reglas no negociables

1. **Indexa siempre por `key`**, nunca por `name` (el `key` es inmutable; el `name` cambia).
2. El **tipo lo dicta la definición**, nunca el valor recibido. Un `"1"` en un campo `boolean` es
   `true`; en un campo `text` es el texto `"1"`.
3. **Nunca** pintes JSON crudo, `[object Object]`, arreglos entre corchetes, ni `true` / `1` en un
   campo booleano de lectura: usa `Sí` / `No`.
4. **Al escribir, usa el tipo real**: número → `num`, booleano → `true`/`false` (nunca el string
   `"false"`), `checkbox` → arreglo de strings, `pattern` → objeto `{"type":…,"value":…}` completo.
5. **`pattern` nunca es `null`**: al limpiar o cambiar de modo emite el objeto con `value` vacío (§8.5).
6. **`custom_fields` se envía completo** en alta y edición, no solo los campos modificados.
7. **Prefiere JSON sobre multipart** cuando no haya fotos: evita que los tipos se degraden a string (§6).
8. **`select`/`checkbox` sin `options`** → campo deshabilitado con el texto «Sin opciones configuradas»;
   no revientes el formulario.
9. Un valor guardado que **ya no está en `options`** se muestra como texto, no se descarta.
10. Un **`key` sin definición** se muestra al final como texto plano (rótulo = `key` con `_` → espacio),
    en sentence case.
11. Un **tipo desconocido** degrada a texto; nunca lanza excepción ni deja la pantalla en blanco.
12. Textos visibles en **español y sentence case** («Pin de desbloqueo», «Sin opciones configuradas»,
    «No establecido»); identificadores, clases y campos en **inglés**.

### 5. Criterios de aceptación

Se cumplen **todos** los puntos de **§12** del documento 04. Resumen verificable:

- Un campo de cada uno de los 7 tipos se ve con su widget correcto (switch, numérico, desplegable,
  chips, tablero, texto corto y texto largo). **Ninguno** aparece como texto genérico.
- En edición, los valores existentes se precargan en el widget correcto (`"1"` → switch encendido;
  `["Funda"]` → chip marcado; `{"type":"pattern",…}` → trazo dibujado).
- Guardar sin tocar los campos personalizados **no** los borra.
- En detalle: `boolean` → `Sí` / `No`; `checkbox` → **todas** las opciones (marcadas las elegidas,
  tachadas las demás); `pattern` → dibujado sin gestos; `password` → enmascarado; vacíos → `N/A`.
- La sección no se pinta cuando `custom_fields` está vacío.

### 6. Orden de trabajo sugerido

1. Modelos + normalizadores + sus tests (primero los casos de multipart con strings).
2. Fábrica de widgets del editor y precarga en edición.
3. `PatternInput` (captura y `readOnly`) con test de geometría: `1` arriba-izquierda, `9`
   abajo-derecha.
4. Renderizador de lectura del detalle.
5. Repaso de contraste y microcopy contra §03, y recorrido del checklist §12.

### 7. Prohibido

- Modificar el backend, agregar endpoints, campos o validaciones nuevas.
- Guardar `custom_fields` como **string** (`"{\"a\":1}"`): debe ser una estructura real.
- Guardar `"false"` / `"null"` / `"undefined"` como texto.
- Re-derivar el mapa de campos al vuelo al enviar: serializa la bolsa que ya tienes en estado.
- Implementar gestión (CRUD) de definiciones desde la app: en esta versión la app **solo lee**.
- Bloquear el guardado porque un campo `is_required` esté vacío (el backend no lo valida).

### 8. Qué entregar al terminar

Un resumen con: archivos creados/modificados, cómo correr los tests, y cualquier discrepancia
encontrada contra el documento 04 (con el campo, el valor real que devolvió el servidor y la captura
o log que lo respalde).
