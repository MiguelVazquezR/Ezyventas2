-- ============================================================================
-- Help center - Guides for the Servicios (services and service orders) module
--
-- Inserts 10 published guides for the "services" module of the
-- help_center_articles table. The guides automatically appear in the
-- Servicios card of the help center.
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
-- 1. How to create a service in the catalog
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'services',
    'Cómo crear un servicio en el catálogo',
    'Registra un servicio con precio, duración, categoría y sucursales para poder agregarlo a tus órdenes de servicio y cobrarlo en caja.',
    '<p>El catálogo concentra los servicios que puedes agregar a una orden de servicio. Registrar cada servicio con su precio y sus sucursales te evita capturar montos a mano y mantiene la información consistente en tickets, estados de cuenta y reportes.</p>
<h3>Antes de empezar</h3>
<ul>
<li>Si tu plan llega al límite de servicios, verás el aviso "Límite de servicios alcanzado" y el botón "Nuevo servicio" quedará deshabilitado. Ten en cuenta que cada variante de un servicio cuenta como un servicio adicional.</li>
<li>Necesitas al menos una sucursal activa. El servicio solo se podrá vender en las sucursales que marques.</li>
<li>Si la categoría que necesitas no existe, créala con el botón "Gestionar" que está junto al campo.</li>
<li>Si no ves el botón "Nuevo servicio", pide a un administrador que te asigne el permiso para crear servicios.</li>
</ul>
<h3>Captura la información del servicio</h3>
<ol>
<li>Entra a Servicios, abre "Catálogo de servicios" y pulsa "Nuevo servicio".</li>
<li>Escribe el nombre del servicio. Usa un nombre que tu equipo reconozca, por ejemplo "Cambio de pantalla".</li>
<li>Selecciona una o varias sucursales en "Disponible en sucursales". Si el servicio no está disponible en la sucursal del usuario, no aparecerá al crear una orden.</li>
<li>Selecciona la categoría. El botón "Gestionar" abre el administrador de categorías sin salir del formulario.</li>
<li>Agrega una descripción breve; se muestra en el detalle del servicio y ayuda a distinguirlo de otros similares.</li>
<li>Captura el "Precio Base". Si el precio cambia según el modelo o la calidad, activa las variantes (ver la guía "Cómo configurar variantes de un servicio") y deja el precio base en cero: el detalle mostrará "Precio variable".</li>
<li>Escribe la "Duración Estimada" como texto libre: "1-2 horas", "3 días hábiles", etc. Es informativa y no bloquea la orden.</li>
<li>Sube una imagen si quieres identificar el servicio visualmente. Es opcional.</li>
</ol>
<p>Si tu suscripción incluye facturación electrónica, también verás los campos "Clave de servicio (En caso de emitir facturas)" y "Clave Unidad (En caso de emitir facturas)". Se usan al facturar; si no los capturas, la factura puede quedar incompleta.</p>
<h3>Guarda y verifica</h3>
<ol>
<li>Pulsa "Guardar servicio". El sistema te devuelve al catálogo con el mensaje "Servicio creado con éxito".</li>
<li>Busca el servicio por nombre y confirma que el precio, la categoría, las sucursales y las variantes sean correctos.</li>
<li>Abre "Ver detalles" desde el menú de la fila para revisar la ficha completa.</li>
</ol>
<h3>Edita, exporta o elimina</h3>
<ul>
<li>El menú de la fila incluye "Ver detalles", "Editar servicio" y "Eliminar".</li>
<li>"Exportar Servicios" descarga el catálogo en un archivo. La importación de servicios no está disponible desde esta pantalla.</li>
<li>Para eliminar varios servicios, selecciónalos con las casillas y pulsa "Eliminar" en la barra de acciones masivas. El sistema pide confirmación antes de borrar.</li>
</ul>
<p>Cambiar el precio de un servicio no altera las órdenes ya creadas: cada línea conserva el precio con el que se agregó a la orden.</p>
<h3>Recomendaciones</h3>
<ul>
<li>Usa nombres claros y consistentes: "Cambio de pantalla" es más fácil de encontrar que "Reparación 1".</li>
<li>Incluye la mano de obra en el precio del servicio y usa las refacciones solo para los productos que sí descuentan inventario.</li>
<li>Revisa el catálogo antes de abrir una orden para confirmar que el servicio está disponible en la sucursal donde estás trabajando.</li>
</ul>',
    10,
    1,
    NOW(),
    NOW(),
    NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM help_center_articles
    WHERE module = 'services' AND title = 'Cómo crear un servicio en el catálogo'
);

-- ----------------------------------------------------------------------------
-- 2. How to configure service variants
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'services',
    'Cómo configurar variantes de un servicio',
    'Usa variantes para manejar precios y duraciones distintos por modelo o calidad, y elige la variante correcta al agregar el servicio a una orden.',
    '<p>Las variantes sirven cuando el mismo servicio tiene un precio o una duración distinta según el equipo, el modelo o la calidad de la refacción. En lugar de crear varios servicios parecidos, creas uno solo con una lista de variantes.</p>
<h3>Activa las variantes</h3>
<ol>
<li>Entra a Servicios, abre "Catálogo de servicios" y crea un servicio nuevo o edita uno existente.</li>
<li>Activa el interruptor "Este servicio tiene múltiples variantes (ej. por modelo, calidad, cilindraje)".</li>
<li>Al activarlo, el campo "Precio Base" se reemplaza por la sección "Variantes del servicio". El precio base queda en cero y el servicio se mostrará como "Precio variable".</li>
</ol>
<h3>Agrega cada variante</h3>
<ol>
<li>Pulsa "Añadir" para crear una fila nueva.</li>
<li>Escribe el nombre de la variante con el criterio que uses para cotizar, por ejemplo "iPhone 13 - 128 GB" o "Pantalla OLED".</li>
<li>Captura el precio de esa variante. Es obligatorio y debe ser un monto válido.</li>
<li>Agrega la duración si cambia respecto a otras variantes. Es opcional y acepta texto libre.</li>
<li>Repite el proceso para cada modelo o calidad. Usa el buscador "Buscar variante/modelo..." cuando la lista sea larga.</li>
<li>Para quitar una variante, pulsa el ícono de basura y confirma la eliminación.</li>
</ol>
<h3>Guarda y verifica</h3>
<ol>
<li>Pulsa "Guardar servicio" o "Guardar cambios" según corresponda.</li>
<li>Abre "Ver detalles" y revisa la tarjeta "Variantes y Modelos": muestra el nombre, la duración y el precio de cada variante.</li>
<li>Confirma que el precio general aparezca como "Precio variable" y no como un monto fijo.</li>
</ol>
<h3>Usa las variantes en una orden</h3>
<ol>
<li>Al crear o editar una orden, busca el servicio en "Refacciones y mano de obra" y pulsa "Agregar".</li>
<li>Si el servicio tiene variantes, se abre el selector "Seleccionar variante". Elige la que corresponde al equipo del cliente.</li>
<li>La descripción de la línea se completa como "Servicio - Variante" y el precio se toma de la variante elegida.</li>
<li>Si te equivocaste, pulsa "Cambiar variante" en la línea para seleccionar otra. La descripción y el precio se actualizan.</li>
</ol>
<h3>Ten en cuenta</h3>
<ul>
<li>Cada variante cuenta como un servicio adicional para el límite de tu plan.</li>
<li>Si desactivas el interruptor de variantes y guardas, las variantes configuradas se eliminan. Esta acción no se puede deshacer.</li>
<li>Cambiar el precio de una variante no modifica las líneas que ya existen en órdenes anteriores.</li>
<li>No uses variantes para productos con inventario: para esos casos usa las variantes del producto, que sí controlan stock por separado.</li>
</ul>',
    20,
    1,
    NOW(),
    NOW(),
    NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM help_center_articles
    WHERE module = 'services' AND title = 'Cómo configurar variantes de un servicio'
);


-- ----------------------------------------------------------------------------
-- 3. How to register a service order step by step
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'services',
    'Cómo registrar una orden de servicio paso a paso',
    'Captura al cliente, el equipo, la falla reportada, las refacciones y la evidencia inicial para abrir una orden con folio propio y dejarla lista para seguimiento.',
    '<p>Una orden de servicio documenta la recepción de un equipo, la falla que reporta el cliente y el trabajo que vas a realizar. Al guardarla, el sistema genera un folio, crea la venta asociada y te permite cobrar un anticipo.</p>
<h3>Antes de empezar</h3>
<ul>
<li>Debe haber una sesión de caja abierta. Si no la hay, el sistema te pedirá abrir una caja o unirte a una sesión disponible antes de guardar.</li>
<li>Ten a mano el nombre y el teléfono del cliente, la descripción del equipo y la falla reportada.</li>
<li>Si vas a cobrar un anticipo, confirma el monto y el método de pago.</li>
<li>Si no ves el botón "Nueva orden", pide a un administrador que te asigne el permiso para crear órdenes de servicio.</li>
</ul>
<h3>Abre el formulario</h3>
<ol>
<li>Entra a Servicios, abre "Órdenes de servicio" y pulsa "Nueva orden".</li>
<li>Verás la pantalla "Registrar nueva orden de servicio" con un menú lateral que te lleva a cada sección: "Información principal", "Refacciones y mano de obra", "Técnico y detalles adicionales" y "Evidencia inicial".</li>
</ol>
<h3>Información principal</h3>
<ol>
<li>Escribe el nombre del cliente en "Nombre del cliente". La lista sugiere clientes existentes; selecciona uno para vincular la orden a su cuenta.</li>
<li>Si el nombre no coincide con ningún cliente, aparece el aviso "Este parece ser un cliente nuevo. ¿Deseas agregarlo a tus registros?". Activa el interruptor para crearlo; entonces podrás capturar "Asignar límite de crédito". Si lo dejas apagado, la orden queda a nombre de un cliente no registrado y no se podrá cargar el saldo a una cuenta.</li>
<li>Captura "Teléfono del cliente" y "Correo electrónico" si los tienes. Son opcionales y se guardan en la ficha del cliente para futuras consultas.</li>
<li>Elige la "Fecha promesa de entrega" si acordaste una fecha con el cliente. Es opcional.</li>
<li>Describe el equipo en "Descripción del equipo", por ejemplo "iPhone 13 Pro, 256 GB, azul sierra". Incluye marca, modelo, color y número de serie o IMEI cuando aplique.</li>
<li>Captura "Falla o problema reportado por el cliente" con las palabras del cliente y lo que observaste al recibir el equipo.</li>
</ol>
<h3>Refacciones, técnico y evidencia</h3>
<ul>
<li>Agrega refacciones y mano de obra en "Refacciones y mano de obra". Este paso es opcional; puedes crear la orden y agregar conceptos después. La guía "Cómo agregar refacciones y mano de obra a una orden" explica el detalle.</li>
<li>Si asignas un técnico, activa el interruptor de "Asignación de técnico", escribe el nombre, elige el tipo de comisión ("Porcentaje (%)" o "Monto Fijo ($)") y captura el valor. El porcentaje se calcula sobre el subtotal de la orden; el monto fijo se resta completo de la ganancia.</li>
<li>En "Detalles adicionales" verás los campos personalizados que haya configurado tu negocio. El botón "Gestionar" solo aparece si tienes permiso para administrarlos.</li>
<li>En "Evidencia inicial" puedes subir hasta 5 imágenes del equipo al recibirlo, de máximo 2 MB cada una. Sirven como respaldo del estado en que llegó.</li>
</ul>
<h3>Guarda la orden</h3>
<ol>
<li>Pulsa "Crear orden". El sistema valida los campos obligatorios y la sesión de caja.</li>
<li>Si no hay caja abierta, se abre la ventana para iniciar o unirte a una sesión. Al terminar, la orden se guarda automáticamente.</li>
<li>Si todo es correcto, el sistema te lleva al detalle de la orden con el mensaje "Orden de servicio creada" y abre la ventana "Procesar pago" para registrar un anticipo.</li>
<li>Captura el anticipo y pulsa "Registrar abono", o elige "Omitir por ahora (dejar a crédito)" para continuar sin cobrar. Al cerrar esa ventana se abre la ventana "Configurar impresión" para imprimir el ticket de recepción.</li>
</ol>
<h3>Qué ocurre al guardar</h3>
<ul>
<li>La orden recibe un folio consecutivo por sucursal con el formato OS-001 y la venta asociada un folio OS-V-001.</li>
<li>El estatus inicial es "Pendiente" y la fecha de recepción se registra automáticamente.</li>
<li>El total de la orden se carga al saldo del cliente cuando está vinculado, y cada abono lo reduce.</li>
<li>Las refacciones con inventario se descuentan del stock en ese momento, no al entregar el equipo.</li>
</ul>
<h3>Recomendaciones</h3>
<ul>
<li>Fotografía el equipo por todos lados y enciéndelo si es posible: la evidencia inicial evita reclamos posteriores.</li>
<li>Describe la falla con detalle, no solo con una palabra. "No enciende después de caerse" es mejor que "falla".</li>
<li>No prometas una fecha de entrega que no puedas cumplir; la fecha promesa aparece en el seguimiento.</li>
</ul>',
    30,
    1,
    NOW(),
    NOW(),
    NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM help_center_articles
    WHERE module = 'services' AND title = 'Cómo registrar una orden de servicio paso a paso'
);


-- ----------------------------------------------------------------------------
-- 4. How to add parts and labor to a service order
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'services',
    'Cómo agregar refacciones y mano de obra a una orden',
    'Agrega productos del inventario, servicios del catálogo o conceptos escritos a mano, ajusta cantidades y precios, y calcula el subtotal con o sin descuento.',
    '<p>La sección "Refacciones y mano de obra" define qué se cobra en la orden. Puedes combinar productos con inventario, servicios del catálogo y conceptos capturados a mano, y el sistema calcula el total con el descuento que apliques.</p>
<h3>Agrega un concepto</h3>
<ol>
<li>Abre la orden en creación o edición y baja a "Refacciones y mano de obra".</li>
<li>En el buscador "Busca o escribe un concepto..." escribe el nombre del producto o servicio. La lista muestra cada resultado con una etiqueta "Producto" o "Servicio".</li>
<li>Selecciona el resultado y pulsa "Agregar". Si escribiste un concepto que no existe en el catálogo, se agrega como concepto manual con la descripción que escribiste.</li>
<li>Repite el proceso para cada refacción y cada servicio de mano de obra.</li>
</ol>
<h3>Elige el tipo de cada línea</h3>
<ul>
<li>La columna "Tipo" permite marcar la línea como "Refacción" o "Servicio". En los conceptos tomados del catálogo el selector queda bloqueado, porque el tipo ya está definido por el origen.</li>
<li>En un concepto manual puedes cambiar el tipo. Si lo dejas como Refacción y no está ligado a un producto, no descuenta inventario.</li>
<li>Cuando la línea es una refacción ligada a un producto, debajo del tipo aparece el aviso "(Se descontarán N unidad(es) del stock)".</li>
</ul>
<h3>Ajusta cantidades, precios y variantes</h3>
<ol>
<li>Edita la "Descripción" si necesitas precisar el trabajo o la refacción.</li>
<li>Cambia la "Cantidad" con los botones de la fila. El mínimo es 1.</li>
<li>Ajusta el "Precio unit." cuando des un precio especial. El "Total" de la línea se multiplica automáticamente.</li>
<li>Si el producto o el servicio tiene variantes, pulsa "Seleccionar variante" y elige la correcta. La descripción y el precio se actualizan. Para cambiarla después, pulsa "Cambiar variante".</li>
<li>Si agregas un producto con variantes, el selector se abre automáticamente después de "Agregar". Si lo cierras sin elegir, la línea queda con el precio base; usa "Seleccionar variante" para completarla.</li>
<li>Para quitar una línea, pulsa el ícono de basura y confirma con "Sí".</li>
</ol>
<h3>Calcula el subtotal y el descuento</h3>
<ul>
<li>Al crear una orden, el subtotal inicia en modo manual (el botón del candado está cerrado). Agrega las líneas y pulsa el candado para activar el cálculo automático, o escribe el subtotal a mano si lo necesitas. El ícono muestra la ayuda "Cambiar a cálculo automático" cuando está en manual.</li>
<li>Al editar una orden, el subtotal se calcula automáticamente y se actualiza al cambiar cantidades o precios.</li>
<li>El descuento puede ser "Fijo ($)" o "Porcentaje (%)". El sistema limita el descuento al subtotal, así que el total nunca queda en negativo.</li>
<li>El campo "TOTAL" muestra el resultado final que se cargará a la venta y al saldo del cliente.</li>
</ul>
<h3>Inventario: lo que debes saber</h3>
<ul>
<li>El stock de las refacciones se descuenta al guardar la orden, es decir, al crearla o al actualizarla. No se descuenta al entregar el equipo.</li>
<li>Al editar, el sistema compara las cantidades anteriores con las nuevas y ajusta solo la diferencia. Si quitas una refacción, la cantidad vuelve al inventario.</li>
<li>Los servicios y los conceptos manuales no descuentan inventario.</li>
<li>El sistema no bloquea la venta si pides más unidades de las disponibles: la existencia puede quedar en negativo. Revisa la columna de stock en el buscador antes de guardar.</li>
</ul>
<h3>Recomendaciones</h3>
<ul>
<li>Captura la mano de obra como servicio del catálogo para conservar un precio consistente y poder reportarla después.</li>
<li>No escribas refacciones a mano si existen como producto: perderías el control de inventario y el costo real de la reparación.</li>
<li>Verifica el subtotal antes de guardar, sobre todo si usaste el modo manual.</li>
</ul>',
    40,
    1,
    NOW(),
    NOW(),
    NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM help_center_articles
    WHERE module = 'services' AND title = 'Cómo agregar refacciones y mano de obra a una orden'
);


-- ----------------------------------------------------------------------------
-- 5. What each service order status means
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'services',
    'Qué significa cada estatus de una orden de servicio',
    'Conoce los seis estatus de una orden, en qué orden se usan, cómo se ven en la lista y qué implica cada uno para el cliente, el inventario y el cobro.',
    '<p>El estatus indica en qué punto va la reparación. Se muestra como una etiqueta de color en la columna "Estatus" de la lista y en la tarjeta "Estatus actual" del detalle. Cambiarlo mantiene informado a tu equipo y al cliente.</p>
<h3>Los estatus del flujo</h3>
<ul>
<li><strong>Pendiente:</strong> la orden se recibió y todavía no empieza el trabajo. Es el estatus inicial de toda orden nueva y se muestra en amarillo.</li>
<li><strong>En progreso:</strong> el técnico ya está trabajando en el equipo. Se muestra en azul.</li>
<li><strong>Esperando refacción:</strong> el trabajo está detenido porque falta una pieza. Úsalo cuando dependas de un proveedor para que el equipo no parezca atrasado por descuido; se muestra en gris.</li>
<li><strong>Terminado:</strong> la reparación concluyó y el equipo está listo para entregarse. Se muestra en verde. Puedes pasar a este estatus aunque el cliente todavía deba dinero.</li>
<li><strong>Entregado:</strong> el equipo ya se entregó al cliente y la orden se cierra operativamente. Se muestra en verde.</li>
<li><strong>Cancelado:</strong> el proceso se detuvo y la orden ya no puede avanzar. Se muestra en rojo.</li>
</ul>
<h3>Cómo se ve el flujo</h3>
<p>El detalle de la orden muestra la tarjeta "Flujo de estatus" con cinco pasos: Pendiente, En progreso, Esperando refacción, Terminado y Entregado. El paso actual aparece resaltado y los pasos completados se marcan con una palomita.</p>
<p>El estatus Cancelado no forma parte de la línea de pasos. Cuando una orden se cancela, la tarjeta muestra el aviso "Esta orden ha sido cancelada" y "El proceso se ha detenido y el inventario ha sido liberado".</p>
<h3>Qué implica cada cambio</h3>
<ul>
<li>Cambiar de estatus no borra el diagnóstico, las evidencias, los conceptos ni los pagos. Solo actualiza la etapa de la orden.</li>
<li>El inventario de las refacciones se descuenta al crear o editar la orden, no al cambiar de estatus. Entregar el equipo no vuelve a descontar stock.</li>
<li>Cancelar sí devuelve el stock de las refacciones y cancela el saldo pendiente de la venta asociada.</li>
<li>Cada cambio queda registrado en "Historial de movimientos" con la fecha, el usuario y el estatus anterior y nuevo.</li>
</ul>
<h3>Recomendaciones</h3>
<ul>
<li>Actualiza el estatus el mismo día en que ocurre el cambio; el historial pierde valor si se captura días después.</li>
<li>Usa "Esperando refacción" en lugar de dejar la orden en "En progreso" cuando el retraso no depende de tu equipo.</li>
<li>Antes de marcar "Entregado", confirma que el cliente revisó el equipo y que el saldo está cubierto o acordado.</li>
<li>No uses "Cancelado" para corregir un error de captura: si la orden se creó por equivocación, conviene eliminarla.</li>
</ul>',
    50,
    1,
    NOW(),
    NOW(),
    NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM help_center_articles
    WHERE module = 'services' AND title = 'Qué significa cada estatus de una orden de servicio'
);


-- ----------------------------------------------------------------------------
-- 6. How to advance the status and deliver the equipment
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'services',
    'Cómo avanzar el estatus y entregar el equipo',
    'Avanza la orden paso a paso desde la recepción hasta la entrega, conoce el efecto de regresar de etapa y qué hacer cuando queda saldo pendiente al entregar.',
    '<p>La tarjeta "Flujo de estatus" del detalle es el control diario de la reparación. Desde ahí avanzas la orden y, al final del proceso, la marcas como entregada. Cada click pide confirmación y queda en el historial.</p>
<h3>Avanza de etapa</h3>
<ol>
<li>Abre la orden desde "Órdenes de servicio" y ubica la tarjeta "Flujo de estatus" arriba del detalle.</li>
<li>Pulsa el círculo de la etapa siguiente, por ejemplo "En progreso".</li>
<li>Revisa la ventana "Confirmar avance" con el mensaje "¿Estás seguro de que quieres avanzar el estatus a ...?" y acepta.</li>
<li>Repite el proceso conforme avance el trabajo. Puedes saltar etapas si el trabajo ya está más adelantado, por ejemplo de "Pendiente" a "Terminado".</li>
</ol>
<h3>Regresa a una etapa anterior</h3>
<ol>
<li>Pulsa el círculo de una etapa previa. Se abre la ventana "Regresar estatus" con la advertencia de que podrías anular el progreso de las etapas posteriores.</li>
<li>Confirma el cambio. La orden regresa a esa etapa, pero no se borra el diagnóstico, las evidencias, los conceptos ni los pagos capturados.</li>
<li>Usa esta opción cuando cerraste una etapa por error, por ejemplo si marcaste "Terminado" y el equipo volvió a presentar la falla.</li>
</ol>
<h3>Entrega el equipo</h3>
<ol>
<li>Antes de entregar, verifica que el trabajo esté concluido y que la orden tenga los conceptos correctos. Si falta cobrar, define si el cliente pagará en ese momento o quedará a crédito.</li>
<li>Pulsa el círculo "Entregado" y acepta la confirmación.</li>
<li>Si la orden tiene saldo pendiente, se abre la ventana "Procesar pago" para registrar el pago de la entrega. Captura el monto y el método, o elige "Omitir por ahora (dejar a crédito)" si acordaste cobrar después.</li>
<li>Imprime el ticket desde "Opciones" para que el cliente firme de recibido.</li>
</ol>
<h3>Qué ocurre al marcar Entregado</h3>
<ul>
<li>El estatus cambia de inmediato. Si después decides cobrar el saldo, usa "Opciones" y elige "Registrar pago".</li>
<li>El inventario no cambia en este paso: las refacciones ya se descontaron al crear o editar la orden.</li>
<li>El saldo que quede pendiente sigue cargado a la cuenta del cliente y se refleja en su estado de cuenta.</li>
<li>Si el cobro se omite, la venta queda como deuda del cliente. No es un error: es la misma lógica de una venta a crédito.</li>
</ul>
<h3>Permisos y restricciones</h3>
<ul>
<li>Para avanzar de etapa necesitas el permiso de cambio de estatus. Para regresar a una etapa anterior necesitas además el permiso de edición de órdenes.</li>
<li>Si pulsas una etapa y no ocurre nada, probablemente te falta el permiso correspondiente. Pide a un administrador que lo revise.</li>
<li>Una orden cancelada ya no muestra el flujo activo: no se puede avanzar ni regresar. Para retomar el trabajo debes crear una orden nueva.</li>
</ul>
<h3>Recomendaciones</h3>
<ul>
<li>Marca "Terminado" solo cuando el equipo esté probado. Si el cliente debe traer un accesorio para la prueba, deja la orden en "En progreso".</li>
<li>Entrega siempre contra ticket impreso: es tu comprobante de que el equipo salió del taller.</li>
<li>Si entregas con saldo, registra el acuerdo en las notas del técnico para que cualquier compañero lo vea.</li>
</ul>',
    60,
    1,
    NOW(),
    NOW(),
    NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM help_center_articles
    WHERE module = 'services' AND title = 'Cómo avanzar el estatus y entregar el equipo'
);


-- ----------------------------------------------------------------------------
-- 7. How to register payments and balances on a service order
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'services',
    'Cómo registrar abonos y controlar el saldo de una orden',
    'Registra anticipos y pagos parciales, divide el cobro entre métodos, usa el saldo a favor del cliente y entiende cómo se carga el adeudo a su cuenta.',
    '<p>Cada orden de servicio genera una venta asociada. Los cobros que registres se abonan a esa venta y reducen el saldo pendiente. La tarjeta "Estado de cuenta" muestra el total neto, el monto abonado y lo que resta por pagar.</p>
<h3>Registra un abono</h3>
<ol>
<li>Abre la orden y revisa la tarjeta "Estado de cuenta". Si hay saldo, verás "Resta por pagar" en rojo; si está cubierto, verás "Servicio liquidado".</li>
<li>Pulsa "Opciones" y elige "Registrar pago". La opción solo aparece cuando hay saldo pendiente y la orden tiene un total mayor a cero.</li>
<li>En la ventana "Procesar pago", captura el "Monto" del abono. Puedes usar el teclado numérico en pantalla.</li>
<li>Si el cliente tiene saldo a favor, activa "Usar saldo a favor" para aplicarlo antes de cobrar.</li>
<li>Pulsa "Agregar método de pago" y elige "Efectivo", "Tarjeta" o "Transferencia". Si cobras con tarjeta o transferencia, selecciona la cuenta bancaria de destino cuando se solicite.</li>
<li>Para dividir el cobro, agrega otro método y ajusta el monto de cada línea. La suma debe coincidir con lo que estás cobrando.</li>
<li>Pulsa "Registrar abono". El saldo se actualiza en la tarjeta "Estado de cuenta" y el pago aparece en "Anticipos y pagos".</li>
</ol>
<h3>Anticipo al crear la orden</h3>
<ul>
<li>Al crear una orden, la ventana "Procesar pago" se abre automáticamente para capturar el anticipo. Usa el mismo proceso y confirma con "Registrar abono".</li>
<li>Si el cliente no deja anticipo, elige "Omitir por ahora (dejar a crédito)". El texto de la ventana lo confirma: "El total quedará registrado como saldo pendiente (a crédito)".</li>
<li>Después de cerrar esa ventana se abre la impresión del ticket de recepción, que puedes imprimir o cerrar.</li>
</ul>
<h3>Consulta los pagos</h3>
<ul>
<li>La tarjeta "Anticipos y pagos" lista cada movimiento con fecha, método y monto. Si aún no hay cobros, muestra "No se han registrado pagos para esta orden".</li>
<li>Cada pago queda ligado a la sesión de caja del usuario que lo registró. Para cobrar necesitas una sesión de caja abierta; si no la hay, verás el mensaje "No hay una sesión de caja activa para registrar el pago".</li>
<li>Los métodos se identifican con su ícono: efectivo, tarjeta o transferencia.</li>
</ul>
<h3>Cómo se maneja el adeudo</h3>
<ul>
<li>El total de la orden se carga a la cuenta del cliente como un cargo cuando está vinculado a un cliente registrado. Cada abono se refleja como un pago en su estado de cuenta.</li>
<li>El saldo pendiente aparece en el estado de cuenta del cliente y en la tarjeta "Estado de cuenta" de la orden. Esto aplica aunque entregues el equipo con saldo.</li>
<li>Si la orden no está vinculada a un cliente, no se puede cargar el adeudo a una cuenta. En ese caso conviene cobrar el total al momento.</li>
<li>Al cancelar la orden, el saldo pendiente de la venta asociada se cancela y el cliente deja de deber ese monto.</li>
</ul>
<h3>Recomendaciones</h3>
<ul>
<li>Registra el anticipo desde el inicio: reduce el riesgo de que el cliente no recoja el equipo.</li>
<li>No uses "Omitir por ahora" para clientes sin registro; el saldo quedará sin forma de seguimiento.</li>
<li>Verifica que el método seleccionado corresponda al dinero que realmente recibiste antes de confirmar, ya que el pago afecta el corte de caja.</li>
</ul>',
    70,
    1,
    NOW(),
    NOW(),
    NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM help_center_articles
    WHERE module = 'services' AND title = 'Cómo registrar abonos y controlar el saldo de una orden'
);


-- ----------------------------------------------------------------------------
-- 8. How to record diagnosis and photo evidence
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'services',
    'Cómo registrar el diagnóstico y la evidencia fotográfica',
    'Documenta la falla encontrada, la solución aplicada y las fotos de recepción y de cierre para proteger tu trabajo ante cualquier reclamo.',
    '<p>La evidencia fotográfica y el diagnóstico del técnico son tu respaldo. La orden guarda dos momentos: las fotos de recepción, que documentan cómo llegó el equipo, y las fotos de cierre, que muestran el resultado del trabajo.</p>
<h3>Evidencia inicial al recibir el equipo</h3>
<ol>
<li>Al crear la orden, baja a la sección "Evidencia inicial".</li>
<li>Selecciona o arrastra hasta 5 imágenes del equipo. Cada archivo debe ser una imagen de máximo 2 MB.</li>
<li>Revisa la vista previa y quita las que no sirvan antes de guardar.</li>
<li>Pulsa "Crear orden". Las fotos quedan ligadas a la orden y se muestran en la tarjeta "Evidencia inicial" del detalle.</li>
</ol>
<p>Si olvidaste alguna foto, abre "Opciones" y elige "Editar orden". En la sección "Evidencia inicial" verás las imágenes existentes y podrás agregar más.</p>
<h3>Diagnóstico y evidencia de cierre</h3>
<ol>
<li>Abre la orden y pulsa "Opciones". Elige "Registrar diagnóstico y evidencia".</li>
<li>En la ventana "Registrar diagnóstico", escribe en "Diagnóstico del técnico" la falla encontrada y la solución aplicada. Tienes hasta 1,000 caracteres.</li>
<li>En "Evidencia de cierre" adjunta hasta 5 imágenes del equipo terminado, funcionando o con las piezas reemplazadas. El límite es de 10 MB por archivo.</li>
<li>Pulsa "Guardar diagnóstico". El texto aparece en el detalle de la orden y las fotos en la tarjeta "Evidencia de cierre".</li>
</ol>
<h3>Consulta la evidencia</h3>
<ul>
<li>La tarjeta "Evidencia inicial" muestra las fotos de recepción. Si no hay ninguna, indica "Sin imágenes" y "No se adjuntaron fotografías iniciales al recibir el equipo".</li>
<li>La tarjeta "Evidencia de cierre" muestra las fotos del trabajo terminado.</li>
<li>Pulsa cualquier imagen para verla en grande y revisar detalles.</li>
<li>El diagnóstico aparece junto a "Problemas reportados por el cliente", para comparar lo que el cliente describió con lo que encontró el técnico.</li>
</ul>
<h3>Restricciones</h3>
<ul>
<li>Para registrar el diagnóstico necesitas permiso de edición de órdenes.</li>
<li>La opción no aparece en órdenes canceladas. Si necesitas documentar algo de una orden cancelada, hazlo antes de cancelarla.</li>
<li>El diagnóstico se puede corregir volviendo a abrir la ventana: se reemplaza el texto anterior. Las fotos de cierre que ya estaban guardadas se conservan y las nuevas se agregan.</li>
</ul>
<h3>Recomendaciones</h3>
<ul>
<li>Toma fotos de recepción con el equipo encendido, apagado, por ambos lados y con cualquier golpe visible.</li>
<li>En el diagnóstico anota qué pruebas hiciste, no solo el nombre de la falla. Esto te ayuda a justificar el cobro.</li>
<li>Adjunta la evidencia de cierre antes de marcar la orden como "Entregado", no después.</li>
</ul>',
    80,
    1,
    NOW(),
    NOW(),
    NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM help_center_articles
    WHERE module = 'services' AND title = 'Cómo registrar el diagnóstico y la evidencia fotográfica'
);


-- ----------------------------------------------------------------------------
-- 9. Cancel or delete a service order: differences
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'services',
    'Cancelar o eliminar una orden de servicio: cuál elegir',
    'Cancela para conservar el historial de un trabajo que no se realizó y elimina solo cuando la orden se creó por error. Conoce qué pasa con el stock y con el saldo del cliente.',
    '<p>Cancelar y eliminar parecen lo mismo porque ambas acciones detienen el trabajo, pero no lo son. Cancelar conserva la orden en el historial con el estatus "Cancelado"; eliminar la borra de forma permanente junto con la venta asociada.</p>
<h3>Cuándo cancelar</h3>
<ul>
<li>El cliente decidió no reparar el equipo.</li>
<li>El equipo no tiene solución o la reparación no es costeable.</li>
<li>El cliente no volvió por el equipo y necesitas cerrar la orden.</li>
<li>Quieres dejar evidencia de lo que ocurrió para consultarlo después.</li>
</ul>
<h3>Cómo cancelar una orden</h3>
<ol>
<li>Abre la orden y pulsa "Cancelar orden", el botón rojo junto a "Opciones".</li>
<li>Lee la advertencia: "Al cancelar la orden, su estatus cambiará a Cancelado, no podrá seguir avanzando en el flujo y se regresará el stock de las refacciones registradas".</li>
<li>Confirma la acción. El estatus cambia de inmediato y la tarjeta de flujo muestra que el proceso se detuvo.</li>
</ol>
<h3>Qué ocurre al cancelar</h3>
<ul>
<li>La orden queda con el estatus "Cancelado" y ya no se puede avanzar ni regresar de etapa.</li>
<li>Se devuelve al inventario el stock de las refacciones que se habían descontado al crear o editar la orden.</li>
<li>Se cancela el saldo pendiente que la orden había cargado a la cuenta del cliente. Si ya estaba pagada, no se genera ningún movimiento nuevo.</li>
<li>La venta asociada queda cancelada.</li>
<li>Los anticipos y pagos ya cobrados no se reembolsan automáticamente. Si necesitas devolver el dinero, hazlo por el procedimiento de caja de tu negocio.</li>
<li>La orden sigue visible en la lista y en los reportes con su folio y su historial de movimientos.</li>
</ul>
<h3>Cómo eliminar una orden</h3>
<ol>
<li>Abre la orden y pulsa "Opciones".</li>
<li>Elige "Eliminar", la opción en rojo al final del menú.</li>
<li>Confirma en la ventana "Confirmar eliminación". El aviso es claro: "Esta acción no se puede deshacer".</li>
<li>El sistema te regresa a la lista de órdenes con el mensaje "Orden de servicio eliminada".</li>
</ol>
<h3>Qué ocurre al eliminar</h3>
<ul>
<li>La orden y su venta asociada se borran de forma permanente. No se puede recuperar.</li>
<li>También se devuelve el stock de las refacciones y se cancela el saldo pendiente del cliente, con la misma lógica que la cancelación.</li>
<li>Los pagos ya registrados se pierden con la orden y no se reembolsan.</li>
<li>La orden desaparece de la lista, del historial del cliente y de los reportes.</li>
</ul>
<h3>Cómo decidir</h3>
<ul>
<li>Elige cancelar si el trabajo existió y quieres conservar el rastro: fechas, técnico, diagnóstico, evidencia y pagos.</li>
<li>Elige eliminar solo si la orden se creó por error, por ejemplo duplicada o con un cliente equivocado, y no hay pagos registrados.</li>
<li>Antes de eliminar, confirma que no necesitas el folio para una factura, una garantía o una aclaración con el cliente.</li>
</ul>
<h3>Restricciones y permisos</h3>
<ul>
<li>Cancelar requiere permiso de cambio de estatus. El botón no aparece en órdenes ya canceladas ni si te falta el permiso.</li>
<li>Eliminar requiere permiso de eliminación de órdenes. Si no ves la opción, pide a un administrador que la habilite.</li>
<li>Una orden cancelada no se puede reactivar. Si el cliente regresa, crea una orden nueva y menciona el folio anterior en las notas.</li>
</ul>',
    90,
    1,
    NOW(),
    NOW(),
    NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM help_center_articles
    WHERE module = 'services' AND title = 'Cancelar o eliminar una orden de servicio: cuál elegir'
);


-- ----------------------------------------------------------------------------
-- 10. How to print, invoice and export service orders
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'services',
    'Cómo imprimir, facturar y exportar órdenes de servicio',
    'Imprime el ticket de recepción o entrega, genera la factura cuando la venta está pagada y exporta el historial a Excel.',
    '<p>Desde la orden puedes entregar un comprobante al cliente, facturar la venta y respaldar la información en una hoja de cálculo. Cada acción depende de permisos y del estado de la orden.</p>
<h3>Imprime el ticket de la orden</h3>
<ol>
<li>Abre la orden y pulsa "Opciones".</li>
<li>Elige "Imprimir" para abrir la ventana "Configurar impresión" con la vista previa del ticket.</li>
<li>En "1. Selecciona plantillas y copias" elige la plantilla y ajusta las copias de cada trabajo.</li>
<li>En "2. Selecciona impresora" elige la impresora y pulsa "Imprimir". Si usas una impresora térmica, el sistema envía la instrucción para abrir el cajón de dinero.</li>
<li>En dispositivos móviles puedes usar "Vista previa" y luego "Descargar" para guardar el ticket como archivo e imprimirlo después.</li>
</ol>
<h3>Genera el PDF con una plantilla</h3>
<ol>
<li>En "Opciones", elige "Ver PDF / Imprimir".</li>
<li>Si tu negocio tiene plantillas configuradas, se abre la ventana "Formato de impresión". Selecciona "Estándar del sistema" o una de tus plantillas y pulsa "Generar PDF".</li>
<li>Si no hay plantillas, el PDF se genera directamente con el formato estándar en una pestaña nueva.</li>
</ol>
<h3>Guarda o comparte el comprobante</h3>
<p>El envío por WhatsApp está disponible para ventas y abonos, no para órdenes de servicio. Para entregar el comprobante al cliente, imprime el ticket o usa "Descargar" en la vista previa y comparte el archivo por el medio que prefieras.</p>
<h3>Factura la venta</h3>
<ol>
<li>Verifica que la venta esté pagada en su totalidad. La opción "Facturar venta" solo aparece cuando no hay saldo pendiente, la venta está completada y no tiene una factura previa.</li>
<li>En el detalle, pulsa "Opciones" y elige "Facturar venta". También puedes hacerlo desde la lista con el menú de la orden y la opción "Facturar".</li>
<li>Completa los datos fiscales en la pantalla de facturación y genera la factura.</li>
<li>Cuando la factura exista, el menú mostrará "Ver factura" o "Ver prefactura" para consultarla o descargarla.</li>
</ol>
<p>Si la venta aún tiene adeudo, primero registra el pago con "Registrar pago". Si ya fue facturada, no vuelvas a facturarla: usa "Ver factura".</p>
<h3>Exporta las órdenes</h3>
<ol>
<li>Entra a "Órdenes de servicio".</li>
<li>Pulsa la flecha junto a "Nueva orden" y elige "Exportar órdenes".</li>
<li>Se descarga un archivo de Excel con el historial de órdenes de tu suscripción.</li>
<li>Abre el archivo y filtra por estatus, técnico, fecha o sucursal según lo que necesites analizar.</li>
</ol>
<p>La exportación incluye ID, cliente, teléfono, email, equipo, falla reportada, diagnóstico técnico, estatus, fecha de recepción, fecha promesa, total final, técnico asignado y sucursal.</p>
<h3>Importación de órdenes</h3>
<p>Por ahora la carga masiva de órdenes de servicio no está disponible desde la pantalla. Si necesitas registrar muchas órdenes, usa la exportación como referencia de columnas y captúralas manualmente con "Nueva orden".</p>
<h3>Permisos requeridos</h3>
<ul>
<li>Imprimir tickets: acceso al detalle de la orden.</li>
<li>Exportar: permiso de importación y exportación de órdenes de servicio.</li>
<li>Facturar: permiso para crear facturas.</li>
</ul>
<h3>Recomendaciones</h3>
<ul>
<li>Imprime el ticket de recepción al crear la orden: es el comprobante que firma el cliente al dejar el equipo.</li>
<li>Factura solo después de cobrar el total; si facturas antes, el proceso se bloquea por el saldo pendiente.</li>
<li>Exporta el historial al cierre de cada mes para conservar un respaldo fuera del sistema.</li>
</ul>',
    100,
    1,
    NOW(),
    NOW(),
    NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM help_center_articles
    WHERE module = 'services' AND title = 'Cómo imprimir, facturar y exportar órdenes de servicio'
);

