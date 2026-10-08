# 04 — Campos personalizados en órdenes de servicio (tipos, payloads y renderizado)

> **Para quién es este documento:** el agente que desarrolla la app móvil (Flutter/Android).
> **Complementa:** `01-contrato-api-v1.md` § Órdenes de servicio y `02-modelo-de-datos.md`
> § `custom_field_definitions`.
> **Objetivo:** que la app pinte y muestre cada campo personalizado **según su tipo**
> (`boolean`, `number`, `select`, `checkbox`, `pattern`…) y no como texto plano.

---

## 1. Resumen ejecutivo (leer esto primero)

1. Un campo personalizado se compone de **dos piezas**: la **definición** (el "molde": `key`, `name`,
   `type`, `options`, `is_required`) y el **valor** (lo que el técnico capturó, guardado dentro del
   JSON `service_orders.custom_fields`).
2. El backend **entrega el molde** por dos vías: `GET /service-orders/custom-fields` (antes de crear
   la orden) y la llave `custom_field_definitions` dentro de `GET /service-orders/{id}`.
3. Hay **7 tipos** (validados en el backend): `text`, `number`, `textarea`, `boolean`, `select`,
   `checkbox`, `pattern`.
4. El backend **no valida ni transforma los valores**. Guarda literalmente lo que la app (o la web)
   envíe dentro de `custom_fields`. La única regla es `custom_fields` → `nullable|array`.
   → **Toda la semántica de cada tipo vive en el cliente.** Si la app guarda todo como string y lo
   pinta como string, es exactamente lo que se verá: texto plano.
5. El único tipo con valor **estructurado** es `pattern` (desbloqueo celular): su valor es un
   objeto `{ "type": "pattern" | "password", "value": … }`. Los demás son escalares o
   arreglos de strings.
6. Con **multipart** (cuando se suben fotos) los valores llegan al servidor como **strings**
   (`"1"`, `"12"`). Con **JSON** llegan con su tipo real (`true`, `12`). La app debe tolerar ambos
   al **leer** (ver §7) y usar el tipo correcto al **escribir** (§6).

---

## 2. Modelo de datos

### 2.1 `custom_field_definitions` — el molde

| Columna | Tipo | Notas |
|---|---|---|
| `id` | bigint | |
| `subscription_id` | bigint | filtro obligatorio: las definiciones son **por suscripción** |
| `module` | string | para órdenes de servicio siempre `service_orders` |
| `name` | string | etiqueta visible al usuario (ej. `PIN de desbloqueo`) |
| `key` | string | clave dentro del JSON `custom_fields` (ej. `pin_de_desbloqueo`) |
| `type` | string | uno de los 7 tipos de §4 |
| `options` | json nullable | arreglo de strings; **solo** tiene sentido en `select` y `checkbox` |
| `is_required` | boolean | informativo (ver §11) |

- Único por (`subscription_id`, `module`, `key`).
- El `key` se genera **una sola vez** al crear la definición a partir del `name`
  (`Str::snake`); en las ediciones posteriores **solo cambia `name`**, el `key` es inmutable.
  → La app **siempre** debe indexar por `key`, **nunca** por `name`.
- ⚠️ Trampa: el comentario de la columna `type` en la migración dice
  `"Input type: text, number, boolean, textarea"`. Es un **comentario desactualizado**; el catálogo
  real es de 7 tipos (el que valida `CustomFieldDefinitionController`).

### 2.2 `service_orders.custom_fields` — el valor

- Columna `json nullable`, casteada en el modelo (`'custom_fields' => 'array'`).
- Estructura: **bolsa abierta** `{ "<key>": <valor> }`, sin esquema rígido.
- El servidor **no** valida: no verifica que los `key` existan en las definiciones, ni que el valor
  corresponda al `type`, ni que `is_required` esté lleno.
- Consecuencias prácticas para la app:
  - Puede haber `key` **huérfanos** (definiciones borradas o renombradas) → hay que mostrarlos igual (§9.3).
  - Un valor puede venir `null`, ausente, como string `"1"` o como `true`, según quién lo escribió.
  - **Nunca** asumir el tipo del valor a partir del valor mismo: el tipo lo dicta la **definición**.

---

## 3. Endpoints que exponen campos personalizados

| Método y ruta | Permiso | Devuelve |
|---|---|---|
| `GET /api/v1/service-orders/custom-fields` | `services.orders.access` | Solo las definiciones (`{ "data": [ … ] }`) |
| `GET /api/v1/service-orders/{id}` | `services.orders.see_details` | `custom_fields` (valores) + `custom_field_definitions` (mismo arreglo que el endpoint anterior) |
| `POST /api/v1/service-orders` | `services.orders.create` | Acepta `custom_fields` (objeto, nullable) |
| `PUT /api/v1/service-orders/{id}` | `services.orders.edit` | Acepta `custom_fields` (objeto, nullable) → **reemplaza la bolsa completa** |

Orden de las definiciones: **alfabético por `name`**, calculado en el backend
(`orderBy('name')`). La app debe respetar ese orden tal cual llega (la web usa el mismo arreglo).

`custom_field_definitions` (mismo objeto en las dos vías; el ejemplo está ordenado por `name`,
como lo devuelve la API):

```json
[
  { "key": "accesorios",     "name": "Accesorios recibidos", "type": "checkbox", "options": ["Funda", "Mica", "Cargador", "Memoria SD"], "is_required": false },
  { "key": "imei",           "name": "IMEI",                 "type": "number",   "options": null,                                        "is_required": true  },
  { "key": "notas_equipo",   "name": "Notas del equipo",     "type": "textarea", "options": null,                                        "is_required": false },
  { "key": "patron",         "name": "Patrón de pantalla",   "type": "pattern",  "options": null,                                        "is_required": false },
  { "key": "pin_desbloqueo", "name": "PIN de desbloqueo",    "type": "text",     "options": null,                                        "is_required": false },
  { "key": "tipo_equipo",    "name": "Tipo de equipo",       "type": "select",   "options": ["Celular", "Tablet", "Laptop"],             "is_required": true  },
  { "key": "con_cargador",   "name": "¿Incluye cargador?",   "type": "boolean",  "options": null,                                        "is_required": false }
]
```

---

## 4. Catálogo de tipos y contrato de valor

Etiquetas y controles tal como se administran y capturan **hoy en la web**
(`ManageCustomFields.vue` y `AdditionalDetails.vue`). La app debe replicar el mismo
comportamiento con su widget equivalente (§9).

| `type` | Etiqueta en la web | Control (web → Flutter sugerido) | Valor al **escribir** | Valor típico al **leer** |
|---|---|---|---|---|
| `text` | Texto corto | `InputText` → `TextFormField` | `"iPhone 13"` (string) | `"iPhone 13"` |
| `number` | Número | `InputNumber` → `TextFormField` numérico | `12` (número) | `12` o `"12"` |
| `textarea` | Texto largo | `Textarea` → `TextFormField` (maxLines > 1) | `"Golpe en esquina"` | string |
| `boolean` | Sí/No (switch) | `ToggleSwitch` → `SwitchListTile` | `true` / `false` | `true`, `"1"`, `1`, `"true"` |
| `select` | Selección única | `Select` → `DropdownButtonFormField` / bottom sheet | `"Celular"` (uno de `options`) | string (puede ya no estar en `options`) |
| `checkbox` | Selección múltiple | `MultiSelect` → chips + bottom sheet | `["Funda", "Mica"]` (⊂ `options`) | arreglo de strings (puede ser `[]`) |
| `pattern` | Desbloqueo celular | componente `PatternLock` → widget propio 3×3 | `{"type":"pattern","value":[1,4,5,8]}` o `{"type":"password","value":"1234"}` | objeto **o** arreglo vacío `[]` (ver §8) |

Reglas del contrato de valor:

- `select`: **un solo** string de `options`. Si el valor guardado ya no está en `options`
  (cambiaron las opciones), la web lo pinta como texto crudo: la app debe hacer lo mismo y **no**
  descartarlo.
- `checkbox`: arreglo de strings dentro de `options`; `[]` es válido y significa "ninguno".
- `number`: la web usa `InputNumber`, que entrega número. Con multipart puede llegar a guardarse
  como string numérica (`"12"`) → al leer, parsear.
- `boolean`: **nunca** enviar `"false"` como string (PHP/multipart lo guardaría tal cual y al leer
  `"false"` no es `false`). Enviar `false` real (JSON) o `0` (multipart), y al leer normalizar (§7.1).

### 4.1 Ejemplo completo (lectura de una orden real)

```json
{
  "custom_fields": {
    "accesorios": ["Funda", "Mica"],
    "con_cargador": true,
    "imei": 356938035643809,
    "notas_equipo": "Golpe en la esquina inferior derecha, marco con rayones.",
    "patron": { "type": "pattern", "value": [1, 2, 3, 5, 7, 8, 9] },
    "pin_desbloqueo": "1234",
    "tipo_equipo": "Celular"
  },
  "custom_field_definitions": [ "…ver §3…" ]
}
```

### 4.2 Ejemplo con multipart (mismo contenido, tipos degradados a string)

Si la orden se creó con fotos (petición `multipart/form-data`), el servidor guarda los valores
escalares tal como los entregó PHP:

```json
{
  "custom_fields": {
    "accesorios": ["Funda", "Mica"],
    "con_cargador": "1",
    "imei": "356938035643809",
    "patron": { "type": "pattern", "value": ["1", "2", "3", "5", "7", "8", "9"] },
    "tipo_equipo": "Celular"
  }
}
```

> Los dos ejemplos son **la misma orden** conceptualmente. La app no puede diferenciarlos:
> debe aplicar las mismas normalizaciones de lectura en ambos casos.

---

## 5. Valores iniciales (al abrir el formulario de alta)

Antes de pintar el formulario, la app debe construir el mapa completo con **una entrada por
definición** y su valor inicial. La web lo hace así (`AdditionalDetails.vue` → `initializeCustomFields`):

| `type` | Valor inicial | Razón |
|---|---|---|
| `text` | `null` | vacío (la caja de texto se ve vacía igual) |
| `number` | `null` | no inventar 0 |
| `textarea` | `null` | vacío |
| `boolean` | `false` | el switch debe verse apagado, no indefinido |
| `select` | `null` | forzar selección explícita |
| `checkbox` | `[]` | "ninguno seleccionado" |
| `pattern` | `[]` (sí, arreglo vacío) | la web inicializa con `[]` y solo se convierte en objeto cuando el usuario dibuja/escribe (ver §8.4) |

> Valores iniciales textuales de la web:
> `field.type === 'checkbox' ? [] : (field.type === 'boolean' ? false : (field.type === 'pattern' ? [] : null))`

Reglas:

1. Si la definición es nueva (no hay valor previo en la orden), usar el inicial de la tabla.
2. Si **ya hay** valor guardado en `custom_fields[key]`, ese manda (normalizado, ver §7).
3. En **edición** (`PUT`), mandar la bolsa completa: `custom_fields` reemplaza por completo el JSON
   anterior, así que hay que reenviar todos los campos, no solo los modificados.

---

## 6. Envío del payload: JSON vs multipart

| Escenario | Content-Type | Cómo van los valores |
|---|---|---|
| Alta/edición **sin** fotos | `application/json` | Tipos reales (`true`, `12`, arreglos y objetos anidados tal cual) — **recomendado** |
| Alta/edición **con** fotos (`initial_evidence_images[]`) | `multipart/form-data` | PHP convierte todo a string / arreglos de string; los objetos anidados se aplanan con notación de corchetes |

### 6.1 JSON (recomendado)

```bash
curl -X POST "$API/service-orders" \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{
        "customer_id": 8,
        "customer_name": "Ana Ramírez",
        "assign_technician": false,
        "item_description": "iPhone 13, pantalla rota",
        "reported_problems": "No enciende después de una caída",
        "custom_fields": {
          "con_cargador": true,
          "imei": 356938035643809,
          "tipo_equipo": "Celular",
          "accesorios": ["Funda", "Mica"],
          "patron": { "type": "pattern", "value": [1, 2, 3, 5, 7, 8, 9] }
        },
        "items": [], "subtotal": 0, "discount_type": "fixed",
        "discount_value": 0, "discount_amount": 0, "final_total": 0,
        "cash_register_session_id": 41, "client_uuid": "'$UUID'"
      }'
```

### 6.2 Multipart (cuando hay evidencia fotográfica)

Los objetos anidados y los arreglos se envían con notación de corchetes; en edición conviene
mandar **todos** los campos para no perder los que no se tocaron:

```bash
curl -X POST "$API/service-orders" \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
  -F "customer_id=8" \
  -F "customer_name=Ana Ramírez" \
  -F "assign_technician=0" \
  -F "item_description=iPhone 13, pantalla rota" \
  -F "reported_problems=No enciende después de una caída" \
  -F "custom_fields[con_cargador]=1" \
  -F "custom_fields[imei]=356938035643809" \
  -F "custom_fields[tipo_equipo]=Celular" \
  -F "custom_fields[accesorios][]=Funda" \
  -F "custom_fields[accesorios][]=Mica" \
  -F "custom_fields[patron][type]=pattern" \
  -F "custom_fields[patron][value][]=1" \
  -F "custom_fields[patron][value][]=2" \
  -F "custom_fields[patron][value][]=3" \
  -F "subtotal=0" -F "discount_type=fixed" -F "discount_value=0" \
  -F "discount_amount=0" -F "final_total=0" \
  -F "cash_register_session_id=41" -F "client_uuid=$UUID" \
  -F "initial_evidence_images[]=@/ruta/foto1.jpg"
```

Notas importantes del modo multipart:

- Los **booleanos** viajan como `1` / `0` y quedan guardados como los strings `"1"` / `"0"`
  (ver §4.2 y §7.1). Es inevitable; la app lo compensa al leer.
- Un **`checkbox` sin selección** no se expresa en multipart: o se omite la clave
  (y la app asume `[]` al leer) o se manda el arreglo vacío de forma explícita.
- Un **`pattern` borrado** (sin dibujo y sin contraseña) debe enviarse igual con `value` vacío,
  para que el servidor no conserve el anterior cuando se edita.
- El backend **no** valida tipos: si la app manda `custom_fields` ya serializado como string
  (`"{\"a\":1}"`), el servidor lo guarda como string y la web lo muestra como texto plano.
  Enviar **siempre** una estructura real, nunca un JSON convertido a cadena.

---

## 7. Lectura y normalización (obligatorio antes de pintar nada)

La app **no** puede confiar en el tipo del valor JSON: según cómo se guardó (web Inertia, API JSON,
API multipart o datos históricos) un mismo campo puede llegar en varias formas. Normalizar **siempre**
antes de decidir el widget y antes de formatear el texto de lectura.

### 7.1 Normalizadores (pseudocódigo Dart)

```dart
/// Valor "vacío" para cualquier tipo: null, '' o un arreglo/objeto sin contenido.
bool isBlank(dynamic v) {
  if (v == null) return true;
  if (v is String) return v.trim().isEmpty;
  if (v is List) return v.isEmpty;
  if (v is Map) return v.isEmpty;
  return false;
}

/// boolean -> acepta true/false y las representaciones string de multipart.
bool normalizeBool(dynamic v) {
  if (v is bool) return v;
  if (v is num) return v != 0;
  final s = v?.toString().toLowerCase().trim();
  return s == '1' || s == 'true' || s == 'si' || s == 'sí';
}

/// checkbox -> siempre lista de strings, sin repetidos y sin vacíos.
List<String> normalizeList(dynamic v) {
  if (v == null) return const [];
  if (v is List) {
    return v.map((e) => e.toString().trim()).where((e) => e.isNotEmpty).toSet().toList();
  }
  if (v is String) {
    // Datos históricos capturados como texto: "Funda, Mica"
    return v.split(',').map((e) => e.trim()).where((e) => e.isNotEmpty).toList();
  }
  return [v.toString()];
}

/// number -> num? (acepta "12", 12 y "" -> null)
num? normalizeNumber(dynamic v) {
  if (v == null) return null;
  if (v is num) return v;
  return num.tryParse(v.toString().replaceAll(',', '').trim());
}
```

### 7.2 Regla de oro al leer

```
para cada definición D del arreglo custom_field_definitions (en el orden recibido):
    valorCrudo = custom_fields[D.key]        // puede no existir -> null
    valor      = normalizarSegun(D.type, valorCrudo)
    pintar fila de lectura con (D.name, valor, D.type)
al terminar, si quedan claves en custom_fields sin definición:
    pintarlas al final como texto plano (label = key con "_" -> espacio, sentence case)
```

- Los `key` huérfanos **no se ocultan**: la web los muestra igual (fallback de etiqueta).
- Si `custom_fields` viene vacío (`{}` o `null`), la sección completa **no se pinta**
  (la web y la impresión la omiten cuando no hay ningún valor).

---

## 8. Tipo `pattern` (desbloqueo celular) en detalle

Es el único tipo con valor compuesto y el que más errores causa si se trata como texto.

### 8.1 Estructura del valor

```json
{ "type": "pattern",  "value": [1, 4, 5, 8] }   // patrón dibujado (modo "Patrón")
{ "type": "password", "value": "1234" }         // contraseña escrita (modo "Contraseña")
```

- `type` indica el **modo** elegido por el usuario, no el tipo del campo.
- En modo `pattern`, `value` es el **arreglo ordenado de puntos** que tocó el dedo
  (1..9, en el orden del trazo, no ordenados de menor a mayor).
- En modo `password`, `value` es el texto tal cual.
- El valor se guarda **en claro** en la base de datos (el taller necesita el desbloqueo para
  probar el equipo). No es un secreto cifrado: la app **no** debe loggearlo ni mostrarlo sin
  enmascarar en listados.

### 8.2 Numeración del tablero 3×3

Coincide con el componente web (`PatternLock.vue`: `x = (i % 3)`, `y = floor(i / 3)`, id = `i + 1`),
es decir **fila por fila, de izquierda a derecha y de arriba hacia abajo**:

```
 1 ── 2 ── 3
 │  ╲ │ ╱ │
 4 ── 5 ── 6
 │  ╱ │ ╲ │
 7 ── 8 ── 9
```

Un patrón en "L" (arriba-izquierda → abajo-izquierda → abajo-derecha) es `[1, 4, 7, 8, 9]`.

### 8.3 Estados posibles del valor (la app debe tolerar los cuatro)

| Valor guardado | Significado | Qué pintar |
|---|---|---|
| `{"type":"pattern","value":[1,2,3]}` | Patrón dibujado | Tablero con el trazo dibujado (solo lectura) |
| `{"type":"password","value":"1234"}` | Contraseña escrita | Texto enmascarado con `•` |
| `[]`, `{}`, `null` o ausente | Nunca se capturó nada | `N/A` |
| `{"type":"pattern","value":[]}` | Se capturó y se borró | `N/A` |

### 8.4 Normalizador específico

```dart
class PatternValue {
  final String mode;              // 'pattern' | 'password'
  final List<int> points;         // solo en modo 'pattern'
  final String password;          // solo en modo 'password'
  bool get isEmpty => mode == 'pattern' ? points.isEmpty : password.isEmpty;
}

PatternValue normalizePattern(dynamic v) {
  if (v is Map && v['type'] == 'password') {
    return PatternValue(mode: 'password', password: (v['value'] ?? '').toString());
  }
  if (v is Map) {
    // Mapa con type == 'pattern' (o sin type, por historial)
    final raw = v['value'];
    final points = (raw is List ? raw : const [])
        .map((e) => int.tryParse(e.toString()) ?? 0)
        .where((e) => e >= 1 && e <= 9)
        .toList();
    return PatternValue(mode: 'pattern', points: points);
  }
  // [] -> el formulario web inicializa el campo como arreglo vacío
  if (v is List) {
    final points = v.map((e) => int.tryParse(e.toString()) ?? 0)
        .where((e) => e >= 1 && e <= 9).toList();
    return PatternValue(mode: 'pattern', points: points);
  }
  // String suelto -> trátalo como contraseña
  if (v is String && v.trim().isNotEmpty) {
    return PatternValue(mode: 'password', password: v.trim());
  }
  return PatternValue(mode: 'pattern', points: const []);
}
```

### 8.5 Reglas del editor `pattern` en la app

1. Dos modos conmutables: **Patrón** y **Contraseña** (mismos rótulos que la web; la web usa un
   `SelectButton` de dos opciones y captura en un diálogo "Establecer Patrón" / "Establecer Contraseña").
2. Al dibujar, emitir `{"type":"pattern","value":[...]}` con los puntos en orden de trazo.
3. Al escribir, emitir `{"type":"password","value":"..."}` (texto plano, sin hashear).
4. Al limpiar, emitir **siempre un objeto** con el modo activo y valor vacío:
   `{"type":"pattern","value":[]}` o `{"type":"password","value":""}` — nunca `null`.
5. Cambiar de modo vuelve a emitir el objeto con el valor vacío del nuevo modo
   (dibujar un patrón y luego cambiar a contraseña descarta el dibujo, igual que en la web).
6. En modo lectura el tablero se pinta **sin gestos** (solo el trazo) y escalado; la web reutiliza
   el mismo componente tanto en la vista de la orden como en el ticket impreso. Si no hay valor,
   la web muestra **"No establecido"**; si lo hay, un botón **"Ver patrón"** / **"Ver contraseña"**.

---

## 9. Un solo renderizador por `type` (editor y lectura)

### 9.1 Editor (formulario de alta/edición)

```dart
Widget buildEditor(CustomFieldDefinition def, dynamic rawValue, ValueChanged<dynamic> onChanged) {
  final label = def.name + (def.isRequired ? ' *' : '');

  switch (def.type) {
    case 'text':
      return TextField(label: label, value: (rawValue ?? '').toString(),
                       onChanged: onChanged);                       // string
    case 'textarea':
      return TextField(label: label, value: (rawValue ?? '').toString(),
                       maxLines: 4, onChanged: onChanged);          // string
    case 'number':
      return NumberField(label: label, value: normalizeNumber(rawValue),
                         onChanged: (v) => onChanged(v));           // num, no string
    case 'boolean':
      return SwitchField(label: label, value: normalizeBool(rawValue),
                         onChanged: (v) => onChanged(v));           // bool real
    case 'select':
      return DropdownField(label: label, options: def.options ?? [],
                           value: rawValue?.toString(),
                           onChanged: (v) => onChanged(v));         // string
    case 'checkbox':
      return MultiChipsField(label: label, options: def.options ?? [],
                             value: normalizeList(rawValue),
                             onChanged: (list) => onChanged(list)); // List<String>
    case 'pattern':
      return PatternField(label: label, value: normalizePattern(rawValue),
                          // el editor emite SIEMPRE un Map, nunca solo el arreglo
                          onChanged: (pv) => onChanged(
                            pv.mode == 'password'
                              ? {'type': 'password', 'value': pv.password}
                              : {'type': 'pattern', 'value': pv.points}));
    default:
      // Tipo desconocido (el backend agregó uno nuevo): degradar a texto, NUNCA romper el formulario.
      return TextField(label: label, value: (rawValue ?? '').toString(), onChanged: onChanged);
  }
}
```

### 9.2 Vista de lectura de una orden

Comportamiento **exacto** de la web hoy (`Show.vue`), que es el que la app debe replicar:

| `type` | Cómo se muestra | Referencia en la web |
|---|---|---|
| `pattern` | Tablero dibujado, solo lectura, dentro de un recuadro | `<PatternLock :edit="false">` |
| `select` | El valor como texto; `N/A` si está vacío | `value \|\| 'N/A'` |
| `checkbox` | **Todas** las `options` listadas: las seleccionadas con palomita verde y texto en negritas; las no seleccionadas en gris y tachadas | lista de `options` con `pi-check-circle` / `pi-times-circle` |
| `text`, `number`, `textarea`, `boolean` y **cualquier tipo sin caso especial** | `Sí` si el valor es `true`, `No` si es `false`, el valor tal cual si es distinto de vacío, `N/A` si está vacío | `value === true ? 'Sí' : value === false ? 'No' : value \|\| 'N/A'` |

> **Nunca** mostrar el JSON crudo (`{"type":"pattern","value":[1,2,3]}`), ni `[object Object]`,
> ni arreglos con corchetes. Ese es exactamente el síntoma que este documento busca eliminar.

### 9.3 Rótulo de la sección y de cada fila

- La tarjeta se titula **"Detalles adicionales"** con el subtítulo
  **"Información personalizada del servicio"**, y **solo se pinta** si
  `custom_fields` tiene al menos una clave.
- El rótulo de cada fila:
  - **Recomendado en la app:** usar `name` de la definición (es la etiqueta que el taller
    configuró, ej. *PIN de desbloqueo*).
  - Fallback (valores huérfanos, sin definición): el `key` con los guiones bajos convertidos en
    espacios (ej. `pin_de_desbloqueo` → *pin de desbloqueo*).
  - ℹ️ Hoy la web usa **siempre** el `key` transformado en mayúsculas por CSS y **no** el `name`;
    es una diferencia conocida y sin impacto funcional. Lo importante es que la app **nunca**
    rompa si `name` viene ausente.

---

## 10. Impresión (ticket térmico y WhatsApp)

Las plantillas de impresión usan la variable `{{os.custom.<key>}}` (una por cada campo
personalizado; la web las lista en el editor de plantillas como *"Campos personalizados"*).
El **servidor** ya resuelve esas variables al generar el ticket
(`PrintEncoderService::getServiceOrderReplacements`) con este formato:

| Valor guardado | Lo que imprime el servidor |
|---|---|
| `null` | cadena vacía |
| `true` / `false` | `Si` / `No` |
| `"1"` / `"0"` (booleano guardado vía multipart) | `1` / `0` — **no** `Si`/`No`: el servidor solo traduce booleanos reales |
| `{"type":"pattern","value":[1,2,3]}` | `1, 2, 3` (los puntos separados por coma) |
| `{"type":"password","value":"1234"}` | `1234` |
| `["Funda","Mica"]` | `Funda, Mica` |
| `[]` o `{"type":"pattern","value":[]}` | cadena vacía |
| string / número | tal cual |

Consecuencias para la app:

- Si la app imprime usando el **payload del servidor** (`POST /print/bluetooth-payload`), no tiene
  que formatear nada: los valores ya vienen resueltos.
- Si la app renderiza plantillas **localmente** (offline), debe replicar exactamente la tabla de
  arriba para que el ticket coincida con el de la web.
- El trazo del patrón **no** se imprime como dibujo en el ticket térmico del servidor: sale como
  lista de números.
- ⚠️ La traducción `Si`/`No` solo aplica a booleanos **reales** (`true`/`false` en JSON). Un booleano
  guardado como string por una petición multipart se imprime como `1` / `0`. Para que un ticket
  salga bien, conviene **enviar la orden por JSON**.

### 10.1 Las dos rutas de impresión de la web (y cuál debe imitar la app)

| Ruta | Quién formatea | Reglas |
|---|---|---|
| **Servidor** (ticket térmico ESC/POS, `PrintEncoderService`) | PHP, resolviendo `{{os.custom.<key>}}` | Tabla de arriba: `true` → `Si` (sin acento), listas con `, `, patrón como números |
| **Navegador** (`Print.vue`, "Imprimir / Guardar PDF") | Vue, con `getFormattedCustomValue()` | `boolean` → `Sí` / `No` (**con acento**), `checkbox` → `valor.join(', ')`, `pattern` → tablero **dibujado** con `PatternLock` en modo lectura, `null`/ausente → `N/A`; **solo** pinta claves que tengan definición |

Diferencias que la app debe decidir conscientemente (no mezclar):

- `Si` (servidor, sin acento) vs `Sí` (navegador, con acento) para booleanos.
- El navegador **dibuja** el patrón y el servidor lo imprime como lista de puntos.
- El navegador **omite** las claves sin definición; el servidor las imprime igual porque recorre
  `custom_fields` completo.

---

## 11. Estado real y limitaciones (lo que la app NO debe asumir)

| Tema | Estado real hoy | Qué hace la app |
|---|---|---|
| `is_required` | Se guarda y se expone, pero **nadie lo valida**: el `FormRequest` solo exige `custom_fields` como arreglo y la web no bloquea el guardado | Marcar con `*` y validar localmente como sugerencia, **sin** impedir guardar (si no, la app sería más estricta que la web y bloquearía capturas legítimas) |
| `options` en `select`/`checkbox` | Puede venir `null` o vacío si el taller no las configuró | Mostrar el campo deshabilitado con texto **"Sin opciones configuradas"** (no reventar el formulario) |
| Longitud de `text`/`textarea` | No hay límite validado en el servidor | Aplicar un límite razonable en la app (255 / 1000) o ninguno; nunca truncar en silencio |
| Valores con `key` sin definición | Ocurre al borrar/renombrar definiciones | Pintarlos como texto plano al final de la lista |
| Valor que no corresponde a `options` | Ocurre al editar las opciones después | Mostrarlo como texto (igual que la web), no descartarlo |
| Tipo desconocido (backend nuevo) | El catálogo puede crecer | Degradar a texto; **nunca** lanzar excepción ni dejar la pantalla en blanco |
| Escritura de definiciones | La API **no** expone crear/editar/borrar definiciones: se administran en la web (`CustomFieldDefinitionController`) | La app solo lee definiciones; no ofrecer gestión de campos personalizados en v1 |
| Nombre de la sección | Fijo | Usar "Detalles adicionales" |
| Idempotencia offline | La cola local debe reenviar el mismo `custom_fields` completo | Serializar la bolsa antes de encolar (no derivarla del formulario al vuelo) |

---

## 12. Checklist de aceptación (QA de la app)

Formulario de **alta**:

- [ ] Con el taller configurando un campo de **cada uno de los 7 tipos**, el formulario muestra:
      caja de texto, caja de texto largo, campo numérico, switch, desplegable, chips múltiples y el
      tablero de desbloqueo. Ninguno aparece como texto genérico.
- [ ] Un `select`/`checkbox` pinta exactamente las `options` que devuelve la API, en ese orden.
- [ ] El campo `boolean` inicia apagado y se envía como `false` real (JSON) o `0` (multipart).
- [ ] Un `checkbox` sin nada seleccionado se envía como `[]` (o se omite la clave), nunca como `null`.
- [ ] El campo `pattern` permite alternar entre **Patrón** y **Contraseña** y guarda
      `{"type":…,"value":…}` (el objeto completo, no solo el arreglo).
- [ ] Los campos vacíos **no** se envían como el string `"null"` ni `"undefined"`.

Formulario de **edición**:

- [ ] Los valores existentes se precargan en el widget correcto (el switch aparece encendido si
      `true` o `"1"`; los chips aparecen marcados; el patrón aparece dibujado).
- [ ] Guardar sin tocar los campos personalizados **no** los borra del JSON.

Vista de la orden:

- [ ] `boolean` se ve como `Sí` / `No` (nunca `true` / `1`).
- [ ] `checkbox` lista **todas** las opciones, marcando las seleccionadas y tachando las que no.
- [ ] `pattern` se ve **dibujado** (sin gestos); `password` se ve enmascarado.
- [ ] Cualquier valor vacío muestra `N/A`.
- [ ] Un `key` huérfano (sin definición) se muestra como texto plano al final, sin romper la pantalla.
- [ ] Un tipo desconocido no rompe la pantalla (cae a texto).

Tickets e impresión:

- [ ] El ticket que genera el servidor imprime `Si` / `No` y listas separadas por coma.
- [ ] Si la app imprime offline, el resultado coincide carácter por carácter con el del servidor
      (incluido el caso de un booleano guardado como string, que imprime `1` / `0` y no `Si` / `No`).

---

## 13. Fuente de verdad en el código (para consultas puntuales)

Backend:

| Archivo | Qué aporta |
|---|---|
| `app/Models/CustomFieldDefinition.php` | Modelo de la definición (único archivo con `options` casteado a arreglo) |
| `app/Http/Controllers/CustomFieldDefinitionController.php` | Catálogo real de los 7 tipos y validación de `name`/`key`/`options`/`is_required` |
| `app/Http/Controllers/Api/V1/ServiceOrders/ServiceOrderController.php` | Endpoint `customFields()` (definiciones para la app) |
| `app/Services/ServiceOrders/ServiceOrderReadService.php` | `customFieldDefinitions()`: campos, orden alfabético y forma del objeto |
| `app/Http/Requests/StoreServiceOrderRequest.php` y `app/Http/Requests/Api/V1/ServiceOrders/StoreServiceOrderRequest.php` | Regla `custom_fields` → `nullable|array` |
| `app/Actions/ServiceOrders/CreateServiceOrderAction.php` y `UpdateServiceOrderAction.php` | Persistencia (la bolsa se guarda tal cual llega) |
| `app/Services/PrintEncoderService.php` → `getServiceOrderReplacements()` | Formato de impresión de `{{os.custom.<key>}}` |
| `routes/api/v1/service-orders.php` | Rutas y orden de registro (antes de `{serviceOrderId}`) |
| `tests/Feature/Api/V1/ServiceOrderWriteApiTest.php` | Ejemplo real de payload con `custom_fields` |
| `tests/Feature/Api/V1/ServiceOrderApiTest.php` | Aserciones del detalle (`custom_fields` y `custom_field_definitions`) |

Frontend web (comportamiento de referencia):

| Archivo | Qué aporta |
|---|---|
| `resources/js/Components/ManageCustomFields.vue` | Etiquetas y catálogo de los 7 tipos (`fieldTypes`) |
| `resources/js/Pages/ServiceOrder/Partials/Form/AdditionalDetails.vue` | Inicialización por tipo y editor de cada tipo |
| `resources/js/Components/PatternLock.vue` | Numeración 3×3, modos Patrón/Contraseña, modo lectura |
| `resources/js/Pages/ServiceOrder/Show.vue` | Render de lectura por tipo ("Detalles adicionales") |
| `resources/js/Pages/ServiceOrder/Print.vue` | Render del patrón en el ticket del navegador |
| `resources/js/Composables/useTemplateVariables.js` | Variables de plantilla `{{os.custom.<key>}}` |
