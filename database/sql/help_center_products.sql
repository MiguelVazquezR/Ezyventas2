-- ============================================================================
-- Help center - Guides for the Productos (products) module
--
-- Inserts 12 published guides for the "products" module of the
-- help_center_articles table. The guides automatically appear in the
-- Productos card of the help center.
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
-- 1. How to create a product in the catalog
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'products',
    'Cómo crear un producto en el catálogo',
    'Captura los datos generales, asígnalo a una categoría y una sucursal, y guarda el producto para empezar a venderlo.',
    '<p>El catálogo reúne todo lo que vendes: datos generales, inventario, precios e imágenes. Cada producto pertenece a una categoría y se publica en una o varias sucursales.</p>
<h3>Antes de empezar</h3>
<ul>
<li>Necesitas permiso para crear productos. Si el botón "Nuevo producto" no aparece, pide a un administrador que lo habilite.</li>
<li>Ten lista la categoría del producto. Si no existe, créala sin salir del formulario con el botón "Gestionar".</li>
<li>Si tu plan tiene un límite de productos y ya lo alcanzaste, el botón aparece deshabilitado con el mensaje "Límite de N productos alcanzado".</li>
</ul>
<h3>Crear el producto</h3>
<ol>
<li>Entra a Productos y colócate en "Catálogo de productos".</li>
<li>Pulsa "Nuevo producto".</li>
<li>En "Información general" captura el "Nombre del producto", que es obligatorio. Usa un nombre que reconozcas rápido en la caja: marca, modelo y presentación.</li>
<li>Captura el "SKU / Código de barras". Es opcional, pero se usa para buscar y escanear el producto en el punto de venta, y debe ser único en tu sucursal.</li>
<li>Indica la "Ubicación (Estante/Pasillo)" si manejas un almacén físico.</li>
<li>Si tu cuenta emite facturas, captura la "Clave de Producto" y la "Clave Unidad" del SAT.</li>
<li>En "Disponible en sucursales" selecciona al menos una sucursal.</li>
<li>Selecciona la "Categoría". Después define la "Marca" y el "Proveedor" si aplican.</li>
<li>Escribe la "Descripción". Se muestra en la ficha del producto y, si publicas en línea, en tu tienda.</li>
<li>Recorre las secciones "Inventario y Variantes", "Precios y visibilidad" e "Imágenes" con el menú lateral del formulario.</li>
<li>Pulsa "Crear producto" en la barra inferior. El sistema te devuelve al catálogo con el mensaje "Producto creado con éxito."</li>
</ol>
<h3>Después de guardar</h3>
<p>Busca el producto por nombre o código en el catálogo. Al hacer clic en la fila ves la "Vista rápida" con sus datos, o la ficha completa si tu preferencia de clic está configurada así. Desde la ficha puedes ajustar stock, editar o imprimir la etiqueta.</p>
<h3>Recomendaciones</h3>
<ul>
<li>No cambies el SKU de un producto que ya vendiste: los reportes y las etiquetas lo usan como referencia.</li>
<li>Da de alta el producto antes de su primera venta para que el inventario y los reportes salgan completos.</li>
<li>Si el sistema rechaza el SKU, revisa si otro producto de tu sucursal ya lo tiene.</li>
</ul>',
    10, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'products'
      AND title = 'Cómo crear un producto en el catálogo'
);

-- ----------------------------------------------------------------------------
-- 2. How to set prices, wholesale tiers and margin
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'products',
    'Cómo definir precios, mayoreo y margen',
    'Captura el costo y el precio de venta, agrega niveles de mayoreo y revisa la utilidad que deja cada producto antes de venderlo.',
    '<p>El precio de venta es el que se cobra en el punto de venta. El precio de costo es opcional, pero sin él no puedes ver la utilidad, el margen ni la valorización del inventario.</p>
<h3>Capturar los precios base</h3>
<ol>
<li>Abre el producto y entra a la sección "Precios y visibilidad".</li>
<li>Captura el "Precio de costo (Opcional)" con lo que te cuesta una unidad.</li>
<li>Captura el "Precio de venta", que es obligatorio y debe ser mayor o igual a cero.</li>
</ol>
<p>Cuando ambos precios tienen valor, el formulario muestra "Utilidad estimada por unidad" y el "Rendimiento del X% sobre tu costo de inversión". Si el precio de venta es menor al costo, el aviso cambia a "Pérdida".</p>
<h3>Configurar precios de mayoreo</h3>
<ol>
<li>En "Precios de mayoreo (Opcional)" pulsa "Añadir nivel".</li>
<li>Captura "A partir de (cantidad)": el mínimo de unidades para aplicar ese precio. El sistema no acepta dos niveles con la misma cantidad.</li>
<li>Captura el "Precio unitario" del nivel.</li>
<li>Revisa el "Margen: X%" que aparece junto al nivel; si es negativo se muestra en rojo.</li>
<li>Repite el proceso para cada escala. Para quitar un nivel pulsa el icono de papelera con el tooltip "Eliminar nivel".</li>
</ol>
<p>El punto de venta aplica el precio del nivel cuando la cantidad vendida alcanza el mínimo. Si el nivel inicia en 5 unidades, al vender 5 o más se cobra el precio de mayoreo.</p>
<h3>Cómo se calcula el margen</h3>
<p>El rendimiento se calcula sobre el costo de inversión: utilidad entre costo, por cien. Un producto con costo de 100 y venta de 150 muestra 50 de utilidad y 50% de rendimiento. Es un cálculo distinto del margen sobre el precio de venta.</p>
<h3>Productos a granel y variantes</h3>
<ul>
<li>En un producto a granel los precios corresponden a una unidad completa de la unidad de medida. Si vendes 0.250 Kg, el sistema cobra la cuarta parte del precio.</li>
<li>En los niveles de mayoreo de un producto a granel la cantidad mínima acepta decimales.</li>
<li>En un producto con variantes, la "Matriz de variantes" permite ajustar el precio final de cada opción sumando o restando un modificador al precio base.</li>
</ul>
<h3>Recomendaciones</h3>
<ul>
<li>Actualiza el costo cuando cambie tu proveedor: de él dependen la utilidad y los reportes de valorización.</li>
<li>Ordena los niveles de menor a mayor cantidad y con precio decreciente para no cobrar de más al mayoreo.</li>
<li>Antes de publicar un precio en la tienda en línea, revisa que cubra el costo y el envío.</li>
</ul>',
    20, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'products'
      AND title = 'Cómo definir precios, mayoreo y margen'
);

-- ----------------------------------------------------------------------------
-- 3. How to control product visibility in the POS and the online store
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'products',
    'Cómo controlar la visibilidad del producto en el punto de venta y la tienda en línea',
    'Decide si el producto se vende en caja, aparece en tu tienda en línea o queda como insumo interno, sin abrir cada ficha.',
    '<p>Un producto puede venderse en el punto de venta, mostrarse en tu tienda en línea, o ambos. También puede existir solo como insumo interno, para controlar su inventario sin ponerlo a la venta.</p>
<h3>Mostrar u ocultar en el punto de venta</h3>
<ol>
<li>Entra a la sección "Precios y visibilidad" del producto.</li>
<li>Activa o desactiva la pregunta "¿Mostrar en punto de venta?".</li>
</ol>
<p>Si la desactivas, el artículo se trata como un insumo interno: conserva su stock y sus movimientos, pero no aparece en la pantalla de caja.</p>
<h3>Mostrar u ocultar en la tienda en línea</h3>
<ol>
<li>Activa "¿Mostrar en tienda en línea?". Esta opción solo aparece si tu suscripción incluye el módulo de tienda en línea.</li>
<li>Captura el "Precio en línea" si quieres cobrar distinto que en el punto de venta. Si lo dejas vacío se usa el precio de venta normal.</li>
<li>Activa "Destacar en tienda" para que el producto aparezca en la sección de destacados.</li>
</ol>
<h3>Cambiar la visibilidad desde el catálogo</h3>
<p>En la columna "Visibilidad" de "Catálogo de productos" hay tres indicadores que se activan con un clic:</p>
<ul>
<li>Icono de tienda: visible u oculto en el punto de venta. El tooltip confirma el cambio antes de aplicarlo.</li>
<li>Icono de globo con punto verde: visible u oculto en la tienda en línea.</li>
<li>Estrella: producto destacado en la tienda.</li>
</ul>
<p>El cambio se guarda al instante y no necesitas abrir el producto. Si la tienda en línea no está contratada, los dos últimos indicadores no se muestran.</p>
<h3>Recomendaciones</h3>
<ul>
<li>Oculta del punto de venta los artículos que no se cobran: empaques, bolsas, material de limpieza o refacciones de uso interno.</li>
<li>Revisa la visibilidad antes de una promoción: un producto oculto no se puede vender en caja aunque tenga stock.</li>
<li>En un producto con variantes la visibilidad se controla en el producto, no variante por variante.</li>
<li>Si ocultas un producto de la tienda en línea, deja de aparecer en el escaparate y en la búsqueda de la tienda.</li>
</ul>',
    30, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'products'
      AND title = 'Cómo controlar la visibilidad del producto en el punto de venta y la tienda en línea'
);

-- ----------------------------------------------------------------------------
-- 4. How to configure initial stock, minimum, maximum and location
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'products',
    'Cómo configurar inventario inicial, mínimos, máximos y ubicación',
    'Captura el stock con el que arranca el producto, define sus límites de reabastecimiento y guarda dónde se encuentra físicamente.',
    '<p>El inventario define cuántas unidades tienes para vender y en qué sucursal. Se captura al crear el producto y se corrige después con entradas y salidas, no editando la ficha.</p>
<h3>Elegir el tipo de producto</h3>
<p>En "Inventario y Variantes" selecciona el "Tipo de producto": "Producto simple", "Venta a granel", "Con variantes" o "Kit/Combo". Los tres primeros llevan inventario propio; el kit calcula su disponibilidad con el stock de sus componentes.</p>
<h3>Capturar el stock inicial</h3>
<ol>
<li>En "Stock actual" escribe las unidades físicas que tienes hoy en la sucursal.</li>
<li>Selecciona la "Unidad de medida": Pza, Kg, Grs, Lts, Mts o Cm.</li>
<li>Captura el "Stock mínimo (Opcional)". Es el nivel con el que quieres que el sistema marque que debes reabastecer.</li>
<li>Captura el "Stock máximo (Opcional)" como referencia del nivel ideal de existencia después de comprar.</li>
<li>Indica la "Ubicación (Estante/Pasillo)" en "Información general".</li>
</ol>
<h3>Cómo se usan el mínimo y el máximo</h3>
<ul>
<li>La columna "Existencias" del catálogo colorea la etiqueta según el disponible: rojo cuando es cero o negativo, ámbar cuando está en el mínimo o por debajo, verde cuando hay existencia suficiente.</li>
<li>El mínimo también se muestra en la columna "Mínimo" del catálogo y en la ficha del producto.</li>
<li>El máximo es un dato de referencia para planear compras; no cambia el cobro ni el descuento de inventario.</li>
<li>En las variantes el mínimo y el máximo se capturan por opción y se suman en el resumen de la ficha.</li>
</ul>
<h3>Variantes y kits</h3>
<ul>
<li>Si el producto tiene variantes, el stock, el mínimo y el máximo se capturan por opción en la "Matriz de variantes".</li>
<li>Un "Kit/Combo" no tiene inventario físico: la columna muestra "Dinámico" y su disponibilidad depende de los componentes.</li>
</ul>
<h3>Cómo corregir el inventario después</h3>
<p>Usa "Entrada/salida de stock" desde el menú del producto o el botón "Ajustar stock" de la ficha. Cada movimiento queda en el "Historial de movimientos" con motivo, usuario y fecha. Evita editar el producto solo para cambiar cantidades: pierdes la trazabilidad.</p>
<h3>Recomendaciones</h3>
<ul>
<li>Captura el stock real al dar de alta el producto, no el que esperas comprar.</li>
<li>Define el mínimo con base en tu tiempo de reabastecimiento: si tu proveedor tarda una semana, el mínimo debe cubrir esa semana de ventas.</li>
<li>Usa la ubicación para ordenar el almacén: el catálogo la muestra en la columna "Ubicación" y la ficha la repite.</li>
</ul>',
    40, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'products'
      AND title = 'Cómo configurar inventario inicial, mínimos, máximos y ubicación'
);

-- ----------------------------------------------------------------------------
-- 5. How to create a product with variants
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'products',
    'Cómo crear un producto con variantes',
    'Genera una matriz de combinaciones por color, talla o material, y controla el inventario y el precio de cada opción por separado.',
    '<p>Un producto con variantes tiene una ficha por combinación de atributos: color, talla, material o cualquier característica que definas por categoría. Así controlas el inventario y el precio de cada opción.</p>
<h3>Antes de empezar</h3>
<ul>
<li>La categoría del producto debe tener atributos configurados. Sin ellos no hay opciones para combinar.</li>
<li>Los atributos se administran desde "Configurar atributos", en el bloque "Generador automático de variantes".</li>
<li>Si la categoría todavía no tiene atributos, el formulario muestra un aviso y el botón para crearlos.</li>
</ul>
<h3>Crear las variantes</h3>
<ol>
<li>Selecciona la categoría del producto en "Información general". Si aún no la eliges, el generador te pide hacerlo primero.</li>
<li>En "Tipo de producto" elige "Con variantes".</li>
<li>Selecciona los valores de cada atributo en sus listas. El texto de ayuda cambia según el atributo: por ejemplo, "Seleccionar color".</li>
<li>Pulsa "Generar combinaciones". El sistema crea la "Matriz de variantes" con todas las combinaciones posibles de los valores elegidos.</li>
<li>En cada fila captura el SKU, la ubicación, el precio final, el stock inicial, el mínimo y el máximo.</li>
<li>Si necesitas una opción que no sale de los atributos, pulsa "Añadir variante manual" y describe el detalle.</li>
<li>Guarda el producto.</li>
</ol>
<h3>Cómo funciona el precio de las variantes</h3>
<p>Cada variante se vende al precio base más un modificador. Si el producto cuesta 100 y una variante tiene un modificador de 20, su precio final es 120. Al cambiar el precio de venta base, el sistema recalcula el precio final de todas las variantes conservando el modificador.</p>
<h3>Trabajar con matrices grandes</h3>
<ul>
<li>Usa el buscador "Buscar variante o SKU..." para filtrar la matriz.</li>
<li>El paginador permite ver 10, 25 o 50 variantes por página.</li>
<li>Las combinaciones que ya existían conservan sus datos cuando vuelves a generar la matriz.</li>
<li>Para quitar una variante de la lista pulsa el icono de papelera y confirma con "Sí, eliminar".</li>
</ul>
<h3>Después de crear el producto</h3>
<p>El catálogo muestra "Múltiples" en la columna "Ubicación", suma las existencias de todas las variantes y marca el desglose con un icono. En la ficha verás la tabla de variantes y el botón "Ajustar stock" para mover cantidades por opción.</p>
<h3>Recomendaciones</h3>
<ul>
<li>Define atributos reutilizables por categoría para no repetir valores en cada producto.</li>
<li>No generes combinaciones que no vas a vender: cada variante ocupa un lugar en el límite de productos de tu plan.</li>
<li>Captura un SKU por variante si imprimes etiquetas individuales.</li>
</ul>',
    50, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'products'
      AND title = 'Cómo crear un producto con variantes'
);

-- ----------------------------------------------------------------------------
-- 6. How to build a kit or combo
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'products',
    'Cómo armar un kit o combo',
    'Agrupa varios productos en una sola venta y deja que el sistema descuente el inventario de cada componente de forma automática.',
    '<p>Un kit o combo agrupa varios productos para venderlos como uno solo. No tiene inventario propio: su disponibilidad sale del stock de sus componentes y, al venderse, descuenta cada componente.</p>
<h3>Armar el kit</h3>
<ol>
<li>En "Inventario y Variantes" selecciona "Kit/Combo" como "Tipo de producto".</li>
<li>Lee el aviso "Configuración de Kit / Combo": el inventario del kit se calcula con sus componentes.</li>
<li>En "Buscar productos para agregar al kit" escribe el nombre o el SKU del componente.</li>
<li>Elige el resultado. Si el producto tiene variantes, cada variante aparece como componente independiente con su SKU y su stock.</li>
<li>En la tabla ajusta la "Cantidad a descontar por venta" de cada componente. Si seleccionas el mismo componente dos veces, el sistema suma la cantidad.</li>
<li>Pulsa el icono de papelera con el tooltip "Quitar del kit" para eliminar un componente.</li>
<li>Guarda el producto.</li>
</ol>
<h3>Cómo se comporta el inventario</h3>
<ul>
<li>En el catálogo la columna "Existencias" muestra la etiqueta "Dinámico" y la ubicación aparece como guion.</li>
<li>La ficha lista los componentes en "Componentes del Kit/Combo" con nombre y SKU, y enlaza a cada producto.</li>
<li>Los botones de ajuste de stock no aplican al kit: las entradas y salidas se registran sobre los componentes.</li>
<li>Si un componente se queda sin existencia, el kit también deja de estar disponible.</li>
</ul>
<h3>Precio del kit</h3>
<p>El precio de venta del kit se captura en "Precios y visibilidad" como cualquier producto. No se calcula sumando los precios de los componentes: tú defines el precio final del paquete, así que conviene revisar que cubra el costo de todos sus componentes.</p>
<h3>Recomendaciones</h3>
<ul>
<li>Arma kits con productos que ya tengan inventario capturado.</li>
<li>Revisa la disponibilidad de los componentes antes de promocionar el combo.</li>
<li>Si cambias los componentes, confirma que el precio sigue siendo rentable.</li>
<li>No uses un kit para corregir inventario: los ajustes se hacen en los componentes.</li>
</ul>',
    60, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'products'
      AND title = 'Cómo armar un kit o combo'
);

-- ----------------------------------------------------------------------------
-- 7. How to configure a bulk product
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'products',
    'Cómo configurar un producto a granel',
    'Vende fracciones de kilo, litro o metro con inventario decimal y cobra de forma proporcional a la cantidad que pida el cliente.',
    '<p>La venta a granel permite vender fracciones de un producto: 1.5 Kg de café, 0.250 Lts de aceite o 2.75 Mts de cable. El inventario y el cobro aceptan hasta tres decimales.</p>
<h3>Configurar el producto</h3>
<ol>
<li>En "Inventario y Variantes" elige "Venta a granel" como "Tipo de producto".</li>
<li>Selecciona la "Unidad de medida" correcta: Kg, Grs, Lts, Mts o Cm.</li>
<li>Captura el "Stock actual" con decimales si es necesario.</li>
<li>Captura el "Stock mínimo" y el "Stock máximo" en la misma unidad.</li>
<li>En "Precios y visibilidad" captura el costo y el precio de venta que corresponden a una unidad completa.</li>
<li>Guarda el producto.</li>
</ol>
<h3>Cómo se cobra una fracción</h3>
<p>El precio que capturas es el de una unidad entera. El sistema lo prorratea según la cantidad vendida: si el kilo cuesta 200 y el cliente lleva 0.250 Kg, se cobran 50. La guía de la sección "Precios y visibilidad" lo explica dentro del formulario.</p>
<h3>Stock y mayoreo en productos a granel</h3>
<ul>
<li>El stock se muestra con hasta tres decimales en el catálogo y en los movimientos.</li>
<li>En los niveles de mayoreo la cantidad mínima acepta decimales y la etiqueta cambia a "A partir de (Kg)" o la unidad que hayas elegido.</li>
<li>La etiqueta "Venta a granel" aparece junto al nombre en el catálogo para distinguirlo de los productos por pieza.</li>
</ul>
<h3>Registrar entradas y salidas</h3>
<ol>
<li>Pulsa "Entrada/salida de stock" en el producto.</li>
<li>Elige "Dar entrada" o "Dar salida" y el motivo.</li>
<li>Captura la cantidad con decimales. El campo acepta pasos de 0.250 y hasta tres decimales.</li>
<li>Pulsa "Registrar entrada" o "Registrar salida".</li>
</ol>
<h3>Recomendaciones</h3>
<ul>
<li>Elige la unidad más pequeña que vendas para evitar conversiones: si vendes gramos, usa Grs.</li>
<li>No cambies la unidad de medida de un producto que ya tiene movimientos: el historial quedaría en otra escala.</li>
<li>Revisa el precio por unidad completa antes de capturarlo: cualquier error se multiplica en cada venta fraccionada.</li>
</ul>',
    70, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'products'
      AND title = 'Cómo configurar un producto a granel'
);

-- ----------------------------------------------------------------------------
-- 8. How to record stock entries and exits
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'products',
    'Cómo registrar entradas y salidas de stock',
    'Mueve el inventario con un motivo claro, registra el gasto de tus compras y conserva el historial de cada ajuste.',
    '<p>Las entradas y salidas alimentan y corrigen el inventario con un motivo. Cada movimiento queda en el historial del producto y, cuando corresponde, genera un gasto contable.</p>
<h3>Abrir el movimiento</h3>
<ul>
<li>Desde el catálogo: abre el menú de tres puntos del producto y elige "Entrada/salida de stock".</li>
<li>Desde la ficha: pulsa "Ajustar stock".</li>
<li>En lote: selecciona varios productos y pulsa "Ajustar stock" en la barra de acciones.</li>
</ul>
<h3>Registrar el movimiento</h3>
<ol>
<li>Elige "Dar entrada" o "Dar salida" en el selector superior.</li>
<li>Selecciona el "Motivo / Concepto". Las entradas permiten "Compra / Reabastecimiento", "Devolución de cliente", "Ajuste de inventario (+)", "Inventario inicial", "Producción interna" y "Otro". Las salidas permiten "Venta externa", "Merma / Caducado", "Producto dañado", "Robo / Pérdida", "Uso interno", "Ajuste de inventario (-)" y "Otro".</li>
<li>Captura la "Cantidad a ingresar" o la "Cantidad a descontar".</li>
<li>Si el producto tiene variantes, captura la cantidad de cada variante en la tabla; verás el disponible, el físico total y el apartado de cada una.</li>
<li>Pulsa "Registrar entrada" o "Registrar salida".</li>
</ol>
<h3>Registrar el gasto de una compra</h3>
<p>Cuando la operación es una entrada con el motivo "Compra / Reabastecimiento", el formulario muestra la pregunta "¿Registrar gasto contable por esta compra?". Al activarla puedes elegir entre "Usar precio de compra registrado", que multiplica la cantidad por el costo del producto, o "Ingresar total de forma manual" para capturar el total del ticket o la factura.</p>
<p>Completa también la "Fecha de compra / del gasto", el "Método de pago" y, si pagas con tarjeta o transferencia, la "Cuenta bancaria (De dónde salió el dinero)".</p>
<p>Si pagas en efectivo de la caja, activa "Tomar dinero de la caja registradora": el egreso se registra en la sesión de caja activa para no descuadrar el corte. Esta opción requiere una sesión abierta; si no la hay, el sistema muestra "No hay una sesión de caja activa."</p>
<h3>Después del movimiento</h3>
<ul>
<li>El stock del producto o de la variante se actualiza al instante.</li>
<li>El movimiento aparece en "Historial de movimientos" de la ficha con fecha, usuario y motivo.</li>
<li>Si registraste el gasto, se refleja en el módulo de gastos y en los reportes financieros.</li>
</ul>
<h3>Recomendaciones</h3>
<ul>
<li>Elige el motivo correcto: de él dependen los reportes de mermas y ajustes.</li>
<li>No uses una salida para corregir apartados. Para eso existe "Ajustar apartados".</li>
<li>Antes de una salida grande revisa el disponible: si queda en negativo, el sistema lo marca en rojo y conviene revisar el físico.</li>
</ul>',
    80, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'products'
      AND title = 'Cómo registrar entradas y salidas de stock'
);

-- ----------------------------------------------------------------------------
-- 9. How to read stock, layaway reservations and available quantity
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'products',
    'Cómo interpretar existencias, apartados y disponible',
    'Distingue el stock físico de las unidades apartadas y del disponible real, y corrige las diferencias sin perder el control.',
    '<p>Un producto tiene tres cantidades distintas y conviene no confundirlas: el stock físico, los apartados y el disponible. El punto de venta cobra con base en el disponible, así que un error aquí afecta lo que puedes vender.</p>
<h3>Qué significa cada cantidad</h3>
<ul>
<li>Stock físico: las unidades que existen en la sucursal. Se mueve con entradas y salidas.</li>
<li>Apartados: las unidades comprometidas en apartados o pedidos activos. Siguen físicamente en tu almacén, pero ya tienen dueño.</li>
<li>Disponible: el stock físico menos los apartados. Es lo que puedes vender hoy.</li>
</ul>
<h3>Dónde verlas</h3>
<p>En "Catálogo de productos", la columna "Existencias" muestra el disponible y, cuando hay apartados, una segunda etiqueta con el texto "apartado(s)". El tooltip de esa etiqueta indica el "Stock físico Total". El color cambia según la situación: rojo cuando el disponible es cero o negativo, ámbar cuando está en el mínimo o por debajo, verde cuando hay existencia suficiente.</p>
<p>En un producto con variantes la etiqueta suma todas las opciones y un icono abre el desglose. Si alguna variante está en negativo, el tooltip lo advierte con el texto "Tienes N variantes con existencias negativas, revísalas".</p>
<p>En la ficha del producto encontrarás la tarjeta de inventario con el físico, el apartado y el disponible, el "Stock mínimo" y, más abajo, la tabla "Detalle de apartados activos" con fecha, folio, cliente, cantidad y vencimiento.</p>
<h3>Corregir apartados o disponible</h3>
<ol>
<li>En la ficha del producto pulsa "Ajustar apartados".</li>
<li>Captura la "Cantidad de apartados" y la "Cantidad disponible" correctas.</li>
<li>Si el producto tiene variantes, ajusta cada fila de la tabla: variante, apartados y disponible.</li>
<li>Pulsa "Guardar ajuste".</li>
</ol>
<p>Usa este ajuste solo cuando los datos no coincidan por un error: la cantidad disponible se calcula como físico menos apartados, por lo que el sistema la vuelve a derivar de las cantidades que captures.</p>
<h3>Qué hacer con un disponible negativo</h3>
<ul>
<li>Significa que hay más unidades comprometidas que físicas: se vendió o se apartó sin registrar la entrada.</li>
<li>Revisa el "Historial de movimientos" y registra la entrada faltante con el motivo correcto.</li>
<li>Confirma con un conteo físico antes de ajustar, para no inventar existencias.</li>
</ul>
<h3>Recomendaciones</h3>
<ul>
<li>Filtra el catálogo con "Con apartados" para revisar los productos comprometidos antes de prometer una entrega.</li>
<li>Los kits no muestran cantidades propias: su existencia es "Dinámico" y depende de los componentes.</li>
<li>Revisa los apartados vencidos de la tabla "Detalle de apartados activos" y regularízalos en el módulo de apartados.</li>
</ul>',
    90, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'products'
      AND title = 'Cómo interpretar existencias, apartados y disponible'
);

-- ----------------------------------------------------------------------------
-- 10. How to read the inventory reports
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'products',
    'Cómo consultar los reportes de inventario',
    'Elige entre siete reportes de inventario, ajusta el período y la categoría, y revisa el resultado en pantalla o imprímelo.',
    '<p>Los reportes de inventario responden preguntas concretas: qué no se vende, qué se vende más, cuánto vale tu inventario o qué productos se quedaron sin existencia. Se generan desde el catálogo y se pueden imprimir.</p>
<h3>Abrir los reportes</h3>
<ol>
<li>Entra a Productos y quédate en "Catálogo de productos".</li>
<li>En la barra superior pulsa el botón con el icono de documento; el tooltip dice "Reportes de inventario".</li>
<li>Selecciona el tipo de reporte.</li>
</ol>
<h3>Tipos disponibles</h3>
<ul>
<li>"Mercancía sin movimiento": productos con stock sin salidas en el período.</li>
<li>"Productos más vendidos": ranking por cantidad, frecuencia o ingreso.</li>
<li>"Rotación de inventario": ventas entre inventario promedio.</li>
<li>"Quiebres de stock": productos que llegaron a cero en el período.</li>
<li>"Valorización de inventario": stock por costo, por producto o por categoría.</li>
<li>"Mayor valor inmovilizado": productos sin movimiento con alto valor en stock.</li>
<li>"Margen por producto": ingreso menos costo, con la utilidad real.</li>
</ul>
<h3>Ajustar los filtros</h3>
<ul>
<li>"Fecha inicio" y "Fecha fin": disponibles en los reportes que analizan un período.</li>
<li>"Categoría": limita el análisis a una categoría o lo deja en "Todas".</li>
<li>"Ordenar por": en "Productos más vendidos" puedes ordenar por cantidad vendida, frecuencia o ingreso generado.</li>
<li>"Agrupar por": en "Valorización de inventario" puedes agrupar por producto o por categoría.</li>
<li>"Stock mínimo": en "Mercancía sin movimiento" define desde cuántas unidades se considera que hay inventario.</li>
</ul>
<p>Pulsa "Generar reporte" para ejecutarlo.</p>
<h3>Leer el resultado</h3>
<p>El reporte muestra el título y el subtítulo del período, tarjetas de resumen y una tabla con las columnas del tipo elegido. Los importes aparecen en pesos y el margen negativo se marca en rojo. Con "Imprimir / PDF" abres la versión imprimible y con "Nuevo reporte" regresas al selector.</p>
<p>Si no hay datos, el mensaje "No se encontraron registros para este período." indica que los filtros no arrojaron resultados.</p>
<h3>Resumen de inventario</h3>
<p>El botón con el icono de gráfico de pastel abre "Resumen de inventario local", con el total de unidades físicas, la separación entre simples y variantes y el desglose por categoría ordenado por volumen.</p>
<h3>Recomendaciones</h3>
<ul>
<li>Corre "Valorización de inventario" al cierre de mes para revisar el capital inmovilizado.</li>
<li>Usa "Quiebres de stock" junto con tus mínimos para planear compras.</li>
<li>Antes de comparar períodos, confirma que no queden entradas pendientes de registrar.</li>
</ul>',
    100, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'products'
      AND title = 'Cómo consultar los reportes de inventario'
);

-- ----------------------------------------------------------------------------
-- 11. How to use quick edit and bulk actions
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'products',
    'Cómo usar la edición rápida y las acciones masivas',
    'Cambia precios, códigos y límites de varios productos a la vez, ajusta stock en lote y elimina registros sin abrirlos uno por uno.',
    '<p>Cuando necesitas cambiar precios, códigos o límites de muchos productos, usa las acciones masivas del catálogo. También puedes cambiar la visibilidad desde la propia tabla sin abrir cada ficha.</p>
<h3>Seleccionar productos</h3>
<ol>
<li>En "Catálogo de productos" marca la casilla de la primera columna de cada producto.</li>
<li>La barra de acciones aparece con el número de "seleccionados".</li>
<li>La selección se conserva mientras trabajas en la misma vista y se limpia al completar una acción.</li>
</ol>
<h3>Edición rápida</h3>
<ol>
<li>Selecciona los productos y pulsa "Edición rápida".</li>
<li>Se abre "Edición Masiva de Productos" con una fila por producto y otra por cada variante.</li>
<li>Edita el SKU, el precio, el costo, el stock mínimo y el stock máximo.</li>
<li>Ajusta los interruptores de punto de venta, tienda en línea y destacado en los productos. En las variantes esos valores aparecen deshabilitados con el tooltip "Heredado del producto base".</li>
<li>Pulsa "Guardar cambios masivos". Si algún campo no es válido se marca en rojo y el sistema pide revisarlo.</li>
</ol>
<p>En las variantes el precio se muestra como precio final y el costo se hereda del producto base. Al terminar, el sistema confirma con el mensaje "Productos actualizados masivamente con éxito."</p>
<h3>Ajustar stock en lote</h3>
<ol>
<li>Selecciona los productos y pulsa "Ajustar stock".</li>
<li>Elige "Dar entrada" o "Dar salida" y el motivo.</li>
<li>Captura la cantidad de cada producto y de cada variante.</li>
<li>Confirma con "Registrar entrada" o "Registrar salida".</li>
</ol>
<h3>Eliminar en lote</h3>
<p>Pulsa "Eliminar" y confirma en "Confirmación de eliminación masiva". La acción no se puede deshacer.</p>
<h3>Acciones rápidas en la tabla</h3>
<ul>
<li>En la columna "Visibilidad" puedes activar o desactivar el punto de venta, la tienda en línea y el destacado con un clic.</li>
<li>El icono de impresora de la columna "Código" abre la impresión de etiqueta del producto.</li>
<li>El menú de tres puntos de cada fila ofrece "Ver", "Editar", "Entrada/salida de stock", "Agregar promoción" y "Eliminar".</li>
</ul>
<h3>Recomendaciones</h3>
<ul>
<li>Exporta el catálogo antes de una edición masiva para conservar una copia de los valores anteriores.</li>
<li>Verifica que el precio de venta no quede por debajo del costo antes de guardar.</li>
<li>La edición rápida no cambia el inventario: para cantidades usa "Ajustar stock".</li>
</ul>',
    110, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'products'
      AND title = 'Cómo usar la edición rápida y las acciones masivas'
);

-- ----------------------------------------------------------------------------
-- 12. How to import base catalog products and export your catalog
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'products',
    'Cómo importar productos del catálogo base y exportar tu catálogo',
    'Copia productos sugeridos a tu inventario y descarga un respaldo en Excel de los productos que tú creaste.',
    '<p>Ezyventas incluye un catálogo base con productos sugeridos que puedes copiar a tu inventario. Tu catálogo propio, en cambio, se puede descargar a Excel para respaldarlo o analizarlo.</p>
<h3>Importar del catálogo base</h3>
<ol>
<li>Entra a Productos y pulsa la pestaña "Catálogo Base". La pestaña solo aparece si tienes permiso para gestionar productos del catálogo base.</li>
<li>Usa "Búsqueda global" para filtrar por nombre o SKU, o marca categorías en la lista lateral. Pulsa "Limpiar" para quitar el filtro.</li>
<li>Selecciona los productos con un clic sobre la tarjeta. La barra superior muestra cuántos productos base estás explorando y el botón "Seleccionar todos (página)" marca los de la página actual.</li>
<li>Cuando termines, pulsa "Importar al inventario" en la barra flotante inferior.</li>
</ol>
<p>Los productos importados se copian a tu catálogo con su información y precio sugerido. Después puedes ajustar precio, inventario y visibilidad como cualquier producto propio. Las tarjetas con la etiqueta "Ya en tu tienda" ya fueron importadas y no se pueden volver a seleccionar.</p>
<h3>Exportar tu catálogo</h3>
<ol>
<li>En "Catálogo de productos" pulsa el botón con la flecha hacia abajo de la barra superior.</li>
<li>Elige "Exportar a excel" en el menú.</li>
<li>Revisa el aviso del cuadro "Exportar a Excel": solo se exportan los productos que tú creaste; los productos del catálogo base de Ezyventas no se incluyen.</li>
<li>Confirma los contadores "Tus productos" y "Catálogo base" para saber cuántos registros entrarán en el archivo.</li>
<li>Pulsa "Descargar Excel" y espera a que se genere la descarga.</li>
</ol>
<p>Si el contador de productos propios es cero, el botón no aparece y el cuadro muestra el mensaje "No tienes productos propios para exportar."</p>
<h3>Recomendaciones</h3>
<ul>
<li>Importa del catálogo base solo lo que vas a vender: cada producto cuenta para el límite de tu plan.</li>
<li>Revisa el precio sugerido antes de venderlo: se definió como referencia y puede no coincidir con tu costo.</li>
<li>Exporta antes de hacer cambios masivos y guarda el archivo con la fecha en el nombre.</li>
</ul>',
    120, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'products'
      AND title = 'Cómo importar productos del catálogo base y exportar tu catálogo'
);

