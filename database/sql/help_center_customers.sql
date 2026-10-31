-- ============================================================================
-- Help center - Guides for the Clientes (customers) module
--
-- Inserts 10 published guides for the "customers" module of the
-- help_center_articles table. The guides automatically appear in the
-- Clientes card of the help center.
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
-- 1. How to register a customer step by step
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'customers',
    'Cómo registrar un cliente paso a paso',
    'Captura datos generales, domicilio, datos fiscales y configuración financiera para dejar la cuenta del cliente lista desde el primer día, con o sin saldo inicial.',
    '<p>El directorio de clientes concentra las cuentas de tu sucursal. Registrar cada cliente con sus datos completos te permite cobrar a crédito, apartar productos, facturar y llevar su estado de cuenta sin capturas posteriores.</p>
<h3>Antes de empezar</h3>
<ul>
<li>Necesitas el permiso "Crear nuevos clientes". Si no ves el botón "Nuevo cliente", pide a un administrador que lo habilite.</li>
<li>Decide si el cliente tendrá crédito. El límite de crédito es el monto máximo que puede deber.</li>
<li>Si el cliente ya te debe dinero o tiene dinero a su favor, ten a mano el monto exacto para capturarlo como saldo inicial.</li>
</ul>
<h3>Captura los datos generales</h3>
<ol>
<li>Entra a Clientes desde el menú y pulsa "Nuevo cliente".</li>
<li>En "Datos Generales" escribe el nombre. Es el único dato obligatorio y aparecerá en tickets, ventas y estados de cuenta.</li>
<li>De forma opcional agrega empresa, teléfono, correo y RFC. El teléfono se usa para enviar tickets por WhatsApp y la empresa y el correo ayudan a localizar al cliente en la búsqueda.</li>
<li>Llena el domicilio en la sección "Domicilio / Dirección": calle, número exterior, número interior, colonia, ciudad o municipio, estado, código postal y el campo "Entre calles y referencias".</li>
</ol>
<h3>Captura los datos fiscales</h3>
<p>La sección "Datos para Facturación" aparece solo si tu suscripción incluye el módulo de facturación electrónica. Es necesaria únicamente si vas a facturar al cliente.</p>
<ul>
<li>Escribe la razón social tal como aparece en la constancia de situación fiscal. El sistema la convierte a mayúsculas.</li>
<li>Captura el RFC y selecciona el régimen fiscal del catálogo (601 a 626).</li>
<li>Si el domicilio fiscal es el mismo que el domicilio principal, activa la casilla "El domicilio fiscal es el mismo que el domicilio principal". Si no, captura calle, números, colonia, ciudad, estado y código postal fiscales.</li>
</ul>
<h3>Define la configuración financiera</h3>
<ul>
<li><strong>Límite de crédito:</strong> monto máximo que el cliente puede deber. Déjalo en cero si no otorgas crédito. Debe ser un número igual o mayor que cero.</li>
<li><strong>Saldo inicial:</strong> elige "Saldo a favor (Positivo)" si el cliente tiene dinero a su favor o "Saldo deudor (Negativo)" si ya te debe, y captura el monto en el campo "Monto" sin signos. La dirección la define el selector.</li>
</ul>
<p>El saldo inicial no se guarda como un campo suelto: queda registrado como un movimiento de ajuste manual con la nota "Saldo Inicial registrado al crear cliente", así que podrás consultarlo después en el historial de movimientos del cliente.</p>
<h3>Guarda y verifica</h3>
<ol>
<li>Pulsa "Guardar cliente" en la parte inferior del formulario.</li>
<li>El sistema te devuelve al directorio con el mensaje "Cliente creado con éxito".</li>
<li>Busca el cliente, abre su perfil y revisa que el saldo, el límite de crédito, el contacto y los datos fiscales sean correctos.</li>
</ol>
<h3>Recomendaciones</h3>
<ul>
<li>Registra el nombre completo o la razón social; evita apodos que dificulten la búsqueda posterior.</li>
<li>Captura el teléfono desde el alta: agiliza el envío de tickets y el cobro por WhatsApp.</li>
<li>Si vas a facturar, valida el RFC y el régimen fiscal antes de la primera venta. Corregirlos después no modifica las facturas ya emitidas.</li>
<li>No uses el saldo inicial para corregir una venta mal capturada: en ese caso conviene ajustar el saldo manualmente y dejar el motivo registrado.</li>
</ul>',
    10,
    1,
    NOW(),
    NOW(),
    NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM help_center_articles
    WHERE module = 'customers' AND title = 'Cómo registrar un cliente paso a paso'
);

-- ----------------------------------------------------------------------------
-- 2. How to edit customer information
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'customers',
    'Cómo editar los datos de un cliente',
    'Corrige contacto, domicilio, datos fiscales y límite de crédito sin alterar el saldo ni el historial de la cuenta.',
    '<p>Los datos de un cliente cambian con el tiempo: un teléfono nuevo, otra dirección o un límite de crédito distinto. Puedes actualizarlos en cualquier momento sin afectar sus ventas, apartados ni movimientos de saldo.</p>
<h3>Antes de empezar</h3>
<ul>
<li>Necesitas el permiso "Editar clientes existentes".</li>
<li>Si lo que quieres corregir es el saldo, no uses este formulario: utiliza "Ajuste de saldo manual" desde el menú del perfil del cliente.</li>
</ul>
<h3>Abre el formulario de edición</h3>
<ul>
<li>Desde el directorio: pulsa el menú de la fila del cliente y elige "Editar cliente".</li>
<li>Desde el perfil: abre el menú de acciones y elige "Editar cliente".</li>
</ul>
<h3>Qué puedes cambiar</h3>
<ul>
<li>Datos generales: nombre, empresa, teléfono, correo y RFC.</li>
<li>Domicilio principal: calle, números, colonia, ciudad, estado, código postal y referencias.</li>
<li>Datos fiscales: razón social, RFC, régimen fiscal y domicilio fiscal.</li>
<li>Límite de crédito. Si el cliente ya debe más que el nuevo límite, la deuda se conserva y el crédito disponible aparece en cero.</li>
</ul>
<h3>Qué no cambia desde aquí</h3>
<ul>
<li>El saldo del cliente. Se modifica únicamente con ventas, abonos o ajustes manuales.</li>
<li>Las ventas, apartados y pagos registrados. Se conservan tal como estaban.</li>
<li>Las facturas ya emitidas. Si necesitas otra razón social o RFC en un comprobante, corrige los datos y emite una factura nueva.</li>
</ul>
<h3>Guarda los cambios</h3>
<ol>
<li>Corrige los campos necesarios.</li>
<li>Pulsa "Guardar cambios".</li>
<li>El sistema te devuelve al directorio con el mensaje "Cliente actualizado con éxito".</li>
<li>Abre el perfil del cliente y verifica los datos modificados.</li>
</ol>
<h3>Recomendaciones</h3>
<ul>
<li>Actualiza el teléfono cuando cambie: el ticket y el estado de cuenta se envían al número registrado.</li>
<li>El límite de crédito define el crédito disponible que se muestra en el perfil, en el panel lateral del directorio y en los documentos impresos, así que revísalo antes de modificarlo.</li>
<li>No cambies el nombre del cliente para dejar anotada una situación; usa el ajuste manual con una razón clara o registra la nota fuera del directorio.</li>
</ul>',
    20,
    1,
    NOW(),
    NOW(),
    NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM help_center_articles
    WHERE module = 'customers' AND title = 'Cómo editar los datos de un cliente'
);

-- ----------------------------------------------------------------------------
-- 3. How to search customers and use the quick details panel
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'customers',
    'Cómo buscar clientes y usar los detalles rápidos del directorio',
    'Encuentra un cliente por nombre, empresa, correo o teléfono, ordena la tabla y revisa su saldo y apartados desde el panel lateral.',
    '<p>El "Directorio de clientes" muestra las cuentas de tu sucursal con búsqueda, ordenamiento, paginación y un panel lateral de consulta rápida. Desde ahí puedes abrir el perfil, editarlo, imprimir su estado de cuenta o eliminarlo.</p>
<h3>Qué ves en la tabla</h3>
<ul>
<li>Nombre del cliente y, debajo, su empresa cuando está registrada.</li>
<li>Contacto: teléfono y correo. Si no hay datos aparece "No registrado".</li>
<li>Saldo, artículos apartados y límite de crédito. Estas tres columnas solo aparecen si tienes permiso para ver información financiera de clientes.</li>
</ul>
<h3>Busca y ordena</h3>
<ol>
<li>Escribe en el buscador. La búsqueda encuentra coincidencias por nombre, empresa, correo o teléfono.</li>
<li>Pulsa el encabezado de una columna para ordenar de forma ascendente o descendente. Vuelve a pulsarlo para invertir el orden.</li>
<li>Ajusta las filas por página a 20, 50, 100 o 200 y navega con el paginador.</li>
</ol>
<h3>Consulta rápida desde el panel lateral</h3>
<ol>
<li>Haz clic en una fila para abrir el panel "Detalles rápidos" del cliente.</li>
<li>Revisa la información de contacto, la dirección y, si tienes permiso, el estado financiero con saldo actual, límite de crédito y crédito disponible.</li>
<li>Usa "Ver perfil completo" para abrir el historial del cliente o "Editar información" para corregir sus datos.</li>
<li>Cierra el panel y sigue trabajando en la tabla.</li>
</ol>
<h3>Acciones por cliente</h3>
<ul>
<li>"Ver" abre el perfil completo con ventas, apartados, movimientos y facturas.</li>
<li>"Editar cliente" abre el formulario de edición.</li>
<li>"Estado de cuenta" genera el documento con todos sus movimientos.</li>
<li>"Eliminar" borra el cliente después de confirmar. Esta acción no se puede deshacer.</li>
</ul>
<h3>Selecciona varios clientes</h3>
<p>Marca las casillas de la izquierda para seleccionar varios clientes. Aparece una barra con el número de seleccionados y el botón "Eliminar" para borrarlos en lote.</p>
<h3>Por qué no ves todos los clientes</h3>
<p>El directorio muestra únicamente los clientes de la sucursal en la que tienes sesión. Si un cliente se registró en otra sucursal, no aparecerá aquí aunque pertenezca a la misma suscripción.</p>
<h3>Recomendaciones</h3>
<ul>
<li>Busca por teléfono cuando no recuerdes el nombre exacto: es el dato más rápido de obtener en mostrador.</li>
<li>Antes de eliminar, abre el estado de cuenta y confirma que no hay adeudos ni apartados vigentes.</li>
<li>Si un cliente aparece dos veces, revisa empresa y teléfono antes de eliminarlo: probablemente uno de los dos registros esté incompleto.</li>
</ul>',
    30,
    1,
    NOW(),
    NOW(),
    NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM help_center_articles
    WHERE module = 'customers' AND title = 'Cómo buscar clientes y usar los detalles rápidos del directorio'
);

-- ----------------------------------------------------------------------------
-- 4. How to read the customer profile (balance, credit, layaways, sales, invoices)
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'customers',
    'Cómo interpretar el perfil del cliente: saldo, crédito, apartados, ventas y facturas',
    'Lee el estado financiero del cliente, entiende el signo del saldo y recorre apartados, ventas, movimientos y facturas en un solo lugar.',
    '<p>El perfil del cliente reúne su información de contacto, su estado financiero, sus apartados activos, sus ventas y los movimientos que explican su saldo. Es la pantalla que conviene revisar antes de cobrar, ajustar o eliminar una cuenta.</p>
<h3>Estado financiero</h3>
<ul>
<li><strong>Saldo actual:</strong> si aparece en verde con signo positivo, el cliente tiene dinero a favor. Si aparece en rojo con signo negativo, te debe esa cantidad. En cero, la cuenta está liquidada.</li>
<li><strong>Crédito disponible:</strong> resulta del límite otorgado menos lo que el cliente debe. Si no tiene deuda, el crédito disponible es igual al límite; si la deuda supera el límite, aparece en cero.</li>
<li><strong>Límite otorgado:</strong> el monto capturado al crear o editar el cliente.</li>
</ul>
<p>Esta tarjeta solo aparece si cuentas con el permiso "Ver información financiera de clientes". Sin ese permiso verás el contacto y el domicilio, pero no el saldo, el límite ni el crédito disponible.</p>
<h3>Apartados activos</h3>
<ul>
<li><strong>Folio:</strong> liga al detalle de la venta en apartado.</li>
<li><strong>Fecha de registro y vencimiento:</strong> la fecha de vencimiento se muestra en rojo cuando ya pasó.</li>
<li><strong>Total y deuda:</strong> el monto total del apartado y lo que falta por pagar.</li>
<li><strong>Artículos apartados:</strong> despliega la fila para ver cantidades, descripciones y subtotales.</li>
</ul>
<h3>Historial de ventas y órdenes</h3>
<p>Lista cronológica de las ventas y órdenes de servicio del cliente, con folio, fecha, estado, total, monto pagado y saldo pendiente. Pulsa cualquier fila para abrir el detalle de la venta y operar sobre ella.</p>
<h3>Historial de movimientos de saldo</h3>
<p>Cada fila explica por qué cambió el saldo del cliente: venta a crédito, abono, deuda generada por un apartado, uso de crédito, devolución a balance, ajuste manual y créditos por cancelación o reembolso. La columna de monto indica el efecto del movimiento y el saldo resultante te permite seguir la secuencia.</p>
<h3>Facturas</h3>
<p>La sección de facturas aparece solo si tu suscripción incluye facturación electrónica. Muestra el estado del timbrado, el RFC y régimen fiscal del receptor, el uso de CFDI, la forma de pago, los conceptos y el desglose de montos de cada comprobante.</p>
<h3>Qué hacer si el saldo no coincide</h3>
<ol>
<li>Revisa el historial de movimientos y localiza el último movimiento que consideres correcto.</li>
<li>Abre la venta o el abono relacionado con el movimiento dudoso.</li>
<li>Si el error está en un abono aplicado a la venta equivocada, corrige el pago desde el detalle de la venta.</li>
<li>Si la diferencia no proviene de ningún documento, registra un ajuste manual y explica la razón del ajuste.</li>
</ol>
<h3>Recomendaciones</h3>
<ul>
<li>Antes de otorgar más crédito, revisa saldo, crédito disponible y apartados activos.</li>
<li>Imprime el estado de cuenta cuando necesites explicar el saldo al cliente.</li>
<li>No ajustes el saldo sin revisar antes el historial: la mayoría de las diferencias se explican por un movimiento ya registrado.</li>
</ul>',
    40,
    1,
    NOW(),
    NOW(),
    NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM help_center_articles
    WHERE module = 'customers' AND title = 'Cómo interpretar el perfil del cliente: saldo, crédito, apartados, ventas y facturas'
);

-- ----------------------------------------------------------------------------
-- 5. How to register a payment from the customer profile
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'customers',
    'Cómo registrar un abono desde el perfil del cliente',
    'Aplica un pago global a la deuda del cliente, entiende cómo se reparte entre sus ventas y apartados, y entrega el ticket de abono.',
    '<p>Un abono a saldo es un pago global: no se aplica a una venta en particular, sino a la deuda del cliente. Es la forma más rápida de cobrar cuando el cliente paga una cantidad sin indicar a qué venta corresponde.</p>
<h3>Antes de empezar</h3>
<ul>
<li>Necesitas el permiso "Editar clientes existentes".</li>
<li>Debe existir una sesión de caja abierta. Si no la hay, el sistema te pedirá iniciarla o unirte a una disponible.</li>
<li>Confirma con el cliente el monto y el método de pago antes de capturar.</li>
</ul>
<h3>Registra el abono</h3>
<ol>
<li>Abre el perfil del cliente y pulsa el menú de acciones.</li>
<li>Elige "Abonar / agregar saldo".</li>
<li>Captura el monto del abono. Debe ser mayor que cero.</li>
<li>Selecciona el método de pago y, si corresponde, la cuenta bancaria y la caja.</li>
<li>Confirma el pago. El sistema responde con el mensaje "Abono registrado correctamente".</li>
</ol>
<h3>Cómo se reparte el dinero</h3>
<p>El sistema aplica el monto a las ventas y apartados pendientes del cliente, de la más antigua a la más reciente. Puedes registrar varios abonos con métodos distintos en la misma operación. Si el monto supera la deuda total, el excedente queda como saldo a favor del cliente y se descuenta en sus próximas compras.</p>
<h3>Ticket de abono</h3>
<p>Al guardar, el sistema abre el ticket de abono con el desglose por folio: cuánto se abonó, cuánto queda pendiente de cada documento y si la venta quedó liquidada, además del total pendiente y el próximo vencimiento. Puedes imprimirlo, guardarlo como PDF o enviarlo por WhatsApp. Antes de enviarlo puedes corregir el teléfono del cliente desde el mismo modal.</p>
<h3>Dónde queda registrado</h3>
<ul>
<li>Como movimiento en el historial de movimientos del cliente y en su estado de cuenta.</li>
<li>En el corte de caja y, si aplica, en la cuenta bancaria según el método y la cuenta elegidos.</li>
<li>No aparece como una venta nueva en el historial de ventas: es un pago.</li>
</ul>
<h3>Diferencia con abonar una venta específica</h3>
<p>Desde el perfil, el abono se reparte automáticamente entre las deudas pendientes. Si necesitas abonar a una venta en particular o liquidarla, abre esa venta desde el historial y usa la opción de abono o liquidación en su detalle.</p>
<h3>Si te equivocaste</h3>
<p>Un abono mal aplicado no se corrige con un ajuste manual: corrige o elimina el pago desde el detalle de la venta para que el corte de caja y la cuenta del cliente queden consistentes.</p>',
    50,
    1,
    NOW(),
    NOW(),
    NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM help_center_articles
    WHERE module = 'customers' AND title = 'Cómo registrar un abono desde el perfil del cliente'
);

-- ----------------------------------------------------------------------------
-- 6. How to adjust a customer balance manually
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'customers',
    'Cómo ajustar el saldo de un cliente manualmente',
    'Corrige el saldo con un movimiento o definiendo el saldo final, deja la razón registrada y evita usar el ajuste como si fuera un cobro.',
    '<p>El ajuste manual cambia el saldo del cliente sin registrar un cobro ni una venta. Sirve para corregir diferencias, aplicar bonificaciones o capturar un saldo que no corresponde a ningún documento.</p>
<h3>Cuándo usarlo</h3>
<ul>
<li>Para corregir un error de captura que ya no se puede rastrear a un documento.</li>
<li>Para aplicar una bonificación o un cargo acordado con el cliente.</li>
<li>Para regularizar una cuenta que se migró desde otro sistema.</li>
</ul>
<h3>Cuándo no usarlo</h3>
<ul>
<li>Para registrar un cobro: usa "Abonar / agregar saldo" para que el dinero quede en la caja y en la cuenta bancaria.</li>
<li>Para cancelar una venta: usa la cancelación desde el detalle de la venta, que revierte inventario y saldo.</li>
<li>Para cuadrar la caja: el ajuste no mueve efectivo, así que no corrige el corte.</li>
</ul>
<h3>Registra el ajuste</h3>
<ol>
<li>Abre el perfil del cliente y elige "Ajuste de saldo manual" en el menú de acciones.</li>
<li>Elige el modo: "Aplicar movimiento" para sumar o restar un monto, o "Definir saldo final" para fijar el saldo total de la cuenta.</li>
<li>Indica la dirección. Con "Aplicar movimiento" puedes elegir "Sumar (+)" o "Restar (-)". Con "Definir saldo final" eliges si la cuenta queda "A favor (Positivo)" o "Deudor (Negativo)".</li>
<li>Captura el monto del movimiento o el nuevo saldo total, según el modo.</li>
<li>Escribe la razón del ajuste. Es obligatoria, admite hasta 255 caracteres y quedará visible en el historial del cliente.</li>
<li>Pulsa "Aplicar ajuste" y confirma. El sistema responde con el mensaje "Saldo del cliente ajustado con éxito".</li>
</ol>
<h3>Qué deja registrado</h3>
<ul>
<li>Un movimiento con el tipo "ajuste manual" y la razón que escribiste, con fecha y saldo resultante.</li>
<li>La actualización inmediata del saldo y del crédito disponible en el perfil y en el directorio.</li>
<li>Ningún movimiento de caja ni bancario.</li>
</ul>
<h3>Recomendaciones</h3>
<ul>
<li>Escribe una razón clara y verificable: también se mostrará en el estado de cuenta del cliente.</li>
<li>Usa "Aplicar movimiento" cuando conozcas la diferencia exacta; usa "Definir saldo final" solo cuando hayas conciliado la cuenta completa.</li>
<li>Documenta el ajuste por otro medio si implica un acuerdo con el cliente, para tener respaldo fuera del sistema.</li>
<li>Necesitas el permiso "Editar clientes existentes" para aplicar ajustes.</li>
</ul>',
    60,
    1,
    NOW(),
    NOW(),
    NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM help_center_articles
    WHERE module = 'customers' AND title = 'Cómo ajustar el saldo de un cliente manualmente'
);

-- ----------------------------------------------------------------------------
-- 7. How to print the account statement and the customer sheet
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'customers',
    'Cómo imprimir el estado de cuenta y la ficha del cliente',
    'Genera el estado de cuenta con todos los movimientos y el saldo resultante, o imprime la ficha del cliente para entregarla o enviarla por WhatsApp.',
    '<p>El módulo de Clientes ofrece dos impresiones distintas: el estado de cuenta, que documenta todo el historial, y la ficha o ticket, que resume la situación del cliente para entregar en mostrador o enviar por mensaje.</p>
<h3>Estado de cuenta</h3>
<ol>
<li>Abre el perfil del cliente y elige "Estado de cuenta (PDF)" en el menú de acciones. También puedes generarlo desde el menú de la fila en el directorio.</li>
<li>El documento se abre en una pestaña nueva con el encabezado del negocio y la sucursal, el nombre del cliente y la fecha de generación.</li>
<li>Revisa el resumen del cliente: nombre, empresa, contacto, saldo actual y límite de crédito.</li>
<li>Pulsa "Imprimir / Guardar PDF" y elige la impresora o la opción de guardar como PDF en el diálogo del navegador.</li>
</ol>
<p>El historial de movimientos muestra una fila por movimiento con las columnas fecha, tipo, descripción, monto y saldo resultante, de modo que el cliente puede seguir cómo llegó a su saldo actual. Si no hay movimientos, el documento indica "Sin movimientos registrados".</p>
<h3>Ficha o ticket del cliente</h3>
<ol>
<li>Abre el perfil del cliente y elige "Imprimir Ficha / Ticket" en el menú de acciones.</li>
<li>Se abre el modal de impresión con las plantillas disponibles para clientes. Elige la plantilla que quieras usar.</li>
<li>Revisa la vista previa: saldo actual presentado como deuda o a favor, ventas pendientes y último pago registrado.</li>
<li>Imprime, guarda el documento o envíalo por WhatsApp. Antes de enviarlo puedes corregir el teléfono del cliente.</li>
</ol>
<p>Solo aparecen las plantillas de tipo ticket o etiqueta configuradas para clientes o de uso general. Si tu negocio no tiene una plantilla adecuada, solicita que la configuren para la sucursal.</p>
<h3>Cuál usar</h3>
<ul>
<li><strong>Estado de cuenta:</strong> documento de cobranza. Incluye todos los movimientos y el saldo resultante; conviene para aclaraciones, entregas formales y respaldos.</li>
<li><strong>Ficha o ticket:</strong> resumen rápido. Sirve para entregar en mostrador o enviar por WhatsApp con la deuda y lo pendiente de entrega.</li>
</ul>
<h3>Recomendaciones</h3>
<ul>
<li>Necesitas el permiso "Ver detalles de clientes" para acceder a estas impresiones.</li>
<li>Imprime el estado de cuenta antes de eliminar un cliente: su historial de movimientos se borra con la cuenta.</li>
<li>Guarda el PDF en el expediente del cliente cuando el documento respalde un acuerdo de pago.</li>
<li>Verifica el teléfono antes de enviar la ficha por WhatsApp para no compartir información con un número equivocado.</li>
</ul>',
    70,
    1,
    NOW(),
    NOW(),
    NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM help_center_articles
    WHERE module = 'customers' AND title = 'Cómo imprimir el estado de cuenta y la ficha del cliente'
);

-- ----------------------------------------------------------------------------
-- 8. How to export the customer directory
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'customers',
    'Cómo exportar el directorio de clientes',
    'Descarga un archivo de Excel con tus clientes, conoce qué columnas incluye y qué sucursales abarca antes de compartirlo.',
    '<p>La exportación genera un archivo de Excel con los clientes de tu suscripción. Es útil para respaldar información, preparar campañas o revisar datos incompletos sin recorrer el directorio cliente por cliente.</p>
<h3>Genera el archivo</h3>
<ol>
<li>Entra a Clientes desde el menú.</li>
<li>Junto al botón "Nuevo cliente", pulsa la flecha del menú.</li>
<li>Elige "Exportar clientes". El archivo se descarga automáticamente con el nombre clientes.xlsx.</li>
<li>Abre el archivo en Excel, Numbers o cualquier hoja de cálculo compatible.</li>
</ol>
<h3>Qué columnas incluye</h3>
<ul>
<li>Nombre</li>
<li>Empresa</li>
<li>Email</li>
<li>Teléfono</li>
<li>RFC</li>
<li>Límite de crédito</li>
</ul>
<h3>Qué clientes incluye</h3>
<p>La exportación abarca los clientes registrados en la suscripción, no solo los de la sucursal en la que tienes sesión. Tenlo en cuenta al compartir el archivo dentro del negocio.</p>
<h3>Usos recomendados</h3>
<ul>
<li>Respaldo periódico del directorio de clientes.</li>
<li>Base para campañas de WhatsApp o correo con clientes identificados.</li>
<li>Revisión de RFC faltantes y de límites de crédito antes de un cierre.</li>
</ul>
<h3>Recomendaciones</h3>
<ul>
<li>Necesitas el permiso "Importar y exportar clientes". Si no ves la opción, pide a un administrador que lo habilite.</li>
<li>El archivo contiene datos personales: guárdalo en un lugar seguro y no lo compartas fuera del negocio.</li>
<li>No borres ni renombres los encabezados si piensas usar el archivo como base para actualizar información.</li>
<li>El archivo se genera al momento: si acabas de modificar un cliente, vuelve a exportar para obtener los datos actualizados.</li>
</ul>',
    80,
    1,
    NOW(),
    NOW(),
    NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM help_center_articles
    WHERE module = 'customers' AND title = 'Cómo exportar el directorio de clientes'
);

-- ----------------------------------------------------------------------------
-- 9. How to delete a customer and what happens to their history
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'customers',
    'Cómo eliminar un cliente y qué pasa con su historial',
    'Borra uno o varios clientes, entiende qué ocurre con sus ventas, apartados y movimientos de saldo, y evita perder información que necesitas.',
    '<p>Eliminar un cliente lo quita del directorio de forma definitiva. Antes de hacerlo revisa qué ocurre con su historial para no perder información que necesitas para cobrar o para tu contabilidad.</p>
<h3>Qué ocurre al eliminar un cliente</h3>
<ul>
<li>El cliente desaparece del directorio, de la búsqueda y de los selectores de venta.</li>
<li>Sus ventas y órdenes se conservan en el historial del negocio, pero quedan sin cliente asignado.</li>
<li>Su historial de movimientos de saldo se elimina, incluidos abonos, cargos y ajustes.</li>
<li>Sus apartados activos quedan sin dueño registrado, lo que dificulta cobrarlos y entregar la mercancía.</li>
<li>La acción no se puede deshacer: no existe una papelera de clientes.</li>
</ul>
<h3>Elimina un cliente</h3>
<ol>
<li>En el directorio, pulsa el menú de la fila del cliente y elige "Eliminar".</li>
<li>Lee el mensaje de confirmación y pulsa "Sí, eliminar".</li>
<li>El sistema responde con el mensaje "Cliente eliminado con éxito".</li>
</ol>
<h3>Elimina varios clientes</h3>
<ol>
<li>Marca las casillas de los clientes que quieres borrar.</li>
<li>En la barra de selección pulsa "Eliminar".</li>
<li>Revisa cuántos clientes seleccionaste y confirma con "Sí, eliminar".</li>
</ol>
<h3>Antes de eliminar, verifica</h3>
<ul>
<li>Que el saldo esté en cero. Si hay deuda, cóbrala o ajústala antes de borrar la cuenta.</li>
<li>Que no tenga apartados activos ni ventas pendientes de entrega.</li>
<li>Que no necesites su historial: imprime el estado de cuenta y guárdalo como respaldo.</li>
</ul>
<h3>Alternativas a eliminar</h3>
<ul>
<li>Si solo quieres dejar de venderle a crédito, ajusta su límite de crédito a cero y conserva el historial.</li>
<li>Si el cliente pidió la baja de sus datos, elimínalo y conserva el estado de cuenta impreso como respaldo contable.</li>
<li>Si solo está inactivo, déjalo registrado: eliminar la cuenta borra la trazabilidad de sus pagos.</li>
</ul>
<p>Necesitas el permiso "Eliminar clientes" para borrar cuentas, tanto de forma individual como masiva.</p>',
    90,
    1,
    NOW(),
    NOW(),
    NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM help_center_articles
    WHERE module = 'customers' AND title = 'Cómo eliminar un cliente y qué pasa con su historial'
);

-- ----------------------------------------------------------------------------
-- 10. Which permissions control the customers module
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'customers',
    'Qué permisos controlan el módulo de clientes',
    'Revisa qué habilita cada permiso del módulo, dónde se refleja en la pantalla y cómo evitar que un usuario vea información financiera que no le corresponde.',
    '<p>El acceso al módulo de Clientes se controla con permisos independientes. Un usuario puede consultar el directorio sin ver saldos, o editar clientes sin poder eliminarlos. Asígnelos según la función de cada persona.</p>
<h3>Permisos del módulo</h3>
<ul>
<li><strong>Ver listado de clientes:</strong> muestra el módulo en el menú y permite abrir el directorio de la sucursal.</li>
<li><strong>Ver detalles de clientes:</strong> permite abrir el perfil del cliente, consultar su historial, generar el estado de cuenta y usar "Imprimir Ficha / Ticket".</li>
<li><strong>Crear nuevos clientes:</strong> habilita el botón "Nuevo cliente" y el guardado de cuentas nuevas.</li>
<li><strong>Editar clientes existentes:</strong> permite abrir el formulario de edición, registrar abonos desde el perfil y aplicar ajustes de saldo manuales.</li>
<li><strong>Eliminar clientes:</strong> habilita la eliminación individual y la eliminación masiva desde el directorio.</li>
<li><strong>Registrar venta desde perfil de cliente:</strong> permiso reservado. Hoy la venta se registra desde el punto de venta y este permiso no agrega ningún botón adicional en el perfil.</li>
<li><strong>Importar y exportar clientes:</strong> habilita la exportación del directorio a Excel y la importación cuando está disponible en la interfaz.</li>
<li><strong>Ver información financiera de clientes:</strong> muestra las columnas de saldo, apartados y límite de crédito en el directorio, la tarjeta "Estado financiero" en el perfil y el bloque financiero del panel lateral.</li>
</ul>
<h3>Dónde se refleja cada permiso</h3>
<ul>
<li>En el menú lateral: si falta el permiso de listado, el módulo Clientes no aparece.</li>
<li>En los botones del directorio y del perfil: nuevo, editar, eliminar y estado de cuenta se ocultan cuando falta el permiso correspondiente.</li>
<li>En las columnas de la tabla: saldo, apartados y límite de crédito solo se muestran con el permiso de información financiera.</li>
<li>En el panel lateral: el bloque "Estado financiero" se oculta si no hay permiso para ver información financiera.</li>
</ul>
<h3>Recomendaciones de asignación</h3>
<ul>
<li>Cajeros y vendedores: listado, detalles y creación de clientes. La información financiera solo si necesitan consultar saldos en mostrador.</li>
<li>Encargados de sucursal: los permisos anteriores más edición, abonos y ajustes de saldo.</li>
<li>Administradores: todos los permisos, incluida la eliminación y la exportación.</li>
<li>Personal contable: listado, detalles, información financiera y exportación, sin permisos de edición ni eliminación.</li>
</ul>
<h3>Cuidados de seguridad</h3>
<ul>
<li>El permiso de información financiera expone deudas y límites de crédito: asígnalo solo a quien lo necesite.</li>
<li>Quien pueda editar clientes puede mover saldos con abonos y ajustes; revisa periódicamente quién tiene ese permiso.</li>
<li>La exportación entrega datos personales de todas las sucursales de la suscripción: limita el permiso y el manejo del archivo.</li>
<li>Los permisos se administran por rol; si necesitas un ajuste puntual, crea un rol específico en lugar de modificar uno existente.</li>
</ul>',
    100,
    1,
    NOW(),
    NOW(),
    NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM help_center_articles
    WHERE module = 'customers' AND title = 'Qué permisos controlan el módulo de clientes'
);


