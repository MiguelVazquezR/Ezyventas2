-- ============================================================================
-- Help center - Guides for the Historial de ventas (transactions) module
--
-- Inserts 10 published guides for the "transactions" module of the
-- help_center_articles table. The guides automatically appear in the
-- Historial de ventas card of the help center.
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
-- 1. How to search and filter sales in the history
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'transactions',
    'Cómo buscar y filtrar ventas en el historial',
    'Localiza cualquier venta por folio, cliente o invitado, filtra por estatus y rango de fechas, y abre su detalle completo.',
    '<p>El Historial de ventas concentra las operaciones de tu sucursal: ventas de mostrador, pedidos, apartados, ventas a crédito, operaciones de la tienda en línea y cargos generados desde otros módulos. Desde aquí puedes localizar una venta, revisar su estado y entrar a su detalle completo.</p>
<h3>Antes de empezar</h3>
<ul>
<li>Necesitas el permiso para consultar el historial de ventas. Si no ves la opción en el menú, pide a un administrador que la active en tu rol.</li>
<li>La lista muestra únicamente las ventas de la sucursal a la que pertenece tu usuario.</li>
<li>Los abonos a saldo del cliente no aparecen como ventas: se consultan desde el estado de cuenta del cliente.</li>
</ul>
<h3>Buscar por folio, cliente o invitado</h3>
<ol>
<li>Entra a Historial de ventas.</li>
<li>Escribe en el buscador "Buscar por folio o cliente...". La lista se actualiza automáticamente mientras escribes.</li>
<li>Puedes buscar por número de folio, por el nombre del cliente registrado o por el nombre del contacto de una venta sin cliente (invitado).</li>
<li>Borra el texto del buscador para regresar a la lista completa.</li>
</ol>
<h3>Filtrar por estatus y por fechas</h3>
<ol>
<li>Usa el selector "Filtrar por estatus" y elige el estado que buscas: completado, pendiente, apartado, por entregar, en ruta, entregado por pagar, cambiado, cancelado o reembolsado.</li>
<li>Usa "Rango de fechas" para elegir el periodo. El filtro se aplica cuando seleccionas la fecha inicial y la final.</li>
<li>Combina la búsqueda, el estatus y el rango de fechas: los filtros se suman entre sí.</li>
<li>Pulsa el botón de limpiar del selector de estatus para quitar ese filtro.</li>
</ol>
<p>El rango de fechas se calcula sobre la fecha de la venta, no sobre la fecha de los pagos. Si una venta se registró con una fecha anterior por una corrección, aparecerá en el periodo de esa fecha.</p>
<h3>Ordenar y navegar la lista</h3>
<ul>
<li>Pulsa los encabezados Folio, Fecha y Hora, Cliente, Canal, Total Venta, Estatus o Cajero para ordenar de forma ascendente o descendente.</li>
<li>Usa el paginador inferior para cambiar de página o la cantidad de filas por página.</li>
</ul>
<h3>Abrir una venta</h3>
<p>Al pulsar una fila se abre el detalle según tu preferencia de usuario: una vista lateral con los datos principales o directamente la pantalla completa de la venta. También puedes usar el menú de tres puntos y elegir "Ver detalle".</p>
<h3>Recomendaciones</h3>
<ul>
<li>Para localizar un cobro, busca primero por folio si lo tienes en el ticket.</li>
<li>Si no encuentras una venta reciente, revisa que no tengas un rango de fechas o un estatus aplicado.</li>
<li>Las ventas canceladas y reembolsadas siguen visibles para auditoría. No las elimines si solo quieres anularlas.</li>
</ul>',
    10, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'transactions'
      AND title = 'Cómo buscar y filtrar ventas en el historial'
);

-- ----------------------------------------------------------------------------
-- 2. How to read the detail of a sale
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'transactions',
    'Cómo leer el detalle de una venta',
    'Interpreta cada panel del detalle: información operativa, conceptos, resumen financiero e historial de pagos.',
    '<p>El detalle de una venta reúne toda la información de la operación y concentra las acciones disponibles según el estatus de la venta y tus permisos. Se abre desde el historial al pulsar una fila o desde el menú de tres puntos con la opción "Ver detalle".</p>
<h3>Vista lateral rápida</h3>
<p>Al pulsar una fila del historial se abre un panel lateral con el folio, el estatus, la fecha y hora, el cliente, el cajero, los artículos registrados, el historial de pagos y los totales: total de venta, abonado y resta por cobrar. El botón "Ver detalles completos" abre la pantalla completa.</p>
<h3>Información de la venta</h3>
<ul>
<li><strong>Estatus operativo:</strong> indica en qué momento del proceso está la venta o el pedido.</li>
<li><strong>Entrega programada:</strong> aparece cuando la venta es un pedido. Muestra la fecha de entrega y la dirección de envío. En pedidos con estatus "por entregar" incluye el botón "Reprogramar".</li>
<li><strong>Datos de contacto (invitado):</strong> se muestra cuando la venta no tiene cliente registrado. Al pulsar el teléfono puedes elegir "Llamar" o "Mandar WhatsApp".</li>
<li><strong>Vencimiento del apartado o del crédito:</strong> muestra la fecha límite, los días restantes y el botón para extenderla.</li>
</ul>
<h3>Conceptos</h3>
<p>La tabla de conceptos lista cada artículo o servicio vendido con su SKU, descripción, cantidad, precio unitario y total de la línea. Si el artículo existe en el catálogo, la descripción es un enlace al producto o servicio. Cuando hubo descuento, el precio anterior aparece tachado y se muestra el ahorro por unidad.</p>
<h3>Resumen financiero</h3>
<ul>
<li><strong>Subtotal:</strong> suma de los conceptos antes de descuentos e impuestos.</li>
<li><strong>Envío:</strong> costo de entrega cuando el pedido lo incluye.</li>
<li><strong>Descuento:</strong> total de descuentos aplicados a la venta.</li>
<li><strong>Total:</strong> monto final de la venta.</li>
<li><strong>Abonado:</strong> suma de los pagos registrados.</li>
<li><strong>Resta por pagar:</strong> diferencia entre el total y lo abonado. Si hay saldo pendiente y tienes permiso, aparece el botón "Abonar a esta cuenta".</li>
</ul>
<h3>Pagos realizados</h3>
<p>Cada pago muestra el método (efectivo, tarjeta, transferencia, saldo del cliente o intercambio), la fecha, el monto, la cuenta bancaria cuando aplica y las notas. Desde aquí puedes editar o eliminar un pago si tienes el permiso correspondiente. Los reembolsos por transferencia se registran como un pago negativo para que el estado de cuenta del banco cuadre.</p>
<h3>Acciones disponibles</h3>
<p>El botón "Opciones" agrupa las acciones del detalle: "Abonar / Liquidar", "Intercambiar producto" o "Modificar apartado", "Extender Vencimiento", "Facturar venta" o "Ver factura", "Imprimir ticket" y "Cancelar / Devolver". Cada acción aparece solo cuando el estatus de la venta y tus permisos lo permiten.</p>
<h3>Recomendaciones</h3>
<ul>
<li>Antes de cancelar, revisa el panel de pagos para saber cuánto dinero hay que devolver.</li>
<li>Si la resta por pagar no coincide con lo que esperabas, revisa el historial de pagos antes de registrar un abono nuevo.</li>
<li>Usa la vista lateral para consultas rápidas y la pantalla completa cuando necesites ejecutar una acción.</li>
</ul>',
    20, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'transactions'
      AND title = 'Cómo leer el detalle de una venta'
);

-- ----------------------------------------------------------------------------
-- 3. What each sale status means
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'transactions',
    'Qué significa cada estatus de una venta',
    'Distingue los estatus de venta y de pedido, qué implica cada uno para el dinero y el inventario, y cómo filtrarlos.',
    '<p>El estatus resume en una palabra qué pasa con el dinero, el inventario y la deuda del cliente. Aparece como etiqueta de color en el historial y en el detalle de la venta, y también se usa como filtro.</p>
<h3>Estatus de venta</h3>
<ul>
<li><strong>Completado:</strong> la venta está pagada en su totalidad. No queda saldo pendiente y el dinero ya se registró en caja o en la cuenta bancaria correspondiente.</li>
<li><strong>Pendiente:</strong> es una venta a crédito con saldo por cobrar. La deuda queda registrada en el estado de cuenta del cliente y la venta tiene fecha de vencimiento.</li>
<li><strong>Apartado:</strong> reserva con abono parcial. El inventario queda reservado, el cliente debe el resto y existe una fecha límite para liquidar.</li>
<li><strong>Cambiado:</strong> la venta se reemplazó por un cambio de producto. Se conserva como historial de la operación original y la nueva venta se registra con su propio folio.</li>
<li><strong>Cancelado:</strong> la venta se anuló. Puede ser una cancelación sin pagos registrados o una cancelación con penalización, donde el negocio retiene el dinero recibido. El inventario regresa al catálogo.</li>
<li><strong>Reembolsado:</strong> la venta se anuló y el dinero se devolvió al cliente, ya sea en efectivo, por transferencia o abonado a su saldo a favor.</li>
</ul>
<h3>Estatus logísticos de un pedido</h3>
<ul>
<li><strong>Por entregar:</strong> el pedido se recibió y el inventario está reservado, pero todavía no sale del negocio. Desde este estatus puedes reprogramar la entrega.</li>
<li><strong>En ruta:</strong> el pedido ya va en manos del repartidor.</li>
<li><strong>Entregado por pagar:</strong> el pedido se entregó, pero el cliente aún no lo liquida. Se usa en crédito contra entrega.</li>
</ul>
<h3>Canales de venta</h3>
<p>La columna Canal indica el origen de la operación: punto de venta, tienda en línea, orden de servicio, cotización, manual o WhatsApp. Los abonos a saldo del cliente no aparecen en el historial porque no son ventas.</p>
<h3>Cómo usar el filtro de estatus</h3>
<ol>
<li>Entra a Historial de ventas.</li>
<li>Abre el selector "Filtrar por estatus".</li>
<li>Elige el estatus que necesitas revisar. La lista se actualiza al instante.</li>
<li>Combínalo con el rango de fechas para revisar, por ejemplo, los apartados que vencen este mes o los pedidos por entregar de la semana.</li>
<li>Usa la opción de limpiar del selector para regresar a todos los estatus.</li>
</ol>
<h3>Recomendaciones</h3>
<ul>
<li>No confundas cancelado con reembolsado al cuadrar la caja: en el primer caso el dinero puede haberse quedado en el negocio; en el segundo salió del sistema.</li>
<li>Una venta cambiado no debe cancelarse de nuevo: el cambio ya generó una venta nueva y el historial conserva ambas operaciones.</li>
<li>Revisa con frecuencia los estatus pendiente y apartado para contactar a los clientes antes del vencimiento.</li>
</ul>',
    30, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'transactions'
      AND title = 'Qué significa cada estatus de una venta'
);

-- ----------------------------------------------------------------------------
-- 4. How to register a payment or settle a sale
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'transactions',
    'Cómo registrar un abono o liquidar una venta',
    'Cobra el saldo pendiente de una venta a crédito o un apartado, con uno o varios métodos de pago y ticket de abono.',
    '<p>Cuando una venta queda con saldo pendiente, ya sea un crédito o un apartado, el cliente puede abonar en cualquier momento. Cada abono se registra contra la venta, reduce la deuda y queda en el historial de pagos.</p>
<h3>Antes de empezar</h3>
<ul>
<li>La venta debe tener resta por pagar y no estar cancelada ni reembolsada.</li>
<li>Necesitas el permiso para agregar pagos. Si el botón no aparece, pide a un administrador que lo active en tu rol.</li>
<li>Debe existir una sesión de caja activa. Si no la tienes, el sistema te ofrece unirte a una sesión abierta o iniciar un turno antes de cobrar.</li>
</ul>
<h3>Registrar el abono</h3>
<ol>
<li>Abre la venta desde Historial de ventas.</li>
<li>Pulsa el botón "Abonar a esta cuenta" en el resumen financiero o elige "Opciones" y luego "Abonar / Liquidar".</li>
<li>Si no hay caja abierta, elige la sesión a la que te vas a unir o abre un turno. Al terminar, el procesador de pagos se abre automáticamente.</li>
<li>Captura el monto del abono y el método de pago: efectivo, tarjeta o transferencia. Para transferencia o tarjeta selecciona la cuenta bancaria cuando corresponda.</li>
<li>Si el cliente tiene saldo a favor, el sistema te permite aplicarlo para reducir el saldo pendiente.</li>
<li>Puedes combinar varios métodos en el mismo cobro cuando el cliente paga una parte en efectivo y otra con tarjeta, por ejemplo.</li>
<li>Confirma el pago. Se abre la ventana de impresión con el ticket de abono, o con el ticket de pedido si la venta es un pedido, para imprimirlo o enviarlo al cliente.</li>
</ol>
<h3>Qué cambia después del abono</h3>
<ul>
<li>Los importes abonado y resta por pagar se recalculan en el resumen financiero.</li>
<li>Si el saldo queda en cero, la venta cambia a estatus completado.</li>
<li>El dinero entra a la caja del turno o a la cuenta bancaria elegida, y la deuda del cliente se reduce en su estado de cuenta.</li>
<li>El pago aparece en el panel "Pagos realizados" con su fecha, método y notas.</li>
</ul>
<h3>Cuándo no se puede abonar</h3>
<ul>
<li>Cuando la venta está cancelada o reembolsada.</li>
<li>Cuando no hay saldo pendiente: la resta por pagar ya está en cero.</li>
<li>Cuando no hay una sesión de caja activa ni forma de abrir una.</li>
</ul>
<h3>Recomendaciones</h3>
<ul>
<li>Verifica el monto antes de confirmar: un abono mal capturado se puede corregir desde el panel de pagos, pero es mejor evitarlo.</li>
<li>Si el cobro es en efectivo, cuenta el dinero antes de confirmar y guárdalo en la caja del turno.</li>
<li>Usa las notas del pago para dejar constancia de acuerdos con el cliente, por ejemplo un pago parcial acordado por teléfono.</li>
<li>Para apartados, revisa la fecha de vencimiento después de abonar y extiéndela si el cliente necesita más tiempo.</li>
</ul>',
    40, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'transactions'
      AND title = 'Cómo registrar un abono o liquidar una venta'
);

-- ----------------------------------------------------------------------------
-- 5. How to edit or delete a payment
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'transactions',
    'Cómo editar o eliminar un pago de una venta',
    'Corrige el monto, el método o la cuenta de un pago, o elimínalo, conociendo el efecto en caja, banco y saldo del cliente.',
    '<p>Los pagos de una venta se pueden corregir desde el panel "Pagos realizados" del detalle. Cada cambio ajusta el banco, el saldo del cliente y el estatus de la venta, por lo que conviene revisar dos veces antes de confirmar.</p>
<h3>Antes de empezar</h3>
<ul>
<li>Necesitas el permiso para editar pagos. Si los botones no aparecen, pide a un administrador que los active en tu rol.</li>
<li>Ten a la mano el comprobante original: monto, método, cuenta bancaria y fecha.</li>
<li>Si la venta ya tiene factura, revisa con el área de facturación antes de modificar pagos, porque el cambio puede requerir un complemento de pago.</li>
</ul>
<h3>Editar un pago</h3>
<ol>
<li>Abre la venta y ubica el panel "Pagos realizados".</li>
<li>Pulsa el ícono de lápiz del pago que quieres corregir.</li>
<li>Ajusta el monto, el método de pago (efectivo, tarjeta, transferencia o saldo de cliente), la cuenta bancaria o las notas.</li>
<li>Confirma la actualización.</li>
</ol>
<p>Al guardar, el sistema revierte el efecto anterior del pago y aplica el nuevo: descuenta el monto anterior de la cuenta bancaria previa, lo suma a la cuenta nueva, ajusta el saldo del cliente y recalcula el estatus de la venta. Si con el nuevo monto la venta queda pagada, pasa a completado; si deja de estar pagada, regresa a pendiente o apartado según tenga o no fecha de vencimiento.</p>
<h3>Eliminar un pago</h3>
<ol>
<li>Pulsa el ícono de basura del pago.</li>
<li>Lee la advertencia y confirma la eliminación. La acción no se puede deshacer.</li>
</ol>
<p>Al eliminar, el sistema regresa el dinero a la cuenta bancaria, revierte el saldo del cliente, quita el movimiento de efectivo de la caja del turno cuando el pago fue en efectivo y vuelve a calcular el estatus de la venta. Si el total pagado queda por debajo del total de la venta, la venta regresa a pendiente o apartado.</p>
<h3>Qué tener en cuenta</h3>
<ul>
<li><strong>El pago conserva su fecha original:</strong> la edición no cambia la fecha ni la sesión de caja en la que se registró.</li>
<li><strong>El efectivo ya cobrado no se reescribe al editar:</strong> si cambias el monto de un pago en efectivo, el movimiento de caja del turno sigue igual. Revisa el corte y, si es necesario, registra el ajuste correspondiente en caja.</li>
<li><strong>Eliminar sí revierte la caja:</strong> cuando el pago era en efectivo y pertenece a un turno, se quita el ingreso para que el corte no quede inflado.</li>
<li><strong>El estado de cuenta del cliente cambia:</strong> un abono eliminado vuelve a generar deuda, y un pago hecho con saldo a favor devuelve el saldo al cliente.</li>
</ul>
<h3>Cuándo conviene cancelar en lugar de eliminar</h3>
<ul>
<li>Si el cliente devuelve la compra completa, usa "Cancelar / Devolver" para dejar rastro del reembolso o de la penalización.</li>
<li>Si solo fue un error de captura en un pago, edítalo o elimínalo y vuelve a registrarlo.</li>
<li>Nunca elimines pagos para cuadrar un corte: primero identifica si el error está en el pago, en la caja o en el banco.</li>
</ul>',
    50, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'transactions'
      AND title = 'Cómo editar o eliminar un pago de una venta'
);

-- ----------------------------------------------------------------------------
-- 6. How to cancel a sale with refund or penalty
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'transactions',
    'Cómo anular una venta con reembolso o penalización',
    'Cancela una venta, decide si el dinero se devuelve en efectivo, por transferencia o a saldo, o si se retiene como penalización.',
    '<p>Anular una venta devuelve el inventario, cancela la deuda del cliente y conserva la operación en el historial con estatus cancelado o reembolsado. El procedimiento cambia según si la venta tiene pagos registrados.</p>
<h3>Antes de empezar</h3>
<ul>
<li>Necesitas permiso para cancelar o reembolsar ventas. Si la opción no aparece, pide a un administrador que la active en tu rol.</li>
<li>Revisa el panel "Pagos realizados" para saber cuánto dinero hay que devolver.</li>
<li>Define con el cliente si el dinero se devuelve o se retiene como penalización.</li>
<li>Si la venta ya tiene factura, coordina con el área de facturación antes de anularla.</li>
</ul>
<h3>Venta sin pagos registrados</h3>
<ol>
<li>Abre la venta y elige "Opciones" y luego "Cancelar / Devolver".</li>
<li>Confirma en el aviso "Confirmar cancelación". El mensaje cambia si es un apartado, un pedido o una venta a crédito.</li>
<li>La venta queda cancelada, el inventario regresa al catálogo (o se libera la reserva si era un apartado o un pedido) y se anula la deuda del cliente cuando corresponde.</li>
</ol>
<h3>Venta con pagos registrados</h3>
<ol>
<li>Abre la venta y elige "Opciones" y luego "Cancelar / Devolver".</li>
<li>En la ventana "Anular transacción" revisa el total pagado que se muestra en el aviso de información de pagos.</li>
<li>Elige "Devolver al cliente (Reembolso)" o "Cobrar como penalización".</li>
<li>Si elegiste reembolso, selecciona cómo se devuelve el dinero.</li>
<li>Confirma con "Confirmar Devolución" o "Confirmar Penalización".</li>
</ol>
<h3>Formas de reembolso</h3>
<ul>
<li><strong>Entregar efectivo de caja:</strong> requiere una sesión de caja abierta. El dinero sale del turno como un movimiento de egreso.</li>
<li><strong>Transferencia bancaria:</strong> selecciona la cuenta bancaria. El saldo de la cuenta disminuye y el reembolso queda como un pago negativo en el historial.</li>
<li><strong>Abonar a su saldo a favor:</strong> solo está disponible si la venta tiene cliente registrado. El monto se suma a su saldo para futuras compras.</li>
</ul>
<h3>Qué cambia después</h3>
<ul>
<li>La venta queda con estatus reembolsado si se devolvió el dinero, o cancelado si se aplicó una penalización.</li>
<li>El inventario regresa o se libera según el tipo de operación.</li>
<li>La deuda del cliente se cancela y, si hubo reembolso a saldo, se genera el movimiento correspondiente.</li>
<li>El reembolso queda registrado con su forma de pago y la referencia de la venta.</li>
</ul>
<h3>Recomendaciones</h3>
<ul>
<li>No uses la eliminación de la venta para sustituir una anulación: eliminar borra el registro y no deja rastro del reembolso.</li>
<li>Si el cliente cambia un producto por otro, usa "Intercambiar producto" en lugar de cancelar y volver a vender.</li>
<li>Documenta el motivo de la penalización con el cliente antes de confirmar.</li>
</ul>',
    60, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'transactions'
      AND title = 'Cómo anular una venta con reembolso o penalización'
);

-- ----------------------------------------------------------------------------
-- 7. How to exchange a product for another
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'transactions',
    'Cómo intercambiar un producto por otro',
    'Devuelve artículos de una venta, agrega los nuevos y cobra o devuelve la diferencia sin cancelar la venta original.',
    '<p>El cambio de producto recibe los artículos que el cliente devuelve y entrega los nuevos en una sola operación. El inventario se ajusta y la diferencia de dinero se cobra o se devuelve, sin cancelar la venta original.</p>
<h3>Antes de empezar</h3>
<ul>
<li>Necesitas el permiso para intercambiar productos. Si la opción no aparece, pide a un administrador que lo active en tu rol.</li>
<li>Debe haber una sesión de caja abierta, porque el cambio se registra en el turno actual.</li>
<li>La venta no debe estar cancelada ni reembolsada.</li>
<li>Si la venta es un apartado, la opción del menú se llama "Modificar Apartado" y sirve para ajustar los artículos apartados antes de liquidar.</li>
</ul>
<h3>Pasos del cambio</h3>
<ol>
<li>Abre la venta y elige "Opciones" y luego "Intercambiar producto".</li>
<li>En el paso "Inicio" revisa el resumen de la operación y continúa.</li>
<li>En el paso "Devolución" indica la cantidad que el cliente devuelve de cada artículo. No puedes devolver más de lo vendido. Abajo se muestra el valor total de la devolución.</li>
<li>En el paso "Nuevos" busca y agrega los productos nuevos. Ajusta cantidad y precio, y si el producto tiene variantes elige la correcta.</li>
<li>En el paso "Resumen y pago" revisa la diferencia. Si los productos nuevos cuestan más, cubre la diferencia con efectivo, tarjeta, transferencia o crédito, y usa el saldo a favor del cliente si lo tienes disponible.</li>
<li>Si los productos nuevos cuestan menos, el sistema calcula un excedente a favor. Puedes asignarlo a otras deudas del cliente, dejarlo como saldo a favor o entregarlo en efectivo, según las opciones de la ventana.</li>
<li>Confirma el cambio. Se abre la venta nueva con su propio folio y un mensaje de confirmación.</li>
</ol>
<h3>Qué cambia después</h3>
<ul>
<li>La venta original queda con estatus cambiado y se conserva en el historial para auditoría.</li>
<li>Se crea una venta nueva con los artículos conservados y los nuevos. El inventario de los artículos devueltos regresa y el de los nuevos se descuenta.</li>
<li>Los pagos y adeudos se registran en la venta nueva. Si hubo excedente, se aplica a deudas, a saldo a favor o se entrega en efectivo según lo que hayas elegido.</li>
</ul>
<h3>Recomendaciones</h3>
<ul>
<li>Revisa el estado físico de los productos devueltos antes de confirmar el cambio.</li>
<li>Si el cliente cambia varias veces el mismo producto, revisa el historial para no duplicar operaciones.</li>
<li>Imprime el ticket de la venta nueva si el cliente necesita comprobante del cambio.</li>
<li>Para devolver dinero sin entregar productos nuevos, usa "Cancelar / Devolver" en lugar de un cambio.</li>
</ul>',
    70, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'transactions'
      AND title = 'Cómo intercambiar un producto por otro'
);

-- ----------------------------------------------------------------------------
-- 8. How to extend the expiration date of a layaway or credit sale
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'transactions',
    'Cómo extender el vencimiento de un apartado o crédito',
    'Cambia la fecha límite de pago de un apartado o de una venta a crédito sin alterar el saldo ni los pagos registrados.',
    '<p>Los apartados y las ventas a crédito tienen una fecha de vencimiento que se muestra en el detalle de la venta. Si el cliente necesita más tiempo, puedes mover esa fecha sin registrar un pago ni modificar el saldo pendiente.</p>
<h3>Cuándo aplica</h3>
<ul>
<li>En ventas con estatus apartado o pendiente, es decir, apartados activos y ventas a crédito con saldo.</li>
<li>No está disponible para ventas canceladas, reembolsadas, cambiadas ni ventas ya liquidadas.</li>
</ul>
<h3>Cómo extender la fecha</h3>
<ol>
<li>Abre la venta desde Historial de ventas.</li>
<li>En la tarjeta de vencimiento pulsa el botón para extenderla, o elige "Opciones" y luego "Extender Vencimiento".</li>
<li>En la ventana "Extender fecha de vencimiento" elige la "Nueva fecha" en el calendario. Debe ser posterior al día de hoy.</li>
<li>Pulsa "Guardar fecha". El sistema confirma con el mensaje "Fecha de vencimiento actualizada correctamente".</li>
</ol>
<h3>Qué cambia y qué no</h3>
<ul>
<li>Cambia únicamente la fecha límite. El saldo pendiente, los pagos registrados y el estatus de la venta se mantienen igual.</li>
<li>No genera intereses, recargos ni pagos automáticos.</li>
<li>La nueva fecha se refleja en la tarjeta de vencimiento y en los días restantes del detalle.</li>
</ul>
<h3>Recomendaciones</h3>
<ul>
<li>Aplica la misma política de prórroga a todos los clientes para evitar diferencias.</li>
<li>Extiende la fecha antes de que venza: es más fácil dar seguimiento a un apartado vigente que a uno vencido.</li>
<li>Si además necesitas corregir la fecha de la venta, usa el lápiz junto a la fecha del encabezado y decide si mueves también el vencimiento.</li>
<li>Si el cliente ya no quiere el apartado, anúlalo en lugar de extenderlo de forma indefinida.</li>
</ul>',
    80, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'transactions'
      AND title = 'Cómo extender el vencimiento de un apartado o crédito'
);

-- ----------------------------------------------------------------------------
-- 9. How to reschedule the delivery of an order
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'transactions',
    'Cómo reprogramar la entrega de un pedido',
    'Cambia la fecha y la hora de entrega de un pedido pendiente de enviar, sin tocar los pagos ni el inventario reservado.',
    '<p>Los pedidos guardan una fecha y hora de entrega que el equipo usa para preparar y repartir. Si el cliente necesita otro día, puedes reprogramarla desde el detalle de la venta.</p>
<h3>Cuándo aplica</h3>
<ul>
<li>En pedidos con entrega programada y estatus por entregar. En ese estatus el detalle muestra el botón "Reprogramar" dentro de la tarjeta de entrega.</li>
<li>Cuando el pedido ya está en ruta, la entrega se coordina con el repartidor y no se reprograma desde este botón.</li>
</ul>
<h3>Cómo reprogramar la entrega</h3>
<ol>
<li>Abre el pedido desde Historial de ventas.</li>
<li>En el panel "Información de la venta" ubica la entrega programada y pulsa "Reprogramar".</li>
<li>En la ventana "Reprogramar entrega" elige la "Nueva fecha de entrega". Puedes seleccionar la hora en formato de 12 horas; la fecha no puede ser anterior a hoy.</li>
<li>Pulsa "Guardar fecha". El sistema confirma con el mensaje "Fecha de entrega reprogramada correctamente".</li>
</ol>
<h3>Qué cambia y qué no</h3>
<ul>
<li>Cambia la fecha y hora de entrega del pedido y se actualiza en la tarjeta de entrega del detalle.</li>
<li>El estatus, los pagos, el saldo pendiente y el inventario reservado no se modifican.</li>
<li>El pedido sigue apareciendo en el historial con el mismo folio.</li>
</ul>
<h3>Recomendaciones</h3>
<ul>
<li>Confirma la nueva fecha con el cliente antes de guardarla y avísale cualquier cambio de último momento.</li>
<li>Usa el teléfono de contacto del pedido para llamar o enviar un mensaje por WhatsApp y dejar constancia del acuerdo.</li>
<li>Revisa la agenda de entregas del día para no concentrar demasiados pedidos en el mismo horario.</li>
<li>Si el cliente ya no quiere el pedido, anúlalo con "Cancelar / Devolver" en lugar de reprogramarlo varias veces.</li>
</ul>',
    90, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'transactions'
      AND title = 'Cómo reprogramar la entrega de un pedido'
);

-- ----------------------------------------------------------------------------
-- 10. How to correct the date of a sale
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'transactions',
    'Cómo corregir la fecha de una venta',
    'Cambia la fecha y hora de una venta capturada por error y decide si el vencimiento de un apartado o crédito se mueve con ella.',
    '<p>La fecha de la venta determina en qué día aparecen los reportes, los cortes y los filtros del historial. Si una venta se capturó con la fecha equivocada, puedes corregirla desde el encabezado del detalle.</p>
<h3>Antes de empezar</h3>
<ul>
<li>Necesitas acceso al detalle de la venta.</li>
<li>La corrección afecta los reportes y cortes del día: la ventana lo advierte con el mensaje "Cambiar la fecha afectará los reportes y cortes de caja de ese día".</li>
<li>Si la venta ya está facturada, revisa con el área de facturación antes de mover la fecha.</li>
</ul>
<h3>Cómo corregirla</h3>
<ol>
<li>Abre la venta desde Historial de ventas.</li>
<li>En el encabezado, pulsa el lápiz que está junto a la fecha ("Editar fecha").</li>
<li>En la ventana "Editar fecha de venta" elige la "Nueva fecha y hora".</li>
<li>Si la venta es un apartado o un crédito, elige qué hacer con el vencimiento: "Moverlo a la nueva fecha", que lo pasa a la nueva fecha más 30 días, o "Conservar el vencimiento actual".</li>
<li>Pulsa "Actualizar". El sistema confirma con el mensaje "Fecha de transacción actualizada correctamente".</li>
</ol>
<h3>Qué cambia y qué no</h3>
<ul>
<li>Cambia la fecha con la que la venta aparece en el historial, en los filtros y en los reportes.</li>
<li>Si elegiste mover el vencimiento, la fecha límite se recalcula a la nueva fecha de la venta más 30 días.</li>
<li>Los pagos conservan su fecha y su sesión de caja: el efectivo no se mueve de turno.</li>
<li>El estatus, el inventario y los importes de la venta no se modifican.</li>
</ul>
<h3>Recomendaciones</h3>
<ul>
<li>No uses esta opción para corregir un corte de caja: para eso revisa los movimientos de la sesión correspondiente.</li>
<li>Si decides conservar el vencimiento y la nueva fecha de venta queda después de la fecha límite, el sistema te avisa; en ese caso conviene mover el vencimiento o extenderlo después.</li>
<li>Registra el motivo de la corrección en tus notas internas para que el equipo entienda el cambio durante una auditoría.</li>
<li>Verifica la nueva fecha en el encabezado antes de cerrar la ventana.</li>
</ul>',
    100, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'transactions'
      AND title = 'Cómo corregir la fecha de una venta'
);
