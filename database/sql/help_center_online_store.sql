-- ============================================================================
-- Help center - Guides for the Tienda en línea (online store) module
--
-- Inserts 15 published guides for the "online-store" module of the
-- help_center_articles table. The guides automatically appear in the
-- Tienda en línea card of the help center.
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
-- 1. How to activate and publish the online store
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'online-store',
    'Cómo activar y publicar tu tienda en línea',
    'Configura el nombre, la descripción, el enlace y la visibilidad de tu tienda para que tus clientes puedan comprar en línea sin errores.',
    '<p>La tienda en línea es el escaparate público de tu negocio: tus clientes entran desde un enlace, revisan tu catálogo y hacen pedidos sin que tú tengas que capturarlos. Se alimenta del mismo catálogo y del mismo inventario que usas en el punto de venta, así que no hay que duplicar productos.</p>
<h3>Antes de empezar</h3>
<ul>
<li>Tu suscripción debe incluir el módulo de tienda en línea. Si no ves el menú "Tienda en línea", pide a tu administrador que revise el plan.</li>
<li>Ten listos el nombre comercial, una descripción breve y el logo del negocio.</li>
<li>Decide la dirección de tu tienda: es el identificador que compartirás con tus clientes.</li>
<li>Publica al menos un producto visible en la tienda; si no, el escaparate se verá vacío.</li>
</ul>
<h3>Captura la información básica</h3>
<ol>
<li>Entra a "Tienda en línea" y abre "Configuración".</li>
<li>En "Información básica" escribe el nombre de la tienda. Es obligatorio y aparece en el encabezado de la tienda y en la pestaña del navegador.</li>
<li>Agrega un eslogan de hasta 120 caracteres y una descripción de hasta 1000 caracteres. Ambos se muestran en el escaparate y ayudan a que el cliente entienda qué vendes.</li>
<li>Escribe el mensaje de bienvenida, de hasta 500 caracteres. Se muestra como texto destacado en la página principal.</li>
<li>En "Dirección de la tienda" define el identificador. Solo acepta minúsculas, números y guiones, con un máximo de 50 caracteres.</li>
<li>Usa la verificación de disponibilidad antes de guardar. Si el identificador ya está ocupado, el sistema te pedirá elegir otro.</li>
</ol>
<h3>Publica la tienda</h3>
<ol>
<li>En la sección "Visibilidad" activa el interruptor "Tienda activa".</li>
<li>Guarda los cambios. La tienda queda disponible en el enlace que se genera con el identificador.</li>
<li>Pulsa "Ver mi tienda" para abrirla en otra pestaña y revisar cómo la ve el cliente.</li>
</ol>
<h3>Qué pasa cuando la desactivas</h3>
<ul>
<li>La tienda deja de estar disponible: quien entre al enlace verá una página de error y no podrá hacer pedidos.</li>
<li>Los pedidos ya registrados, su historial y la configuración se conservan.</li>
<li>Al reactivarla se vuelve a publicar con la misma dirección y el mismo contenido.</li>
</ul>
<h3>Recomendaciones</h3>
<ul>
<li>No compartas el enlace hasta tener productos visibles, métodos de pago y políticas configuradas.</li>
<li>Prueba la tienda en el celular: la mayoría de tus clientes comprará desde ahí.</li>
<li>Si cambias el identificador, el enlace anterior deja de funcionar. Actualiza tus redes sociales y tu material impreso.</li>
<li>Revisa la tienda después de cada cambio de configuración para confirmar que todo se ve bien.</li>
</ul>',
    10, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'online-store'
      AND title = 'Cómo activar y publicar tu tienda en línea'
);

-- ----------------------------------------------------------------------------
-- 2. How to customize the look and feel of the online store
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'online-store',
    'Cómo personalizar el diseño de tu tienda',
    'Sube tu logo, define los colores y el tema, y agrega hasta tres banners para que la tienda se vea como tu negocio.',
    '<p>La personalización hace que la tienda se vea como tu negocio y no como una plantilla genérica. Los cambios se aplican a todo el escaparate: encabezado, botones, tarjetas de producto, carrito y pantalla de confirmación.</p>
<h3>Sube el logo</h3>
<ol>
<li>En "Configuración" abre la sección "Personalización".</li>
<li>Pulsa "Seleccionar logo" y elige una imagen de hasta 2 MB.</li>
<li>Guarda los cambios para publicarlo. Para quitarlo, usa la opción de eliminar logo y guarda de nuevo.</li>
</ol>
<p>Recomendación: usa un logo con fondo transparente y revisa que se lea bien sobre el fondo claro y sobre el oscuro.</p>
<h3>Define los colores</h3>
<ul>
<li><strong>Color primario:</strong> se usa en botones principales, precios destacados y acentos.</li>
<li><strong>Color secundario:</strong> se usa en degradados y elementos de apoyo.</li>
</ul>
<p>Elige colores con contraste suficiente para que el texto y los botones se lean sin esfuerzo. Un color muy claro puede desaparecer sobre el fondo blanco.</p>
<h3>Elige el tema</h3>
<p>El tema puede ser claro u oscuro. El tema oscuro da un aspecto más sobrio y hace que las fotografías resalten; el claro suele sentirse más cercano para compras de consumo diario. El tema se aplica a todas las páginas públicas de la tienda.</p>
<h3>Agrega banners</h3>
<ol>
<li>En la misma sección usa "Agregar banner" para subir una imagen de hasta 4 MB.</li>
<li>Puedes tener hasta 3 banners. Se muestran en el escaparate como imágenes de portada.</li>
<li>Para quitar uno, usa la opción de eliminar sobre la vista previa y guarda los cambios.</li>
</ol>
<h3>Recomendaciones</h3>
<ul>
<li>Usa imágenes horizontales de buena resolución y evita texto pequeño dentro del banner: en el celular se reduce.</li>
<li>Reserva el primer banner para tu promoción principal y los otros para categorías o avisos.</li>
<li>Revisa la tienda después de cambiar colores o tema; algunos textos pueden necesitar ajustes de contraste.</li>
<li>Si vas a cambiar de temporada, actualiza banners y mensaje de bienvenida al mismo tiempo.</li>
</ul>',
    20, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'online-store'
      AND title = 'Cómo personalizar el diseño de tu tienda'
);

-- ----------------------------------------------------------------------------
-- 3. Delivery, shipping and preparation settings
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'online-store',
    'Cómo configurar entrega, envío y preparación',
    'Define si tus clientes recogen en tienda o reciben a domicilio, cuánto cuesta el envío, cuándo es gratis y en cuánto tiempo preparas un pedido.',
    '<p>Las opciones de entrega definen cómo reciben el pedido tus clientes y cuánto se cobra por el envío. Se configuran en la sección "Opciones de entrega" y se aplican en el carrito de la tienda.</p>
<h3>Activa los tipos de entrega</h3>
<ul>
<li><strong>Recoger en tienda:</strong> el cliente pasa por el producto. Nunca se cobra envío en esta opción.</li>
<li><strong>Envío a domicilio:</strong> el cliente captura la dirección y se le suma el costo de envío que definas.</li>
</ul>
<p>Deja activa al menos una opción. Si desactivas las dos, el cliente no podrá elegir cómo recibir su pedido y no podrá completar la compra.</p>
<h3>Define el costo de envío</h3>
<ol>
<li>Escribe el monto en "Costo de envío". Usa cero si tu envío es gratis.</li>
<li>Guarda los cambios. El monto se muestra en el carrito y se suma al total solo cuando el cliente elige envío a domicilio.</li>
</ol>
<h3>Configura el envío gratis por monto</h3>
<ol>
<li>Captura el "Mínimo para envío gratis" con base en el subtotal de productos. Deja cero si no quieres umbral.</li>
<li>Guarda los cambios.</li>
<li>Revisa cómo lo ve el cliente: mientras no llegue al mínimo, el carrito muestra "Agrega $X más para envío gratis"; al alcanzarlo aparece "¡Envío gratis!" y el costo se tacha.</li>
</ol>
<p>El umbral se calcula con el subtotal de los productos, sin incluir el envío. Si el cliente elige recoger en tienda, el costo de envío no se cobra aunque no alcance el mínimo.</p>
<h3>Define el tiempo de preparación</h3>
<ol>
<li>Captura días, horas y minutos en "Tiempo de preparación". El sistema lo guarda en minutos.</li>
<li>Guarda los cambios.</li>
</ol>
<p>Este tiempo es tu referencia de cuánto tardas en tener listo un pedido. Sé realista: si prometes menos de lo que puedes cumplir, el cliente preguntará por su pedido antes de tiempo.</p>
<h3>Agrega tu WhatsApp</h3>
<ol>
<li>Escribe el número en "WhatsApp" con 10 a 15 dígitos, con o sin signo de más.</li>
<li>Guarda los cambios. El botón flotante de WhatsApp aparece en todas las páginas de la tienda para que el cliente te escriba.</li>
</ol>
<h3>Recomendaciones</h3>
<ul>
<li>Calcula el costo de envío con tu costo real de gasolina, tiempo y empaque.</li>
<li>Usa el mínimo de envío gratis para subir el ticket promedio, pero sin perder margen.</li>
<li>Confirma tu tiempo de preparación con tu equipo antes de publicarlo.</li>
<li>Revisa el carrito después de cambiar montos: es la pantalla donde el cliente decide si compra.</li>
</ul>',
    30, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'online-store'
      AND title = 'Cómo configurar entrega, envío y preparación'
);

-- ----------------------------------------------------------------------------
-- 4. Payment methods of the online store
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'online-store',
    'Cómo configurar los métodos de pago',
    'Activa el pago en efectivo contra entrega y Mercado Pago, define las instrucciones que ve el cliente y evita que la compra se detenga por falta de opciones.',
    '<p>La tienda en línea acepta dos formas de pago: efectivo contra entrega y Mercado Pago. Puedes activar una o ambas según tu operación. Los métodos activos son los que el cliente ve al finalizar la compra.</p>
<h3>Efectivo contra entrega</h3>
<ol>
<li>En "Configuración" abre la sección "Métodos de pago".</li>
<li>Activa "Pago en efectivo (contra entrega)".</li>
<li>Escribe las instrucciones que verá el cliente, por ejemplo el monto exacto requerido o si el repartidor lleva cambio. El campo admite hasta 500 caracteres.</li>
<li>Guarda los cambios.</li>
</ol>
<p>Con este método, la venta se registra como pagada en efectivo y el inventario se descuenta al momento de crear el pedido. El cobro real ocurre cuando entregas.</p>
<h3>Mercado Pago</h3>
<ol>
<li>Conecta tu cuenta desde la misma sección con el botón "Conectar". El detalle está en la guía "Cómo conectar y desconectar Mercado Pago".</li>
<li>Activa "Activar Mercado Pago en la tienda".</li>
<li>Guarda los cambios.</li>
</ol>
<p>Con Mercado Pago el cliente paga con tarjeta en la pasarela. El pedido se crea de inmediato, pero la venta queda como pendiente de pago hasta que Mercado Pago confirma la operación.</p>
<h3>Qué pasa si no hay métodos activos</h3>
<ul>
<li>El cliente llega al carrito pero no puede finalizar la compra.</li>
<li>Si Mercado Pago está activo pero la cuenta no está conectada, el cobro en línea no funciona: revisa la conexión antes de publicar la tienda.</li>
</ul>
<h3>Recomendaciones</h3>
<ul>
<li>Ofrece efectivo solo si entregas en tu zona y puedes cobrar al recibir.</li>
<li>Usa Mercado Pago para clientes que no pueden pagar en efectivo o que piden a domicilio lejos.</li>
<li>Revisa las instrucciones de efectivo cada vez que cambien tus condiciones de entrega.</li>
<li>Confirma con un pedido de prueba que el método que activaste aparece en el carrito.</li>
</ul>',
    40, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'online-store'
      AND title = 'Cómo configurar los métodos de pago'
);

-- ----------------------------------------------------------------------------
-- 5. How to connect and disconnect Mercado Pago
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'online-store',
    'Cómo conectar y desconectar Mercado Pago',
    'Vincula la cuenta de Mercado Pago del negocio, revisa el estado de la conexión y entiende qué ocurre cuando un cliente paga con tarjeta.',
    '<p>Mercado Pago permite cobrar con tarjeta en tu tienda en línea. La conexión se hace con tu propia cuenta, así que el dinero llega directo a tu negocio y no a un intermediario.</p>
<h3>Antes de empezar</h3>
<ul>
<li>Ten a la mano los datos de acceso de la cuenta de Mercado Pago del negocio.</li>
<li>La cuenta debe estar activa y con sus datos verificados para poder recibir pagos.</li>
<li>Solo un usuario con permisos de configuración puede conectar la cuenta.</li>
</ul>
<h3>Conecta tu cuenta</h3>
<ol>
<li>Entra a "Tienda en línea" y abre "Configuración".</li>
<li>En "Métodos de pago" localiza la tarjeta de Mercado Pago y pulsa "Conectar".</li>
<li>Inicia sesión en Mercado Pago con la cuenta del negocio y autoriza el acceso cuando te lo pida.</li>
<li>Al terminar, el sistema te regresa a la configuración con la cuenta conectada.</li>
<li>Activa el interruptor "Activar Mercado Pago en la tienda" y guarda los cambios.</li>
</ol>
<h3>Revisa el estado de la conexión</h3>
<ul>
<li>La tarjeta muestra "Conectado" con el identificador de la cuenta y los datos del negocio: nombre, país y User ID.</li>
<li>Si la plataforma está en modo de prueba, aparece un aviso en ámbar. En ese modo no es posible cambiar de cuenta; sirve para validar el flujo antes de cobrar de verdad.</li>
<li>Cuando la cuenta conectada no es la correcta, desconéctala y vuelve a conectar con la cuenta del negocio.</li>
</ul>
<h3>Desconecta la cuenta</h3>
<ol>
<li>Desactiva primero el interruptor de Mercado Pago y guarda los cambios, para que los clientes no intenten pagar mientras haces el cambio.</li>
<li>Pulsa "Desconectar cuenta". La opción solo está disponible fuera del modo de prueba.</li>
<li>Verifica que quede activo otro método de pago, por ejemplo efectivo contra entrega.</li>
</ol>
<h3>Qué ocurre cuando un cliente paga</h3>
<ul>
<li>El pedido se registra y el cliente es enviado a la pasarela de Mercado Pago.</li>
<li>Si el pago se aprueba, el inventario se descuenta y la venta queda marcada como pagada.</li>
<li>Si el pago se rechaza, queda pendiente o el cliente abandona la pasarela, el pedido se elimina y el cliente regresa al carrito con un aviso para intentarlo de nuevo.</li>
<li>Los reembolsos y aclaraciones se gestionan desde tu cuenta de Mercado Pago, no desde el sistema.</li>
</ul>
<h3>Recomendaciones</h3>
<ul>
<li>Haz una compra de prueba antes de publicar la tienda y confirma que el pago aparece en tu cuenta.</li>
<li>No compartas tus credenciales de Mercado Pago con nadie; la conexión se hace desde la configuración con tu propia sesión.</li>
<li>Revisa la conexión después de cambiar la contraseña de Mercado Pago o de modificar los datos del negocio.</li>
<li>Si vas a cambiar de cuenta bancaria, desconecta y vuelve a conectar para evitar depósitos en la cuenta anterior.</li>
</ul>',
    50, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'online-store'
      AND title = 'Cómo conectar y desconectar Mercado Pago'
);

-- ----------------------------------------------------------------------------
-- 6. New order email notifications
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'online-store',
    'Cómo activar los avisos de pedidos por correo',
    'Agrega hasta tres correos para recibir un aviso cada vez que entre un pedido y evita que un pedido se quede sin atender.',
    '<p>Las notificaciones por correo avisan a tu equipo en el momento en que entra un pedido. Son útiles cuando varias personas atienden la tienda o cuando no estás frente a la computadora.</p>
<h3>Activa las notificaciones</h3>
<ol>
<li>Entra a "Tienda en línea" y abre "Configuración".</li>
<li>En la sección "Notificaciones" activa "Notificar por correo nuevos pedidos".</li>
<li>Escribe el primer correo y usa "Agregar correo" para sumar hasta 3 destinatarios.</li>
<li>Guarda los cambios.</li>
</ol>
<h3>Qué incluye el aviso</h3>
<ul>
<li>El folio del pedido y la fecha.</li>
<li>Los productos, las cantidades y los totales, incluido el costo de envío.</li>
<li>Los datos de contacto del cliente: nombre, teléfono y correo.</li>
<li>El tipo de entrega y la dirección cuando el pedido es a domicilio.</li>
</ul>
<h3>Importante</h3>
<p>El aviso se envía al registrar el pedido, no al confirmar el pago. Con Mercado Pago el correo puede llegar antes de que el cliente termine de pagar, y si el pago se rechaza el pedido se elimina. Por eso el correo es un aviso de actividad: la fuente confiable es la lista de pedidos.</p>
<h3>Recomendaciones</h3>
<ul>
<li>Usa un correo del negocio y otro del encargado de mostrador o almacén.</li>
<li>Revisa la carpeta de spam la primera semana y marca el remitente como seguro.</li>
<li>Mantén los correos vigentes; un aviso que llega a una cuenta dormida es un pedido que nadie atiende.</li>
<li>Si no llegan los avisos, confirma que el interruptor sigue activo y que los correos están guardados.</li>
</ul>',
    60, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'online-store'
      AND title = 'Cómo activar los avisos de pedidos por correo'
);

-- ----------------------------------------------------------------------------
-- 7. Store policies, terms and footer note
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'online-store',
    'Cómo publicar políticas, términos y pie de página',
    'Redacta las condiciones de entrega y devolución que verá el cliente, y usa el pie de página para avisos rápidos como horarios y zonas de cobertura.',
    '<p>Las políticas le dicen al cliente qué puede esperar de tu tienda: tiempos de entrega, costos, devoluciones y garantías. Publicarlas reduce dudas y reclamos, y te da un documento al que puedes remitir cuando hay una aclaración.</p>
<h3>Redacta la política de entrega</h3>
<ol>
<li>En "Configuración" abre "Políticas de la tienda".</li>
<li>En "Política de entrega" describe zonas de cobertura, días y horarios de reparto, tiempos estimados y costos. El campo admite hasta 2000 caracteres.</li>
<li>Guarda los cambios.</li>
</ol>
<h3>Redacta los términos y condiciones</h3>
<ol>
<li>En "Términos y condiciones" incluye devoluciones, cambios, garantías, cancelaciones y qué pasa si el cliente no está al recibir. El campo admite hasta 10000 caracteres.</li>
<li>Si el campo está vacío puedes usar "Cargar plantilla" para empezar con un texto base y adaptarlo a tu negocio.</li>
<li>Guarda los cambios.</li>
</ol>
<p>La página pública de políticas se muestra en la tienda cuando hay términos capturados. Si el campo queda vacío, la página no está disponible para el cliente.</p>
<h3>Escribe la nota de pie de página</h3>
<ol>
<li>En "Pie de página" captura un texto breve, de hasta 500 caracteres.</li>
<li>Guarda los cambios. La nota aparece en la parte inferior de la tienda, en todas las páginas.</li>
</ol>
<p>Sirve para avisos rápidos: horarios de atención, teléfono, leyendas de precio o la zona donde entregas.</p>
<h3>Recomendaciones</h3>
<ul>
<li>Escribe en frases cortas y con datos concretos: plazos, montos y condiciones.</li>
<li>No prometas algo que no puedas cumplir: las políticas también te obligan.</li>
<li>Si vendes productos perecederos, explica cómo manejas devoluciones y cambios.</li>
<li>Revisa el texto cuando cambien tus costos de envío o tus tiempos de entrega.</li>
<li>Ante dudas legales, valida el contenido con un asesor.</li>
</ul>',
    70, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'online-store'
      AND title = 'Cómo publicar políticas, términos y pie de página'
);

-- ----------------------------------------------------------------------------
-- 8. Online price, featured products and catalog visibility
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'online-store',
    'Cómo elegir el precio en línea y los productos destacados',
    'Publica solo los productos que quieres vender en la tienda, define un precio distinto al del mostrador y elige qué aparece en la sección de destacados.',
    '<p>No todo tu catálogo tiene que estar en la tienda. En la ficha de cada producto decides si se muestra en línea, a qué precio y si aparece en la sección de destacados. Lo que no actives no aparece en el escaparate ni en la búsqueda de la tienda.</p>
<h3>Activa el producto en la tienda</h3>
<ol>
<li>Abre el producto y entra a la sección "Precios y visibilidad".</li>
<li>Activa "¿Mostrar en tienda en línea?". La sección solo aparece si tu suscripción incluye el módulo.</li>
<li>Guarda el producto.</li>
</ol>
<h3>Define el precio en línea</h3>
<ul>
<li>Captura el "Precio en línea" si quieres cobrar distinto que en el punto de venta. Es útil para absorber la comisión de la pasarela de pagos.</li>
<li>Si lo dejas vacío, la tienda usa el precio de venta normal del producto.</li>
<li>El precio en línea es el que se cobra en el pedido, el que usa el filtro de precios y el que se muestra en el escaparate.</li>
</ul>
<h3>Destaca tus productos</h3>
<ol>
<li>En la misma sección activa "Destacar en tienda".</li>
<li>Guarda el producto.</li>
<li>Revisa la sección de destacados en el escaparate. Se muestran hasta 8 productos destacados.</li>
</ol>
<h3>Revisa la descripción y las imágenes</h3>
<ul>
<li>La ficha pública muestra la descripción del producto tal como está capturada. Cuida la ortografía y evita notas internas.</li>
<li>Las fotografías son lo primero que ve el cliente: usa imágenes claras y con buena luz.</li>
<li>El escaparate ordena los productos por el orden de catálogo y después por nombre. El cliente puede buscar por nombre, filtrar por categoría y por rango de precio, y ordenar de menor a mayor precio.</li>
</ul>
<h3>Recomendaciones</h3>
<ul>
<li>Destaca los productos con mejor margen o con stock suficiente para cumplir la demanda.</li>
<li>No uses el precio en línea para pruebas: el cliente lo verá de inmediato.</li>
<li>Si un producto es insumo interno o no lo quieres vender en línea, déjalo sin visibilidad en la tienda.</li>
<li>Revisa la columna de visibilidad del catálogo para confirmar qué productos están publicados.</li>
</ul>',
    80, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'online-store'
      AND title = 'Cómo elegir el precio en línea y los productos destacados'
);

-- ----------------------------------------------------------------------------
-- 9. Stock availability and backorder purchases
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'online-store',
    'Cómo funcionan las existencias y las ventas bajo pedido',
    'Entiende cómo se calcula el disponible que ve el cliente, cuándo se descuenta el inventario y cómo vender productos agotados con un tiempo de reposición.',
    '<p>La tienda en línea trabaja con el inventario real de tu sucursal. Antes de agregar un producto al carrito, el cliente ve cuántas piezas hay y qué pasa si se agotaron.</p>
<h3>Cómo se calcula lo disponible</h3>
<p>El disponible es el stock actual menos las piezas apartadas. El escaparate y la ficha del producto muestran ese número y cambian de color cuando queda poco:</p>
<ul>
<li>Con el número de piezas cuando hay existencia suficiente.</li>
<li>En ámbar cuando quedan 5 piezas o menos.</li>
<li>En rojo con el texto "Sin existencias" cuando el disponible es cero.</li>
</ul>
<h3>Ventas bajo pedido</h3>
<ol>
<li>En "Configuración" abre "Opciones de entrega".</li>
<li>Activa "Permitir comprar productos sin existencia".</li>
<li>Captura el "Tiempo extra de reposición" en días, horas y minutos. Se suma al tiempo de preparación para los productos agotados.</li>
<li>Guarda los cambios.</li>
</ol>
<p>Con esta opción activa, un producto agotado se muestra como "Disponible bajo pedido" y el cliente puede comprarlo. En la ficha del producto aparece el tiempo estimado de reposición que configuraste.</p>
<h3>Cuándo se descuenta el inventario</h3>
<ul>
<li><strong>Pago en efectivo contra entrega:</strong> el inventario se descuenta al crear el pedido.</li>
<li><strong>Mercado Pago:</strong> el inventario se descuenta cuando la pasarela confirma el pago.</li>
<li><strong>Cancelación:</strong> si cancelas el pedido, las piezas regresan al inventario con su movimiento registrado.</li>
</ul>
<h3>Recomendaciones</h3>
<ul>
<li>Si no puedes surtir rápido, deja la venta bajo pedido desactivada y repón antes de publicar.</li>
<li>Revisa el stock antes de una promoción: un producto agotado en plena campaña genera reclamos.</li>
<li>Ajusta el tiempo extra de reposición con el tiempo real de tu proveedor.</li>
<li>Recuerda que el inventario es por sucursal: si vendes desde varias, confirma de dónde sale el pedido.</li>
</ul>',
    90, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'online-store'
      AND title = 'Cómo funcionan las existencias y las ventas bajo pedido'
);

-- ----------------------------------------------------------------------------
-- 10. Review and filter online store orders
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'online-store',
    'Cómo revisar y filtrar los pedidos de la tienda',
    'Usa las pestañas de estado, la paginación y las acciones de contacto para atender cada pedido en línea sin perder ninguno.',
    '<p>La sección "Pedidos" concentra todo lo que entra por tu tienda en línea. Es tu bandeja de trabajo: ahí revisas qué falta preparar, qué está listo y qué se canceló.</p>
<h3>Abre la lista de pedidos</h3>
<ol>
<li>Entra a "Tienda en línea" y abre "Pedidos".</li>
<li>Revisa las pestañas de estado. "Todas" muestra el total y cada pestaña indica cuántos pedidos hay en ese estado.</li>
<li>Pulsa una pestaña para filtrar la lista.</li>
</ol>
<h3>Qué muestra cada pedido</h3>
<ul>
<li><strong>Pedido #:</strong> folio que identifica la venta; menciónalo cuando hables con el cliente.</li>
<li><strong>Cliente:</strong> nombre capturado al finalizar la compra.</li>
<li><strong>Tipo:</strong> recoger en tienda o envío a domicilio.</li>
<li><strong>Estado:</strong> pendiente, en revisión, en preparación, listo, entregado o cancelado.</li>
<li><strong>Total:</strong> incluye productos y costo de envío cuando aplica.</li>
<li><strong>Fecha:</strong> los pedidos más recientes aparecen primero.</li>
</ul>
<p>La lista muestra 20 pedidos por página, con los más recientes primero.</p>
<h3>Contacta al cliente sin salir del sistema</h3>
<ul>
<li>Usa el botón de WhatsApp para abrir el chat con el teléfono del pedido.</li>
<li>Usa el botón de llamada para marcar directo desde tu dispositivo.</li>
<li>Abre el detalle del pedido para ver productos, dirección, notas y el historial de estados.</li>
</ul>
<h3>Recomendaciones</h3>
<ul>
<li>Revisa la lista de pendientes al abrir el turno y antes de cerrar.</li>
<li>Atiende primero los pedidos de envío a domicilio: dependen de una ruta.</li>
<li>No borres pedidos para limpiar la lista; cambia el estado y conserva el historial.</li>
<li>Los pagos con Mercado Pago que se rechazan o se abandonan no aparecen aquí: el pedido se elimina automáticamente.</li>
</ul>',
    100, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'online-store'
      AND title = 'Cómo revisar y filtrar los pedidos de la tienda'
);

-- ----------------------------------------------------------------------------
-- 11. Order statuses and allowed transitions
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'online-store',
    'Cómo funcionan los estados de un pedido',
    'Avanza cada pedido de pendiente a entregado, deja notas en el historial y entiende por qué el sistema rechaza algunos cambios de estado.',
    '<p>Cada pedido avanza por una secuencia de estados. El sistema solo permite el paso siguiente, así que no es posible saltar de "Pendiente" a "Entregado" sin pasar por la preparación. Este orden te da un seguimiento confiable y evita marcar como entregado algo que no salió.</p>
<h3>Qué significa cada estado</h3>
<ul>
<li><strong>Pendiente:</strong> el pedido acaba de entrar y nadie lo ha revisado.</li>
<li><strong>En revisión:</strong> ya lo viste y estás verificando existencias, pago o dirección.</li>
<li><strong>En preparación:</strong> el pedido se está armando o produciendo.</li>
<li><strong>Listo:</strong> está terminado y espera entrega o recolección.</li>
<li><strong>Entregado:</strong> el cliente ya lo recibió. Es un estado final.</li>
<li><strong>Cancelado:</strong> el pedido se anuló. Es un estado final.</li>
</ul>
<h3>Cambia el estado de un pedido</h3>
<ol>
<li>Abre el pedido desde la lista.</li>
<li>En "Cambiar estado" selecciona el estado siguiente permitido.</li>
<li>Agrega una nota si quieres dejar contexto para tu equipo, con un máximo de 500 caracteres.</li>
<li>Pulsa el botón "Marcar como..." y confirma el cambio.</li>
</ol>
<p>Al cancelar se pide una confirmación adicional, porque la cancelación devuelve el inventario y afecta la venta registrada.</p>
<h3>Historial de estados</h3>
<p>En el detalle del pedido el historial muestra cada cambio con el estado anterior, el nuevo, la fecha, el usuario que lo hizo y la nota capturada. Es la mejor evidencia cuando un cliente pregunta cuándo cambió su pedido.</p>
<h3>Si el sistema no te deja avanzar</h3>
<p>Verás el mensaje "Transición de estado no válida". Significa que el estado elegido no es el siguiente en la secuencia. Recarga el detalle, revisa el estado actual y elige la opción que corresponde.</p>
<h3>Recomendaciones</h3>
<ul>
<li>Mueve el pedido en cuanto avances de verdad: el cliente y tu equipo ven el mismo seguimiento.</li>
<li>Usa "En revisión" para confirmar pago y existencias antes de comprometer una fecha.</li>
<li>Marca "Listo" solo cuando el pedido esté completo y empacado.</li>
<li>Deja notas breves con datos útiles: quién empacó, qué falta o cuándo se avisó al cliente.</li>
</ul>',
    110, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'online-store'
      AND title = 'Cómo funcionan los estados de un pedido'
);

-- ----------------------------------------------------------------------------
-- 12. Cancel or delete an order and its inventory impact
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'online-store',
    'Cómo cancelar o eliminar un pedido y qué pasa con el inventario',
    'Aprende la diferencia entre cancelar y eliminar un pedido, cómo se repone el stock y cómo gestionar el reembolso con el cliente.',
    '<p>Cancelar y eliminar un pedido no son lo mismo. Cancelar conserva el registro y devuelve el inventario; eliminar borra el pedido y su venta vinculada. Elegir bien evita perder historial y descuadrar el inventario.</p>
<h3>Cancelar un pedido</h3>
<ol>
<li>Abre el pedido y entra a "Cambiar estado".</li>
<li>Selecciona "Cancelado". Solo se puede cancelar mientras el pedido está pendiente, en revisión, en preparación o listo.</li>
<li>Lee la confirmación: el inventario se repone y el pago debe gestionarse aparte.</li>
<li>Confirma la cancelación.</li>
</ol>
<p>Al cancelar, el sistema:</p>
<ul>
<li>Devuelve las piezas al inventario con el movimiento "Reposición por cancelación de pedido en línea".</li>
<li>Marca la venta vinculada como cancelada.</li>
<li>Conserva el pedido, sus productos y el historial de estados.</li>
</ul>
<h3>Eliminar un pedido</h3>
<ol>
<li>Abre el pedido y usa la opción de eliminar.</li>
<li>Confirma la acción: no se puede deshacer.</li>
</ol>
<p>Al eliminar, el sistema borra el pedido, sus productos y su historial, repone el inventario y elimina la venta vinculada. Úsalo solo para pedidos de prueba, duplicados o capturados por error.</p>
<h3>Reembolsos</h3>
<p>El sistema no devuelve dinero automáticamente. Si el cliente ya pagó con Mercado Pago, gestiona el reembolso desde tu cuenta de Mercado Pago. Si pagó en efectivo, acuerda la devolución con el cliente y registra el movimiento que corresponda en tu operación.</p>
<h3>Recomendaciones</h3>
<ul>
<li>Cancela en lugar de eliminar cuando el pedido existió de verdad.</li>
<li>No elimines un pedido entregado: además de borrar la venta, devuelve inventario que ya salió.</li>
<li>Si solo necesitas corregir existencias, usa el ajuste de stock del módulo Productos.</li>
<li>Avisa al cliente antes de cancelar y deja la nota en el historial.</li>
</ul>',
    120, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'online-store'
      AND title = 'Cómo cancelar o eliminar un pedido y qué pasa con el inventario'
);

-- ----------------------------------------------------------------------------
-- 13. How orders appear in sales, cash register and reports
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'online-store',
    'Cómo se reflejan los pedidos en ventas, caja y reportes',
    'Cada pedido en línea genera una venta con su pago y movimiento de inventario. Aprende a leerla, conciliarla y evitar registros duplicados.',
    '<p>Los pedidos de la tienda en línea no viven aparte: al registrarse generan una venta con canal "Tienda en línea" que aparece en el historial de ventas y en los reportes que se alimentan de ventas. Así puedes comparar el mostrador con el canal digital.</p>
<h3>Qué se registra al crear un pedido</h3>
<ul>
<li>Una venta con folio propio, canal "Tienda en línea" y los productos del pedido.</li>
<li>El costo de envío en el campo de envío, para que el total coincida con lo que paga el cliente.</li>
<li>Los datos de contacto del cliente (nombre, teléfono y correo) y la dirección cuando es envío a domicilio.</li>
<li>Una nota con el folio del pedido para poder rastrearlo desde ventas.</li>
</ul>
<p>El cliente no se crea en el directorio de clientes: queda como contacto de la venta. Si necesitas su estado de cuenta, regístralo en Clientes y relaciona la venta manualmente.</p>
<h3>Cómo se comporta según el método de pago</h3>
<ul>
<li><strong>Efectivo contra entrega:</strong> la venta se registra como completada, con un pago en efectivo completado, y el inventario se descuenta al crear el pedido.</li>
<li><strong>Mercado Pago:</strong> la venta queda pendiente con un pago en proceso. Cuando la pasarela confirma, el pago se marca como completado, la venta se cierra y el inventario se descuenta.</li>
<li><strong>Cancelación:</strong> la venta vinculada se marca como cancelada y el inventario se repone.</li>
<li><strong>Eliminación del pedido:</strong> la venta vinculada se elimina.</li>
</ul>
<h3>Caja y conciliación</h3>
<p>Estas ventas no se ligan a un turno de caja y no requieren una caja abierta para atenderse. El efectivo de los pedidos contra entrega se cobra en la entrega, así que considéralo en tu conciliación diaria y no lo mezcles con el corte del mostrador.</p>
<h3>Recomendaciones</h3>
<ul>
<li>Revisa todos los días las ventas con canal tienda en línea para detectar pedidos sin pago confirmado.</li>
<li>No registres una venta manual por el mismo pedido: duplicarías el ingreso y el inventario.</li>
<li>Usa el folio del pedido como referencia cuando revises la venta en el historial.</li>
<li>Si un pedido se canceló, verifica que la venta también esté cancelada antes de cerrar el mes.</li>
</ul>',
    130, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'online-store'
      AND title = 'Cómo se reflejan los pedidos en ventas, caja y reportes'
);

-- ----------------------------------------------------------------------------
-- 14. The customer purchase journey
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'online-store',
    'Cómo compra un cliente en tu tienda en línea',
    'Recorre el flujo completo del cliente: escaparate, ficha del producto, carrito, pago y confirmación, para explicarlo y detectar dónde se pierden ventas.',
    '<p>Conocer el recorrido del cliente te ayuda a explicarlo por teléfono o WhatsApp y a detectar dónde se pierden las ventas. Este es el camino completo, desde que entra al enlace hasta que ve la confirmación.</p>
<h3>1. Escaparate</h3>
<ul>
<li>Ve el nombre, el eslogan, los banners y la sección de destacados.</li>
<li>Puede buscar por nombre, filtrar por categoría y por rango de precio, y ordenar por precio.</li>
<li>Cada tarjeta muestra la fotografía, el precio y la disponibilidad.</li>
</ul>
<h3>2. Ficha del producto</h3>
<ul>
<li>Lee la descripción y el precio en línea.</li>
<li>Elige la cantidad y agrega al carrito, ya sea para seguir comprando o para ir directo al carrito.</li>
<li>Si el producto está agotado y la venta bajo pedido está activa, ve el tiempo estimado de reposición.</li>
</ul>
<h3>3. Carrito y datos de contacto</h3>
<ul>
<li>El carrito se guarda en el navegador del cliente. Si cierra el navegador o cambia de dispositivo, pierde el contenido; conviene finalizar la compra en el mismo momento.</li>
<li>Captura nombre y teléfono, que son obligatorios, y el correo si lo desea.</li>
<li>Elige recoger en tienda o envío a domicilio. Si elige envío, la dirección es obligatoria.</li>
<li>Puede dejar notas para el pedido y ve el resumen con subtotal, envío y total.</li>
</ul>
<h3>4. Pago</h3>
<ul>
<li>Con efectivo contra entrega: el pedido se registra y pasa directo a la confirmación.</li>
<li>Con Mercado Pago: se abre la pasarela para pagar con tarjeta y después regresa a la tienda.</li>
<li>Si el pago se rechaza, queda pendiente o se abandona, el cliente vuelve al carrito con un aviso y el pedido se elimina. Puede intentarlo de nuevo.</li>
</ul>
<h3>5. Confirmación</h3>
<ul>
<li>Ve el número de pedido, el resumen de productos, el envío y el total.</li>
<li>El carrito se vacía automáticamente.</li>
<li>Puede seguir comprando o escribirte por el botón flotante de WhatsApp.</li>
</ul>
<h3>Qué no incluye la tienda</h3>
<ul>
<li>El cliente no crea una cuenta ni tiene historial de pedidos. Para dar seguimiento debe contactarte por WhatsApp o teléfono.</li>
<li>Las políticas de la tienda están en el pie de página del escaparate.</li>
</ul>
<h3>Recomendaciones</h3>
<ul>
<li>Aclara por WhatsApp los tiempos de entrega y las formas de pago antes de que el cliente compre.</li>
<li>Si un cliente reporta que no ve su carrito, pídele finalizar la compra en el mismo dispositivo y navegador.</li>
<li>Revisa la tienda en un celular real: es donde ocurre la mayor parte de las compras.</li>
</ul>',
    140, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'online-store'
      AND title = 'Cómo compra un cliente en tu tienda en línea'
);

-- ----------------------------------------------------------------------------
-- 15. Troubleshooting the most common online store issues
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'online-store',
    'Soluciones a los problemas más frecuentes de la tienda',
    'Revisa por qué la tienda no carga, un producto no aparece, el pago se rechaza o no llegan los avisos, y cuándo conviene escribir a soporte.',
    '<p>Estos son los problemas más frecuentes al operar la tienda en línea y cómo resolverlos. Antes de escribir a soporte, revisa la configuración y la lista de pedidos.</p>
<h3>La tienda muestra error o no carga</h3>
<ul>
<li>Revisa que el interruptor "Tienda activa" esté encendido y guardado.</li>
<li>Confirma que el enlace sea el actual: si cambiaste el identificador, el anterior ya no funciona.</li>
<li>Si usas un dominio personalizado, verifica que esté bien apuntado.</li>
</ul>
<h3>Un producto no aparece en la tienda</h3>
<ul>
<li>Confirma que el producto tenga activa la opción "¿Mostrar en tienda en línea?" y que hayas guardado.</li>
<li>Verifica que el producto pertenezca a tu sucursal y no esté eliminado.</li>
<li>Revisa si hay una búsqueda, una categoría o un rango de precio aplicado en el escaparate.</li>
<li>Si en el catálogo el producto aparece como oculto en la tienda, actívalo desde la sección "Precios y visibilidad".</li>
</ul>
<h3>El producto aparece agotado</h3>
<ul>
<li>El disponible es el stock actual menos las piezas apartadas. Si es cero, aparece "Sin existencias".</li>
<li>Repón inventario o activa la venta bajo pedido para que el cliente pueda comprarlo.</li>
</ul>
<h3>El cliente no ve métodos de pago</h3>
<ul>
<li>Activa al menos un método y guarda los cambios.</li>
<li>Si solo ofreces Mercado Pago, confirma que la cuenta esté conectada.</li>
<li>Pide al cliente recargar la página del carrito después de tus cambios.</li>
</ul>
<h3>No puedo conectar Mercado Pago</h3>
<ul>
<li>Inicia sesión en Mercado Pago con la cuenta del negocio, no con una cuenta personal distinta.</li>
<li>Si la plataforma está en modo de prueba, no es posible cambiar de cuenta conectada.</li>
<li>Vuelve a intentarlo desde "Configuración" y revisa que la cuenta esté habilitada para cobrar.</li>
</ul>
<h3>El pago se rechazó y el pedido no aparece</h3>
<p>Es el comportamiento esperado: cuando el pago se rechaza, queda pendiente o el cliente abandona la pasarela, el pedido se elimina y el cliente regresa al carrito con un aviso. Pídele intentarlo de nuevo y, si el cargo sí apareció en Mercado Pago, revísalo desde tu cuenta.</p>
<h3>No llegan los correos de pedidos nuevos</h3>
<ul>
<li>Confirma que el interruptor de notificaciones esté activo y que los correos estén guardados.</li>
<li>Revisa spam y marca el remitente como seguro.</li>
<li>Recuerda que el aviso se envía al crear el pedido; si el pago falló, el pedido ya no existirá.</li>
</ul>
<h3>No se aplica el envío gratis</h3>
<ul>
<li>El subtotal debe alcanzar el mínimo configurado, sin contar el envío.</li>
<li>Si el cliente eligió recoger en tienda, no hay envío que descontar.</li>
<li>Si el costo de envío es cero, el mensaje de envío gratis no aplica.</li>
</ul>
<h3>No puedo avanzar el estado del pedido</h3>
<p>El sistema solo permite el estado siguiente de la secuencia. Revisa el estado actual en el detalle y elige la transición correcta; si el pedido ya está entregado o cancelado, no admite más cambios.</p>
<h3>Los cambios de configuración no se ven</h3>
<ul>
<li>Confirma que guardaste la sección modificada.</li>
<li>Recarga la tienda en una ventana nueva para evitar copias en caché.</li>
<li>Revisa que estés viendo el enlace correcto de tu tienda.</li>
</ul>
<h3>Cuándo escribir a soporte</h3>
<p>Si el problema no se resuelve con estas revisiones, envía el enlace de la tienda, el folio del pedido afectado y una captura de lo que ves. Con esos datos el equipo puede revisar el caso sin pedirte más información.</p>',
    150, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'online-store'
      AND title = 'Soluciones a los problemas más frecuentes de la tienda'
);
