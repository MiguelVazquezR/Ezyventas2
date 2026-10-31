-- ============================================================================
-- Help center - Guides for the billing module
--
-- Inserts 12 published guides for the "billing" module of the
-- help_center_articles table. The guides automatically appear in the
-- billing card of the help center.
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
-- 1. First steps to start invoicing
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'billing',
    'Primeros pasos para facturar',
    'Deja listo tu emisor fiscal, el certificado CSD y tu saldo de timbres, y emite tu primera factura con el flujo recomendado.',
    '<p>El módulo Facturación emite comprobantes fiscales digitales (CFDI 4.0) a partir de tus ventas o de datos capturados manualmente. Antes de timbrar tu primera factura necesitas dejar listo el emisor fiscal, el certificado de sello digital y el saldo de timbres.</p>
<h3>Requisitos previos</h3>
<ul>
<li>Tener permiso para usar el módulo. Si no ves Facturación en el menú, pide a un administrador que habilite tus permisos.</li>
<li>Contar con tu Constancia de Situación Fiscal para copiar el RFC, la razón social, el régimen fiscal y el código postal.</li>
<li>Tener a la mano los archivos .cer y .key del CSD, junto con la contraseña de la llave privada.</li>
<li>Tener timbres disponibles. Sin timbres no es posible certificar facturas.</li>
</ul>
<h3>Configura tu emisor fiscal</h3>
<ol>
<li>Entra a Facturación y abre "Configuración fiscal".</li>
<li>Pulsa "Agregar emisor fiscal" y captura RFC, código postal, razón social, régimen fiscal y email de contacto fiscal.</li>
<li>Guarda con "Guardar emisor".</li>
<li>En el menú de acciones del emisor elige "Agregar certificados" y sube el archivo .cer, el archivo .key y la contraseña del CSD.</li>
<li>Si tu cuenta es una subcuenta, el sistema pedirá "Firmar manifiesto" para autorizar al proveedor de timbrado.</li>
<li>Desde el mismo menú elige "Comprar timbres" y completa el pedido.</li>
</ol>
<h3>Confirma que todo esté listo</h3>
<ul>
<li>Estado del emisor: Activo. Si dice Pendiente de activación, espera la activación de la cuenta.</li>
<li>CSD: Activo. Si aparece Por vencer o Vencido, renueva el certificado antes de timbrar.</li>
<li>Manifiesto: Firmado, cuando tu cuenta lo requiera.</li>
<li>Timbres: un saldo mayor a cero. El resumen muestra los timbres disponibles de cada emisor.</li>
</ul>
<h3>Emite tu primera factura</h3>
<ol>
<li>En "Lista de facturas" pulsa "Emitir factura" o, si la venta ya existe, usa "Facturar venta" desde el punto de venta.</li>
<li>Selecciona el emisor, captura el receptor y elige forma y método de pago.</li>
<li>Agrega los conceptos y revisa el desglose financiero.</li>
<li>Pulsa "Timbrar ahora" para certificar la factura o "Guardar como prefactura" para revisarla después.</li>
</ol>
<h3>Estados que verás</h3>
<ul>
<li>Pre-factura: borrador sin validez fiscal; se puede editar o eliminar.</li>
<li>Pendiente: el timbrado está en curso o falló y se puede reintentar.</li>
<li>Timbrada: CFDI certificado con UUID; ya no se puede editar.</li>
<li>En verificación: el SAT aún confirma el resultado del timbrado.</li>
<li>Cancelación pendiente: enviaste la solicitud y esperas la respuesta del receptor.</li>
<li>Cancelada: la factura quedó sin efectos.</li>
</ul>
<h3>Reglas que conviene conocer</h3>
<ul>
<li>La fecha de emisión no puede ser futura ni tener más de 72 horas de antigüedad.</li>
<li>Cada timbrado consume un timbre; las prefacturas y las cancelaciones no consumen.</li>
<li>Una factura timbrada solo se corrige con una nota de crédito o con la cancelación.</li>
</ul>',
    10, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'billing'
      AND title = 'Primeros pasos para facturar'
);

-- ----------------------------------------------------------------------------
-- 2. How to add and manage fiscal issuers
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'billing',
    'Cómo agregar y administrar emisores fiscales',
    'Registra el RFC con el que facturas, completa sus datos fiscales y controla su estado, certificados, logotipo y timbres.',
    '<p>Un emisor fiscal es el RFC con el que se emiten las facturas. Puedes administrar uno o varios emisores desde "Configuración fiscal", dentro del módulo Facturación.</p>
<h3>Agregar un emisor fiscal</h3>
<ol>
<li>Abre Facturación y entra a "Configuración fiscal".</li>
<li>Pulsa "Agregar emisor fiscal".</li>
<li>Captura el RFC con homoclave y el código postal de tu domicilio fiscal.</li>
<li>Escribe la razón social exactamente como aparece en tu Constancia de Situación Fiscal.</li>
<li>Selecciona el régimen fiscal y un email de contacto fiscal.</li>
<li>Pulsa "Guardar emisor". El emisor aparece en la lista con etiquetas de CSD, manifiesto y timbres.</li>
</ol>
<h3>Completa los pasos pendientes</h3>
<ul>
<li>Certificados: usa el menú de acciones y elige "Agregar certificados" para cargar el CSD.</li>
<li>Manifiesto: si la etiqueta dice Pendiente, firma el manifiesto del SAT desde el mismo menú.</li>
<li>Timbres: compra timbres para poder certificar facturas.</li>
</ul>
<h3>Qué puedes modificar</h3>
<p>El RFC solo se puede editar mientras el emisor no tenga CSD cargado; después el campo queda bloqueado con el aviso "No se puede modificar el RFC porque tiene CSD cargados". La razón social, el régimen fiscal, el código postal y el email se pueden corregir en cualquier momento con "Editar datos fiscales".</p>
<h3>Etiquetas de estado</h3>
<ul>
<li>Estado del emisor: Activo, Pendiente de activación o Inactivo.</li>
<li>CSD: Pendiente, Activo, Por vencer (con los días restantes) o Vencido.</li>
<li>Manifiesto: Firmado o Pendiente, solo en cuentas que lo requieren.</li>
<li>Timbres: el saldo disponible; aparece en ámbar cuando está por agotarse.</li>
</ul>
<h3>Activar o inactivar un emisor</h3>
<p>Con "Inactivar emisor fiscal" dejas de usarlo al crear facturas; tus facturas anteriores no cambian. Si está inactivo, la fila se atenúa y el menú ofrece "Activar emisor fiscal" para volver a usarlo.</p>
<h3>Eliminar un emisor</h3>
<p>El sistema solo permite eliminar emisores sin CSD cargado. Si ya tiene certificados, inactívalo para conservar el historial fiscal.</p>
<h3>Varios emisores</h3>
<ul>
<li>Al crear una factura, el selector "Datos del emisor" aparece cuando la cuenta tiene más de un RFC.</li>
<li>La lista de facturas permite filtrar por emisor fiscal.</li>
<li>El resumen de Facturación muestra el saldo de timbres y las facturas de cada emisor.</li>
</ul>',
    20, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'billing'
      AND title = 'Cómo agregar y administrar emisores fiscales'
);

-- ----------------------------------------------------------------------------
-- 3. How to upload the digital seal certificate (CSD)
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'billing',
    'Cómo cargar tu certificado de sello digital (CSD)',
    'Sube los archivos .cer y .key del SAT, valida la contraseña de la llave privada y revisa la vigencia antes de timbrar.',
    '<p>El Certificado de Sello Digital (CSD) es el archivo que el SAT entrega para firmar electrónicamente tus facturas. Se compone de dos archivos: el certificado público (.cer) y la llave privada (.key), protegida con una contraseña.</p>
<h3>Antes de empezar</h3>
<ul>
<li>Descarga el CSD vigente desde el portal del SAT. La e.firma o FIEL no sirve para timbrar; el manifiesto sí la requiere.</li>
<li>Verifica que el RFC del certificado coincida con el del emisor registrado.</li>
<li>Confirma la fecha de vencimiento. Si está vencido, el timbrado será rechazado.</li>
</ul>
<h3>Cargar el certificado</h3>
<ol>
<li>Entra a Facturación y abre "Configuración fiscal".</li>
<li>En la fila del emisor abre el menú de acciones y elige "Agregar certificados". Si ya tenía uno, la opción se llama "Actualizar certificados".</li>
<li>En la ventana "Cargar certificado (CSD)" sube "Certificado (.cer)", "Llave privada (.key)" y escribe la "Contraseña del CSD".</li>
<li>Pulsa "Cargar certificados". El sistema valida los archivos con el proveedor de timbrado.</li>
</ol>
<h3>Consulta los datos registrados</h3>
<p>Cuando el CSD está cargado, la ventana muestra la etiqueta "Certificado activo" con el "Número de serie" y la "Vigencia" (fecha inicial y final). Desde ahí mismo puedes pulsar "Actualizar certificado" para reemplazarlo.</p>
<h3>Vigencia y renovación</h3>
<ul>
<li>Activo: el certificado es válido y se puede timbrar.</li>
<li>Por vencer: faltan 30 días o menos; aparece con los días restantes.</li>
<li>Vencido: el SAT rechazará el timbrado hasta que cargues uno nuevo.</li>
</ul>
<p>Renueva antes del vencimiento para no interrumpir la facturación. El SAT suele permitir la renovación desde su portal con la e.firma.</p>
<h3>Errores comunes</h3>
<ul>
<li>"Contraseña incorrecta": verifica la contraseña de la llave privada, no la de la e.firma.</li>
<li>Archivos intercambiados: el .cer es el certificado y el .key la llave; no los subas al revés.</li>
<li>RFC distinto: el certificado debe corresponder al RFC del emisor.</li>
<li>Certificado vencido: solicita uno nuevo y vuelve a cargarlo.</li>
</ul>
<h3>Seguridad</h3>
<p>La contraseña se usa solo para validar el certificado y no se muestra en la interfaz. Evita compartir los archivos .key por correo y guarda un respaldo seguro.</p>',
    30, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'billing'
      AND title = 'Cómo cargar tu certificado de sello digital (CSD)'
);

-- ----------------------------------------------------------------------------
-- 4. How to sign the SAT manifest
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'billing',
    'Cómo firmar el manifiesto del SAT',
    'Autoriza a tu proveedor de timbrado con la e.firma en tres pasos y deja operativo el emisor fiscal de tu subcuenta.',
    '<p>El manifiesto es la autorización que el SAT exige para que un proveedor autorizado (PAC) timbre tus facturas. Solo aparece cuando tu cuenta opera como subcuenta del proveedor de timbrado; en cuentas compartidas la columna Manifiesto se oculta.</p>
<h3>Cómo saber si lo necesitas</h3>
<ul>
<li>En "Configuración fiscal", la fila del emisor muestra la etiqueta "Manifiesto: Pendiente".</li>
<li>En el menú de acciones aparece la opción "Firmar manifiesto".</li>
</ul>
<h3>Paso 1: obtén el texto del manifiesto</h3>
<ol>
<li>Abre el menú de acciones del emisor y elige "Firmar manifiesto".</li>
<li>En la ventana "Firmar Manifiesto SAT" sube el archivo .cer del emisor. El archivo .cer es público, así que este paso no expone tu llave privada.</li>
<li>Pulsa "Obtener texto del manifiesto".</li>
</ol>
<h3>Paso 2: lee y acepta</h3>
<ol>
<li>Revisa el texto del manifiesto que muestra la ventana.</li>
<li>Marca la casilla "He leído y acepto el manifiesto anterior".</li>
</ol>
<h3>Paso 3: firma con la e.firma</h3>
<ol>
<li>Sube el certificado y la llave de tu e.firma (FIEL). Los CSD no son válidos para este paso.</li>
<li>Escribe la contraseña de la llave privada.</li>
<li>Captura el "Correo para recibir copia" si quieres una copia de la autorización.</li>
<li>Pulsa "Firmar manifiesto".</li>
</ol>
<h3>Después de firmar</h3>
<ul>
<li>La etiqueta cambia a "Manifiesto: Firmado".</li>
<li>Si necesitas repetir el proceso, el menú muestra "Volver a firmar manifiesto".</li>
<li>Si el SAT rechaza la firma, revisa la vigencia de la e.firma y vuelve a intentarlo.</li>
</ul>
<h3>Importante</h3>
<ul>
<li>Firmar el manifiesto no sustituye al CSD: ambos son necesarios para timbrar.</li>
<li>El manifiesto autoriza al proveedor de facturación a emitir tus comprobantes ante el SAT.</li>
<li>Si el proceso falla varias veces, contacta al administrador de tu cuenta antes de reintentar.</li>
</ul>',
    40, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'billing'
      AND title = 'Cómo firmar el manifiesto del SAT'
);

-- ----------------------------------------------------------------------------
-- 5. How to buy stamps and check the balance
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'billing',
    'Cómo comprar timbres y revisar tu saldo',
    'Calcula el precio por volumen, paga por transferencia y consulta los timbres disponibles y usados de cada emisor.',
    '<p>Un timbre es el folio fiscal que el SAT asigna a cada CFDI certificado. El saldo de timbres se administra por emisor fiscal y se consume al timbrar.</p>
<h3>Consulta tu saldo</h3>
<ul>
<li>En el resumen de Facturación, cada emisor muestra "Timbres adquiridos", "Timbres usados" y "Timbres disponibles".</li>
<li>En "Configuración fiscal" la fila muestra la etiqueta "Timbres" con el saldo actual.</li>
<li>Si el saldo es bajo, aparece el aviso "Saldo bajo de timbres" y la etiqueta cambia a color ámbar.</li>
</ul>
<h3>Cómo comprar timbres</h3>
<ol>
<li>Entra a "Configuración fiscal" y abre el menú de acciones del emisor.</li>
<li>Elige "Comprar timbres".</li>
<li>Captura la "Cantidad" de timbres. El sistema calcula el precio por volumen automáticamente.</li>
<li>Revisa el desglose: precio unitario, ahorro por volumen y "Total a pagar".</li>
<li>Selecciona el "Método de pago". La transferencia bancaria está disponible; Mercado Pago aparecerá próximamente.</li>
<li>Con transferencia, copia la CLABE con el botón de copiar y realiza el depósito desde tu banco.</li>
<li>Sube el "Comprobante de pago" en el área de archivos.</li>
<li>Pulsa "Confirmar compra".</li>
</ol>
<h3>Qué pasa después</h3>
<p>El pedido queda pendiente hasta que un administrador apruebe el comprobante. Cuando se aprueba, los timbres se acreditan al emisor y el saldo se actualiza en el resumen y en la configuración fiscal.</p>
<h3>Cómo se consumen</h3>
<ul>
<li>Cada factura timbrada consume un timbre, sin importar el monto ni la cantidad de conceptos.</li>
<li>Las prefacturas no consumen timbres.</li>
<li>Cancelar una factura no devuelve el timbre: el cobro ocurre al timbrar.</li>
<li>Los complementos de pago y las notas de crédito también son CFDI y consumen un timbre.</li>
</ul>
<h3>Recomendaciones</h3>
<ul>
<li>Compra con anticipación si tienes temporadas altas de facturación.</li>
<li>Conserva el comprobante de pago hasta que el pedido se acredite.</li>
<li>Verifica la CLABE antes de transferir; los depósitos a una cuenta incorrecta no se aplican automáticamente.</li>
</ul>',
    50, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'billing'
      AND title = 'Cómo comprar timbres y revisar tu saldo'
);

-- ----------------------------------------------------------------------------
-- 6. How to create an invoice from scratch
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'billing',
    'Cómo crear una factura desde cero',
    'Captura emisor, receptor, forma de pago y conceptos; revisa retenciones y decide entre guardar una prefactura o timbrar.',
    '<p>Una factura de ingreso se crea desde "Lista de facturas" con el botón "Emitir factura". El formulario se organiza en secciones que puedes recorrer con la barra lateral: Emisor, Receptor, Forma y método de pago, Conceptos y Totales.</p>
<h3>Datos del comprobante</h3>
<ul>
<li>Emisor: elige los "Datos del emisor" cuando tengas más de un RFC.</li>
<li>Tipo de comprobante: Ingreso, para una venta o un servicio cobrado.</li>
<li>Fecha de emisión: no puede ser futura ni tener más de 72 horas de antigüedad.</li>
<li>Exportación: se mantiene en 01 - No aplica para operaciones nacionales.</li>
</ul>
<h3>Receptor</h3>
<ol>
<li>Busca al cliente en el catálogo o captura sus datos fiscales.</li>
<li>Verifica el RFC, la razón social, el régimen fiscal y el código postal.</li>
<li>Selecciona el uso del CFDI que te indique el cliente, por ejemplo G03 - Gastos en general.</li>
</ol>
<h3>Forma y método de pago</h3>
<ul>
<li>Forma de pago: cómo se pagó, por ejemplo 01 - Efectivo o 03 - Transferencia electrónica de fondos.</li>
<li>Método de pago: PUE cuando se paga en una sola exhibición y PPD cuando se paga en parcialidades o después.</li>
<li>Con PPD la forma de pago suele ser 99 - Por definir y después se emite un complemento de pago.</li>
<li>Si facturas en dólares, captura el tipo de cambio que exige el SAT.</li>
</ul>
<h3>Conceptos</h3>
<ol>
<li>Busca un producto o servicio del catálogo para rellenar la descripción y las claves SAT.</li>
<li>Captura cantidad, precio unitario y descuento si aplica.</li>
<li>Elige el objeto de impuesto y la tasa de IVA. Si el concepto es tasa 0 % o exento, el sistema lo advierte.</li>
<li>Activa "Los precios ya incluyen IVA" si tus precios de venta ya traen el impuesto, para que el total coincida.</li>
<li>Agrega tantos conceptos como necesites; el desglose financiero se recalcula al instante.</li>
</ol>
<h3>Retenciones</h3>
<p>Cuando el emisor es persona física y el receptor es persona moral, el sistema calcula las retenciones de ISR e IVA que correspondan. En régimen RESICO se aplica la retención de ISR del 1.25 %. Revisa el aviso ámbar del desglose antes de timbrar.</p>
<h3>Guardar o timbrar</h3>
<ul>
<li>"Guardar como prefactura" guarda el borrador sin validez fiscal. Después puedes editarlo, timbrarlo o eliminarlo.</li>
<li>"Timbrar ahora" valida los datos y solicita el folio fiscal al SAT de inmediato.</li>
<li>En modo edición los botones cambian a "Guardar cambios" y "Timbrar factura".</li>
</ul>
<h3>Revisa antes de timbrar</h3>
<ul>
<li>Totales: subtotal, IVA trasladado, retenciones y total.</li>
<li>Datos fiscales del receptor y uso del CFDI.</li>
<li>Forma y método de pago coherentes con el cobro real.</li>
<li>Saldo de timbres y vigencia del CSD.</li>
</ul>',
    60, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'billing'
      AND title = 'Cómo crear una factura desde cero'
);

-- ----------------------------------------------------------------------------
-- 7. How to invoice a point-of-sale transaction
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'billing',
    'Cómo facturar una venta del punto de venta',
    'Aprovecha los datos de una venta ya registrada para prellenar cliente, conceptos y totales, y timbra sin capturar todo de nuevo.',
    '<p>Si la venta ya existe en el punto de venta, no captures los conceptos de nuevo: el sistema puede prellenar la factura con el cliente, los productos, las cantidades y los totales de la venta.</p>
<h3>Desde el punto de venta</h3>
<ol>
<li>Abre la venta y elige "Facturar venta".</li>
<li>El formulario de "Nueva factura" se abre con la venta preseleccionada.</li>
<li>Revisa el receptor y los conceptos precargados; puedes editarlos.</li>
<li>Selecciona la forma y el método de pago y pulsa "Timbrar ahora".</li>
</ol>
<h3>Desde el formulario de factura</h3>
<ol>
<li>En la sección "Venta relacionada" usa el buscador "Buscar venta (Opcional)".</li>
<li>Escribe el folio o el nombre del cliente y elige la venta de la lista.</li>
<li>El sistema llena el receptor, los conceptos y los totales.</li>
<li>Pulsa "Guardar como prefactura" si quieres revisarla después o "Timbrar ahora" para certificarla.</li>
</ol>
<h3>Qué ventas aparecen</h3>
<ul>
<li>Ventas completadas, entregadas por pagar o a crédito que todavía no tienen factura.</li>
<li>Las ventas a crédito se facturan como PPD con forma de pago 99 - Por definir.</li>
<li>Las ventas ya facturadas no aparecen en el buscador.</li>
</ul>
<h3>Precios con IVA incluido</h3>
<p>Si tus precios de venta ya incluyen IVA, activa "Los precios ya incluyen IVA" en la sección Conceptos. Así el total de la factura coincide con el total cobrado en la venta.</p>
<h3>Avisos que pueden aparecer</h3>
<ul>
<li>Venta a crédito sin liquidar: se selecciona PPD y forma de pago 99 automáticamente; puedes cambiarlos si el cliente ya pagó.</li>
<li>Varios métodos de pago: el SAT solo permite uno en la factura, así que se elige el de mayor monto. Puedes ajustarlo en "Forma y método de pago".</li>
<li>Diferencia de centavos: el cálculo de IVA por producto puede variar el total unos centavos. Revisa los conceptos antes de timbrar.</li>
</ul>
<h3>Desvincular la venta</h3>
<p>Con el botón de la x en la tarjeta de la venta seleccionada puedes desvincularla y capturar conceptos manualmente. La venta volverá a estar disponible para facturar.</p>',
    70, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'billing'
      AND title = 'Cómo facturar una venta del punto de venta'
);

-- ----------------------------------------------------------------------------
-- 8. How to stamp a draft invoice and the 72-hour rule
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'billing',
    'Cómo timbrar una prefactura y qué hacer después de 72 horas',
    'Convierte una prefactura en CFDI certificado, resuelve la fecha de emisión vencida y reintenta el timbrado si falla.',
    '<p>Una prefactura es un borrador sin validez fiscal. Al timbrarla, el SAT le asigna un UUID y la convierte en un CFDI certificado que ya no se puede editar.</p>
<h3>Cómo timbrar</h3>
<ol>
<li>Abre la prefactura desde "Lista de facturas".</li>
<li>Revisa emisor, receptor, conceptos y totales.</li>
<li>Pulsa "Timbrar factura" en el detalle. También puedes usar el menú "Opciones" de la lista.</li>
<li>Espera la respuesta del SAT. Mientras se procesa, la factura queda en estado Pendiente.</li>
</ol>
<h3>La regla de las 72 horas</h3>
<p>El SAT no permite timbrar con una fecha de emisión de más de 72 horas ni con fechas futuras. Si la prefactura supera ese plazo, aparece la ventana "Fecha de emisión vencida" con este aviso: "Han pasado más de 72 horas desde la fecha de emisión de esta prefactura. El SAT ya no permite timbrar un comprobante con esa fecha".</p>
<h3>Opciones cuando la fecha está vencida</h3>
<ul>
<li>"Timbrar con fecha de hoy": el CFDI se emite con la fecha y hora actuales. Los datos del comprobante no cambian.</li>
<li>"Editar fecha": regresa a la prefactura para ajustar la fecha de emisión a una válida, dentro de las últimas 72 horas.</li>
</ul>
<h3>Antes de timbrar revisa</h3>
<ul>
<li>Que el emisor esté activo y con CSD vigente.</li>
<li>Que tengas timbres disponibles.</li>
<li>Que el régimen fiscal y el código postal del receptor sean correctos.</li>
<li>Que la forma y el método de pago correspondan al cobro real.</li>
</ul>
<h3>Si el timbrado falla</h3>
<ul>
<li>La factura queda en estado Pendiente con el mensaje del proveedor de timbrado.</li>
<li>Corrige el dato señalado y usa "Reintentar timbrado" desde el detalle o el menú "Opciones".</li>
<li>Si el error persiste, revisa la vigencia del CSD, los datos del emisor y el saldo de timbres.</li>
<li>En estado En verificación el SAT todavía confirma el resultado; espera y vuelve a consultar antes de reintentar.</li>
</ul>
<h3>Después de timbrar</h3>
<ul>
<li>La factura pasa a Timbrada y muestra su UUID (folio fiscal).</li>
<li>Puedes descargar el XML y el PDF desde el detalle.</li>
<li>Ya no se puede editar: si hay un error, emite una nota de crédito o solicita la cancelación.</li>
</ul>',
    80, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'billing'
      AND title = 'Cómo timbrar una prefactura y qué hacer después de 72 horas'
);

-- ----------------------------------------------------------------------------
-- 9. How to create a credit note (CFDI de egreso)
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'billing',
    'Cómo crear una nota de crédito (CFDI de egreso)',
    'Documenta devoluciones o descuentos posteriores relacionando la factura original por UUID, sin cancelarla.',
    '<p>Una nota de crédito es un CFDI de egreso que documenta una devolución, un descuento posterior o una corrección sobre una factura ya timbrada. No sustituye a la cancelación: son operaciones distintas.</p>
<h3>Cuándo usarla</h3>
<ul>
<li>Devolviste total o parcialmente el importe de una venta facturada.</li>
<li>Otorgaste un descuento o bonificación después de timbrar.</li>
<li>Corriges el importe de una factura sin cancelarla.</li>
</ul>
<h3>Crear la nota de crédito</h3>
<ol>
<li>En "Lista de facturas" pulsa "Emitir factura".</li>
<li>Selecciona el tipo de comprobante Egreso.</li>
<li>Elige el mismo emisor y captura el mismo receptor de la factura original.</li>
<li>Captura la forma y el método de pago. El uso del CFDI se propone como G02 - Devoluciones, descuentos o bonificaciones y el método como PUE.</li>
<li>En "CFDI relacionados" agrega el UUID de la factura original. Puedes buscarlo por folio, UUID o cliente.</li>
<li>Agrega los conceptos con el importe que vas a acreditar.</li>
<li>Revisa los totales y pulsa "Timbrar ahora".</li>
</ol>
<h3>Sobre los CFDI relacionados</h3>
<ul>
<li>Debes relacionar al menos un UUID; el SAT rechaza una nota de crédito sin relación.</li>
<li>El tipo de relación se propone como 01 - Nota de crédito de los documentos relacionados.</li>
<li>El buscador solo muestra facturas timbradas del mismo emisor y cliente; si no encuentras una, escribe el UUID manualmente.</li>
<li>El campo acepta solo números y letras de la A a la F y agrega los guiones automáticamente.</li>
</ul>
<h3>Después de timbrar</h3>
<ul>
<li>La nota de crédito es un CFDI independiente con su propio UUID.</li>
<li>La factura original sigue vigente y conserva su importe; la nota documenta el ajuste.</li>
<li>Si el objetivo es dejar sin efectos la factura original, solicita su cancelación.</li>
<li>Entrega el XML y el PDF al cliente para que refleje el ajuste en su contabilidad.</li>
</ul>
<h3>Recomendaciones</h3>
<ul>
<li>Usa la misma combinación de RFC, régimen fiscal y código postal del receptor.</li>
<li>No acredites un importe mayor al de la factura original.</li>
<li>Describe el motivo en los conceptos para facilitar la conciliación contable.</li>
</ul>',
    90, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'billing'
      AND title = 'Cómo crear una nota de crédito (CFDI de egreso)'
);

-- ----------------------------------------------------------------------------
-- 10. How to issue a payment complement for PPD invoices
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'billing',
    'Cómo emitir un complemento de pago para facturas PPD',
    'Registra el pago de una factura PPD con su parcialidad, saldo anterior y saldo insoluto, y liquídala ante el SAT.',
    '<p>Cuando facturas con método PPD, el pago se documenta con un CFDI de Pago (complemento de pago 2.0). Este comprobante relaciona la factura PPD con el importe recibido y actualiza el saldo pendiente ante el SAT.</p>
<h3>Crear el complemento desde la factura PPD</h3>
<ol>
<li>Abre la factura timbrada con método PPD.</li>
<li>En el menú "Opciones" elige "Facturar pago".</li>
<li>El formulario se abre con tipo de comprobante Pago y la factura preseleccionada.</li>
<li>Revisa el receptor, que se llena con los datos fiscales de la factura.</li>
<li>Pulsa "Timbrar ahora".</li>
</ol>
<h3>Crear el complemento desde cero</h3>
<ol>
<li>Pulsa "Emitir factura" y elige el tipo de comprobante Pago.</li>
<li>Selecciona el emisor y busca la factura en "Buscar venta a crédito (factura PPD)".</li>
<li>Al elegirla se llenan el receptor y el primer documento relacionado.</li>
</ol>
<h3>Detalle del pago</h3>
<ul>
<li>"Fecha y hora de recepción del pago": cuándo recibiste el dinero.</li>
<li>"Forma de pago real": cómo te pagaron, por ejemplo transferencia o efectivo.</li>
<li>"Moneda del pago" y "Monto total del pago". Si la moneda no es MXN, captura el "Tipo de cambio del pago".</li>
<li>El concepto se genera automáticamente como Pago con clave 84111506 y objeto de impuesto 01.</li>
</ul>
<h3>Documentos relacionados</h3>
<p>Cada documento representa una factura PPD y su parcialidad. Captura o revisa:</p>
<ul>
<li>"Folio de Factura timbrada PPD" y "UUID de la factura".</li>
<li>"Núm. parcialidad": 1 para el primer pago, 2 para el segundo y así sucesivamente.</li>
<li>"Saldo anterior": el saldo insoluto antes de este pago.</li>
<li>"Importe pagado" y "Saldo insoluto": lo que resta después del pago.</li>
</ul>
<p>Puedes agregar varios documentos con "Agregar" si un solo pago cubre varias facturas. Si el saldo insoluto queda en cero, el sistema muestra "Con este pago la factura quedará liquidada ante el SAT".</p>
<h3>Reglas que debes respetar</h3>
<ul>
<li>Solo se pueden relacionar facturas timbradas con método PPD del mismo emisor y cliente.</li>
<li>Una factura cancelada no puede recibir complementos de pago.</li>
<li>El importe pagado no debe superar el saldo anterior.</li>
<li>El monto total del pago debe coincidir con la suma de los importes pagados de los documentos.</li>
<li>Cada complemento de pago consume un timbre.</li>
</ul>
<h3>Errores comunes</h3>
<ul>
<li>UUID incorrecto: la factura no se localiza o el SAT rechaza la relación.</li>
<li>Parcialidad repetida: usa el número consecutivo que corresponda.</li>
<li>Saldo anterior distinto al insoluto del pago previo: revisa el historial de complementos de la factura.</li>
</ul>',
    100, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'billing'
      AND title = 'Cómo emitir un complemento de pago para facturas PPD'
);

-- ----------------------------------------------------------------------------
-- 11. How to cancel an invoice and answer cancellation requests
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'billing',
    'Cómo cancelar una factura y responder solicitudes de cancelación',
    'Elige el motivo correcto, da seguimiento a la aceptación del receptor y responde las solicitudes que te envíen.',
    '<p>Una factura timbrada no se elimina: se cancela ante el SAT. La cancelación puede ser inmediata o requerir la aceptación del receptor, según el motivo y la antigüedad del comprobante.</p>
<h3>Solicitar la cancelación</h3>
<ol>
<li>Abre la factura timbrada desde "Lista de facturas".</li>
<li>En el menú "Opciones" elige "Solicitar cancelación".</li>
<li>Selecciona el "Motivo de cancelación" del catálogo del SAT.</li>
<li>Si el motivo es 01, captura el "UUID de sustitución" de la factura que reemplaza a la cancelada.</li>
<li>Pulsa "Confirmar cancelación".</li>
</ol>
<h3>Motivos de cancelación</h3>
<ul>
<li>01 - Comprobante emitido con errores con relación: requiere el UUID del comprobante que lo sustituye.</li>
<li>02 - Comprobante emitido con errores sin relación.</li>
<li>03 - No se llevó a cabo la operación.</li>
<li>04 - Operación nominativa relacionada en factura global.</li>
</ul>
<h3>Más de 72 horas desde el timbrado</h3>
<p>Si la factura se timbró hace más de 72 horas, la ventana advierte: "Esta factura tiene más de 72 horas desde que se timbró. Es probable que el SAT requiera la aprobación de tu cliente para poder cancelarla. La cancelación no será inmediata en ese caso".</p>
<h3>Estado Cancelación pendiente</h3>
<ul>
<li>La solicitud quedó enviada y el receptor debe aceptarla o rechazarla ante el SAT.</li>
<li>Mientras tanto la factura sigue vigente; no la consideres cancelada.</li>
<li>El detalle muestra el plazo de aceptación y la fecha de vencimiento.</li>
<li>Usa "Verificar estatus" para consultar la respuesta; también se actualiza al abrir la factura.</li>
<li>Si el plazo vence sin respuesta, puedes reintentar la cancelación.</li>
</ul>
<h3>Responder solicitudes de cancelación</h3>
<ol>
<li>En "Lista de facturas" abre el menú "Opciones" y elige "Responder cancelación".</li>
<li>Selecciona el RFC receptor que recibió la solicitud.</li>
<li>Captura el UUID de la factura que te piden cancelar.</li>
<li>Indica si aceptas o rechazas y pulsa "Enviar respuesta (aceptar)" o "Enviar respuesta (rechazar)".</li>
<li>Consulta la pestaña "Historial" para revisar las respuestas anteriores.</li>
</ol>
<h3>Timbre y cancelación</h3>
<p>Cancelar no modifica tu saldo de timbres: el timbre se cobra al timbrar, no al cancelar. Tampoco elimina la factura del historial; queda registrada como Cancelada con su motivo.</p>',
    110, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'billing'
      AND title = 'Cómo cancelar una factura y responder solicitudes de cancelación'
);

-- ----------------------------------------------------------------------------
-- 12. How to review invoices, download PDF/XML and change the logo
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'billing',
    'Cómo consultar facturas, descargar PDF y XML, y cambiar el logotipo',
    'Filtra por estado, emisor o método de pago, interpreta cada estado, descarga los archivos fiscales y personaliza el PDF.',
    '<p>La "Lista de facturas" reúne todos los comprobantes de la cuenta y te permite consultar su estado, descargar archivos y dar seguimiento a cancelaciones.</p>
<h3>Buscar y filtrar</h3>
<ul>
<li>Usa el buscador por folio, cliente, razón social, RFC o UUID.</li>
<li>Filtra por estado: Pre-factura, Pendiente, Timbrada, En verificación, Cancelación pendiente o Cancelada.</li>
<li>Filtra por método de pago: PUE o PPD.</li>
<li>Filtra por emisor fiscal cuando tengas más de un RFC.</li>
</ul>
<h3>Columnas de la tabla</h3>
<ul>
<li>Folio, tipo de comprobante, razón social o cliente, RFC y emisor.</li>
<li>UUID (folio fiscal), total y estado.</li>
<li>Acciones rápidas para ver el PDF y descargar el XML.</li>
</ul>
<h3>Qué significa cada estado</h3>
<ul>
<li>Pre-factura: borrador editable, sin validez fiscal.</li>
<li>Pendiente: el timbrado está en curso o se puede reintentar.</li>
<li>Timbrada: CFDI certificado con UUID.</li>
<li>En verificación: el SAT aún confirma el resultado.</li>
<li>Cancelación pendiente: solicitud enviada al receptor.</li>
<li>Cancelada: comprobante sin efectos.</li>
</ul>
<h3>Descargar PDF y XML</h3>
<ol>
<li>En la lista usa los iconos de la columna de acciones, o abre el detalle de la factura.</li>
<li>Pulsa "XML" para descargar el archivo fiscal que entrega el SAT a tu contador.</li>
<li>Pulsa "PDF" para abrir la representación impresa con el logo del emisor.</li>
</ol>
<p>El XML es el documento fiscal válido. El PDF es una representación impresa para enviar al cliente.</p>
<h3>Detalle de la factura</h3>
<p>En el detalle encontrarás los datos del emisor y del receptor, los conceptos, el desglose de impuestos y retenciones, el UUID, la fecha de timbrado y el historial de cancelación. Desde ahí también se timbra una prefactura, se factura un pago o se solicita la cancelación.</p>
<h3>Resumen de facturación</h3>
<p>El resumen muestra las prefacturas, las facturas timbradas, las cancelaciones pendientes y las canceladas. Puedes filtrar por periodo, aplicar un rango personalizado y pulsar "Exportar" para descargar la información del periodo.</p>
<h3>Cambiar el logotipo del emisor</h3>
<ol>
<li>Entra a "Configuración fiscal" y abre el menú de acciones del emisor.</li>
<li>Elige "Agregar logotipo" o "Cambiar logotipo".</li>
<li>Selecciona una imagen JPG, PNG o WebP de máximo 2 MB.</li>
<li>Revisa la vista previa y pulsa "Guardar logotipo".</li>
</ol>
<p>El logotipo aparece en los PDF que generes. Para quitarlo, usa el botón de eliminar dentro de la misma ventana y confirma la acción.</p>',
    120, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'billing'
      AND title = 'Cómo consultar facturas, descargar PDF y XML, y cambiar el logotipo'
);

-- ============================================================================
-- End of billing help center articles.
-- ============================================================================
