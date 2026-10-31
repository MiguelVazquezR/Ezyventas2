-- ============================================================================
-- Help center - Guides for the Punto de venta (pos) module
--
-- Inserts 12 published guides for the "pos" module of the
-- help_center_articles table. The guides automatically appear in the
-- Punto de venta card of the help center.
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
-- 1. How to open and close the POS cash register
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'pos',
    'Cómo abrir y cerrar la caja del punto de venta',
    'Aprende a iniciar un turno con el fondo de caja correcto, unirte a una sesión abierta y hacer el corte final con arqueo de efectivo.',
    '<p>El punto de venta solo permite registrar ventas, pedidos y apartados cuando hay una sesión de caja activa. Abrir la caja significa declarar con cuánto efectivo y saldo bancario empiezas; cerrarla significa contar el dinero físico y compararlo con lo que el sistema espera.</p>
<h3>Antes de empezar</h3>
<ul>
<li>La sucursal debe tener al menos una caja registradora creada en el módulo Cajas. Si no aparece ninguna, usa el botón "Crear nueva caja" que se muestra en la pantalla de bloqueo.</li>
<li>Ten a la mano el fondo fijo de efectivo y los saldos de las cuentas bancarias que vas a declarar.</li>
</ul>
<h3>Abrir un turno nuevo</h3>
<ol>
<li>Entra a Punto de venta. Si no hay una sesión activa verás la tarjeta "Abrir sesión de caja".</li>
<li>Pulsa "Inicializar caja" para tomar una terminal libre.</li>
<li>En "Equipo asignado" selecciona la caja que vas a operar.</li>
<li>Captura el "Efectivo físico en caja" con el que inicias. Si el usuario tiene cuentas bancarias asignadas, captura también el saldo de cada una.</li>
<li>Pulsa "Iniciar turno". La barra superior mostrará el indicador "Caja:" con el nombre de la terminal.</li>
</ol>
<p>Estos saldos son de control: el sistema los usa para calcular el efectivo esperado al final del turno. No representan una venta ni un ingreso.</p>
<h3>Unirse a una sesión que ya está abierta</h3>
<ol>
<li>Pulsa "Unirse a sesión activa".</li>
<li>Elige la caja y pulsa "Unirme".</li>
</ol>
<p>Al unirte compartes la sesión y sus movimientos con el operador que la abrió; no se vuelven a declarar saldos iniciales. Todos los cobros que hagas quedan en la misma sesión.</p>
<h3>Consultar el estado del turno</h3>
<p>El botón con el icono de billetera, "Resumen de sesión", muestra el efectivo en caja, los cobros con tarjeta y los cobros por transferencia. El botón con el icono de reloj abre el historial de ventas de la sesión.</p>
<h3>Cerrar la caja</h3>
<ol>
<li>Pulsa el botón rojo de apagado, "Cerrar caja".</li>
<li>Si hay otros usuarios conectados, elige "Corte total de caja" para cerrar la sesión para todos o "Desconexión personal" para salir sin cerrar la caja.</li>
<li>En la advertencia pulsa "Proceder al corte".</li>
<li>Revisa el bloque "Movimientos en efectivo": apertura inicial, ingresos por ventas, ingresos manuales y retiros. Confirma el "Total del sistema".</li>
<li>Revisa el bloque "Ingresos digitales": cobros con tarjeta y por transferencia.</li>
<li>Captura el "Monto final físico en caja" contando el dinero real.</li>
<li>Si aparece una diferencia, revisa el recuadro "Descuadre / Diferencia" y explica el motivo en "Notas de arqueo".</li>
<li>Pulsa "Finalizar turno". La sesión se cierra y se desconecta a todos los usuarios.</li>
</ol>
<h3>Recomendaciones</h3>
<ul>
<li>Cuenta el efectivo antes de abrir y antes de cerrar. Un fondo mal declarado arrastra la diferencia a todo el turno.</li>
<li>No uses el cierre para corregir errores de captura: registra un ingreso o un retiro con su motivo y luego cierra.</li>
<li>Antes de finalizar, verifica que no queden carritos en espera con clientes que ya pagaron.</li>
<li>Si la diferencia no se explica, revisa los pagos en efectivo, los retiros manuales y el cambio entregado antes de firmar el corte.</li>
</ul>',
    10, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'pos'
      AND title = 'Cómo abrir y cerrar la caja del punto de venta'
);

-- ----------------------------------------------------------------------------
-- 2. How to record cash in and cash out movements
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'pos',
    'Cómo registrar entradas y salidas de efectivo',
    'Registra ingresos y retiros de efectivo con su motivo para que el corte de caja refleje el dinero real y las diferencias queden explicadas.',
    '<p>Durante el turno el efectivo de la caja puede cambiar por motivos que no son ventas: un fondo adicional, un pago a proveedor o un depósito al banco. Estos movimientos se registran por separado para que el corte de caja explique cada peso.</p>
<h3>Antes de empezar</h3>
<ul>
<li>Debes tener una sesión de caja activa. Si no la tienes, el menú de efectivo no está disponible.</li>
<li>Todo movimiento requiere un monto mayor que cero y un motivo escrito.</li>
<li>Los movimientos afectan solo el efectivo físico. Los cobros con tarjeta y transferencia se controlan en sus propios bloques.</li>
</ul>
<h3>Registrar un ingreso o un retiro</h3>
<ol>
<li>En la barra superior pulsa el botón de billetera, "Resumen de sesión".</li>
<li>Elige "Ingresar efectivo" o "Retirar efectivo" según corresponda.</li>
<li>Captura el "Monto a ingresar" o el "Monto a retirar".</li>
<li>Escribe la "Descripción / motivo". Sé concreto: cambio adicional, pago a proveedor, depósito al banco, retiro a bóveda o fondo para cambio.</li>
<li>Pulsa "Registrar movimiento".</li>
<li>Vuelve a abrir "Resumen de sesión" para confirmar que el efectivo en caja cambió.</li>
</ol>
<h3>Cuándo usar cada movimiento</h3>
<ul>
<li><strong>Ingreso:</strong> cuando entra efectivo que no proviene de una venta, por ejemplo un fondo extra para dar cambio o la devolución de un préstamo interno.</li>
<li><strong>Retiro:</strong> cuando sale efectivo de la caja, por ejemplo un pago en efectivo, un depósito bancario o dinero que se resguarda en bóveda.</li>
</ul>
<h3>Qué evitar</h3>
<ul>
<li>No registres ventas como ingresos manuales: la venta debe capturarse en el punto de venta para descontar inventario y generar su folio.</li>
<li>No uses un retiro para ocultar un faltante. Si el efectivo no cuadra, registra el movimiento real y documenta la diferencia al cerrar.</li>
<li>Los gastos del módulo Gastos pueden descontar efectivo de esta caja cuando eliges pagarlos desde la caja registradora; si se pagan con otro medio, no afectan el efectivo del turno.</li>
</ul>
<h3>Recomendaciones</h3>
<ul>
<li>Registra el movimiento en el momento en que ocurre, no al final del turno.</li>
<li>Si te equivocaste en el monto o en el tipo, registra el movimiento contrario con una nota; no borres ni edites el historial.</li>
<li>Al cerrar, revisa la lista de ingresos y retiros manuales junto con los cobros en efectivo.</li>
</ul>',
    20, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'pos'
      AND title = 'Cómo registrar entradas y salidas de efectivo'
);

-- ----------------------------------------------------------------------------
-- 3. How to register a sale in the point of sale
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'pos',
    'Cómo registrar una venta en el punto de venta',
    'Arma el carrito, elige al cliente, cobra y entrega el ticket de una venta de mostrador sin saltarte ningún paso.',
    '<p>Una venta de mostrador se registra en una sola pantalla: agregas productos al carrito, confirmas el cliente y cobras. Al finalizar, el sistema genera el folio, descuenta el inventario y abre la ventana de impresión del ticket.</p>
<h3>Antes de empezar</h3>
<ul>
<li>Debes tener una sesión de caja activa. Sin caja abierta el sistema no permite cobrar.</li>
<li>El producto debe existir en el catálogo de la sucursal y tener precio de venta.</li>
<li>El cliente es opcional, pero es necesario para ventas a crédito, apartados y para dar seguimiento posterior.</li>
<li>Si vas a imprimir, confirma que la impresora esté encendida y vinculada.</li>
</ul>
<h3>Registrar la venta</h3>
<ol>
<li>Verifica que el carrito esté vacío. Si tiene productos de otro cliente, ponlo en espera o límpialo con el botón de basura.</li>
<li>Selecciona al cliente en "Buscar cliente" escribiendo su nombre o teléfono. Si no está registrado, usa el botón de más para crearlo. Si no aplica, deja el cliente "Público en general".</li>
<li>Agrega productos de cualquiera de estas formas: pulsa "Añadir" en la tarjeta, escanea el código de barras o búscalo por nombre o SKU en la barra superior.</li>
<li>Si el producto tiene variantes, elige la combinación en la tarjeta o pulsa "Elegir opciones" para abrir el detalle y usa "Añadir al carrito".</li>
<li>Ajusta las cantidades con los controles de cada partida. Para productos a granel se permiten decimales. Quita una partida con la X.</li>
<li>Revisa el resumen: subtotal, descuentos, promociones aplicadas y total.</li>
<li>Pulsa "Cobrar".</li>
<li>Elige "Pago al contado", confirma o ajusta el monto y pulsa "Finalizar venta".</li>
<li>Imprime el ticket desde la ventana de impresión o ciérrala si el cliente no lo necesita.</li>
</ol>
<h3>Qué ocurre al finalizar</h3>
<ul>
<li>Se muestra el folio de la venta en un aviso de confirmación.</li>
<li>El inventario se descuenta en la sucursal de la sesión.</li>
<li>El carrito se limpia y queda listo para el siguiente cliente.</li>
<li>La venta aparece en el historial de la sesión y en el módulo Historial de ventas.</li>
</ul>
<h3>Casos particulares</h3>
<ul>
<li>Si el total es cero, el botón "Cobrar" cambia a "Finalizar". Confirma con el cliente el motivo antes de continuar.</li>
<li>Si el producto no tiene existencia, el sistema muestra una advertencia y permite continuar. Revisa la guía de productos agotados antes de usarlo como práctica habitual.</li>
<li>Para entregas programadas usa "Pedido" en lugar de "Cobrar". Para reservar mercancía usa el apartado.</li>
</ul>
<h3>Recomendaciones</h3>
<ul>
<li>Asigna al cliente cuando la venta pueda generar garantía, crédito o devolución.</li>
<li>Verifica cantidad y precio unitario antes de cobrar; corregir después requiere una devolución o un cambio.</li>
<li>Entrega el ticket impreso o compártelo, porque es el comprobante para cualquier aclaración.</li>
</ul>',
    30, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'pos'
      AND title = 'Cómo registrar una venta en el punto de venta'
);

-- ----------------------------------------------------------------------------
-- 4. How to charge with several payment methods
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'pos',
    'Cómo cobrar con varios métodos de pago',
    'Combina efectivo, tarjeta y transferencia en una misma venta, registra cada monto y entrega el cambio correcto.',
    '<p>Una venta puede pagarse con más de un método. El sistema suma los montos capturados, calcula lo que falta y no permite finalizar hasta que el total esté cubierto.</p>
<h3>Antes de empezar</h3>
<ul>
<li>Debes tener una sesión de caja activa.</li>
<li>Para cobrar con tarjeta o transferencia necesitas al menos una cuenta bancaria disponible. Puedes agregarla desde el mismo formulario con el botón de más.</li>
<li>Cada método se agrega una sola vez por venta. Si necesitas dos cobros con el mismo método, suma ambos importes en una sola línea.</li>
</ul>
<h3>Cobrar con varios métodos</h3>
<ol>
<li>Pulsa "Cobrar" en el carrito.</li>
<li>Elige "Pago al contado". El sistema agrega automáticamente una línea de efectivo por el total.</li>
<li>Si vas a dividir el pago, pulsa el método "Tarjeta" o "Transferencia". Cuando el efectivo cubre el total y agregas otro método, el sistema quita la línea de efectivo para que no tengas que borrarla.</li>
<li>Ajusta el "Monto" de cada línea según lo que entrega el cliente.</li>
<li>En las líneas de tarjeta y transferencia selecciona la cuenta en "Cuenta destino". Sin cuenta asignada no se puede finalizar.</li>
<li>Revisa el resumen: "Restante" indica lo que falta y "Su cambio" aparece cuando el cliente paga de más.</li>
<li>Pulsa "Finalizar venta" cuando el total esté cubierto.</li>
<li>Entrega el cambio en efectivo e imprime el ticket si el cliente lo solicita.</li>
</ol>
<h3>Cómo se refleja en la caja</h3>
<ul>
<li>Los cobros en efectivo aumentan el efectivo esperado del turno.</li>
<li>Los cobros con tarjeta y por transferencia se registran en cuentas bancarias y se muestran aparte en el corte.</li>
<li>El cambio entregado reduce el efectivo físico. Si el cliente paga en efectivo con un billete grande, captura el monto recibido para que el sistema calcule el cambio.</li>
</ul>
<h3>Recomendaciones</h3>
<ul>
<li>Verifica el voucher de la terminal y la referencia de la transferencia antes de finalizar.</li>
<li>Confirma con el cliente el desglose cuando la venta se paga con tres métodos.</li>
<li>Si la cuenta bancaria no aparece, agrégala con el botón de más y selecciónala antes de continuar.</li>
<li>Si el botón "Finalizar venta" está deshabilitado, revisa si falta una cuenta destino o si aún queda saldo por cubrir.</li>
</ul>',
    40, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'pos'
      AND title = 'Cómo cobrar con varios métodos de pago'
);

-- ----------------------------------------------------------------------------
-- 5. How to apply discounts and change prices in a sale
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'pos',
    'Cómo aplicar descuentos y cambiar precios en una venta',
    'Aplica promociones automáticas, cambia el precio de una partida solo para la venta o actualiza el catálogo de forma permanente.',
    '<p>El punto de venta reconoce dos tipos de ajuste: las promociones que el sistema aplica solo y los cambios de precio que hace el cajero en una partida. Cada uno se refleja distinto en el resumen y en el ticket.</p>
<h3>Antes de empezar</h3>
<ul>
<li>Para cambiar precios necesitas el permiso de edición de precios del punto de venta. Si no lo tienes, el lápiz no aparece.</li>
<li>Para actualizar el catálogo de forma permanente necesitas el permiso de edición de productos.</li>
<li>Las promociones se configuran en el módulo Promociones. En la venta solo se aplican y se muestran.</li>
<li>El punto de venta no tiene un descuento global sobre el carrito: los ajustes se hacen partida por partida.</li>
</ul>
<h3>Cambiar el precio de una partida</h3>
<ol>
<li>En el carrito ubica la partida y pulsa el lápiz junto al precio unitario.</li>
<li>Escribe el nuevo precio y pulsa Enter o el botón de confirmación.</li>
<li>Se abre la ventana "Cambio de precio". Revisa el monto y elige una opción.</li>
<li>Pulsa "Solo para esta venta" para aplicar el precio únicamente a esta operación.</li>
<li>Pulsa "Actualizar catálogo permanente" para modificar el producto o la variante en el catálogo. Esta opción requiere el permiso de edición de productos.</li>
<li>Verifica el resumen: si el precio bajó, el ajuste aparece en "Descuentos"; si subió, aparece como "Ajuste manual" y aumenta el total.</li>
</ol>
<h3>Cómo se aplican las promociones</h3>
<ul>
<li>Las promociones activas se aplican automáticamente al agregar productos y se listan en el resumen con su nombre y su monto.</li>
<li>El icono de fuego en la partida abre el detalle de las promociones que la afectan.</li>
<li>No es necesario capturar nada: si una promoción no aparece, revisa sus fechas, condiciones y productos en el módulo Promociones.</li>
</ul>
<h3>Qué tener en cuenta</h3>
<ul>
<li>Cuando cambias el precio a mano, la partida deja de recalcularse con precios de mayoreo o promociones aunque modifiques la cantidad.</li>
<li>Si la partida ya tiene precio de mayoreo, el lápiz está bloqueado. Reduce la cantidad por debajo del mínimo de mayoreo o quita la partida y vuelve a agregarla si necesitas otro precio.</li>
<li>Si falla la actualización permanente, el sistema aplica el precio solo a la venta y muestra un aviso de error.</li>
<li>El motivo del ajuste queda registrado en la venta como descuento manual, aumento manual, precio de mayoreo o promoción de producto.</li>
</ul>
<h3>Recomendaciones</h3>
<ul>
<li>Define los precios definitivos en el módulo Productos. Usa el cambio permanente en la caja solo para correcciones urgentes.</li>
<li>Pide autorización antes de un descuento manual y registra el motivo.</li>
<li>Revisa que el precio ajustado siga cubriendo el costo del producto.</li>
</ul>',
    50, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'pos'
      AND title = 'Cómo aplicar descuentos y cambiar precios en una venta'
);

-- ----------------------------------------------------------------------------
-- 6. How to use smart search in the point of sale
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'pos',
    'Cómo usar la búsqueda inteligente en el punto de venta',
    'Encuentra productos por nombre o SKU, filtra por categoría, escanea códigos y abre ventas, clientes u órdenes de servicio desde la barra principal.',
    '<p>La barra superior del punto de venta no solo busca productos: también reconoce folios de venta, folios de órdenes de servicio y teléfonos o nombres de clientes. Es la forma más rápida de trabajar con el lector de código de barras.</p>
<h3>Antes de empezar</h3>
<ul>
<li>Debes tener una sesión de caja activa para ver el catálogo y la barra de búsqueda.</li>
<li>Los resultados corresponden a la sucursal de tu usuario.</li>
<li>El lector de código de barras debe enviar Enter al final del código; es la configuración habitual de fábrica.</li>
</ul>
<h3>Buscar productos</h3>
<ol>
<li>Escribe en "Escanear o buscar producto". La lista se filtra por nombre o SKU mientras escribes.</li>
<li>Combina el texto con los filtros de categoría que aparecen debajo de la barra.</li>
<li>Pulsa "Añadir" en la tarjeta para agregar el producto o el icono de expandir para ver su detalle.</li>
<li>Desplázate al final de la lista para cargar más productos.</li>
<li>Usa la X de la barra para limpiar la búsqueda y volver al catálogo completo.</li>
</ol>
<h3>Escanear códigos</h3>
<ul>
<li>El lector funciona aunque el cursor no esté en la barra de búsqueda, siempre que no estés escribiendo en otro campo ni con una ventana abierta.</li>
<li>Al escanear, el sistema espera el Enter del lector y busca el código. Si no coincide con una venta, una orden o un cliente, usa el código como búsqueda de producto.</li>
<li>La detección con Enter y el escaneo ignoran los códigos de menos de tres caracteres para evitar búsquedas accidentales.</li>
</ul>
<h3>Abrir ventas, clientes u órdenes de servicio</h3>
<ol>
<li>Escribe o escanea el folio o el dato y pulsa Enter.</li>
<li>Si el dato coincide con una venta, una orden de servicio o un cliente, aparece la ventana "Entidad detectada".</li>
<li>Pulsa "Ver detalles" para abrir el registro en una pestaña nueva. La búsqueda se limpia.</li>
<li>Pulsa "Cancelar" para conservar el texto y continuar buscando productos con él.</li>
</ol>
<p>Los clientes se detectan por teléfono registrado o por nombre exacto. Los folios deben escribirse completos, tal como aparecen en el ticket o en la orden.</p>
<h3>Recomendaciones</h3>
<ul>
<li>Usa el teléfono a diez dígitos para localizar clientes y evitar coincidencias con nombres parecidos.</li>
<li>Mantén los SKU únicos y sin espacios para que la búsqueda y el escaneo sean confiables.</li>
<li>Si un producto recién creado no aparece, limpia la búsqueda y verifica que esté visible en el punto de venta.</li>
<li>El botón "Búsqueda inteligente" resume estos atajos dentro de la misma pantalla.</li>
</ul>',
    60, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'pos'
      AND title = 'Cómo usar la búsqueda inteligente en el punto de venta'
);

-- ----------------------------------------------------------------------------
-- 7. How to sell with wholesale prices and variants
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'pos',
    'Cómo vender con precios de mayoreo y variantes',
    'Vende al precio correcto según la cantidad y la presentación: mayoreo automático, variantes, combos y productos a granel.',
    '<p>Muchos productos cambian de precio según la cantidad o la presentación. El punto de venta aplica esas reglas por ti, pero necesitas saber cómo se comportan para no cobrar de más ni de menos.</p>
<h3>Antes de empezar</h3>
<ul>
<li>Los precios por cantidad se configuran en el módulo Productos, con una cantidad mínima y un precio para cada nivel.</li>
<li>Las variantes y los combos también se definen en el catálogo, no en la caja.</li>
<li>Verifica que el producto tenga existencia en la sucursal correcta.</li>
</ul>
<h3>Precios de mayoreo</h3>
<ol>
<li>Agrega el producto al carrito y ajusta la cantidad.</li>
<li>Cuando la cantidad alcanza el mínimo del siguiente nivel, el precio unitario cambia automáticamente.</li>
<li>La partida muestra una estrella con el aviso "Precio de mayoreo aplicado".</li>
<li>Si reduces la cantidad por debajo del mínimo, el precio vuelve al nivel anterior o al precio de lista.</li>
<li>Al cobrar, el descuento se registra con el motivo "Precio de mayoreo".</li>
</ol>
<h3>Variantes y presentaciones</h3>
<ol>
<li>En la tarjeta del producto elige una opción de cada atributo, por ejemplo talla y color.</li>
<li>Las opciones que no forman una combinación válida se deshabilitan.</li>
<li>El precio, la imagen y la existencia se actualizan según la combinación elegida.</li>
<li>Si la tarjeta muestra "Elegir opciones", abre el detalle del producto y pulsa "Añadir al carrito" cuando la selección esté completa.</li>
<li>Cada combinación se agrega como una partida independiente, aunque sea el mismo producto.</li>
</ol>
<h3>Combos y productos a granel</h3>
<ul>
<li>Los combos muestran la etiqueta "Combo" y en su detalle aparece el "Contenido del paquete" con cada componente y su cantidad.</li>
<li>Los productos a granel aceptan cantidades con decimales y muestran su unidad de medida junto al precio.</li>
<li>La existencia reservada aparece como "apart." y se descuenta de la disponibilidad; si todo está apartado, la tarjeta muestra cero disponibles.</li>
</ul>
<h3>Recomendaciones</h3>
<ul>
<li>Revisa que los niveles de mayoreo dejen margen suficiente antes de activarlos.</li>
<li>Confirma la combinación elegida en el carrito antes de cobrar; una variante equivocada requiere un cambio.</li>
<li>Si el precio no cambia al alcanzar la cantidad, revisa la configuración de niveles del producto y que la partida no tenga un precio manual.</li>
<li>Para cambiar el precio de una partida con mayoreo, primero reduce la cantidad o vuelve a agregar el producto.</li>
</ul>',
    70, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'pos'
      AND title = 'Cómo vender con precios de mayoreo y variantes'
);

-- ----------------------------------------------------------------------------
-- 8. How to work with out of stock products
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'pos',
    'Cómo trabajar con productos agotados',
    'Interpreta el indicador de existencia, entiende el efecto de las reservas y decide qué hacer cuando un producto aparece en cero.',
    '<p>La tarjeta de cada producto muestra la existencia disponible en la sucursal. Cuando el indicador está en rojo, el producto aparece agotado para la venta, aunque el sistema todavía permite agregarlo con una advertencia.</p>
<h3>Cómo leer el indicador</h3>
<ul>
<li><strong>Disp.:</strong> existencia disponible, es decir, la existencia física menos lo que está reservado por apartados o pedidos.</li>
<li><strong>Apart.:</strong> cantidad reservada que ya está comprometida con un cliente.</li>
<li>El indicador verde significa que hay existencia disponible; el rojo indica cero disponible.</li>
<li>En productos con variantes, el indicador cambia según la combinación seleccionada.</li>
</ul>
<h3>Qué ocurre al agregar un producto agotado</h3>
<ol>
<li>Al agregarlo, aparece el aviso "Sin stock" y la partida se suma al carrito de todas formas.</li>
<li>Si la partida ya existe y la existencia es mayor que cero, el sistema impide superar la cantidad disponible y muestra "Stock insuficiente".</li>
<li>Al cobrar, el inventario se descuenta aunque quede en negativo.</li>
<li>El producto seguirá apareciendo con cero disponibles hasta que se reponga o se ajuste la existencia.</li>
</ol>
<h3>Qué hacer cuando no hay existencia</h3>
<ul>
<li>Confirma con el proveedor la fecha de reposición antes de prometer una entrega.</li>
<li>Si el cliente acepta esperar, registra un pedido con fecha de entrega: el pedido reserva el inventario para ese cliente.</li>
<li>Si el cliente deja un anticipo, usa el sistema de apartado.</li>
<li>Si la existencia física sí existe y el sistema está desfasado, corrige el inventario desde el módulo Productos con el ajuste correspondiente y su motivo.</li>
<li>Revisa cuánto está apartado: un producto puede tener existencia física y aun así mostrar cero disponible.</li>
</ul>
<h3>Recomendaciones</h3>
<ul>
<li>No uses la venta de productos agotados como práctica habitual: deja existencias negativas difíciles de conciliar.</li>
<li>Registra el motivo del ajuste de inventario para poder rastrear diferencias.</li>
<li>Antes de cancelar un apartado o un pedido, confirma que el cliente no va a liquidarlo, porque al cancelar se libera la reserva.</li>
<li>Si un producto debe dejar de venderse aunque tenga existencia, ocúltalo del punto de venta desde el catálogo en lugar de ajustar el inventario a cero.</li>
</ul>',
    80, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'pos'
      AND title = 'Cómo trabajar con productos agotados'
);

-- ----------------------------------------------------------------------------
-- 9. How to save and resume pending carts
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'pos',
    'Cómo guardar y reanudar carritos en espera',
    'Guarda el carrito de un cliente que no ha terminado, atiende a otro y reanúdalo después sin perder productos, precios ni cliente.',
    '<p>Cuando un cliente deja productos y se va por algo más, o cuando llega otro cliente, no necesitas borrar el carrito: puedes ponerlo en espera y reanudarlo después.</p>
<h3>Antes de empezar</h3>
<ul>
<li>El carrito debe tener al menos un producto.</li>
<li>Los carritos en espera se guardan en este equipo, asociados a tu usuario y a la sucursal. No se comparten con otras cajas ni con otros equipos.</li>
<li>Un carrito en espera no es una venta ni un apartado: no reserva inventario ni registra pagos.</li>
</ul>
<h3>Guardar un carrito</h3>
<ol>
<li>Agrega los productos y, si lo sabes, asigna al cliente.</li>
<li>En la cabecera del carrito pulsa el botón de marcador, "Poner en espera".</li>
<li>Verás el aviso "Carrito guardado" y el carrito actual se vacía.</li>
<li>El icono de carritos en espera de la barra superior muestra un contador con la cantidad de carritos guardados.</li>
</ol>
<h3>Reanudar un carrito</h3>
<ol>
<li>Pulsa el icono de carritos en espera.</li>
<li>Revisa la lista: muestra el cliente asignado, el operador que lo guardó, la hora y el total.</li>
<li>Pulsa "Reanudar" en el carrito que vas a atender.</li>
<li>Si tenías otro carrito en curso, el sistema lo guarda automáticamente antes de cargar el seleccionado.</li>
<li>Verifica los productos, las cantidades y el cliente antes de cobrar.</li>
</ol>
<h3>Eliminar un carrito</h3>
<ol>
<li>Abre la lista de carritos en espera.</li>
<li>Pulsa la X del carrito que ya no se usará.</li>
<li>Confirma con "Sí, eliminar".</li>
</ol>
<h3>Recomendaciones</h3>
<ul>
<li>Asigna siempre un cliente para identificar el carrito cuando hay varios en espera.</li>
<li>No acumules carritos durante todo el día: conviértelos en venta, pedido o apartado según lo que acuerde el cliente.</li>
<li>Antes de cerrar el turno, revisa la lista y resuelve los carritos pendientes.</li>
<li>Si cambias de equipo, vuelve a capturar los productos: la lista no viaja con tu usuario a otra terminal.</li>
</ul>',
    90, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'pos'
      AND title = 'Cómo guardar y reanudar carritos en espera'
);

-- ----------------------------------------------------------------------------
-- 10. How to create a delivery order from the point of sale
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'pos',
    'Cómo crear un pedido para entrega desde el punto de venta',
    'Registra un pedido con fecha de entrega, dirección y cargo por envío, reserva el inventario y cobra cuando se entregue.',
    '<p>Un pedido separa dos momentos: la reserva de la mercancía y el cobro. El inventario se reserva al crear el pedido, pero el pago puede recibirse después, cuando el cliente recoja o reciba los productos.</p>
<h3>Antes de empezar</h3>
<ul>
<li>Debes tener una sesión de caja activa.</li>
<li>Ten el nombre de quien recibe y la fecha y hora de entrega; son los datos obligatorios.</li>
<li>Si el cliente ya está registrado, selecciónalo en el carrito para enlazar el pedido a su historial.</li>
</ul>
<h3>Crear el pedido</h3>
<ol>
<li>Agrega los productos al carrito y ajusta cantidades.</li>
<li>Pulsa "Pedido" en la parte inferior del carrito.</li>
<li>En "Información de contacto" revisa el "Nombre de quien recibe" y captura el "Teléfono / WhatsApp". Si enlazaste un cliente, el nombre se toma del cliente y no se puede editar.</li>
<li>En "Detalles de entrega" elige la "Fecha y hora programada".</li>
<li>Captura el "Cargo por envío" si aplica.</li>
<li>Escribe la "Dirección de entrega" y las "Notas internas" si son necesarias.</li>
<li>Revisa el "Total del pedido", que suma productos y envío.</li>
<li>Pulsa "Confirmar pedido".</li>
</ol>
<h3>Qué ocurre al confirmar</h3>
<ul>
<li>El sistema genera el folio del pedido y muestra un aviso de confirmación.</li>
<li>El inventario queda reservado para ese pedido y aparece como "apart." en las tarjetas de producto.</li>
<li>El pedido se registra con estado por entregar en el módulo Historial de ventas.</li>
<li>Se abre la ventana de impresión para que puedas imprimir el comprobante del pedido.</li>
</ul>
<h3>Entregar y cobrar</h3>
<ol>
<li>Abre el pedido desde Historial de ventas.</li>
<li>Confirma la entrega con el cliente y registra el pago con el método que utilice.</li>
<li>Al liquidar el pedido, la reserva se convierte en salida de inventario.</li>
</ol>
<h3>Recomendaciones</h3>
<ul>
<li>Confirma el teléfono y la dirección con el cliente antes de cerrar el pedido.</li>
<li>Propón una fecha de entrega realista según la existencia reservada.</li>
<li>Explica el cargo por envío y el total antes de confirmar.</li>
<li>Si el pedido se cancela, la reserva se libera. Hazlo solo cuando el cliente ya no vaya a recogerlo.</li>
</ul>',
    100, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'pos'
      AND title = 'Cómo crear un pedido para entrega desde el punto de venta'
);

-- ----------------------------------------------------------------------------
-- 11. How to register a layaway or a credit sale
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'pos',
    'Cómo registrar un apartado o una venta a crédito',
    'Diferencia apartado y crédito, reserva inventario, usa el límite del cliente y cobra el saldo pendiente más adelante.',
    '<p>El punto de venta permite que el cliente se lleve la mercancía y pague después, o que deje un anticipo para reservarla. Aunque las dos opciones generan un saldo pendiente, se comportan distinto con el inventario.</p>
<h3>Antes de empezar</h3>
<ul>
<li>Debes tener una sesión de caja activa.</li>
<li>Selecciona un cliente antes de cobrar. Las opciones de crédito y apartado están deshabilitadas para el público en general.</li>
<li>El límite de crédito se configura en el módulo Clientes. El sistema muestra el crédito disponible antes de autorizar la venta.</li>
<li>Ambas opciones requieren una fecha límite para liquidar.</li>
</ul>
<h3>Venta a crédito</h3>
<ol>
<li>Agrega los productos, selecciona al cliente y pulsa "Cobrar".</li>
<li>Elige "A crédito / pagos".</li>
<li>Si el cliente deja un abono, agrégalo como pago. El resto queda como saldo.</li>
<li>En "Vencimiento del Crédito" elige la fecha límite. El sistema propone treinta días.</li>
<li>Revisa el botón de finalización: muestra "Guardar a crédito" con el monto pendiente.</li>
<li>Pulsa el botón para registrar la venta.</li>
</ol>
<p>La venta a crédito descuenta el inventario de inmediato y registra la deuda en el estado de cuenta del cliente. Si el monto supera el crédito disponible, aparece el aviso "El cliente no tiene suficiente crédito" y no se puede finalizar hasta ajustar el pago o el límite.</p>
<h3>Apartado</h3>
<ol>
<li>Agrega los productos, selecciona al cliente y pulsa "Cobrar".</li>
<li>Elige "Sistema de apartado".</li>
<li>Captura el anticipo. Sin un pago mayor que cero no se puede crear el apartado.</li>
<li>En "Configuración del Apartado" elige la fecha límite para liquidar.</li>
<li>Pulsa "Crear apartado".</li>
</ol>
<p>El apartado reserva el inventario, pero no lo descuenta. La mercancía queda comprometida y se descuenta hasta que el cliente liquida el saldo.</p>
<h3>Cobrar el saldo pendiente</h3>
<ol>
<li>Abre el apartado o la venta a crédito desde Historial de ventas o desde el perfil del cliente.</li>
<li>Registra el pago con el método que use el cliente.</li>
<li>Cuando el apartado queda liquidado, el sistema convierte la reserva en salida de inventario.</li>
</ol>
<h3>Recomendaciones</h3>
<ul>
<li>Verifica la identidad y el teléfono del cliente antes de otorgar crédito.</li>
<li>Explica la fecha límite y las condiciones del apartado al cliente.</li>
<li>No apartes mercancía sin existencia disponible: revisa el indicador y la cantidad reservada antes de prometer la entrega.</li>
<li>Da seguimiento a los vencimientos desde el Inicio para reducir saldos vencidos.</li>
</ul>',
    110, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'pos'
      AND title = 'Cómo registrar un apartado o una venta a crédito'
);

-- ----------------------------------------------------------------------------
-- 12. How to handle online store orders
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'pos',
    'Cómo atender los pedidos de tienda en línea',
    'Consulta desde la caja los pedidos que entran por tu tienda en línea, actualiza su estado y cancela con reposición de inventario.',
    '<p>Si tu cuenta tiene tienda en línea, los pedidos que hacen los clientes desde el sitio se pueden revisar y atender sin salir del punto de venta. El botón del globo aparece solo cuando la tienda está habilitada.</p>
<h3>Antes de empezar</h3>
<ul>
<li>Debes tener una sesión de caja activa.</li>
<li>La tienda en línea debe estar configurada y activa en tu cuenta.</li>
<li>Los estados y los datos del pedido se actualizan en el momento; no necesitas recargar la página.</li>
</ul>
<h3>Consultar los pedidos</h3>
<ol>
<li>En la barra superior pulsa el botón del globo, "Pedidos de tienda en línea".</li>
<li>Revisa el contador de pendientes que aparece en el encabezado.</li>
<li>Filtra con las pestañas "Todos" o por estado. Cada pestaña muestra cuántos pedidos tiene.</li>
<li>En cada tarjeta revisa el folio, el cliente, el tipo de entrega, la fecha, el total y los productos.</li>
<li>Usa el enlace de WhatsApp para contactar al cliente o "Ver detalle" para abrir el pedido completo en otra pestaña.</li>
</ol>
<h3>Actualizar el estado</h3>
<ol>
<li>Localiza el pedido y abre el selector "Cambiar estado".</li>
<li>Elige el nuevo estado según el avance real del pedido: Pendiente, En revisión, En preparación, Listo, Entregado o Cancelado.</li>
<li>El cambio se aplica de inmediato y el sistema muestra un aviso con el estado actualizado.</li>
</ol>
<h3>Cancelar un pedido</h3>
<ol>
<li>Selecciona "Cancelado" en el selector de estado.</li>
<li>Lee la confirmación: el inventario del pedido se repondrá y los pagos deberán gestionarse de forma manual.</li>
<li>Pulsa "Sí, cancelar pedido" para confirmar.</li>
<li>Si el cliente ya pagó, gestiona el reembolso desde la caja, con un retiro de efectivo o un gasto según corresponda.</li>
</ol>
<h3>Recomendaciones</h3>
<ul>
<li>Revisa los pedidos pendientes al abrir el turno y antes de cerrar.</li>
<li>Actualiza el estado en cuanto avances en la preparación; el cliente y el negocio ven el mismo seguimiento.</li>
<li>Confirma la existencia antes de prometer una entrega y antes de marcar un pedido como listo.</li>
<li>No canceles un pedido solo para corregir inventario: usa el ajuste de existencias del módulo Productos.</li>
<li>Este modal no captura pagos: si el pedido se paga contra entrega, cobra al entregar; si se pagó en línea, confirma el pago en la tienda antes de cancelar o reembolsar.</li>
</ul>',
    120, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'pos'
      AND title = 'Cómo atender los pedidos de tienda en línea'
);

