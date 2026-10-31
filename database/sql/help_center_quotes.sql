-- ============================================================================
-- Help center - Guides for the Cotizaciones (quotes) module
--
-- Inserts 13 published guides for the "quotes" module of the
-- help_center_articles table. The guides automatically appear in the
-- Cotizaciones card of the help center.
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
-- 1. First steps with quotes
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'quotes',
    'Primeros pasos con cotizaciones',
    'Conoce el flujo de una cotización, cómo se asignan los folios, qué significa cada estatus y qué necesitas antes de crear tu primera propuesta.',
    '<p>El módulo de Cotizaciones te permite preparar propuestas comerciales con productos y servicios de tu catálogo, compartirlas con tus clientes y convertir las que acepten en una venta. Cada cotización conserva su folio, sus importes y el historial de cambios de estatus.</p>
<h3>Dónde encontrarlo</h3>
<ul>
<li>En el menú lateral, entra a "Cotizaciones". Se abre el "Catálogo de cotizaciones" con las propuestas de tu sucursal.</li>
<li>El botón "Nueva cotización" abre el formulario de captura. Solo aparece si tienes permiso para crear cotizaciones.</li>
<li>El buscador superior filtra por folio o por nombre del cliente.</li>
<li>Al hacer clic en una fila se abre el detalle de la cotización, ya sea en el panel lateral "Detalles rápidos" o en la vista completa, según la preferencia guardada en tu perfil.</li>
</ul>
<h3>Cómo se numera una cotización</h3>
<p>Al guardar la primera cotización, el sistema asigna un folio consecutivo por sucursal con el formato COT-001, COT-002, y así sucesivamente. Las versiones de una misma cotización agregan el sufijo -V2, -V3: por ejemplo, COT-001-V2. El folio no se captura a mano y no cambia si editas la cotización.</p>
<h3>El flujo de estatus</h3>
<ol>
<li><strong>Borrador:</strong> la cotización se creó y todavía puedes modificarla. Es el estado inicial.</li>
<li><strong>Enviado:</strong> la propuesta ya se compartió con el cliente. Puedes seguir editándola si necesitas corregir algo.</li>
<li><strong>Autorizada:</strong> el cliente aceptó la propuesta. Es el paso previo obligatorio para generar la venta.</li>
<li><strong>Venta generada:</strong> se creó una venta a partir de la cotización, se descontó el inventario y, si hay cliente, se registró la deuda.</li>
<li><strong>Cancelada:</strong> la venta derivada se canceló, el inventario regresó y la deuda del cliente se revirtió.</li>
</ol>
<p>El detalle de la cotización muestra el "Flujo de estatus" con estos cuatro pasos. Los cambios se hacen desde ahí y siempre avanzan hacia adelante.</p>
<h3>Qué puedes hacer desde el módulo</h3>
<ul>
<li>Crear cotizaciones con productos, servicios, conceptos libres, descuentos, IVA y costo de envío.</li>
<li>Imprimir o generar el PDF con el formato estándar del sistema o con una plantilla personalizada.</li>
<li>Crear versiones para conservar el historial cuando cambian precios o condiciones.</li>
<li>Convertir una cotización autorizada en venta y consultar la transacción resultante.</li>
<li>Agregar campos personalizados si tu operación necesita datos adicionales.</li>
<li>Eliminar cotizaciones que no tengan una venta generada.</li>
</ul>
<h3>Antes de empezar</h3>
<ul>
<li>Necesitas acceso al módulo de Cotizaciones. Las acciones de crear, editar, eliminar, cambiar estatus, convertir a venta y administrar campos personalizados tienen permisos independientes.</li>
<li>Los productos y servicios deben existir en tu catálogo para que aparezcan en el buscador de conceptos. También puedes capturar un concepto libre escribiendo su descripción.</li>
<li>El cliente es opcional. Si no seleccionas uno, puedes capturar los datos de quien recibe la cotización.</li>
<li>Si vas a convertir la cotización en venta, revisa que los productos tengan existencias en la sucursal.</li>
</ul>
<h3>Estatus que pueden aparecer en el catálogo</h3>
<p>Además de los estatus del flujo, el catálogo puede mostrar "Rechazada" y "Expirada". Son etiquetas informativas: la interfaz actual no incluye una acción para asignarlas manualmente ni cambia el estatus de forma automática al llegar la fecha de vencimiento. La fecha de expiración se guarda como referencia y se muestra en la columna "Vencimiento".</p>
<h3>Recomendaciones</h3>
<ul>
<li>Usa la fecha de expiración para dejar claro hasta cuándo es válida la propuesta, aunque el sistema no la cierre automáticamente.</li>
<li>Cuando el cliente pida cambios después de autorizar, crea una nueva versión en lugar de sobrescribir la anterior.</li>
<li>No elimines cotizaciones con venta generada: primero cancela la venta desde el detalle.</li>
</ul>',
    10,
    1,
    NOW(),
    NOW(),
    NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM help_center_articles
    WHERE module = 'quotes' AND title = 'Primeros pasos con cotizaciones'
);

-- ----------------------------------------------------------------------------
-- 2. Create a quote
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'quotes',
    'Cómo crear una cotización',
    'Captura el cliente, la vigencia, los conceptos y los importes de una propuesta, y guárdala como borrador para darle seguimiento.',
    '<p>Una cotización reúne los conceptos que ofreces, sus precios y las condiciones de la propuesta. Al guardarla queda en estatus "Borrador" y puedes editarla, imprimirla o avanzar su estatus cuando tengas la respuesta del cliente.</p>
<h3>Antes de empezar</h3>
<ul>
<li>Verifica que los productos o servicios que vas a cotizar existan en el catálogo de tu sucursal. Si no existen, agrégalos primero o captura un concepto libre.</li>
<li>Si el cliente no está registrado, puedes crearlo con el botón "Nuevo" sin salir del formulario.</li>
<li>Necesitas permiso para crear cotizaciones. Si no ves "Nueva cotización", pide a un administrador que te lo asigne.</li>
</ul>
<h3>Paso 1. Abre el formulario</h3>
<ol>
<li>Entra a "Cotizaciones" desde el menú lateral.</li>
<li>Pulsa "Nueva cotización". Se abre la pantalla "Crear nueva cotización".</li>
</ol>
<h3>Paso 2. Identifica al cliente</h3>
<ol>
<li>En "Cliente (Opcional)", selecciona un cliente de la lista o usa el buscador del campo. Puedes dejar el campo vacío si la propuesta es para alguien que no está registrado.</li>
<li>Para registrar un cliente nuevo, pulsa "Nuevo". Al terminar, el cliente se selecciona automáticamente en la cotización.</li>
<li>Al elegir un cliente, su nombre se copia en "Nombre de quien recibe". Ajusta ese campo si la propuesta va dirigida a otra persona.</li>
<li>Captura "Teléfono de quien recibe" y "Email de quien recibe" si los tienes. Son datos de contacto para la propuesta.</li>
</ol>
<h3>Paso 3. Define la vigencia</h3>
<p>"Fecha de expiración" es opcional. Selecciona la fecha hasta la que consideras válida la propuesta. El sistema la guarda y la muestra en la columna "Vencimiento" del catálogo, pero no cambia el estatus de la cotización de forma automática al llegar esa fecha.</p>
<h3>Paso 4. Agrega los conceptos</h3>
<ol>
<li>En la sección "Conceptos", usa el buscador "Busca o escribe un concepto..." para localizar un producto o servicio del catálogo.</li>
<li>Selecciona el resultado y pulsa "Agregar". El concepto aparece en la tabla con su tipo, descripción, cantidad, precio unitario y total.</li>
<li>Si el producto o servicio tiene variantes, se abre el selector para que elijas la que corresponde. Puedes cambiarla después con "Cambiar variante".</li>
<li>Ajusta la cantidad y el precio unitario si la propuesta lo requiere. El total de la línea se calcula automáticamente.</li>
<li>Repite el proceso para cada concepto. Para quitar una línea, usa el ícono de papelera y confirma la eliminación.</li>
</ol>
<p>También puedes escribir un concepto que no esté en el catálogo: escribe la descripción en el buscador, selecciona la opción para agregarlo como concepto libre y captura cantidad y precio. Los conceptos libres no descuentan inventario. La guía "Cómo agregar conceptos y variantes a una cotización" explica este paso con más detalle.</p>
<h3>Paso 5. Revisa descuento, impuestos y envío</h3>
<ol>
<li>Activa "Desglosar impuestos (IVA 16%)" si la propuesta debe mostrar el IVA. Después elige "Precio + IVA" o "Precio con IVA incluido".</li>
<li>Captura el "Descuento" global en pesos. Se aplica al subtotal de todos los conceptos.</li>
<li>Captura el "Costo de envío / visita" si aplica. Se suma al total.</li>
<li>Revisa "Subtotal", "Impuestos" y "Total". Los tres campos se recalculan al cambiar conceptos, descuento, envío o la forma de aplicar el IVA.</li>
</ol>
<p>La guía "Descuentos, impuestos y costo de envío en cotizaciones" explica cómo se calcula cada importe.</p>
<h3>Paso 6. Completa la entrega y las notas</h3>
<ol>
<li>Captura la "Dirección de envío" si la propuesta incluye entrega a domicilio.</li>
<li>Usa "Notas adicionales" para condiciones, tiempos de entrega o cualquier aclaración que deba leer el cliente. Las notas se incluyen en el detalle y en la impresión.</li>
<li>Si tu operación usa campos personalizados, completa la sección "Detalles adicionales". Si no hay campos definidos, verás un aviso para gestionarlos o solicitarlos a un administrador.</li>
</ol>
<h3>Paso 7. Guarda la cotización</h3>
<ol>
<li>Pulsa "Crear cotización".</li>
<li>Al terminar, vuelves al "Catálogo de cotizaciones" con el mensaje "Cotización creada con éxito.".</li>
<li>Busca la cotización por su folio o por el nombre del cliente para confirmar que los importes y los conceptos quedaron correctos.</li>
</ol>
<h3>Si necesitas corregirla</h3>
<p>Mientras la cotización esté en "Borrador", "Enviado" o "Autorizada", puedes abrir el menú de la fila o el de la vista de detalle y elegir "Editar". Los cambios actualizan la misma cotización y conservan su folio. Si la propuesta ya cambió de fondo y quieres conservar la anterior, crea una nueva versión en lugar de editarla.</p>
<h3>Recomendaciones</h3>
<ul>
<li>Captura la fecha de expiración para que el cliente sepa hasta cuándo puede aceptar.</li>
<li>Usa notas claras sobre anticipos, tiempos de entrega y garantías; se imprimen junto con la propuesta.</li>
<li>No elimines cotizaciones que ya generaron una venta. En esos casos, el detalle ofrece "Cancelar venta" para revertir inventario y deuda.</li>
</ul>',
    20,
    1,
    NOW(),
    NOW(),
    NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM help_center_articles
    WHERE module = 'quotes' AND title = 'Cómo crear una cotización'
);

-- ----------------------------------------------------------------------------
-- 3. Concepts and variants
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'quotes',
    'Cómo agregar conceptos y variantes a una cotización',
    'Agrega productos y servicios del catálogo, elige variantes o captura conceptos libres, y ajusta cantidades y precios en cada línea.',
    '<p>Los conceptos son las líneas de la propuesta: cada producto, servicio o concepto libre con su cantidad y su precio. Puedes combinar artículos del catálogo con conceptos escritos a mano en la misma cotización.</p>
<h3>Agrega un producto o servicio del catálogo</h3>
<ol>
<li>En la sección "Conceptos", escribe el nombre en "Busca o escribe un concepto...". La lista muestra productos y servicios de tu sucursal, con una etiqueta que indica si es "Producto" o "Servicio".</li>
<li>Selecciona el artículo correcto. Si hay varios con el mismo nombre, revisa el tipo y el precio antes de elegir.</li>
<li>Pulsa "Agregar". La línea aparece en la tabla con el tipo, la descripción, la cantidad en 1, el precio del catálogo y el total calculado.</li>
<li>Ajusta la cantidad con los botones del campo o escríbela directamente. La cantidad mínima es 1.</li>
<li>Ajusta el precio unitario si necesitas dar un precio distinto en esta propuesta. El total de la línea se actualiza al instante.</li>
</ol>
<p>El precio que cambies en la cotización no modifica el precio del catálogo. Cada cotización conserva el importe con el que se guardó.</p>
<h3>Elige una variante</h3>
<ol>
<li>Si el producto o servicio tiene variantes, al pulsar "Agregar" se abre el selector de variantes.</li>
<li>Elige la combinación o la variante que corresponde. El sistema completa la descripción y el precio con los datos de la variante.</li>
<li>Confirma la selección. La línea queda ligada a esa variante y la descripción la incluye, por ejemplo "Camisa (Talla M, Color azul)".</li>
<li>Si te equivocas, pulsa "Cambiar variante" debajo de la descripción y elige otra. La descripción y el precio se actualizan.</li>
</ol>
<p>La variante se muestra entre paréntesis en el detalle y en la impresión. Los productos con variantes descuentan el inventario de la variante seleccionada cuando la cotización se convierte en venta.</p>
<h3>Captura un concepto libre</h3>
<ol>
<li>Escribe la descripción completa en el buscador, por ejemplo "Instalación de tubería adicional".</li>
<li>Si no seleccionas una sugerencia, el texto se toma como concepto libre. Pulsa "Agregar".</li>
<li>En la columna "Tipo", elige "Producto" o "Servicio" según corresponda. Esta opción solo se puede cambiar en los conceptos libres; en los artículos del catálogo queda bloqueada.</li>
<li>Captura la cantidad y el precio unitario. Los conceptos libres no descuentan inventario ni se vinculan a un artículo del catálogo.</li>
</ol>
<h3>Edita o elimina líneas</h3>
<ul>
<li>La descripción, la cantidad y el precio unitario se editan directamente en la tabla. El total de la línea se recalcula con la cantidad por el precio.</li>
<li>Si dejas el precio vacío, el sistema lo toma como cero al salir del campo.</li>
<li>Para quitar una línea, pulsa el ícono de papelera. Aparece la confirmación "¿Estás seguro de que quieres eliminar este elemento?"; elige "Sí" para borrarla.</li>
<li>Puedes agregar el mismo artículo varias veces si necesitas separar partidas. El sistema no combina líneas automáticamente.</li>
</ul>
<h3>Verifica después de guardar</h3>
<ol>
<li>Al guardar la cotización, abre su detalle y revisa la tabla "Conceptos".</li>
<li>Confirma que cada línea tenga la descripción, la variante, la cantidad y el precio correctos.</li>
<li>Si algo no coincide, elige "Editar" mientras la cotización esté en "Borrador", "Enviado" o "Autorizada".</li>
</ol>
<h3>Recomendaciones</h3>
<ul>
<li>Usa artículos del catálogo siempre que existan: mantienen precios y variantes consistentes y permiten descontar inventario al convertir la venta.</li>
<li>Reserva los conceptos libres para cargos que no tienen un artículo propio, como maniobras, fletes especiales o trabajos a la medida.</li>
<li>Describe la variante en la descripción del concepto libre si el cliente necesita distinguirla, por ejemplo "Servicio a domicilio, zona norte".</li>
<li>Revisa las cantidades antes de convertir la cotización en venta: la venta descuenta exactamente lo que está en las líneas.</li>
</ul>',
    30,
    1,
    NOW(),
    NOW(),
    NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM help_center_articles
    WHERE module = 'quotes' AND title = 'Cómo agregar conceptos y variantes a una cotización'
);

-- ----------------------------------------------------------------------------
-- 4. Discounts, taxes and shipping
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'quotes',
    'Descuentos, impuestos y costo de envío en cotizaciones',
    'Aprende cómo se calculan el descuento global, el IVA y el costo de envío, y elige entre precios con IVA sumado o con IVA incluido.',
    '<p>La sección de totales de la cotización combina tres conceptos: el descuento global, el IVA y el costo de envío o visita. El sistema recalcula el subtotal, los impuestos y el total cada vez que cambias un concepto, una cantidad, un precio o una de estas opciones.</p>
<h3>Descuento global</h3>
<ul>
<li>El campo "Descuento" se captura en pesos y se aplica al subtotal de todos los conceptos, no línea por línea.</li>
<li>El descuento se resta antes de calcular el IVA. Por eso también reduce la base sobre la que se cobra el impuesto.</li>
<li>El descuento aparece como una columna editable en la sección de totales. El subtotal, los impuestos y el total son de solo lectura.</li>
<li>Captura un descuento menor o igual al subtotal. Si lo superas, el total puede quedar en negativo o en cero, y la propuesta pierde sentido comercial.</li>
</ul>
<h3>Activa o desactiva el IVA</h3>
<ol>
<li>El interruptor "Desglosar impuestos (IVA 16%)" viene desactivado. En ese caso la cotización no guarda tasa ni tipo de impuesto y el total es el subtotal menos el descuento más el envío.</li>
<li>Actívalo si la propuesta debe mostrar el IVA. La tasa es fija del 16 por ciento.</li>
<li>Al activarlo, elige una de las dos formas de aplicar el impuesto: "Precio + IVA" o "Precio con IVA incluido".</li>
<li>Si lo desactivas después de capturar importes, el IVA vuelve a cero y el total baja. Revisa la cotización antes de guardar.</li>
</ol>
<h3>Precio + IVA</h3>
<p>Los precios unitarios se interpretan como importes sin impuesto. El sistema calcula el IVA sobre el subtotal menos el descuento y lo suma al total.</p>
<ul>
<li>Fórmula del impuesto: (subtotal menos descuento) por 0.16.</li>
<li>Fórmula del total: subtotal menos descuento, más impuesto, más costo de envío.</li>
<li>Ejemplo: conceptos por 1,000.00, descuento de 100.00 y envío de 50.00. El IVA es 144.00 y el total es 1,094.00.</li>
</ul>
<h3>Precio con IVA incluido</h3>
<p>Los precios unitarios ya incluyen el IVA. El sistema toma el subtotal menos el descuento como importe final con impuesto y separa la base gravable.</p>
<ul>
<li>Fórmula de la base: (subtotal menos descuento) entre 1.16.</li>
<li>Fórmula del impuesto: el importe con IVA menos la base.</li>
<li>Fórmula del total: base más impuesto, más costo de envío. Es decir, el subtotal menos el descuento, más el envío.</li>
<li>Ejemplo: conceptos por 1,000.00, descuento de 100.00 y envío de 50.00. La base es 775.86, el IVA es 124.14 y el total es 950.00.</li>
</ul>
<p>En esta modalidad, el campo "Subtotal" muestra la base sin IVA, no la suma directa de los precios capturados. Es normal que sea menor que la suma de las líneas.</p>
<h3>Costo de envío o visita</h3>
<ul>
<li>Captura el importe en "Costo de envío / visita". Se suma al total después del IVA, por lo que no forma parte de la base del impuesto.</li>
<li>Déjalo en cero si la propuesta no incluye entrega o visita.</li>
<li>Si el envío también debe llevar IVA, inclúyelo en el precio de los conceptos o ajusta el importe manualmente antes de guardar.</li>
</ul>
<h3>Revisa antes de guardar</h3>
<ol>
<li>Confirma que el interruptor de IVA esté en la posición correcta.</li>
<li>Verifica la modalidad elegida: "Precio + IVA" suma el impuesto; "Precio con IVA incluido" lo separa del precio.</li>
<li>Revisa el descuento y el envío. Cambiar cualquiera de los dos altera el total.</li>
<li>Compara el total con la propuesta que enviaste al cliente. Si no coincide, corrige antes de imprimir o convertir a venta.</li>
</ol>
<h3>Recomendaciones</h3>
<ul>
<li>Define con tu equipo una sola modalidad de IVA para evitar diferencias entre cotizaciones parecidas.</li>
<li>Si el cliente pide un precio cerrado con impuesto, usa "Precio con IVA incluido" y revisa la base que se muestra en el detalle.</li>
<li>Documenta el descuento en "Notas adicionales" cuando sea una condición especial, para que quede claro en la propuesta impresa.</li>
<li>Al convertir a venta se usan los importes guardados en la cotización; no se recalculan impuestos ni descuentos.</li>
</ul>',
    40,
    1,
    NOW(),
    NOW(),
    NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM help_center_articles
    WHERE module = 'quotes' AND title = 'Descuentos, impuestos y costo de envío en cotizaciones'
);

-- ----------------------------------------------------------------------------
-- 5. Status reference
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'quotes',
    'Estatus de una cotización y qué significa cada uno',
    'Conoce los siete estatus de una cotización, cuáles son parte del flujo normal y cuáles se muestran como estados finales.',
    '<p>Cada cotización tiene un estatus que indica en qué punto está la propuesta. En la parte superior del detalle aparece el flujo de estatus con cuatro pasos: Borrador, Enviado, Autorizada y Venta generada. Los demás estatus se muestran como estados finales o informativos.</p>
<h3>Estatus del flujo</h3>
<ul>
<li><strong>Borrador:</strong> la cotización se creó pero todavía no se ha enviado al cliente. Es el estatus inicial y puedes editarla libremente.</li>
<li><strong>Enviado:</strong> la propuesta ya se compartió con el cliente y está pendiente de respuesta. Todavía puedes editarla si necesitas corregir algo.</li>
<li><strong>Autorizada:</strong> el cliente aceptó la propuesta. Desde aquí puedes convertirla en venta o seguir editándola si hay un ajuste acordado.</li>
<li><strong>Venta generada:</strong> la cotización ya produjo una venta. El inventario se descontó y la cotización queda vinculada a esa venta. Desde este estatus solo puedes cancelar la venta o consultar el documento.</li>
</ul>
<h3>Estatus finales e informativos</h3>
<ul>
<li><strong>Rechazada:</strong> el cliente declinó la propuesta. Se muestra con un aviso en rojo. No se asigna desde el flujo de la pantalla; proviene de registros creados por otra vía.</li>
<li><strong>Expirada:</strong> la propuesta perdió vigencia. Se muestra con un aviso naranja. El sistema no cambia este estatus automáticamente por la fecha de validez; solo aparece si el registro ya lo tiene guardado.</li>
<li><strong>Cancelada:</strong> la venta derivada de la cotización fue cancelada. Se muestra un aviso que indica que la venta se canceló y el inventario se liberó. Solo se llega a este estatus desde "Venta generada" con la acción "Cancelar venta".</li>
</ul>
<h3>Cómo se ve el estatus en pantalla</h3>
<ul>
<li>El paso actual se resalta en azul y los pasos anteriores aparecen completados.</li>
<li>Los pasos posteriores se ven en gris. Puedes pulsarlos para avanzar si tienes permiso para cambiar el estatus.</li>
<li>En "Rechazada", "Expirada" y "Cancelada" el flujo se sustituye por un aviso con el motivo del estado final.</li>
<li>La lista de cotizaciones y la tarjeta de resumen usan una etiqueta de color para identificar el estatus de un vistazo.</li>
</ul>
<h3>Reglas importantes</h3>
<ul>
<li>El flujo de la pantalla solo avanza hacia adelante: de Borrador a Enviado, de Enviado a Autorizada y de Autorizada a Venta generada. No se regresa a un paso anterior desde el detalle.</li>
<li>Llegar a "Venta generada" crea una venta y descuenta inventario. No es un cambio de etiqueta: tiene efectos en caja, cliente e inventario.</li>
<li>Una cotización eliminada desaparece del listado. Si ya generó una venta, el sistema no permite eliminarla; primero debes cancelar la venta.</li>
<li>La fecha de validez es informativa. Sirve para acordar con el cliente hasta cuándo se respeta el precio, pero no cambia el estatus por sí sola.</li>
</ul>
<h3>Qué revisar en cada estatus</h3>
<ol>
<li>En Borrador, completa conceptos, cliente, condiciones y totales antes de enviar.</li>
<li>En Enviado, da seguimiento con el cliente y registra la respuesta.</li>
<li>En Autorizada, confirma que el cliente aceptó el total y las condiciones antes de convertir.</li>
<li>En Venta generada, revisa la venta vinculada y los pagos registrados.</li>
<li>En Cancelada, verifica que el inventario se haya liberado y que la venta aparezca como cancelada o reembolsada.</li>
</ol>',
    50,
    1,
    NOW(),
    NOW(),
    NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM help_center_articles
    WHERE module = 'quotes' AND title = 'Estatus de una cotización y qué significa cada uno'
);

-- ----------------------------------------------------------------------------
-- 6. Advancing status
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'quotes',
    'Cómo avanzar el estatus de una cotización',
    'Avanza de borrador a enviado, autorizada y venta generada desde el flujo de estatus, con las confirmaciones y validaciones del sistema.',
    '<p>El detalle de la cotización incluye un flujo de estatus en la parte superior. Desde ahí puedes mover la propuesta al siguiente paso sin salir de la pantalla. Cada avance pide confirmación y algunos tienen efectos en inventario, cliente y ventas.</p>
<h3>Antes de empezar</h3>
<ul>
<li>Necesitas el permiso "Cambiar estatus de cotizaciones" (quotes.change_status). Si no lo tienes, los pasos del flujo se ven deshabilitados.</li>
<li>La cotización no debe estar en "Rechazada", "Expirada" o "Cancelada". En esos casos el flujo se reemplaza por un aviso y no permite avanzar.</li>
<li>Verifica que los conceptos, el cliente, el descuento y el total sean los definitivos. El flujo no tiene un botón para regresar a un paso anterior.</li>
</ul>
<h3>Avanza al siguiente paso</h3>
<ol>
<li>Abre la cotización desde el listado o desde el mensaje de creación.</li>
<li>Ubica el bloque "Flujo de estatus". El paso actual aparece resaltado en azul.</li>
<li>Pulsa el círculo del estatus al que quieres pasar. Puedes pulsar el paso inmediato o uno posterior; el sistema no obliga a pasar por "Enviado".</li>
<li>Lee el mensaje de confirmación. El encabezado es "Confirmar cambio de estatus".</li>
<li>Elige "Si" para confirmar o "No" para cancelar. Si cancelas, no se guarda ningún cambio.</li>
<li>Espera el aviso de éxito. La pantalla se actualiza y el paso nuevo queda marcado como actual.</li>
</ol>
<h3>Qué ocurre en cada avance</h3>
<ul>
<li><strong>A Enviado:</strong> solo cambia el estatus. Úsalo cuando ya compartiste la propuesta con el cliente. El mensaje de éxito es "Estatus de la cotización actualizado."</li>
<li><strong>A Autorizada:</strong> solo cambia el estatus. Úsalo cuando el cliente aceptó la propuesta. También puedes editar la cotización después, si ambas partes acuerdan un ajuste.</li>
<li><strong>A Venta generada:</strong> crea una venta nueva, copia los conceptos, descuenta inventario y registra la deuda del cliente. El mensaje de confirmación advierte: "Al cambiar el estatus a Venta generada, el sistema creará automáticamente una nueva venta y descontará el inventario. ¿Deseas continuar?".</li>
</ul>
<h3>Requisitos para llegar a Venta generada</h3>
<ul>
<li>La cotización debe estar en "Autorizada". Si intentas generar la venta desde "Borrador" o "Enviado", el sistema muestra el error "Solo las cotizaciones autorizadas pueden convertirse en venta." y no cambia nada.</li>
<li>La cotización no debe tener ya una venta vinculada. Si ya la tiene, el sistema rechaza la operación con "Esta cotización ya tiene una venta asociada.".</li>
<li>Debes tener el permiso para cambiar estatus. La acción "Convertir a venta" del menú de opciones, en cambio, depende del permiso para crear ventas desde cotizaciones (quotes.create_sale).</li>
</ul>
<h3>Buenas prácticas</h3>
<ul>
<li>Avanza a "Enviado" solo cuando la propuesta realmente salió hacia el cliente; así el listado refleja el seguimiento real.</li>
<li>No marques "Autorizada" sin confirmación del cliente. De ese estatus depende la conversión a venta.</li>
<li>Antes de pulsar "Venta generada", revisa el inventario y el costo de envío. La venta se crea con los conceptos de la cotización y no se puede deshacer desde el flujo.</li>
<li>Si necesitas corregir una propuesta ya autorizada, edítala o crea una nueva versión antes de convertirla.</li>
<li>Si te equivocas al avanzar, no hay un botón para retroceder. Contacta a un administrador para revisar el caso antes de generar la venta.</li>
</ul>',
    60,
    1,
    NOW(),
    NOW(),
    NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM help_center_articles
    WHERE module = 'quotes' AND title = 'Cómo avanzar el estatus de una cotización'
);

-- ----------------------------------------------------------------------------
-- 7. Converting a quote to a sale
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'quotes',
    'Cómo convertir una cotización en venta',
    'Convierte una cotización autorizada en una venta con folio propio, descuenta inventario y registra la deuda del cliente.',
    '<p>Cuando el cliente acepta una cotización, puedes convertirla en venta. El sistema crea una venta nueva con folio propio, copia los conceptos, descuenta el inventario y registra el cargo en el estado de cuenta del cliente.</p>
<h3>Requisitos</h3>
<ul>
<li>La cotización debe estar en "Autorizada". Si está en "Borrador" o "Enviado", primero avanza el estatus.</li>
<li>La cotización no debe tener una venta asociada. Cada cotización se convierte una sola vez.</li>
<li>Para usar la acción "Convertir a venta" necesitas el permiso "Crear ventas desde cotizaciones" (quotes.create_sale). Para usar el paso "Venta generada" del flujo necesitas el permiso "Cambiar estatus de cotizaciones" (quotes.change_status).</li>
<li>Revisa antes los conceptos, las cantidades, el descuento y el total. La venta se crea con lo que esté guardado en la cotización.</li>
</ul>
<h3>Convierte desde el menú de opciones</h3>
<ol>
<li>Abre la cotización autorizada y pulsa "Opciones".</li>
<li>Elige "Convertir a venta".</li>
<li>Lee el mensaje: "Se creará una nueva venta (Transacción) con los datos de esta cotización. El estatus cambiará a Venta Generada. ¿Deseas continuar?".</li>
<li>Confirma con "Si". La venta se crea en ese momento.</li>
<li>El sistema te devuelve al detalle de la cotización y muestra un aviso con el folio de la venta: "Cotización convertida a venta con éxito. Folio de Venta: ...".</li>
</ol>
<h3>Convierte desde el flujo de estatus</h3>
<ol>
<li>Abre la cotización autorizada y ubica el bloque "Flujo de estatus".</li>
<li>Pulsa el paso "Venta generada".</li>
<li>Lee la advertencia sobre la creación de la venta y el descuento de inventario.</li>
<li>Confirma con "Si". El sistema crea la venta y actualiza la cotización en la misma pantalla.</li>
<li>El aviso de éxito indica: "Venta generada automáticamente desde el cambio de estatus.".</li>
</ol>
<p>Ambas rutas ejecutan la misma conversión. La diferencia es el permiso requerido y el mensaje que se muestra al terminar.</p>
<h3>Qué crea la conversión</h3>
<ul>
<li>Una venta con folio nuevo de la sucursal, estatus "Pendiente" y canal "Cotización".</li>
<li>Una copia de cada concepto con su descripción, cantidad, precio unitario y total de línea.</li>
<li>El descuento y el impuesto de la cotización en la venta.</li>
<li>El descuento de inventario de los productos con control de existencias. Los servicios y los conceptos libres no descuentan inventario.</li>
<li>El vínculo entre la cotización y la venta, y el cambio de estatus de la cotización a "Venta generada".</li>
<li>Un cargo en el estado de cuenta del cliente por el total de la cotización, si la cotización tiene cliente asignado. Las cotizaciones de público general no generan cargo.</li>
</ul>
<h3>Revisa la venta generada</h3>
<ol>
<li>Anota el folio que aparece en el aviso de éxito.</li>
<li>Busca ese folio en el módulo de ventas para abrir la venta.</li>
<li>Confirma los conceptos, las cantidades y los importes.</li>
<li>Completa los datos de entrega y registra los pagos que correspondan. La venta nace pendiente de pago.</li>
</ol>
<h3>Ten en cuenta</h3>
<ul>
<li>El costo de envío de la cotización no se copia a la venta. Si la propuesta incluye envío, agrégalo en la venta o inclúyelo en el precio de los conceptos antes de convertir.</li>
<li>Las notas y la dirección de envío de la cotización tampoco se copian. Captúralas de nuevo en la venta si son necesarias para la entrega.</li>
<li>Después de convertir no puedes editar la cotización ni eliminarla. Para corregirla tendrías que cancelar la venta, lo que revierte el inventario.</li>
<li>Si la conversión falla, la cotización conserva su estatus y no se descuenta inventario. El sistema muestra el motivo del error en un aviso.</li>
<li>Para una propuesta que el cliente todavía no acepta, no conviertas: mantén la cotización en "Enviado" y espera la confirmación.</li>
</ul>',
    70,
    1,
    NOW(),
    NOW(),
    NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM help_center_articles
    WHERE module = 'quotes' AND title = 'Cómo convertir una cotización en venta'
);

-- ----------------------------------------------------------------------------
-- 8. Cancelling the generated sale and returning stock
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'quotes',
    'Cómo cancelar la venta de una cotización y devolver el inventario',
    'Cancela la venta generada desde una cotización, devuelve el stock, revierte la deuda del cliente y deja la cotización en estatus cancelada.',
    '<p>Si una cotización ya generó una venta y necesitas deshacerla, usa la acción "Cancelar venta". El sistema cancela la venta vinculada, devuelve el inventario de los productos y ajusta el saldo del cliente. La cotización queda en estatus "Cancelada".</p>
<h3>Cuándo usar esta acción</h3>
<ul>
<li>La cotización está en "Venta generada" y tiene una venta asociada.</li>
<li>La venta ya no se realizará: el cliente canceló el pedido, hubo un error en los conceptos o se cobró por duplicado.</li>
<li>Necesitas liberar el inventario que se descontó al convertir la cotización.</li>
</ul>
<p>No uses esta acción para corregir un precio o una cantidad. En ese caso conviene cancelar la venta y crear una nueva cotización o una nueva versión con los datos correctos.</p>
<h3>Requisitos</h3>
<ul>
<li>Debes tener el permiso "Cambiar estatus de cotizaciones" (quotes.change_status). Sin él, la opción no aparece en el menú.</li>
<li>La cotización debe estar en "Venta generada". En "Borrador", "Enviado" o "Autorizada" no existe venta que cancelar.</li>
<li>Revisa si la venta tiene pagos registrados. El sistema marcará la venta como reembolsada, pero no registra el movimiento de caja del reembolso.</li>
</ul>
<h3>Cancela la venta</h3>
<ol>
<li>Abre la cotización en estatus "Venta generada".</li>
<li>Pulsa "Opciones" y elige "Cancelar venta". También puedes hacerlo desde el menú de acciones de la fila en el listado.</li>
<li>Lee el mensaje: "Esta acción cancelará la venta asociada y devolverá el stock. ¿Estás seguro?". El encabezado es "Confirmar Cancelación".</li>
<li>Confirma con "Si". La operación se ejecuta en una sola transacción.</li>
<li>Espera el aviso: "Cotización cancelada, venta revertida y stock devuelto.".</li>
</ol>
<h3>Qué hace el sistema</h3>
<ul>
<li>Devuelve al inventario la cantidad de cada producto con control de existencias que estaba en la cotización. El movimiento queda registrado con la nota "Cancelación de Venta derivada de Cotización #...".</li>
<li>Cambia la venta a "Cancelada" si no tenía pagos, o a "Reembolsada" si ya tenía pagos registrados.</li>
<li>Revierte la deuda del cliente con un crédito por el total de la venta. Si el cliente ya había pagado, ese importe puede quedar como saldo a favor.</li>
<li>Cambia la cotización a "Cancelada". El flujo de estatus se reemplaza por el aviso "Esta venta ha sido cancelada y el inventario ha sido liberado.".</li>
</ul>
<h3>Después de cancelar</h3>
<ol>
<li>Verifica el inventario del o los productos afectados en el módulo de inventario.</li>
<li>Revisa la venta en el módulo de ventas: debe aparecer como cancelada o reembolsada.</li>
<li>Consulta el estado de cuenta del cliente para confirmar el ajuste de saldo.</li>
<li>Si el cliente pagó y le devolviste dinero en efectivo, registra la salida de caja según el proceso de tu negocio. El sistema no crea ese movimiento automáticamente.</li>
<li>Si necesitas volver a cotizar, crea una nueva cotización o una nueva versión y vuelve a convertirla cuando el cliente acepte.</li>
</ol>
<h3>Qué no hace la cancelación</h3>
<ul>
<li>No elimina la venta ni la cotización: ambas quedan en el historial con su estatus final.</li>
<li>No reintegra el dinero al cliente por sí sola. Solo revierte la deuda y marca la venta como reembolsada.</li>
<li>No devuelve inventario de servicios ni de conceptos libres, porque esos conceptos no descuentan existencias.</li>
<li>No permite reactivar la cotización. Para retomar el negocio, crea un documento nuevo.</li>
</ul>
<h3>Recomendaciones</h3>
<ul>
<li>Confirma con el cliente y con el área de cobranza antes de cancelar, sobre todo si hay pagos parciales.</li>
<li>Documenta el motivo de la cancelación en las notas de la venta o en el expediente del cliente.</li>
<li>Si la cancelación se debe a un error de captura, corrige primero el origen para no repetir el problema en la nueva cotización.</li>
<li>No canceles una venta solo para cambiar el estatus de la cotización: la cancelación tiene efectos contables y de inventario.</li>
</ul>',
    80,
    1,
    NOW(),
    NOW(),
    NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM help_center_articles
    WHERE module = 'quotes' AND title = 'Cómo cancelar la venta de una cotización y devolver el inventario'
);

-- ----------------------------------------------------------------------------
-- 9. Versions
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'quotes',
    'Cómo crear y consultar versiones de una cotización',
    'Crea una nueva versión en borrador con los mismos datos, conserva la original y consulta el historial de versiones desde el listado.',
    '<p>Cuando una propuesta cambia de precio, alcance o condiciones, puedes crear una nueva versión en lugar de modificar la original. Cada versión es una cotización independiente, con su propio folio y su propio estatus, y queda agrupada bajo la cotización principal.</p>
<h3>Cuándo crear una versión</h3>
<ul>
<li>El cliente pidió ajustes después de recibir la propuesta y quieres conservar lo que ya enviaste.</li>
<li>Necesitas comparar escenarios, por ejemplo con distintos descuentos o alcances.</li>
<li>La cotización original ya está autorizada y prefieres no tocarla para dejar rastro de lo acordado.</li>
<li>Quieres reutilizar los conceptos de una propuesta anterior sin capturarlos de nuevo.</li>
</ul>
<h3>Crea una nueva versión</h3>
<ol>
<li>Abre la cotización que servirá de base y pulsa "Opciones".</li>
<li>Elige "Crear nueva versión". También puedes hacerlo desde el menú de acciones de la fila en el listado.</li>
<li>Lee el mensaje: "Se creará una nueva versión en estado Borrador. ¿Deseas continuar?".</li>
<li>Confirma con "Si". El sistema crea la copia y abre el editor de la nueva versión.</li>
<li>Ajusta conceptos, precios, descuento, condiciones o cliente según el nuevo escenario.</li>
<li>Guarda los cambios. La nueva versión queda en "Borrador" con su propio folio.</li>
</ol>
<h3>Qué se copia y qué cambia</h3>
<ul>
<li>Se copian los conceptos, cantidades, precios, descuento, impuestos, costo de envío, notas, cliente, destinatario, direcciones y campos personalizados.</li>
<li>El folio de la nueva versión agrega el sufijo de versión al folio original: COT-001-V2, COT-001-V3, y así sucesivamente.</li>
<li>El estatus siempre inicia en "Borrador", aunque la cotización original esté enviada, autorizada o con venta generada.</li>
<li>La cotización original no se modifica. Conserva su folio, su estatus y sus importes.</li>
<li>El historial de cambios y los archivos adjuntos no se copian; la versión empieza como un documento nuevo.</li>
</ul>
<h3>Consulta el historial de versiones</h3>
<ol>
<li>Ve al listado de cotizaciones. La fila de la cotización principal muestra una etiqueta con el número de versiones, por ejemplo "+2 versiones".</li>
<li>Pulsa la flecha de expansión al inicio de la fila.</li>
<li>Se abre el bloque "Historial de versiones" con una tabla de folio, estatus y total de cada versión.</li>
<li>Pulsa el ícono del ojo para abrir el detalle de una versión específica.</li>
<li>Si la fila no tiene versiones, la expansión muestra "Única versión".</li>
</ol>
<h3>Reglas importantes</h3>
<ul>
<li>Las versiones no aparecen como filas separadas en el listado principal; se consultan dentro de la cotización original.</li>
<li>La búsqueda del listado encuentra la cotización principal por folio o por cliente. No encuentra una versión por su sufijo, por ejemplo "-V2"; busca el folio principal y expande la fila.</li>
<li>Cada versión se edita, se envía y se convierte por separado. Cambiar una versión no cambia las demás.</li>
<li>Solo convierte la versión que el cliente aceptó. Si conviertes dos versiones, se generan dos ventas distintas.</li>
<li>Crear una versión a partir de una cotización con venta generada no cancela ni reemplaza esa venta. La venta original sigue vigente hasta que la canceles.</li>
</ul>
<h3>Buenas prácticas</h3>
<ul>
<li>Anota en las notas de la versión qué cambió respecto a la anterior, por ejemplo "Se ajustó el precio del servicio de instalación".</li>
<li>Usa versiones para conservar la trazabilidad de una negociación y evita duplicar cotizaciones sin relación entre sí.</li>
<li>Comparte con el cliente el folio exacto de la versión que debe revisar para evitar confusiones.</li>
<li>Antes de convertir, confirma que la versión autorizada sea la más reciente y que su total coincida con lo acordado.</li>
</ul>',
    90,
    1,
    NOW(),
    NOW(),
    NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM help_center_articles
    WHERE module = 'quotes' AND title = 'Cómo crear y consultar versiones de una cotización'
);

-- ----------------------------------------------------------------------------
-- 10. Printing and PDF
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'quotes',
    'Cómo imprimir una cotización o generar su PDF',
    'Genera la vista de impresión, elige una plantilla personalizada y guarda el documento como PDF desde el navegador.',
    '<p>Desde el detalle de cualquier cotización puedes abrir una versión lista para imprimir o guardar como PDF. El documento incluye los datos de la sucursal, el cliente, los conceptos, los totales, los campos personalizados y las notas.</p>
<h3>Requisitos</h3>
<ul>
<li>La cotización debe estar guardada. Si acabas de editar conceptos o importes, guarda primero; la impresión usa los datos guardados, no los cambios sin guardar.</li>
<li>Para usar una plantilla personalizada, la plantilla debe ser de tipo "Cotización" y estar asignada a la sucursal de la cotización.</li>
<li>Permite las ventanas emergentes del navegador para este sistema. La vista de impresión se abre en una pestaña nueva y el bloqueador puede impedirla.</li>
</ul>
<h3>Genera la vista de impresión</h3>
<ol>
<li>Abre la cotización y pulsa "Opciones".</li>
<li>Elige "Ver PDF / Imprimir".</li>
<li>Si tu sucursal tiene plantillas de cotización, aparece el cuadro "Formato de impresión". Selecciona "Estándar del sistema" o una de las plantillas de la lista.</li>
<li>Pulsa "Generar PDF". Se abre una pestaña nueva con el documento.</li>
<li>Si no hay plantillas configuradas, el sistema abre directamente el formato estándar.</li>
</ol>
<h3>Imprime o guarda el PDF</h3>
<ol>
<li>En la pestaña del documento, pulsa "Imprimir / Guardar PDF". También puedes usar la combinación de teclado de impresión del navegador.</li>
<li>En el diálogo de impresión, elige la impresora para imprimir en papel.</li>
<li>Para guardar el archivo, elige "Guardar como PDF" o "Microsoft Print to PDF" como destino y confirma.</li>
<li>Selecciona la carpeta y el nombre del archivo. El nombre sugerido incluye el folio de la cotización.</li>
<li>Revisa la vista previa antes de imprimir: la primera página debe mostrar el encabezado y los datos del cliente.</li>
</ol>
<h3>Qué incluye el documento estándar</h3>
<ul>
<li>Datos de la empresa o sucursal, logotipo y datos de contacto.</li>
<li>Folio de la cotización y fechas del documento.</li>
<li>Nombre del cliente, destinatario y dirección de envío, si se capturaron.</li>
<li>Tabla de conceptos con descripción, tipo, variante, cantidad, precio unitario y total de línea.</li>
<li>Subtotal, descuento, impuestos y costo de envío hasta llegar al total.</li>
<li>Campos personalizados con valor y notas adicionales.</li>
</ul>
<h3>Usa una plantilla personalizada</h3>
<ul>
<li>Las plantillas se administran en el módulo de plantillas de impresión y deben ser de tipo "Cotización".</li>
<li>La plantilla define el tamaño de página, los márgenes, la tipografía, el logotipo y la posición de los elementos.</li>
<li>Si no ves una plantilla en el cuadro de selección, revisa que esté asignada a la sucursal correcta.</li>
<li>El formato estándar siempre está disponible, aunque no tengas plantillas propias.</li>
</ul>
<h3>Recomendaciones</h3>
<ul>
<li>Revisa el PDF antes de enviarlo al cliente: confirma folio, nombre del cliente, conceptos, descuento y total.</li>
<li>Si el documento sale cortado o con saltos raros, ajusta los márgenes o el tamaño de página en la plantilla y vuelve a generar el PDF.</li>
<li>Comparte el archivo PDF y no una captura de pantalla, para que los importes y el folio se lean con claridad.</li>
<li>Si la cotización cambia después de imprimir, genera un nuevo PDF y descarta el anterior para evitar versiones encontradas.</li>
<li>El PDF no muestra pagos ni la venta generada; es un documento de propuesta, no un comprobante de pago.</li>
</ul>',
    100,
    1,
    NOW(),
    NOW(),
    NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM help_center_articles
    WHERE module = 'quotes' AND title = 'Cómo imprimir una cotización o generar su PDF'
);

-- ----------------------------------------------------------------------------
-- 11. Custom fields
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'quotes',
    'Cómo crear y usar campos personalizados en cotizaciones',
    'Agrega información propia de tu negocio a las cotizaciones: texto, números, opciones, interruptores o una firma de desbloqueo.',
    '<p>Los campos personalizados te permiten capturar información adicional en cada cotización, por ejemplo la orden de compra del cliente, el tiempo de entrega estimado, el nombre del comprador o una firma de desbloqueo. Los campos son definidos por ti y se muestran en el formulario, en el detalle y en el PDF de la cotización.</p>
<h3>Requisitos</h3>
<ul>
<li>Necesitas el permiso para gestionar campos personalizados de cotizaciones. Si no lo tienes, el botón de configuración no aparece y el sistema te invita a solicitarlo a un administrador.</li>
<li>Las definiciones son compartidas por toda la suscripción: aplican a todas las sucursales y a todas las cotizaciones, no solo a la que estás editando.</li>
<li>La información se guarda por cotización. Cambiar o eliminar una definición no modifica las cotizaciones ya guardadas.</li>
</ul>
<h3>Crea un campo personalizado</h3>
<ol>
<li>Abre una cotización nueva o entra a editar una existente.</li>
<li>En la tarjeta "Detalles adicionales", pulsa el botón "Gestionar" con el ícono de engranaje.</li>
<li>En el cuadro "Gestionar campos personalizados", captura el nombre del campo, por ejemplo "Orden de compra".</li>
<li>Selecciona el tipo de campo.</li>
<li>Si elegiste "Selección única" o "Selección múltiple", captura las opciones separadas por comas, por ejemplo: "Contado, Crédito, Transferencia".</li>
<li>Pulsa "Crear". El campo aparece en la lista de la derecha y de inmediato en el formulario de la cotización.</li>
</ol>
<h3>Tipos de campo disponibles</h3>
<ul>
<li>"Texto corto": una línea de texto. Admite hasta 255 caracteres.</li>
<li>"Número": solo valores numéricos.</li>
<li>"Texto largo": un párrafo de texto. Admite hasta 255 caracteres.</li>
<li>"Sí/No (switch)": un interruptor encendido o apagado.</li>
<li>"Selección única": una lista desplegable con las opciones que definas.</li>
<li>"Selección múltiple": varias casillas para marcar más de una opción.</li>
<li>"Desbloqueo celular": una cuadrícula de patrón, útil para registrar una firma o un desbloqueo.</li>
</ul>
<h3>Edita o elimina una definición</h3>
<ul>
<li>Para editar, selecciona el campo en la lista, ajusta el nombre, el tipo o las opciones y pulsa "Actualizar".</li>
<li>Al cambiar el nombre, la clave interna del campo no cambia; los valores ya capturados se conservan y se siguen mostrando con el nuevo nombre.</li>
<li>Para eliminar, usa el botón de eliminar de la fila y confirma. El campo deja de aparecer en los formularios, pero los valores guardados en las cotizaciones anteriores permanecen almacenados.</li>
<li>No cambies el tipo de un campo que ya tiene valores: la información existente puede quedar inválida. Si necesitas otro tipo, crea un campo nuevo.</li>
<li>No elimines opciones de una lista que ya está en uso: las cotizaciones con ese valor podrían no guardarse al editarlas. Agrega opciones nuevas en lugar de quitar las existentes.</li>
</ul>
<h3>Usa los campos en una cotización</h3>
<ol>
<li>En "Detalles adicionales", captura el valor de cada campo. Todos son opcionales; puedes dejar los que no apliquen vacíos.</li>
<li>Guarda la cotización. Los valores quedan asociados a ese registro.</li>
<li>En el detalle de la cotización, la tarjeta "Detalles adicionales" muestra solo los campos que tienen valor.</li>
<li>Al imprimir o generar el PDF, los campos personalizados con valor se incluyen en el documento.</li>
</ol>
<h3>Recomendaciones</h3>
<ul>
<li>Usa nombres claros y distintos entre sí. Evita duplicar nombres, porque dos campos con el mismo nombre generan la misma clave interna y pueden sobrescribirse.</li>
<li>Define los campos antes de empezar a capturar cotizaciones para mantener la información consistente.</li>
<li>Si un campo solo aplica a ciertos servicios, usa "Selección única" con opciones como "No aplica" en lugar de dejarlo vacío.</li>
<li>Revisa que los campos aparezcan en el PDF antes de compartirlo con el cliente, sobre todo si agregaste una definición reciente.</li>
</ul>',
    110,
    1,
    NOW(),
    NOW(),
    NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM help_center_articles
    WHERE module = 'quotes' AND title = 'Cómo crear y usar campos personalizados en cotizaciones'
);

-- ----------------------------------------------------------------------------
-- 12. Catalog: search, sort and delete
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'quotes',
    'Cómo buscar, ordenar y eliminar cotizaciones en el catálogo',
    'Encuentra una propuesta por folio o cliente, ordena la tabla, revisa el historial de versiones y elimina registros de forma segura.',
    '<p>El catálogo de cotizaciones muestra todas las propuestas de la suscripción. Desde aquí puedes localizar una cotización, ordenar la tabla, abrir sus detalles, consultar sus versiones y eliminar registros.</p>
<h3>Accede al catálogo</h3>
<ol>
<li>En el menú lateral, entra a "Cotizaciones".</li>
<li>La tabla muestra una fila por cotización principal. Las versiones no aparecen como filas separadas; se consultan expandiendo la fila del folio original.</li>
<li>Las columnas visibles son folio, cliente, vencimiento, estatus y total.</li>
</ol>
<h3>Busca una cotización</h3>
<ul>
<li>Escribe en el campo "Buscar por folio o cliente...". La búsqueda se aplica mientras escribes, sin necesidad de pulsar Enter.</li>
<li>La búsqueda cubre todo el catálogo, no solo la página que tienes a la vista, y vuelve a la primera página de resultados.</li>
<li>Coincide con el folio o con el nombre del cliente. No busca por destinatario, concepto, importe ni por el folio de una versión.</li>
<li>Para encontrar una versión, busca el folio de la cotización principal y expande la fila para ver el historial.</li>
<li>Si no hay coincidencias, la tabla muestra "Sin resultados"; borra el texto para volver a la lista completa.</li>
</ul>
<h3>Ordena la tabla</h3>
<ul>
<li>Pulsa el encabezado de "Folio", "Cliente", "Vencimiento", "Estatus" o "Total" para ordenar por esa columna.</li>
<li>Pulsa de nuevo el mismo encabezado para cambiar entre orden ascendente y descendente. La flecha del encabezado indica el criterio activo.</li>
<li>El orden se aplica a todo el catálogo, no solo a la página visible.</li>
<li>Por defecto, las cotizaciones más recientes aparecen primero.</li>
</ul>
<h3>Cambia cuántas filas ves y navega entre páginas</h3>
<ul>
<li>Usa el selector de filas del pie de la tabla para mostrar 20, 50, 100 o 200 registros por página.</li>
<li>Usa los botones de primera página, anterior, siguiente y última para moverte por el catálogo.</li>
<li>El pie indica el rango visible y el total de cotizaciones, por ejemplo "Mostrando 1 a 20 de 45 cotizaciones".</li>
</ul>
<h3>Abre una cotización desde el catálogo</h3>
<ul>
<li>Pulsa sobre una fila para abrir el detalle rápido en un panel lateral o ir directo a la vista completa, según tu preferencia de clic configurada.</li>
<li>El nombre del cliente es un enlace a su ficha; puedes abrirla sin salir del catálogo.</li>
<li>Usa el botón de tres puntos al final de la fila para ver las acciones disponibles: ver detalles, editar, convertir a venta, cancelar venta o eliminar. Cada acción aparece solo si tienes el permiso y el estatus lo permite.</li>
</ul>
<h3>Consulta el historial de versiones</h3>
<ul>
<li>Pulsa la flecha al inicio de la fila para expandirla.</li>
<li>Si la cotización tiene versiones, verás la tabla "Historial de versiones" con el folio, el estatus y el total de cada una, además de un botón para abrir sus detalles.</li>
<li>Los folios de versión llevan un sufijo como "-V2" o "-V3" y conservan el folio original como referencia.</li>
<li>Si no hay versiones, la fila expandida indica "Única versión".</li>
</ul>
<h3>Elimina una cotización individual</h3>
<ol>
<li>Pulsa el botón de tres puntos de la fila y elige "Eliminar".</li>
<li>Confirma en el mensaje "¿Estás seguro de que quieres eliminar la cotización #...?".</li>
<li>La cotización desaparece del catálogo y se eliminan también sus conceptos y sus versiones.</li>
</ol>
<ul>
<li>La opción no aparece para cotizaciones con estatus "Venta generada". En ese caso, primero cancela la venta desde la misma fila; al cancelarla se devuelve el stock y la cotización queda como "Cancelada", momento en el que ya puedes eliminarla.</li>
<li>La eliminación es permanente: no hay papelera ni opción de deshacer. Si necesitas conservar el historial comercial, no elimines la cotización.</li>
</ul>
<h3>Elimina varias cotizaciones a la vez</h3>
<ol>
<li>Marca las casillas de las cotizaciones que quieres eliminar. Puedes seleccionar filas de la página actual.</li>
<li>Aparece una barra azul con el número de cotizaciones seleccionadas.</li>
<li>Pulsa "Eliminar" y confirma en el mensaje de eliminación masiva.</li>
<li>Las cotizaciones seleccionadas se eliminan del catálogo.</li>
</ol>
<h3>Precauciones con la eliminación masiva</h3>
<ul>
<li>Antes de confirmar, revisa la selección: el borrado masivo no distingue estatus y puede incluir cotizaciones con venta generada.</li>
<li>Si eliminas una cotización con venta generada, la venta y sus movimientos no se cancelan, pero se pierde el vínculo con la cotización. Para evitarlo, desmarca esas filas y cancela primero la venta una por una.</li>
<li>Al eliminar una cotización principal también se eliminan sus versiones, aunque no estén visibles como filas en la tabla.</li>
<li>No hay forma de recuperar los registros eliminados. Si dudas, abre el detalle y confirma el folio y el cliente antes de borrar.</li>
</ul>
<h3>Problemas comunes</h3>
<ul>
<li>No aparece el botón "Nueva cotización": te falta el permiso para crear cotizaciones.</li>
<li>No puedes eliminar una cotización: no tienes el permiso de eliminación o la cotización tiene una venta generada y debes cancelarla primero.</li>
<li>Una búsqueda no devuelve resultados: confirma que escribiste el folio o el nombre del cliente, sin buscar por el sufijo de una versión.</li>
<li>La tabla muestra menos filas de las esperadas: revisa la búsqueda activa y el selector de filas por página.</li>
</ul>',
    120,
    1,
    NOW(),
    NOW(),
    NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM help_center_articles
    WHERE module = 'quotes' AND title = 'Cómo buscar, ordenar y eliminar cotizaciones en el catálogo'
);

-- ----------------------------------------------------------------------------
-- 13. Permissions and roles
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'quotes',
    'Permisos de cotizaciones: qué puede hacer cada rol',
    'Revisa los permisos del módulo de cotizaciones, asígnalos a un rol y entiende qué acciones habilita cada uno.',
    '<p>El acceso al módulo de cotizaciones se controla con permisos. Cada permiso habilita una parte del módulo y se asigna a un rol de la sucursal; los usuarios reciben los permisos del rol que tienen asignado. Así puedes, por ejemplo, permitir que un vendedor cree cotizaciones sin que pueda eliminarlas.</p>
<h3>Requisitos</h3>
<ul>
<li>Solo un usuario con permiso para acceder a roles y permisos y administrarlos puede crear roles y modificar sus permisos.</li>
<li>Los roles pertenecen a la sucursal donde se crean. Los cambios de permisos aplican a los usuarios de esa sucursal que tengan el rol asignado.</li>
<li>Los usuarios sin rol se consideran propietarios: el sistema les concede los permisos de los módulos incluidos en su plan. El usuario con ID 1 es el Súper Admin y tiene acceso irrestricto.</li>
<li>Recargar la página actualiza las opciones visibles después de cambiar los permisos.</li>
<li>Los permisos no cambian el alcance de los datos: un usuario solo ve las cotizaciones de las sucursales de su suscripción.</li>
</ul>
<h3>Asigna permisos de cotizaciones a un rol</h3>
<ol>
<li>Entra a "Roles y permisos".</li>
<li>Selecciona el rol en la lista de roles disponibles.</li>
<li>En el panel "Permisos asignados", localiza el grupo "Cotizaciones".</li>
<li>Marca o desmarca los permisos que necesites.</li>
<li>Pulsa "Guardar cambios".</li>
<li>Pide a los usuarios del rol que recarguen el sistema para ver las opciones actualizadas.</li>
</ol>
<h3>Permisos disponibles</h3>
<ul>
<li>"Ver listado de cotizaciones": permite entrar al catálogo de cotizaciones. Sin este permiso, el módulo no está disponible.</li>
<li>"Ver detalles de cotizaciones": permite abrir la ficha completa de una cotización, consultar sus conceptos, versiones, actividad e imprimirla desde ahí.</li>
<li>"Crear nuevos cotizaciones": permite crear cotizaciones y generar nuevas versiones de una cotización existente.</li>
<li>"Editar cotizaciones existentes": permite modificar una cotización mientras su estatus lo permita. No habilita cambiar el estatus ni convertir a venta.</li>
<li>"Eliminar cotizaciones": permite eliminar cotizaciones de forma individual y masiva. Úsalo solo en perfiles de confianza, porque la eliminación es permanente.</li>
<li>"Exportar cotizaciones": muestra la opción de exportación en el catálogo. La exportación de cotizaciones aún no genera el archivo; no dependas de esta opción para respaldar información.</li>
<li>"Cambiar status de cotizaciones" (identificador quotes.change_status): permite avanzar el estatus, rechazar o cancelar una cotización y cancelar la venta asociada con devolución de stock.</li>
<li>"Cambiar status de cotizaciones" (identificador quotes.create_sale): la descripción se repite en el sistema, pero este permiso es el que habilita convertir una cotización autorizada en venta. Guíate por el identificador que aparece debajo de la descripción.</li>
<li>"Administrar campos personalizados de cotizaciones": permite crear, editar y eliminar las definiciones de campos personalizados del módulo.</li>
</ul>
<h3>Combinaciones recomendadas</h3>
<ul>
<li>Vendedor: ver listado, ver detalles, crear y editar. Sin eliminar ni administrar campos personalizados.</li>
<li>Supervisor de ventas: los permisos del vendedor más cambiar status y convertir a venta.</li>
<li>Administrador: todos los permisos, incluidos eliminar, exportar y administrar campos personalizados.</li>
<li>Consulta: solo ver listado y ver detalles, para revisar propuestas sin modificarlas.</li>
</ul>
<h3>Qué ocurre cuando falta un permiso</h3>
<ul>
<li>El módulo o los botones que no corresponden no se muestran.</li>
<li>Si se intenta abrir una dirección directa sin el permiso necesario, el sistema responde con un error de acceso denegado.</li>
<li>Un usuario puede ver el catálogo sin poder abrir el detalle: para trabajar con la ficha completa necesita ambos permisos, ver listado y ver detalles.</li>
<li>Aunque un usuario pueda editar, el sistema sigue validando el estatus: una cotización convertida en venta o cancelada no se puede editar.</li>
</ul>
<h3>Recomendaciones</h3>
<ul>
<li>Asigna permisos por rol y no permiso por usuario, para mantener el control ordenado.</li>
<li>Revisa los roles al menos una vez al año o cuando cambie el personal.</li>
<li>No compartas cuentas entre varias personas: los cambios de estatus y la actividad quedan registrados a nombre del usuario que los realiza.</li>
<li>Si un usuario no ve una acción que esperabas, revisa primero su rol y luego su sucursal asignada.</li>
</ul>',
    130,
    1,
    NOW(),
    NOW(),
    NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM help_center_articles
    WHERE module = 'quotes' AND title = 'Permisos de cotizaciones: qué puede hacer cada rol'
);

-- === END OF FILE ===
