# Prompt — Implementar la pantalla de inicio (dashboard) en la app Flutter

> Pega el bloque de **§Prompt** completo en tu agente del proyecto Flutter.

## Prompt

Eres el agente de la app móvil **EzyVentas (Flutter / Android)**. El backend **ya está terminado** y
expone la pantalla de inicio: **no** modifiques el backend ni inventes endpoints ni campos. Tu tarea
es implementar la pantalla de inicio y sus dos listados en la app.

### 1. Lee SOLO estos fragmentos (el resto de la documentación no aplica y es enorme)

`docs/mobile-app/01-contrato-api-v1.md`
- **§1 Convenciones generales** — formato de respuestas, errores y cómo viaja el dinero.
- **§3 Permisos por endpoint** — solo las **3 filas** de `/dashboard*`.
- **§3b Inicio de la app (dashboard) — COMPLETO (§3b.1 a §3b.5)** ← **este es el contrato a
  implementar**: bloques, permisos, los 3 endpoints, JSON de respuesta, notas de cada campo y
  pendientes conocidos.
- **§6 Caja** — solo el objeto `session` que viaja dentro de `cash_register.session`.
- **§8 Historial de ventas** — solo el endpoint `POST /transactions/{id}/payments` (botón
  «Cobrar saldo»).
- **§12 Catálogo de códigos de error** — para manejar `403` y `422`.
- **§2 Autenticación — solo si tu capa de red todavía no existe** (Bearer token, `POST /auth/login`,
  `GET /auth/me`).
- ❌ No leas §4, §5, §7, §9, §10, §11, §11b, §13, §13b.

`docs/mobile-app/00-contexto-app-movil.md`
- **§2 Reglas de oro**, **§4 Stack y estructura** (y **§4.1** cascarón de navegación: el inicio es la
  primera pantalla), **§4.2 Pantalla de inicio (dashboard)** ← guía de composición de tarjetas,
  **§5 Permisos en la UI**.

`docs/mobile-app/03-design-system-tesla-ui.md`
- **§2** paleta, **§3** tipografía, **§4** espaciado/radios, **§6** superficies por modo, **§7**
  colores de estatus, **§8** mapeo PrimeVue → Flutter, **§9** ejemplo de tema, **§10** formatos
  obligatorios, **§11** microcopy, **§13** contraste (regla obligatoria).

`docs/mobile-app/02-modelo-de-datos.md`
- **§10 Enums completos** (los valores de `status` y de `by_status` son exactamente esos).
- **§6** (`transactions`) y **§4** (`customers.balance`) solo si necesitas la semántica.

### 2. Alcance

Implementar **solo**:
1. Pantalla **Inicio** (`GET /dashboard`, una sola llamada).
2. Pantalla **Apartados y créditos por vencer** (`GET /dashboard/expiring-layaways?days=`).
3. Pantalla **Pedidos por entregar** (`GET /dashboard/upcoming-deliveries?days=`).

Nada de otras pantallas, módulos, endpoints ni refactors.

### 3. Entregables (respeta la estructura y convenciones que ya existan en el proyecto)

- **Modelos** con `fromJson`/`toJson` explícitos: `MobileDashboard`, `SalesSummary`,
  `WeeklyTrendDay`, `InventorySummary`, `LowStockProduct`, `ServiceOrdersSummary`,
  `CashRegisterState`, `ExpiringLayaway`, `UpcomingDelivery`.
- **API**: un cliente de los 3 endpoints sobre el `dio` existente (interceptores de token/`401`
  incluidos).
- **Repository + estado** (riverpod o provider, el que ya use el proyecto): estados `loading`,
  `error`, `data`, y `refresh` que vuelve a pedir `GET /dashboard` (pull-to-refresh).
- **UI**: pantalla de inicio con las tarjetas de §4.2 y las dos pantallas de listado, aplicando el
  design system (§03) y con el modo oscuro como predeterminado.
- **Textos** visibles en **español y sentence case** («Venta de hoy», «Vence hoy», «Abrir caja»);
  identificadores, clases y campos en **inglés**.
- **Tests**: unit de parseo con los 3 JSON de §3b (incluyendo **bloques en `null`** y **listas
  vacías**) + widget test de que una tarjeta con bloque `null` **no se dibuja**.

### 4. Reglas no negociables (esto es lo que se evalúa)

1. **`null` ≠ `0`.** Un bloque en `null` significa «este usuario no puede verlo»: **oculta la
   tarjeta**, no la pintes en cero ni con «Sin datos». Un `0` o una lista vacía sí se muestran
   (con estado vacío real).
2. **`cash_register` viaja siempre** (no depende de permisos). Si `has_open_session` es `false`, la
   barra de caja debe ofrecer **«Abrir caja»**.
3. **Nunca mandes `branch_id`**: la sucursal, la suscripción y los permisos salen del token. Si el
   usuario cambia de sucursal, invalida y vuelve a pedir el inicio.
4. **Dinero**: viene como *string* decimal (`"4820.00"`, sin separador de miles) → `double.parse` +
   `intl` es-MX para mostrarlo. Única excepción: `cash_register.session` conserva números
   (`totals.cash`, `opening_cash_balance`, saldos bancarios).
5. **No recalcules en el teléfono** `days_remaining`, `is_overdue` ni `is_today`: los calcula el
   servidor con la zona horaria del negocio; la app solo los pinta («Vence hoy», «Vencido hace 2
   días», «Entrega de hoy»).
6. **`delivery_date` es una trampa**: es ISO-8601 UTC pero representa un **día** (siempre
   `…T00:00:00.000000Z`), así que convertirlo a hora local muestra el **día anterior** en México. Usa
   `is_today` / `days_remaining` para pintar la fecha, o pásala por la zona del negocio sin hora.
7. **`expiration_date`** sí es fecha local `YYYY-MM-DD` (sin hora).
8. **Contadores**: usa `expiring_count` y `upcoming_deliveries_count` del payload; no cuentes filas en
   el cliente. Al abrir los listados usa el mismo `days` (**3** por defecto) para que el número de la
   tarjeta coincida con el largo de la lista.
9. **`weekly_trend` siempre trae 7 elementos** (lunes → domingo, con días en `"0.00"`). El orden es
   fijo: puedes usar tu propio texto por índice.
10. **`days`**: parámetro opcional, entero **1-30**. No mandes `0`, negativos ni texto. Si el servidor
    responde `422`, muestra el mensaje del servidor y vuelve a `3`.
11. **Errores**: muestra el `message` del servidor **tal cual** (ya viene en español; no lo traduzcas
    ni lo reemplaces por «Error desconocido»). `403` = pantalla/acción oculta con aviso de permisos.
12. **No caches el inicio** (así está documentado): se pide al entrar a la pantalla y con
    pull-to-refresh. El modo offline es otra fase; no lo implementes aquí.
13. **Tolerancia a cambios**: ignora llaves desconocidas en el JSON, pero si **falta** una llave
    documentada, trátalo como error de contrato y repórtalo (no lo asumas `null` en silencio).
14. Coordina la **composición de la pantalla** con §4.2 y el look con §03; no inventes estilos
    nuevos ni uses colores fuera de la paleta.

### 5. Criterios de aceptación

- Al abrir la app se ve, en **una sola petición**, cómo va el día (venta, ticket promedio, tendencia
  semanal), los apartados por vencer, los pedidos por entregar, el saldo por cobrar, el inventario
  con bajo stock, las órdenes de servicio por estatus y el estado de caja.
- Un usuario **propietario** ve todas las tarjetas; un **empleado** sin los permisos del inicio
  (`dashboard.see_sales`, `dashboard.see_layaways`, `dashboard.see_orders`,
  `dashboard.see_outstanding_balances`, `dashboard.see_inventory_details`, `services.orders.access`)
  ve la pantalla **sin esas tarjetas** (y sin errores ni huecos vacíos). Ojo: `service_orders` **no**
  usa un permiso `dashboard.*`, usa `services.orders.access`.
- Tocar «Apartados por vencer» abre el listado con las fechas y los días restantes **ya calculados
  por el servidor** (incluidas las vencidas, marcadas en rojo con §03 §7); lo mismo con «Pedidos por
  entregar».
- `days` responde al filtro elegido (1-30) y por defecto es 3.
- Sin turno de caja abierto, la barra de caja muestra «Abrir caja» y no rompe el resto de la pantalla.
- Pull-to-refresh vuelve a pedir `GET /dashboard` y actualiza `generated_at` en la cabecera
  («Actualizado …»).

### 6. Orden de trabajo sugerido

1. Modelos + `fromJson` y sus tests de parseo con los JSON de §3b (primero los casos con bloques
   `null`).
2. Cliente API + repository + provider (con estados y refresh).
3. Pantalla de inicio y tarjetas (§4.2 + §03).
4. Las dos pantallas de listado + navegación desde las tarjetas.
5. Widget tests (ocultar tarjetas sin permiso, estado vacío, error de red) y repaso de contraste
   (§03 §13).

### 7. Prohibido

- Modificar el backend, añadir campos o endpoints «por conveniencia».
- Recalcular en el cliente fechas, contadores, deuda o totales.
- Mostrar una tarjeta en cero por un bloque `null`, o esconder una lista por un `0` real.
- Usar `paid_amount` en este payload: en el inicio el campo se llama **`total_paid`**
  (junto con `pending_amount`).
- Implementar caché/offline del inicio en esta tarea (es Fase 5).

### 8. Qué entregar al terminar

Un resumen con: archivos creados/modificados, cómo correr los tests, y cualquier duda o discrepancia
encontrada contra §3b (con el campo y la respuesta real del servidor).

