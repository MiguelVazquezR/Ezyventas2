-- ============================================================================
-- Help center - Guides for the Inicio (dashboard) module
--
-- Inserts 10 published guides for the "dashboard" module of the
-- help_center_articles table. The guides automatically appear in the
-- Inicio card of the help center.
--
-- Features:
--   - Idempotent: does not insert a guide when one with the same module
--     and title already exists, so it can be run multiple times safely.
--   - Does not modify or delete existing records.
--   - Can be run locally and in production.
--
-- Usage:
--   Run the whole file with a MySQL or MariaDB client
--   (phpMyAdmin, HeidiSQL, TablePlus, DBeaver or command line).
--
-- Encoding: UTF-8 (utf8mb4).
-- ============================================================================

SET NAMES utf8mb4;

-- ----------------------------------------------------------------------------
-- 1. How to read the Inicio summary
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'dashboard',
    'Cómo leer el resumen de Inicio',
    'Conoce qué mide cada bloque del Inicio, cómo se calculan los indicadores principales y qué rutina diaria conviene seguir para revisar ventas, pendientes y estado del negocio.',
    '<p>El Inicio reúne en una sola pantalla los indicadores clave de la sucursal en la que tienes sesión. Úsalo al abrir el día para detectar pendientes y al cerrar para revisar el estado general del negocio.</p>
<h3>Antes de empezar</h3>
<ul>
<li>Todos los datos corresponden a tu sucursal. Si trabajas en varias, cambia de sucursal para consultar otra.</li>
<li>Los bloques aparecen según tus permisos. Si falta alguno, revisa la guía "Por qué no veo todos los bloques o las cifras no coinciden".</li>
</ul>
<h3>Los cuatro indicadores superiores</h3>
<ol>
<li><strong>Ventas de hoy:</strong> total vendido en el día (subtotal menos descuentos más impuestos) y comparación con ayer. No incluye ventas canceladas ni cambiadas. Al hacer clic abres el historial de ventas.</li>
<li><strong>Vencimientos:</strong> cantidad de apartados y créditos cuya fecha de vencimiento está dentro de los próximos 3 días, incluidos los ya vencidos. Al hacer clic ves el detalle.</li>
<li><strong>Entregas:</strong> pedidos por entregar con fecha de entrega dentro de los próximos 3 días, incluidos los atrasados. Al hacer clic ves el detalle.</li>
<li><strong>Por cobrar:</strong> suma de la deuda registrada en el saldo de tus clientes. Al hacer clic abres el módulo Clientes.</li>
</ol>
<h3>Tendencia semanal</h3>
<p>La gráfica muestra de lunes a domingo el total vendido en cada día de la semana en curso. Los días sin ventas aparecen en cero. Pasa el cursor sobre una barra para ver el monto. No se incluyen ventas canceladas ni cambiadas.</p>
<h3>Estado operativo</h3>
<ul>
<li><strong>Cajas registradoras:</strong> cuántas están activas y cuántas están libres. Al hacer clic abres el módulo Cajas.</li>
<li><strong>Órdenes de servicio:</strong> cuántas están pendientes, en proceso, completadas y entregadas. Al hacer clic abres el módulo Órdenes de servicio.</li>
</ul>
<h3>Tesorería</h3>
<p>Muestra el saldo total de las cuentas a las que tienes acceso y el saldo de cada cuenta. Desde el menú de cada cuenta puedes consultar su historial o realizar una transferencia entre cuentas del negocio.</p>
<h3>Productos y clientes</h3>
<ul>
<li><strong>Top 5 del mes:</strong> productos con más unidades vendidas en el mes en curso.</li>
<li><strong>Baja rotación:</strong> productos sin ventas en los últimos 15 días o que nunca se han vendido.</li>
<li><strong>Clientes recientes:</strong> los últimos clientes registrados en la sucursal.</li>
<li><strong>Clientes frecuentes:</strong> los clientes con más movimientos en el mes en curso.</li>
</ul>
<h3>Estado de almacén</h3>
<p>Resume el valor de venta del inventario, el costo de inversión, la cantidad de ítems y un semáforo de existencias. El detalle de cada bloque se explica en las guías correspondientes del centro de ayuda.</p>
<h3>Rutina recomendada</h3>
<ol>
<li>Al abrir el día, revisa las ventas de hoy y la tendencia semanal.</li>
<li>Después, atiende los vencimientos y las entregas pendientes.</li>
<li>Antes de cerrar, revisa el estado de las cajas, los productos de baja rotación y el semáforo del almacén.</li>
</ol>',
    10, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'dashboard'
      AND title = 'Cómo leer el resumen de Inicio'
);

-- ----------------------------------------------------------------------------
-- 2. Follow up on expiring layaways and credits
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'dashboard',
    'Atender apartados y créditos por vencer',
    'Aprende qué incluye el indicador Vencimientos, cómo abrir el detalle, cómo interpretar el semáforo y qué acciones tomar con los apartados y créditos que están por vencer.',
    '<p>El indicador <strong>Vencimientos</strong> te ayuda a detectar apartados y créditos que están por vencer o que ya vencieron, para que puedas contactar al cliente y dar seguimiento antes de que se conviertan en una pérdida.</p>
<h3>Qué cuenta el indicador</h3>
<ul>
<li>Apartados y créditos de tu sucursal que tienen registrada una fecha de vencimiento.</li>
<li>Registros cuya fecha de vencimiento es hoy, cae dentro de los próximos 3 días o ya pasó.</li>
<li>No se incluyen registros sin fecha de vencimiento ni ventas de contado.</li>
</ul>
<p>El número del indicador y el detalle que se abre al hacer clic se calculan con el mismo criterio, por lo que deben coincidir.</p>
<h3>Cómo abrir el detalle</h3>
<ol>
<li>En el Inicio, localiza la tarjeta <strong>Vencimientos</strong>.</li>
<li>Haz clic sobre la tarjeta para abrir la ventana de detalle.</li>
<li>Usa el filtro de tipo para ver todos, solo créditos o solo apartados.</li>
<li>Usa el filtro de cliente para concentrarte en una persona.</li>
</ol>
<h3>Cómo leer la tabla</h3>
<ul>
<li><strong>Folio y tipo:</strong> identificador de la venta. La etiqueta indica si es apartado o crédito. El folio abre el detalle de la venta.</li>
<li><strong>Contacto:</strong> nombre del cliente y teléfono cuando está registrado. Si la venta es de público en general, no habrá teléfono.</li>
<li><strong>Estado:</strong> fecha de vencimiento y texto con los días restantes. Muestra "Vence hoy" o "Venció hace X días" según corresponda.</li>
<li><strong>Balance:</strong> monto pendiente de pago.</li>
</ul>
<p>El semáforo de la fecha es rojo cuando el registro ya venció o vence en 2 días o menos, y amarillo cuando vence en 3 días.</p>
<h3>Qué hacer con cada registro</h3>
<ol>
<li>Contacta al cliente usando el teléfono registrado.</li>
<li>Abre el folio para revisar el detalle de la venta y los pagos.</li>
<li>Registra el pago si el cliente abona o liquida.</li>
<li>Si el cliente necesita más tiempo, aplica una extensión de la fecha de vencimiento desde el detalle de la venta.</li>
<li>Si el cliente ya no va a pagar, gestiona la cancelación del apartado o crédito según tu política interna.</li>
</ol>
<h3>Recomendaciones</h3>
<ul>
<li>Registra siempre la fecha de vencimiento al crear un apartado o crédito. Sin fecha, el registro no aparece en este panel.</li>
<li>Revisa el panel todos los días, sobre todo al abrir el negocio.</li>
<li>Atiende primero los registros vencidos y los que vencen hoy.</li>
<li>Deja una nota en la venta con el resultado del contacto para dar seguimiento.</li>
</ul>',
    20, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'dashboard'
      AND title = 'Atender apartados y créditos por vencer'
);

-- ----------------------------------------------------------------------------
-- 3. Follow up on pending deliveries
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'dashboard',
    'Dar seguimiento a las entregas pendientes',
    'Descubre qué pedidos aparecen en el indicador Entregas, cómo leer la fecha, el contacto y el destino, y qué hacer para que ninguna entrega se quede atrás.',
    '<p>El indicador <strong>Entregas</strong> muestra los pedidos que están pendientes de entregar y cuya fecha de entrega es hoy, cae dentro de los próximos 3 días o ya venció. Sirve para organizar rutas y evitar clientes sin pedido.</p>
<h3>Qué cuenta el indicador</h3>
<ul>
<li>Pedidos de tu sucursal con estado <strong>por entregar</strong>.</li>
<li>Pedidos con fecha de entrega registrada.</li>
<li>Entregas programadas para hoy, para los próximos 3 días o con fecha ya pasada.</li>
<li>No se incluyen pedidos entregados, cancelados ni pedidos sin fecha de entrega.</li>
</ul>
<h3>Cómo abrir el detalle</h3>
<ol>
<li>En el Inicio, localiza la tarjeta <strong>Entregas</strong>.</li>
<li>Haz clic sobre la tarjeta para abrir la ventana de detalle.</li>
<li>Revisa la lista ordenada por fecha de entrega.</li>
</ol>
<h3>Cómo leer la tabla</h3>
<ul>
<li><strong>Folio:</strong> identificador del pedido. Al hacer clic abres el detalle de la venta.</li>
<li><strong>Contacto:</strong> nombre y teléfono del cliente registrado o del cliente invitado.</li>
<li><strong>Logística:</strong> fecha y hora de entrega. Muestra "Hoy" cuando la entrega es hoy, "Programado" con los días restantes o "Retraso" con los días transcurridos.</li>
<li><strong>Destino:</strong> dirección de envío o, si no hay dirección, una nota del pedido. Si no hay ninguno, indica que no hay ruta asignada.</li>
</ul>
<h3>Qué hacer con cada entrega</h3>
<ol>
<li>Confirma con el cliente la dirección, la fecha y la hora.</li>
<li>Abre el folio para revisar los productos y el estado del pedido.</li>
<li>Coordina la ruta del día y prioriza las entregas con retraso.</li>
<li>Al entregar, registra la entrega en el pedido para que salga de esta lista.</li>
</ol>
<h3>Recomendaciones</h3>
<ul>
<li>Registra la fecha de entrega al crear el pedido. Sin fecha, el pedido no aparece en este panel.</li>
<li>Revisa la lista al inicio del día y antes de salir a ruta.</li>
<li>Mantén actualizados el teléfono y la dirección del cliente.</li>
<li>Si un pedido no se puede entregar, deja una nota y reprograma la fecha.</li>
</ul>',
    30, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'dashboard'
      AND title = 'Dar seguimiento a las entregas pendientes'
);

-- ----------------------------------------------------------------------------
-- 4. Understand and collect outstanding customer balances
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'dashboard',
    'Entender y cobrar el saldo pendiente de tus clientes',
    'Aprende qué suma el indicador Por cobrar, en qué se diferencia de Vencimientos, cómo revisar el estado de cuenta de un cliente y qué hacer cuando el monto no coincide.',
    '<p>El indicador <strong>Por cobrar</strong> muestra cuánto dinero te deben tus clientes en total. Es una fotografía de la deuda acumulada, no de las ventas del día.</p>
<h3>Qué suma el indicador</h3>
<ul>
<li>La suma de los saldos negativos de los clientes de tu sucursal.</li>
<li>El resultado se muestra en positivo para que lo leas como dinero pendiente de cobro.</li>
<li>La deuda se acumula con ventas a crédito, apartados y movimientos de saldo registrados en la cuenta del cliente.</li>
<li>Los abonos y los pagos reducen la deuda.</li>
</ul>
<h3>Diferencia con Vencimientos</h3>
<ul>
<li><strong>Por cobrar</strong> es el total de la deuda acumulada de todos los clientes.</li>
<li><strong>Vencimientos</strong> muestra solo los apartados y créditos que están por vencer o ya vencieron.</li>
<li>Un cliente puede tener deuda sin tener un vencimiento próximo, y puede tener un vencimiento próximo sin que ese sea el total de su deuda.</li>
</ul>
<h3>Cómo revisar la deuda</h3>
<ol>
<li>Haz clic en la tarjeta <strong>Por cobrar</strong> para abrir el módulo Clientes.</li>
<li>Busca al cliente por nombre o teléfono.</li>
<li>Abre su perfil y revisa el estado de cuenta, los movimientos y el saldo actual.</li>
<li>Registra el pago o abono desde el detalle del cliente o desde la venta correspondiente.</li>
</ol>
<h3>Recomendaciones</h3>
<ul>
<li>Revisa la deuda de forma periódica, no solo cuando vence un crédito.</li>
<li>Antes de otorgar más crédito, consulta el saldo del cliente.</li>
<li>Registra los pagos el mismo día para que el saldo se mantenga al día.</li>
<li>Usa las notas de los movimientos para explicar ajustes, devoluciones o cancelaciones.</li>
<li>Evita modificar el saldo manualmente sin dejar un registro claro.</li>
</ul>
<h3>Si el monto no coincide con lo que esperas</h3>
<ul>
<li>Verifica que estés en la sucursal correcta. La deuda se calcula por sucursal.</li>
<li>Revisa los movimientos del cliente para detectar pagos, devoluciones o cancelaciones recientes.</li>
<li>Confirma que la venta que genera la deuda no esté cancelada.</li>
<li>Si la diferencia continúa, revisa el estado de cuenta del cliente en el módulo Clientes, que muestra el detalle movimiento por movimiento.</li>
</ul>',
    40, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'dashboard'
      AND title = 'Entender y cobrar el saldo pendiente de tus clientes'
);

-- ----------------------------------------------------------------------------
-- 5. Use the Inicio treasury section
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'dashboard',
    'Usar la tesorería del Inicio',
    'Consulta el saldo de tus cuentas bancarias, revisa el historial de movimientos y realiza transferencias entre cuentas del negocio sin salir del Inicio.',
    '<p>El bloque <strong>Tesorería</strong> muestra el dinero disponible en las cuentas bancarias del negocio y te permite consultar movimientos y transferir fondos entre cuentas.</p>
<h3>Qué cuentas ves</h3>
<ul>
<li>Si eres administrador, ves las cuentas asignadas a tu sucursal.</li>
<li>Si eres usuario con permisos limitados, ves las cuentas que te fueron asignadas.</li>
<li>El saldo total es la suma de las cuentas visibles.</li>
</ul>
<h3>Consultar el historial</h3>
<ol>
<li>Localiza la cuenta que quieres revisar.</li>
<li>Abre el menú de tres puntos de la cuenta.</li>
<li>Selecciona <strong>Ver historial</strong>.</li>
<li>Revisa la fecha y hora, el tipo de movimiento, el folio, el método, el monto y el saldo final.</li>
</ol>
<p>Los montos positivos aparecen en verde y los negativos en rojo. El folio puede abrir el documento relacionado, como una venta o un gasto. La tabla está paginada y muestra los movimientos más recientes.</p>
<h3>Realizar una transferencia</h3>
<ol>
<li>Abre el menú de tres puntos de la cuenta de origen.</li>
<li>Selecciona <strong>Realizar transferencia</strong>.</li>
<li>Revisa la cuenta de origen y su saldo disponible.</li>
<li>Selecciona la cuenta de destino. No puede ser la misma cuenta de origen.</li>
<li>Escribe el monto a transferir.</li>
<li>Agrega un concepto o nota opcional para identificar la operación.</li>
<li>Haz clic en <strong>Ejecutar</strong>.</li>
</ol>
<p>El sistema valida que la cuenta de origen tenga saldo suficiente. Si no lo tiene, muestra el mensaje "Saldo insuficiente en la cuenta de origen" y no realiza la operación.</p>
<h3>Qué ocurre al transferir</h3>
<ul>
<li>Se descuenta el monto de la cuenta de origen y se suma a la cuenta de destino.</li>
<li>Se genera un folio de transferencia con el formato TR-000.</li>
<li>El saldo total del negocio no cambia, solo se redistribuye entre cuentas.</li>
<li>La operación queda registrada en el historial de ambas cuentas.</li>
</ul>
<h3>Recomendaciones</h3>
<ul>
<li>Usa el concepto para explicar el motivo de la transferencia.</li>
<li>Verifica la cuenta de destino antes de ejecutar.</li>
<li>Consulta el historial si necesitas conciliar los saldos con el banco.</li>
<li>Recuerda que una transferencia no es un ingreso ni un gasto, es un movimiento interno.</li>
</ul>',
    50, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'dashboard'
      AND title = 'Usar la tesorería del Inicio'
);

-- ----------------------------------------------------------------------------
-- 6. Identify best-selling and low-turnover products
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'dashboard',
    'Identificar productos más vendidos y de baja rotación',
    'Usa el top 5 del mes y la lista de baja rotación para decidir qué reponer, qué promocionar y qué dejar de comprar. Conoce cómo se calculan ambas listas.',
    '<p>El Inicio incluye dos listas para tomar decisiones de compra y promoción: los productos más vendidos del mes y los productos de baja rotación.</p>
<h3>Top 5 del mes</h3>
<ul>
<li>Muestra los cinco productos con más unidades vendidas en el mes en curso en tu sucursal.</li>
<li>Cuenta las unidades vendidas, no el dinero facturado.</li>
<li>No incluye ventas canceladas.</li>
<li>Si el producto tiene variantes, la lista muestra la variante vendida con sus atributos y su precio.</li>
<li>Al hacer clic en un producto abres su ficha.</li>
</ul>
<h3>Qué hacer con el top 5</h3>
<ol>
<li>Revisa que los productos con más salida tengan existencias suficientes.</li>
<li>Programa compras o reposiciones antes de que se agoten.</li>
<li>Usa estos productos como ancla en promociones y combos.</li>
<li>Revisa su margen para confirmar que la rotación sea rentable.</li>
</ol>
<h3>Baja rotación</h3>
<ul>
<li>Muestra hasta cinco productos sin ventas en los últimos 15 días o que nunca se han vendido.</li>
<li>No considera ventas canceladas ni devueltas.</li>
<li>Si el producto nunca se ha vendido, la lista lo indica como <strong>Nunca</strong> en los días sin venta.</li>
<li>Muestra el stock actual en la sucursal y los días transcurridos desde la última venta.</li>
<li>Al hacer clic en un producto abres su ficha.</li>
</ul>
<h3>Qué hacer con la baja rotación</h3>
<ol>
<li>Revisa si el producto tiene stock detenido y cuánto capital representa.</li>
<li>Prueba una promoción, un descuento o un combo con productos de alta rotación.</li>
<li>Revisa el precio y la ubicación del producto en el punto de venta.</li>
<li>Si el producto no se vende, considera dejar de comprarlo o descontinuarlo.</li>
<li>Si el producto nunca se ha vendido, verifica que esté visible y cargado en el catálogo de la sucursal.</li>
</ol>
<h3>Recomendaciones</h3>
<ul>
<li>Compara ambas listas cada semana para equilibrar compras y ventas.</li>
<li>No repongas un producto de baja rotación solo porque tiene precio bajo.</li>
<li>Recuerda que el top 5 mide unidades, no utilidad. Revisa el margen antes de tomar decisiones.</li>
</ul>',
    60, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'dashboard'
      AND title = 'Identificar productos más vendidos y de baja rotación'
);

-- ----------------------------------------------------------------------------
-- 7. Read the warehouse status
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'dashboard',
    'Interpretar el estado de tu almacén',
    'Entiende cómo se calculan el valor de venta, el costo de inversión y el semáforo de existencias, y cómo usar esta información para reponer inventario y detectar capital detenido.',
    '<p>El bloque <strong>Estado de almacén</strong> resume el valor de tu inventario y te ayuda a detectar productos agotados o con existencias bajas.</p>
<h3>Qué muestra</h3>
<ul>
<li><strong>Valor de venta:</strong> lo que valdría el inventario si se vendiera todo al precio de venta actual.</li>
<li><strong>Costo de inversión:</strong> lo que costó el inventario que tienes en existencia.</li>
<li><strong>Total de ítems:</strong> cantidad de productos y variantes considerados en el cálculo.</li>
<li><strong>Semáforo:</strong> cantidad de ítems óptimos, con stock bajo y agotados.</li>
</ul>
<h3>Cómo se calcula</h3>
<ul>
<li>Valor de venta de un producto simple: precio de venta por existencias en la sucursal.</li>
<li>Valor de venta de una variante: precio del producto más el ajuste de precio de la variante, multiplicado por sus existencias.</li>
<li>Costo de inversión: costo del producto por existencias, tanto en productos simples como en variantes.</li>
<li>Los productos con variantes se cuentan por variante. El producto padre no se cuenta para evitar duplicados.</li>
<li>Todo el cálculo corresponde a la sucursal en la que tienes sesión.</li>
</ul>
<h3>Cómo leer el semáforo</h3>
<ul>
<li><strong>Óptimo:</strong> las existencias son mayores que el stock mínimo configurado.</li>
<li><strong>Bajo:</strong> hay existencias, pero son iguales o menores que el stock mínimo configurado.</li>
<li><strong>Agotado:</strong> las existencias son cero o menores.</li>
<li>Si el stock mínimo está en cero, cualquier existencia positiva se considera óptima y el cero se considera agotado.</li>
</ul>
<h3>Qué hacer con esta información</h3>
<ol>
<li>Configura el stock mínimo de cada producto y variante según su tiempo de reposición.</li>
<li>Revisa los agotados y prioriza los que tienen más venta.</li>
<li>Revisa los de stock bajo antes de que se agoten.</li>
<li>Usa el costo de inversión para detectar capital detenido en productos de baja rotación.</li>
</ol>
<h3>Recomendaciones</h3>
<ul>
<li>El valor de venta no es utilidad. La utilidad es la diferencia entre el valor de venta y el costo de inversión.</li>
<li>Mantén actualizados los costos y precios para que el resumen sea confiable.</li>
<li>Revisa el semáforo después de registrar compras, ventas o ajustes de inventario.</li>
</ul>',
    70, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'dashboard'
      AND title = 'Interpretar el estado de tu almacén'
);

-- ----------------------------------------------------------------------------
-- 8. Review cash register and service order status
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'dashboard',
    'Revisar el estado de cajas y órdenes de servicio',
    'Interpreta cuántas cajas están activas o libres y en qué estado están tus órdenes de servicio. Aprende qué acciones tomar para mantener la operación al día.',
    '<p>El Inicio muestra dos bloques operativos que conviene revisar todos los días: el estado de las cajas registradoras y el avance de las órdenes de servicio.</p>
<h3>Cajas registradoras</h3>
<ul>
<li><strong>Activas:</strong> cajas en uso en este momento, es decir, con un turno abierto.</li>
<li><strong>Libres:</strong> cajas activas que no tienen un turno abierto y están disponibles para cobrar.</li>
<li>Solo se cuentan las cajas activas de tu sucursal. Las cajas inactivas no aparecen.</li>
<li>Al hacer clic en el bloque abres el módulo Cajas.</li>
</ul>
<h3>Qué hacer con las cajas</h3>
<ol>
<li>Antes de empezar a cobrar, abre un turno en una caja libre.</li>
<li>Al terminar el turno, ciérralo para liberar la caja y registrar el corte.</li>
<li>Si no hay cajas activas, crea una caja o activa la que corresponda.</li>
<li>Si una caja aparece en uso y no debería estarlo, revisa el turno abierto y ciérralo.</li>
</ol>
<h3>Órdenes de servicio</h3>
<ul>
<li><strong>Pendientes:</strong> órdenes recibidas que todavía no comienzan a trabajarse.</li>
<li><strong>En proceso:</strong> órdenes que ya están en manos del técnico o en reparación.</li>
<li><strong>Completadas:</strong> órdenes listas para entregar al cliente.</li>
<li><strong>Entregadas:</strong> órdenes que ya fueron entregadas al cliente.</li>
<li>Los números resumen todas las órdenes de la sucursal agrupadas por estado.</li>
<li>Al hacer clic en el bloque abres el módulo Órdenes de servicio.</li>
</ul>
<h3>Qué hacer con las órdenes</h3>
<ol>
<li>Revisa las órdenes pendientes al inicio del día y asígnalas a un técnico.</li>
<li>Da seguimiento a las órdenes en proceso para evitar retrasos.</li>
<li>Contacta a los clientes de las órdenes completadas para coordinar la entrega.</li>
<li>Marca la entrega cuando el cliente reciba el equipo, para que el conteo se mantenga al día.</li>
</ol>
<h3>Recomendaciones</h3>
<ul>
<li>Revisa ambos bloques al abrir y al cerrar el día.</li>
<li>Mantén actualizado el estado de cada orden. Los conteos dependen de ese estado.</li>
<li>No dejes turnos de caja abiertos al final del día.</li>
</ul>',
    80, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'dashboard'
      AND title = 'Revisar el estado de cajas y órdenes de servicio'
);

-- ----------------------------------------------------------------------------
-- 9. Why blocks are missing or figures do not match
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'dashboard',
    'Por qué no veo todos los bloques o las cifras no coinciden',
    'Revisa los permisos que necesita cada bloque, el periodo y los criterios de cada indicador, y qué hacer cuando una cifra del Inicio no coincide con el detalle del módulo.',
    '<p>Si un bloque del Inicio no aparece o una cifra no coincide con lo que esperas, revisa estos puntos antes de reportar un problema.</p>
<h3>Los bloques dependen de tus permisos</h3>
<ul>
<li><strong>Ventas de hoy y tendencia semanal:</strong> requieren acceso al módulo de ventas y el permiso <em>Inicio: Ver ventas del día y semanal</em>.</li>
<li><strong>Vencimientos:</strong> requieren acceso al módulo de ventas y el permiso <em>Inicio: Ver panel de ventas apartadas</em>.</li>
<li><strong>Entregas:</strong> requieren acceso al módulo de ventas y el permiso <em>Inicio: Ver panel de pedidos</em>.</li>
<li><strong>Por cobrar:</strong> requiere acceso al módulo de clientes y el permiso <em>Inicio: Ver panel de saldos por cobrar</em>.</li>
<li><strong>Clientes recientes y clientes frecuentes:</strong> requieren acceso al módulo de clientes.</li>
<li><strong>Top 5 del mes y baja rotación:</strong> requieren acceso al módulo de productos.</li>
<li><strong>Estado de almacén:</strong> requiere acceso al módulo de productos y el permiso <em>Inicio: Ver detalles de inventario</em>.</li>
<li><strong>Cajas registradoras:</strong> requiere el permiso para gestionar cajas registradoras.</li>
<li><strong>Órdenes de servicio:</strong> requieren acceso al módulo de órdenes de servicio.</li>
<li><strong>Tesorería:</strong> muestra las cuentas que tienes asignadas. Si no ves una cuenta, pide que te la asignen.</li>
</ul>
<p>Si falta un bloque, pide a un administrador que revise tu rol y tus permisos.</p>
<h3>Todos los datos son de tu sucursal</h3>
<p>El Inicio toma la información de la sucursal en la que tienes sesión. Si el negocio tiene varias sucursales, cambia de sucursal y revisa de nuevo. Una venta, un cliente o un producto de otra sucursal no aparecerá en este panel.</p>
<h3>Revisa el periodo de cada indicador</h3>
<ul>
<li><strong>Ventas de hoy:</strong> solo las ventas de hoy, sin canceladas ni cambiadas.</li>
<li><strong>Tendencia semanal:</strong> la semana actual, de lunes a domingo, sin canceladas ni cambiadas.</li>
<li><strong>Vencimientos:</strong> apartados y créditos con fecha de vencimiento hasta 3 días adelante, incluidos los vencidos.</li>
<li><strong>Entregas:</strong> pedidos por entregar con fecha de entrega hasta 3 días adelante, incluidos los atrasados.</li>
<li><strong>Por cobrar:</strong> deuda acumulada de los clientes, sin periodo.</li>
<li><strong>Top 5 del mes:</strong> unidades vendidas en el mes en curso, sin ventas canceladas.</li>
<li><strong>Baja rotación:</strong> productos sin venta en los últimos 15 días o nunca vendidos, sin ventas canceladas ni devueltas.</li>
<li><strong>Clientes frecuentes:</strong> movimientos registrados en el mes en curso.</li>
<li><strong>Estado de almacén:</strong> existencias actuales de la sucursal.</li>
</ul>
<h3>Revisa los criterios de exclusión</h3>
<ul>
<li>Las ventas canceladas no se suman a las ventas del día ni a la tendencia semanal.</li>
<li>Las ventas cambiadas no se suman a las ventas del día ni a la tendencia semanal.</li>
<li>El top 5 no incluye ventas canceladas.</li>
<li>La baja rotación no toma en cuenta ventas canceladas ni devueltas.</li>
<li>Los vencimientos solo incluyen registros con fecha de vencimiento registrada.</li>
<li>Las entregas solo incluyen pedidos con fecha de entrega y estado por entregar.</li>
<li>Las cajas solo cuentan si están activas.</li>
</ul>
<h3>Si la cifra sigue sin coincidir</h3>
<ol>
<li>Confirma la sucursal y el periodo del indicador.</li>
<li>Abre el módulo correspondiente y revisa el detalle. Por ejemplo, Ventas para el historial de ventas o Clientes para el estado de cuenta.</li>
<li>Verifica si hubo cancelaciones, devoluciones, cambios o pagos recientes.</li>
<li>Si el detalle del módulo coincide con tu expectativa y el Inicio no, reporta el caso con la sucursal, la fecha y una captura de ambos valores.</li>
</ol>
<h3>No veo ningún bloque</h3>
<p>Si el Inicio muestra un mensaje de bienvenida en lugar de los indicadores, significa que tu usuario no tiene permisos para los bloques disponibles o que la sucursal aún no tiene datos. Revisa tus permisos con un administrador y confirma que estás en la sucursal correcta.</p>',
    90, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'dashboard'
      AND title = 'Por qué no veo todos los bloques o las cifras no coinciden'
);

-- ----------------------------------------------------------------------------
-- 10. Know your recent and frequent customers
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'dashboard',
    'Conocer a tus clientes recientes y frecuentes',
    'Usa las listas de clientes recientes y frecuentes para dar la bienvenida, completar datos de contacto, ofrecer recompra y detectar oportunidades de fidelización.',
    '<p>El Inicio incluye dos listas de clientes que te ayudan a dar seguimiento comercial: los clientes recientes y los clientes frecuentes.</p>
<h3>Clientes recientes</h3>
<ul>
<li>Muestra los últimos cinco clientes registrados en tu sucursal.</li>
<li>Se ordenan del más nuevo al más antiguo.</li>
<li>Al hacer clic en un cliente abres su perfil.</li>
</ul>
<h3>Qué hacer con los clientes recientes</h3>
<ol>
<li>Revisa que el nombre y el teléfono estén completos.</li>
<li>Da la bienvenida y confirma el medio de contacto preferido.</li>
<li>Registra una nota con el motivo de su primera compra.</li>
<li>Si aplica, explica las condiciones de crédito y los programas de lealtad.</li>
</ol>
<h3>Clientes frecuentes</h3>
<ul>
<li>Muestra los cinco clientes con más movimientos registrados en el mes en curso en tu sucursal.</li>
<li>Se ordenan por cantidad de movimientos, no por monto gastado.</li>
<li>Un cliente con muchas compras pequeñas puede aparecer arriba de uno con una sola compra grande.</li>
<li>Al hacer clic en un cliente abres su perfil.</li>
</ul>
<h3>Qué hacer con los clientes frecuentes</h3>
<ol>
<li>Revisa su historial de compras y sus productos preferidos.</li>
<li>Ofrece reposiciones o productos relacionados.</li>
<li>Invítalos a programas de lealtad o promociones exclusivas.</li>
<li>Consulta su saldo antes de ofrecer crédito.</li>
<li>Da seguimiento si notas que dejaron de comprar.</li>
</ol>
<h3>Recomendaciones</h3>
<ul>
<li>Completa el teléfono y el correo de cada cliente para poder contactarlo.</li>
<li>Usa las notas del cliente para recordar preferencias y acuerdos.</li>
<li>Revisa ambas listas cada semana para detectar oportunidades de recompra.</li>
<li>Recuerda que la frecuencia mide número de movimientos, no utilidad ni monto.</li>
</ul>',
    100, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'dashboard'
      AND title = 'Conocer a tus clientes recientes y frecuentes'
);









