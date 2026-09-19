# 03 — Design system "Tesla UI" aplicado a Flutter

> Fuente de verdad en el proyecto web: `tailwind.config.js`, `resources/js/presets/ezyventas.js`
> (preset basado en Aura de PrimeVue) y `resources/js/Pages/POS/IndexMobile.vue`.
> La app móvil **debe verse como la versión móvil del POS web**.

---

## 1. Principios visuales

1. **Superficies matte, sin sombras.** `elevation: 0` en todos los widgets. La jerarquía se
   resuelve con color de superficie + **borde de 1 px** (`border-gray-100` en claro, `#3a3a3a` en oscuro).
2. **Radios grandes:** 24 px (`rounded-3xl`) para contenedores, dialogs y popovers; 16 px
   (`rounded-2xl`) para cards internas e inputs; 12 px (`rounded-xl`) o `pill` para botones y badges.
3. **Un solo acento:** naranja `#F68C0F`. Nada más compite por la atención.
4. **Tipografía en dos registros:** micro-etiquetas técnicas en MAYÚSCULAS pequeñas
   (10 px, `letterSpacing` alto, bold) y montos grandes y delgados (`font-light`, `tracking-tight`).
5. **Headers compactos:** los títulos no llevan margen nativo (`m-0`, `mb-0`).
6. **Glow sutil en lugar de sombra:** indicadores activos usan un halo suave
   (`boxShadow` con el color del estatus a 40–60 % de opacidad) o un punto `animate-pulse`.
7. **Inputs:** nunca el estilo Material con línea inferior. Usar borde completo, radio 16,
   relleno de fondo y `label` externa en micro-mayúsculas (no `floatingLabel`).

---

## 2. Paleta de marca (hex exactos)

### Primario (naranja EzyVentas)

| Token | Hex |
|---|---|
| primary-50 | `#FEF4E7` |
| primary-100 | `#FDE6C8` |
| primary-200 | `#FBD3A0` |
| primary-300 | `#F9B96F` |
| primary-400 | `#F79F43` |
| **primary-500** | **`#F68C0F`** |
| primary-600 | `#E47909` |
| primary-700 | `#BB610A` |
| primary-800 | `#984E0F` |
| primary-900 | `#7C4112` |
| primary-950 | `#422007` |

### Secundarios y semánticos

| Token | Hex | Uso |
|---|---|---|
| `primarylight` | `#FCDCB5` | fondos suaves de acento (chips, banners) |
| `secondary` | `#E9A527` | acento secundario (amarillo oro) |
| `secondarylight` | `#F8E2BA` | fondo suave del secundario |
| `redDanger` | `#F80505` | errores y acciones destructivas |

### Neutros

| Token | Hex | Uso sugerido |
|---|---|---|
| `grayF2` | `#F2F2F2` | separadores / fondos neutros claros |
| `grayD9` | `#D9D9D9` | bordes marcados en claro |
| `gray99` / `gray9A` | `#999999` / `#9A9A9A` | texto secundario |
| `gray77` | `#777777` | iconos inactivos |
| `gray66` | `#666666` | texto terciario |
| `gray37` | `#373737` | surface 950 del preset en claro |
| `black1` | `#1A1A1A` | superficie interna oscura (`#1a1a1a`) |
| `black2` | `#0D0D0D` | fondo más profundo (uso puntual) |

---

## 3. Tipografía

Fuente: **Figtree** (misma familia que la web). En Flutter se declara en `pubspec.yaml`
o se usa como fallback (`google_fonts: figtree`) si no se quieren empaquetar los `.ttf`.

| Rol | Tamaño | Peso | Extras | Ejemplo |
|---|---|---|---|---|
| Micro-etiqueta de campo | 10 | `w700` | `letterSpacing: 1.4`, MAYÚSCULAS, color `#9CA3AF` (mínimo permitido) | "CLIENTE *", "TOTAL" |
| Texto de cuerpo | 14 | `w400`/`w500` | `height: 1.4`, color `#E5E7EB` (oscuro) / `#1F2937` (claro) | nombres de producto, direcciones |
| Texto secundario | 12 | `w400` | color `#D1D5DB` (oscuro) / `#374151` (claro) | folios, teléfonos |
| Título de pantalla (`h1`) | 24–28 | `w300` | `letterSpacing: -0.4`, `m-0`, color `#FFFFFF` (oscuro) / `#111827` (claro) | "Punto de venta" |
| Título de card (`h2`) | 14 | `w700` | MAYÚSCULAS, `letterSpacing: 1.4`, color `#E5E7EB` (oscuro) / `#374151` (claro) | "ANTICIPOS Y PAGOS" |
| **Monto destacado** | 30 | `w300` | `letterSpacing: -0.6`, `FittedBox`, color `#FFFFFF` | "$1,240.00" |
| Monto en lista | 14 | `w700` | `fontFeatures: [tabularFigures]`, color `#FFFFFF` | "+$350.00" |
| Badge / estatus | 10 | `w700` | MAYÚSCULAS, `letterSpacing: 1.2` | "EN PROGRESO" |

Reglas:
- **Todos los textos de UI en español y en sentence case** (nunca Title Case ni MAYÚSCULAS
  en frases; las micro-etiquetas sí van en mayúsculas por ser estilo técnico, ej. "MÉTODO DE PAGO").
- Los montos usan cifras tabulares para que no bailen al actualizarse.
- **Regla de contraste obligatoria:** ver sección 13. Ningún texto informativo puede quedar por
  debajo de `#9CA3AF` en modo oscuro ni de `#4B5563` en modo claro.

---

## 4. Espaciado, bordes y radios

| Concepto | Valor |
|---|---|
| Padding de pantalla | 16 px horizontal |
| Padding interno de card | 20–24 px (cards principales), 12–16 px (filas) |
| Separación entre cards | 12 px |
| Borde estándar | 1 px, `#f3f4f6` (claro) / `#3a3a3a` (oscuro) |
| Radio contenedor / modal / bottom sheet | 24 px |
| Radio card / input | 16 px |
| Radio botón primario | pill (`StadiumBorder`) |
| Radio chip / badge / avatar | pill o 12 px |
| Altura de input | 48–52 px |
| Altura mínima de botón | 48 px (área táctil cómoda) |
| Sombra | ninguna (usar borde) |

---

## 5. Iconografía

La web usa **PrimeIcons**. En Flutter se mapea a `Material Symbols`/`Icons` conservando el significado:

| PrimeIcons | Uso en la web | Icono Flutter sugerido |
|---|---|---|
| `pi-shopping-cart` | carrito | `Icons.shopping_cart_outlined` |
| `pi-search` | búsqueda | `Icons.search` |
| `pi-barcode` / `pi-qrcode` | escanear | `Icons.qr_code_scanner` |
| `pi-user` / `pi-users` | cliente | `Icons.person_outline` |
| `pi-money-bill` | efectivo | `Icons.payments_outlined` |
| `pi-credit-card` | tarjeta | `Icons.credit_card` |
| `pi-arrows-h` | transferencia | `Icons.swap_horiz` |
| `pi-wallet` | saldo a favor | `Icons.account_balance_wallet_outlined` |
| `pi-inbox` | pendiente | `Icons.inbox_outlined` |
| `pi-cog` | en progreso | `Icons.settings_outlined` |
| `pi-pause` | esperando refacción | `Icons.pause_circle_outline` |
| `pi-check-circle` | terminado | `Icons.check_circle_outline` |
| `pi-box` | entregado | `Icons.inventory_2_outlined` |
| `pi-ban` | cancelado | `Icons.block` |
| `pi-print` | imprimir | `Icons.print_outlined` |
| `pi-whatsapp` / `pi-comments` | enviar ticket | `Icons.chat_outlined` |
| `pi-trash` | eliminar | `Icons.delete_outline` |
| `pi-plus` / `pi-minus` | cantidad | `Icons.add` / `Icons.remove` |
| `pi-sync` | sincronizar | `Icons.sync` |
| `pi-cloud-upload` | pendiente de subir | `Icons.cloud_upload_outlined` |

Siempre acompañar el icono de un texto: **nunca un icono solo** para acciones destructivas.

---

## 6. Superficies por modo (valores usados literalmente en la web)

| Elemento | Modo claro | Modo oscuro |
|---|---|---|
| Fondo de la app (detrás de todo) | `#F9FAFB` (gray-50) | `#1A1A1A` (surface-950) |
| Card / panel / bottom sheet / dialog | `#FFFFFF` | `#232323` (surface-900) |
| Card interna, input, chip, fila alterna | `#F9FAFB` (gray-50) | `#1A1A1A` (surface-950) |
| Borde | `#F3F4F6` (gray-100) | `#3A3A3A` (surface-800) |
| Borde marcado / divisor | `#E5E7EB` (gray-200) | `#4A4A4A` |
| Texto principal | `#111827` (gray-900) | `#FFFFFF` |
| Texto secundario (cuerpo) | `#374151` (gray-700) | `#E5E7EB` |
| Texto de apoyo (folios, fechas, ayudas) | `#4B5563` (gray-600) | `#D1D5DB` |
| Micro-etiquetas / terciario (mínimo) | `#6B7280` (gray-500) | `#9CA3AF` |
| Texto deshabilitado | `#9CA3AF` | `#6B7280` (solo sobre elementos inactivos, nunca datos) |
| Overlay de modal | `rgba(0,0,0,0.4)` | `rgba(0,0,0,0.7)` |

> El modo oscuro es la referencia principal: la web usa `#232323` para paneles y
> `#1A1A1A` para lo que vive dentro de ellos. Respeta ese contraste "panel → interior".

---

## 7. Colores de estatus (copiados de las severidades PrimeVue)

### Órdenes de servicio (`ServiceOrder/Index.vue`, `ServiceOrder/Show.vue`)

| Estatus (valor API) | Etiqueta en español | Severidad | Color sugerido |
|---|---|---|---|
| `pendiente` | Pendiente | warn | `#F59E0B` |
| `en_progreso` | En progreso | info | `#3B82F6` |
| `esperando_refaccion` | Esperando refacción | secondary | `#6B7280` |
| `terminado` | Terminado | success | `#22C55E` |
| `entregado` | Entregado | success | `#22C55E` |
| `cancelado` | Cancelado | danger | `#EF4444` |

### Ventas / pedidos (`Transaction/Index.vue`)

| Estatus (valor API) | Etiqueta en español | Severidad | Color sugerido |
|---|---|---|---|
| `completado` | Completado | success | `#22C55E` |
| `pendiente` | Pendiente | warn | `#F59E0B` |
| `apartado` | Apartado | warn | `#F59E0B` |
| `entregado_por_pagar` | Entregado por pagar | warn | `#F59E0B` |
| `por_entregar` | Por entregar | info | `#3B82F6` |
| `en_ruta` | En ruta | info | `#3B82F6` |
| `reembolsado` | Reembolsado | info | `#3B82F6` |
| `cambiado` | Cambiado | secondary | `#6B7280` |
| `cancelado` | Cancelado | danger | `#EF4444` |

Formato de badge: fondo del color al 10–12 % de opacidad, texto del color pleno,
borde al 30 % de opacidad, radio pill, texto 10 px `w700` en MAYÚSCULAS con `letterSpacing: 1.2`.

Puntos de actividad: círculo de 8 px del color del estatus con animación
`animate-pulse` (opacidad 1 → 0.4 → 1 en 1.5 s) para indicar "en curso" o "conectado".

---

## 8. Mapeo de componentes PrimeVue → Flutter

| PrimeVue (web) | Widget Flutter | Notas de estilo |
|---|---|---|
| `Button` (`severity`) | `FilledButton` / `OutlinedButton` | primario = `#F68C0F`, texto blanco, pill |
| `InputText` | `TextFormField` | `OutlineInputBorder`, radio 16, fondo surface interno |
| `InputNumber` (`mode="currency"`) | `TextFormField` + `TextInputFormatter` | `keyboardType: number`, alineado a la derecha, `es-MX` |
| `Select` / `Dropdown` | `DropdownButtonFormField` o bottom sheet | preferir **bottom sheet** en móvil para listas largas |
| `AutoComplete` | `RawAutocomplete` / pantalla de búsqueda | clientes y productos |
| `DataTable` | `ListView.builder` + `Card` por fila | en móvil nunca tabla real |
| `Drawer` (`position="right"`) | `showModalBottomSheet` | carrito y detalle de orden |
| `Dialog` | `showDialog` / `Dialog.fullscreen` | radio 24, `#232323` en oscuro |
| `Stepper` (órdenes) | stepper propio con `LinearProgressIndicator` | replicar círculos de 48 px con glow azul |
| `Tag` (`severity`) | `StatusBadge` propio | ver sección 7 |
| `Toast` | `SnackBar` | success = verde, error = rojo, duración 4 s |
| `ConfirmDialog` | `AlertDialog` | botones "Cancelar" / "Confirmar" |
| `ProgressSpinner` | `CircularProgressIndicator` | color `#F68C0F`, trazo 3 |
| `Popover` | `PopupMenuButton` / sheet | menú de acciones de fila |
| `Message` (error de validación) | `Text` rojo 12 px bajo el campo | mismo microcopy que la web |

---

## 9. Ejemplo de tema Flutter (`lib/core/theme/app_theme.dart`)

```dart
class EzyColors {
  static const primary = Color(0xFFF68C0F);
  static const primaryLight = Color(0xFFFCDCB5);
  static const secondary = Color(0xFFE9A527);
  static const danger = Color(0xFFF80505);

  static const surfaceDark = Color(0xFF232323);      // paneles / cards
  static const surfaceDarkInner = Color(0xFF1A1A1A); // inputs / cards internas
  static const borderDark = Color(0xFF3A3A3A);

  static const surfaceLight = Color(0xFFFFFFFF);
  static const surfaceLightInner = Color(0xFFF9FAFB);
  static const borderLight = Color(0xFFF3F4F6);
}

ThemeData buildDarkTheme() {
  final base = ThemeData.dark(useMaterial3: true);
  return base.copyWith(
    scaffoldBackgroundColor: EzyColors.surfaceDarkInner,
    colorScheme: base.colorScheme.copyWith(
      primary: EzyColors.primary,
      surface: EzyColors.surfaceDark,
    ),
    cardTheme: const CardThemeData(
      color: EzyColors.surfaceDark,
      elevation: 0,
      margin: EdgeInsets.zero,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.all(Radius.circular(24)),
        side: BorderSide(color: EzyColors.borderDark, width: 1),
      ),
    ),
    inputDecorationTheme: const InputDecorationTheme(
      filled: true,
      fillColor: EzyColors.surfaceDarkInner,
      border: OutlineInputBorder(
        borderRadius: BorderRadius.all(Radius.circular(16)),
        borderSide: BorderSide(color: EzyColors.borderDark),
      ),
      enabledBorder: OutlineInputBorder(
        borderRadius: BorderRadius.all(Radius.circular(16)),
        borderSide: BorderSide(color: EzyColors.borderDark),
      ),
      focusedBorder: OutlineInputBorder(
        borderRadius: BorderRadius.all(Radius.circular(16)),
        borderSide: BorderSide(color: EzyColors.primary),
      ),
    ),
  );
}
```

---

## 10. Formatos obligatorios

| Dato | Formato | Implementación |
|---|---|---|
| Moneda | `$1,240.00 MXN` | `NumberFormat.currency(locale: 'es_MX', symbol: r'$', decimalDigits: 2)` |
| Fecha corta | `18 sep 2026` | `DateFormat('d MMM y', 'es_MX')` |
| Fecha y hora | `18 sep 2026, 14:35` | `DateFormat('d MMM y, HH:mm', 'es_MX')` |
| Cantidad | hasta 3 decimales, sin ceros sobrantes | `1`, `1.5`, `0.25` |
| Folios | tal cual llegan del servidor | `V-001`, `OS-014`, `ABONO-003`, `TMP-A1B2C3` |

Inicializar en `main()`:
```dart
await initializeDateFormatting('es_MX');
Intl.defaultLocale = 'es_MX';
```

---

## 11. Microcopy (español, sentence case)

| Contexto | Texto a usar |
|---|---|
| Cargando | "Cargando información…" |
| Lista vacía de ventas | "Aún no hay ventas registradas." |
| Lista vacía de órdenes | "No hay órdenes de servicio que coincidan con la búsqueda." |
| Sin sesión de caja | "Necesitas una sesión de caja abierta. Ábrela desde la versión web." (solo si no hay terminales libres ni sesiones disponibles) |
| Abrir caja sin conexión | "Necesitas conexión para abrir caja." |
| Terminal ocupada | "Parece que otro usuario abrió caja antes que tú. Puedes unirte a la sesión." |
| Caja abierta | "La caja ha sido abierta con éxito." |
| Sin terminales libres | "No hay terminales disponibles en esta sucursal." |
| Sesión de caja activa | "Ya tienes una sesión de caja activa." |
| Sesión cerrada por otro usuario | "La sesión de caja se cerró. Abre caja para seguir vendiendo." |
| Sesión ya cerrada | "Esa sesión de caja ya fue cerrada." |
| Venta guardada | "Venta registrada con éxito. Folio: V-014" |
| Venta sin conexión | "Venta guardada en el dispositivo. Se enviará al recuperar la conexión." |
| Cambio de estatus | "Estatus actualizado a “Terminado”." |
| Error de red | "No pudimos conectar con el servidor. Revisa tu conexión e inténtalo de nuevo." |
| Error de validación | el mensaje exacto que devuelve el servidor en `errors` |
| Confirmar cancelación | "¿Seguro que quieres cancelar esta venta? El inventario se devolverá al stock." |
| Imprimir | "Ticket enviado a la impresora." |
| WhatsApp | "Ticket listo para enviar por WhatsApp." |
| Permiso denegado | "Tu usuario no tiene permiso para esta acción." |

Prohibido: Title Case ("Guardar Cambios"), frases completas en MAYÚSCULAS y
cualquier texto de UI en inglés.

---

## 12. Pantalla "Inicializar caja" (apertura de turno)

Réplica móvil de `resources/js/Components/StartSessionModal.vue`. Copy exacto a reutilizar:

| Elemento | Texto / estilo |
|---|---|
| Título del modal / pantalla | "Inicializar caja" (`text-xl font-light tracking-tight`) |
| Encabezado interno | **"Apertura de terminal"** (`font-medium text-lg tracking-tight`) + subtítulo "Confirmar saldos y habilitar ventas" (10 px, MAYÚSCULAS, `tracking-widest`, `gray-500`) |
| Icono del encabezado | círculo de 48 px con `Icons.desktop_windows_outlined`, fondo `primary-50`, borde `primary-100` |
| Campo 1 | Etiqueta "Equipo asignado *" · placeholder del selector "Seleccionar terminal libre…" |
| Encabezado de bloque | "Declaración de fondos iniciales" (10 px, MAYÚSCULAS, bold, `gray-400`) |
| Campo 2 | "Efectivo físico en caja *" · `InputNumber` moneda MXN `es-MX`, texto `text-2xl font-light` |
| Campos dinámicos | "Saldo en banco: {bank_name} ({account_name})" (uno por cuenta bancaria) |
| Acción secundaria | "Cancelar" (texto, sin relleno) |
| Acción principal | **"Iniciar turno"** con icono `Icons.power_settings_new`, pill, `#F68C0F` |
| Errores | "El fondo de caja inicial es obligatorio." · "Cada cuenta bancaria debe declarar un saldo." |

Estructura visual:

```
Scaffold (fondo surfaceDarkInner)
└── Card radio 24, borde 1px, elevation 0
    ├── Banner interno (fondo surfaceDarkInner, radio 24, borde 1px)
    │   ├── Círculo 48 px con icono (fondo primary al 10 %)
    │   └── "Apertura de terminal" + "Confirmar saldos y habilitar ventas"
    ├── Campo: Equipo asignado
    ├── Divisor 1px
    ├── "Declaración de fondos iniciales"
    ├── Campo: Efectivo físico en caja (monto grande, alineado a la izquierda)
    ├── Campos: saldos por cuenta bancaria (mismo estilo, con scroll si son muchos)
    └── Fila de acciones: "Cancelar" + "Iniciar turno"
```

Reglas de estilo:
- El fondo de efectivo y los saldos bancarios usan el estilo de **monto grande**
  (`text-3xl font-light tracking-tight`), nunca el de campo de texto común.
- Si hay más de 3 cuentas bancarias, el bloque de saldos hace scroll interno (la pantalla no crece).
- El botón principal se deshabilita mientras `POST /cash-register-sessions` está en vuelo y muestra
  un spinner dentro del propio botón (no un overlay que bloquee la pantalla).
- Si el servidor responde `cash_register_in_use`, el error se muestra como banner de advertencia
  (amarillo, `warn`) dentro del mismo formulario, con la acción "Unirme a esa sesión".

---

## 13. Contraste y legibilidad (regla obligatoria)

> Requisito explícito del cliente: **los textos importantes y los títulos no pueden quedar en gris
> claro o suave**. Deben leerse sin esfuerzo, incluso a plena luz del día en un teléfono.

**Umbrales (WCAG AA):** texto normal ≥ **4.5:1** · texto grande (≥ 24 px o ≥ 18.66 px bold) ≥ **3:1**.

### Tono mínimo permitido por función

| Función | Modo oscuro (sobre `#232323` / `#1A1A1A`) | Ratio aprox. | Modo claro (sobre `#FFFFFF`) | Ratio aprox. |
|---|---|---|---|---|
| Títulos de pantalla y cards | `#FFFFFF` | 15.7:1 | `#111827` | 17.7:1 |
| Montos y totales | `#FFFFFF` | 15.7:1 | `#111827` | 17.7:1 |
| Texto de cuerpo / datos de listas | `#E5E7EB` | 12.6:1 | `#1F2937` | 14.2:1 |
| Texto de apoyo (folios, fechas, ayudas) | `#D1D5DB` | 10.7:1 | `#374151` | 10.4:1 |
| Micro-etiquetas, textos terciarios | `#9CA3AF` (mínimo absoluto) | 6.2:1 | `#4B5563` | 7.5:1 |
| Elementos deshabilitados | `#6B7280` | 3.3:1 | `#9CA3AF` | 2.5:1 |

### Prohibiciones explícitas
- ❌ `#6B7280` (`gray-500`) o más claro para **títulos, montos, nombres, folios, importes, fechas
  de entrega, estados** en modo oscuro (3.3:1 — no cumple AA).
- ❌ `#9CA3AF` (`gray-400`) para cualquier texto legible en **modo claro** (2.5:1).
- ❌ Usar gris para mensajes de error o de éxito: siempre el color de la severidad.
- ❌ Texto blanco puro sobre el naranja `#F68C0F` en bloques grandes (usar `#1A1A1A` sobre naranja
  para botones llenos, o naranja sobre fondo oscuro para textos).

### Colores de texto en badges de estatus
Fondo del color al **12 %** de opacidad + borde al 30 %. El texto usa el tono **claro** del color
en modo oscuro y el **oscuro** en modo claro:

| Severidad | Texto en oscuro | Texto en claro |
|---|---|---|
| success | `#86EFAC` | `#15803D` |
| warn | `#FCD34D` | `#B45309` |
| info | `#93C5FD` | `#1D4ED8` |
| danger | `#FCA5A5` | `#B91C1C` |
| secondary | `#D1D5DB` | `#4B5563` |

### Datos que **siempre** van en alto contraste
Montos y totales · nombres de producto/servicio y de cliente · folios · cantidades ·
saldo pendiente y crédito disponible · fecha prometida de una orden · método de pago ·
contadores de notificaciones · estados del pedido.

---

## 14. Pantallas nuevas: cierre de caja y cuenta

### 14.1 Cierre de caja / corte

| Elemento | Texto / estilo |
|---|---|
| Título | "Cierre de caja" |
| Resumen del turno | Filas etiqueta-valor: "Fondo inicial", "Ventas en efectivo", "Tarjeta", "Transferencia", "Saldo a favor", "Ingresos", "Egresos", "**Total esperado**" (destacado, monto grande) |
| Bloque bancos | "Cuenta principal · BBVA" con "Saldo inicial / Recibido / Gastado / Saldo final" |
| Aviso multi-usuario | "Hay N usuarios en esta sesión; al cerrarla, todos saldrán de la caja." (banner `warn`) |
| Campo de arqueo | "Efectivo físico contado" · monto grande (`text-3xl font-light`), placeholder "$0.00" |
| Diferencia (en vivo) | Verde con check → "Sin diferencia" · Naranja con triángulo → "Descuadre / Diferencia" + monto |
| Notas | "Notas de arqueo (Opcional)" · placeholder "Ej. Faltante por dar cambio incorrecto, se dejó fondo extra…" |
| Acción secundaria | "Regresar" (volver al resumen) / "Cancelar" |
| Acción principal | **"Finalizar turno"** (rojo/danger, icono `Icons.check`) |
| Éxito | "Corte de caja realizado con éxito." + acciones "Imprimir corte" / "Enviar por WhatsApp" |

### 14.2 Cuenta (menú principal)

| Elemento | Texto |
|---|---|
| Título de la pestaña | "Mi cuenta" |
| Encabezado | Nombre del usuario + correo + foto |
| Sucursal | "Sucursal activa" con el nombre del negocio y la sucursal; acción "Cambiar de sucursal" (modo soporte si `user.id = 1`) |
| Opciones | "Mi perfil", "Mi suscripción" (solo propietario), "Centro de soporte", "Notificaciones", "Cerrar sesión" |

### 14.3 Mi perfil

| Elemento | Texto |
|---|---|
| Secciones | "Información personal", "Seguridad", "Sesiones activas" |
| Foto | "Cambiar foto" / "Eliminar foto" |
| Campos | "Nombre", "Correo electrónico" · acción "Guardar cambios" |
| Contraseña | "Contraseña actual", "Nueva contraseña", "Confirmar contraseña" · acción "Actualizar contraseña" |
| Otras sesiones | "Cerrar otras sesiones" + diálogo "Confirmar cierre" pidiendo la contraseña |
| Avisos | "Tus datos se guardaron." · "Te enviamos un código de verificación a tu nuevo correo." |

### 14.4 Mi suscripción

| Elemento | Texto |
|---|---|
| Título | "Mi suscripción" |
| Estado | Etiqueta de color: "Activa" / "Por vencer" / "Expirada" / "Suspendida" + "Vence el 18 oct 2026 (quedan 30 días)" |
| Bloques | "Datos generales", "Plan contratado" (módulos y límites con consumo), "Sucursales", "Cuentas bancarias", "Historial de pagos", "Documentos fiscales" |
| Acciones | "Guardar cambios" · "Subir documento" · "Solicitar factura" · **"Renovar o mejorar plan"** (abre el navegador) |

### 14.5 Centro de soporte

| Elemento | Texto |
|---|---|
| Título | "Centro de soporte" · subtítulo "Estamos aquí para ayudarte" |
| Mensaje | "Puedes solicitar soporte técnico, reportar algún error, sugerir mejoras al sistema o proponer nuevas funcionalidades. Con gusto lo evaluaremos." |
| Horario | "Horario de atención" → "Lunes a viernes 8:00 AM — 7:00 PM" · "Sábados 9:00 AM — 3:00 PM" |
| Canales | "Correo electrónico · notificaciones@ezyventas.com" · "WhatsApp · +52 33 2170 5650" |
| Extra | "Centro de ayuda" + temas (Primeros pasos, Facturación, Mi cuenta, Inventario) |

### 14.6 Notificaciones y cambio de sucursal

| Elemento | Texto |
|---|---|
| Categorías | "Deudas por vencer", "Entregas próximas", "Novedades", "Pedidos pendientes" |
| Vacío | "No tienes notificaciones por ahora." |
| Sucursal | Título "Sucursal activa", filas por sucursal con check verde en la activa, confirmación "¿Cambiar a «Sucursal Centro»?" |
| Bloqueo | "Tienes operaciones sin sincronizar. Sincronízalas antes de cambiar de sucursal." |
| Cerrar sesión | "¿Quieres cerrar sesión?" + (si hay pendientes) "Tienes N operaciones sin sincronizar. Si cierras sesión se perderán." |
