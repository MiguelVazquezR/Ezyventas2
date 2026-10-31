-- ============================================================================
-- Help center - Guides for the Gastos (expenses) module
--
-- Inserts 10 published guides for the "expenses" module of the
-- help_center_articles table. The guides automatically appear in the
-- Gastos card of the help center.
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
-- 1. How to register an expense step by step
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'expenses',
    'Cómo registrar un gasto paso a paso',
    'Captura concepto, fecha, monto, categoría, método de pago y origen del dinero, y guarda el gasto para que se refleje en la caja, el banco y el reporte financiero.',
    '<p>El módulo de Gastos concentra todas las salidas de dinero del negocio. Registrar cada gasto con su origen correcto mantiene actualizados el corte de caja, el saldo de las cuentas bancarias y el reporte financiero.</p>
<h3>Antes de empezar</h3>
<ul>
<li>Necesitas permiso para crear gastos. Si no ves el botón "Nuevo gasto", pide a un administrador que lo habilite.</li>
<li>Ten a mano el comprobante y define de dónde salió el dinero: de la caja del negocio, de una cuenta bancaria del negocio o de tu propio bolsillo.</li>
<li>Si vas a pagar en efectivo desde la caja, debe existir una sesión de caja abierta. Si no la hay, el sistema te pedirá iniciarla o unirte a una disponible.</li>
</ul>
<h3>Captura los datos del gasto</h3>
<ol>
<li>Entra a Gastos desde el menú. Se abre el "Historial de gastos".</li>
<li>Pulsa "Nuevo gasto".</li>
<li>En "Concepto" escribe una referencia corta, por ejemplo "Renta de octubre" o "Compra de insumos". Es opcional, pero te ayuda a encontrar el gasto después.</li>
<li>Revisa la "Fecha del gasto". Se precarga el día de hoy; ajústala si el comprobante corresponde a otra fecha, porque esa fecha define el periodo del reporte financiero.</li>
<li>Captura el "Monto" en pesos. Debe ser mayor que cero.</li>
<li>Selecciona la "Categoría". Es obligatoria. Si no existe la que necesitas, pulsa "Gestionar" para crearla sin salir del formulario.</li>
<li>Opcionalmente escribe una "Descripción" con el motivo del gasto y el número de factura o ticket.</li>
</ol>
<h3>Elige el método de pago y el origen del dinero</h3>
<p>El método indica cómo se pagó y el origen indica de quién era el dinero. Ambos datos son obligatorios.</p>
<ul>
<li><strong>Efectivo:</strong> elige "De la caja activa del día" si el dinero salió de la caja del negocio, o "De mi propio dinero / Efectivo externo" si pagaste con tu efectivo personal.</li>
<li><strong>Tarjeta o transferencia:</strong> elige "Cuenta / Tarjeta del negocio" si el cargo salió de una cuenta de la empresa y selecciona la "Cuenta de origen". Elige "Mi propia cuenta / Tarjeta externa" si usaste una cuenta personal.</li>
</ul>
<p>El campo "Estatus" aparece como "Pagado" y está deshabilitado: todo gasto nuevo se registra como pagado. Para marcar un gasto como pendiente, usa la acción del detalle que se explica en la guía "Qué significa pagado y pendiente en un gasto".</p>
<h3>Guarda y verifica</h3>
<ol>
<li>Pulsa "Guardar gasto" en la parte inferior del formulario.</li>
<li>El sistema te devuelve al historial con un mensaje de confirmación.</li>
<li>Busca el gasto por su concepto, abre el detalle y revisa monto, categoría, fecha, método, origen, cuenta y estatus.</li>
</ol>
<h3>Recomendaciones</h3>
<ul>
<li>Registra el gasto el mismo día en que ocurrió. Así el corte de caja y el reporte del periodo quedan completos.</li>
<li>Usa el concepto como folio interno y conserva el comprobante físico o digital con la misma referencia.</li>
<li>No registres dos veces el mismo gasto. Antes de volver a capturarlo, búscalo en el historial.</li>
<li>Si otra persona pagó con su dinero, usa las opciones externas y anota en la descripción quién pagó.</li>
</ul>',
    10, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'expenses'
      AND title = 'Cómo registrar un gasto paso a paso'
);

-- ----------------------------------------------------------------------------
-- 2. How to choose the money origin of an expense
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'expenses',
    'Cómo elegir el origen del dinero de un gasto',
    'Distingue entre caja del negocio, cuenta del negocio y dinero externo para que cada gasto afecte los saldos correctos y el reporte financiero no se descuadre.',
    '<p>El origen del dinero es la decisión más importante al registrar un gasto. De él dependen el movimiento de caja, el saldo bancario y la forma en que el reporte financiero interpreta el gasto.</p>
<h3>Las tres opciones disponibles</h3>
<ul>
<li><strong>Caja del negocio:</strong> disponible cuando el método es efectivo y existe una sesión de caja abierta. El gasto se registra como una salida de efectivo de esa sesión.</li>
<li><strong>Cuenta del negocio:</strong> disponible cuando el método es tarjeta o transferencia. El gasto se descuenta del saldo de la cuenta bancaria que selecciones.</li>
<li><strong>Dinero externo:</strong> disponible tanto para efectivo como para tarjeta o transferencia. El gasto queda registrado como personal y no mueve la caja ni las cuentas del negocio.</li>
</ul>
<h3>Cómo decidir en tres preguntas</h3>
<ol>
<li><strong>¿De quién era el dinero?</strong> Si era del negocio, usa una opción del negocio. Si era tuyo o de otra persona, usa una opción externa.</li>
<li><strong>¿Con qué se pagó?</strong> Efectivo para la caja; tarjeta o transferencia para la cuenta bancaria.</li>
<li><strong>¿Debe afectar los saldos del negocio?</strong> Si el dinero salió de una cuenta o de la caja del negocio, sí. Si solo quieres dejar constancia de un gasto personal, no.</li>
</ol>
<h3>Qué mueve cada opción</h3>
<ul>
<li><strong>Caja del negocio:</strong> crea un movimiento de salida en la sesión de caja activa. Reduce el efectivo esperado del corte y afecta las métricas de efectivo.</li>
<li><strong>Cuenta del negocio:</strong> retira el monto del saldo de la cuenta seleccionada. Afecta las métricas bancarias y el flujo del negocio.</li>
<li><strong>Dinero externo:</strong> no mueve caja ni banco. El reporte financiero lo muestra en el bloque de gastos externos, separado de los gastos del negocio.</li>
</ul>
<h3>Errores frecuentes</h3>
<ul>
<li>Registrar con dinero externo un gasto que salió de la caja. El corte queda con más efectivo del real porque la salida nunca se registró.</li>
<li>Registrar en una cuenta del negocio un gasto pagado con tarjeta personal. El saldo bancario de la empresa queda disminuido sin motivo.</li>
<li>Elegir efectivo para una transferencia. El gasto aparece en la caja y el banco no se ajusta.</li>
<li>Cambiar el origen después de que el gasto ya forma parte de un corte de caja revisado, sin coordinarlo con el administrador.</li>
</ul>
<h3>Regla práctica</h3>
<p>Si el dinero salió de una cuenta o de la caja del negocio, usa una opción del negocio. Si salió de tu bolsillo y no se va a reembolsar desde la empresa, usa una opción externa. Si dudas, revisa el comprobante: el método de pago y la cuenta aparecen ahí y confirman el origen.</p>',
    20, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'expenses'
      AND title = 'Cómo elegir el origen del dinero de un gasto'
);

-- ----------------------------------------------------------------------------
-- 3. How to register an expense paid from the cash register
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'expenses',
    'Cómo registrar un gasto pagado desde la caja',
    'Registra la salida de efectivo en la sesión de caja activa, entiende cómo afecta el corte y distingue cuándo el efectivo es del negocio y cuándo es personal.',
    '<p>Cuando pagas un gasto con efectivo de la caja, el sistema debe registrar la salida en la sesión de caja para que el corte refleje el efectivo real. Un gasto de caja mal registrado produce diferencias en el arqueo.</p>
<h3>Requisito: una sesión de caja abierta</h3>
<p>El gasto se descuenta de la sesión de caja activa de tu sucursal. Si no hay una sesión abierta, al guardar el gasto el sistema muestra la ventana para iniciar una sesión o unirte a una sesión disponible. El gasto se crea cuando la sesión queda abierta.</p>
<h3>Registra la salida</h3>
<ol>
<li>Entra a Gastos y pulsa "Nuevo gasto".</li>
<li>Captura concepto, fecha, monto, categoría y descripción.</li>
<li>En "Método de pago" selecciona "Efectivo".</li>
<li>En "Origen del dinero" selecciona "De la caja activa del día".</li>
<li>Pulsa "Guardar gasto". Si no hay sesión abierta, completa la ventana de inicio o de unión a la sesión.</li>
<li>Abre el detalle del gasto y confirma que el origen aparece como "Caja del negocio".</li>
</ol>
<h3>Qué pasa en la caja</h3>
<ul>
<li>Se crea un movimiento de salida en la sesión con la referencia del gasto.</li>
<li>El efectivo esperado del corte disminuye por el monto del gasto.</li>
<li>El movimiento queda en la sesión que estaba abierta al guardar, no en la fecha del gasto. Si registras hoy un gasto de la semana pasada, se aplicará a la sesión actual.</li>
</ul>
<h3>Si el efectivo era tuyo</h3>
<p>Selecciona "De mi propio dinero / Efectivo externo" en lugar de la caja del negocio. Así el gasto queda registrado en el historial y en el reporte, pero no descuenta efectivo de la caja ni del corte.</p>
<h3>Recomendaciones</h3>
<ul>
<li>Confirma que la sesión abierta corresponde a tu sucursal y turno antes de guardar.</li>
<li>Registra el gasto cuando ocurre. Si lo registras después del cierre, la salida afectará la siguiente sesión abierta.</li>
<li>Si el gasto pertenece a un corte ya cerrado, no lo edites ni lo elimines sin revisar el impacto con el administrador.</li>
<li>No cambies el origen a externo para "cuadrar" el corte. Si el efectivo salió de la caja, el movimiento debe existir.</li>
</ul>',
    30, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'expenses'
      AND title = 'Cómo registrar un gasto pagado desde la caja'
);

-- ----------------------------------------------------------------------------
-- 4. How to register an expense paid by card or bank transfer
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'expenses',
    'Cómo registrar un gasto con tarjeta o transferencia',
    'Selecciona la cuenta correcta, entiende cómo se descuenta el saldo bancario y evita mezclar cuentas del negocio con cuentas personales.',
    '<p>Los gastos pagados con tarjeta o transferencia se descuentan de una cuenta bancaria. Por eso el sistema te pide indicar el origen del dinero y, cuando es una cuenta del negocio, la cuenta exacta de la que salió el pago.</p>
<h3>Registra el gasto</h3>
<ol>
<li>Entra a Gastos y pulsa "Nuevo gasto".</li>
<li>Captura concepto, fecha, monto, categoría y descripción.</li>
<li>En "Método de pago" selecciona "Tarjeta" o "Transferencia".</li>
<li>En "Origen del dinero" elige "Cuenta / Tarjeta del negocio" si el cargo salió de una cuenta de la empresa.</li>
<li>Selecciona la "Cuenta de origen". El sistema preselecciona la cuenta favorita de tu sucursal cuando existe.</li>
<li>Pulsa "Guardar gasto" y revisa el detalle para confirmar la cuenta y el origen.</li>
</ol>
<h3>Cuenta del negocio</h3>
<p>El monto se retira del saldo de la cuenta seleccionada al guardar. El gasto cuenta como interno, afecta las métricas bancarias y entra en el flujo del negocio del reporte financiero.</p>
<h3>Cuenta personal</h3>
<p>Si pagaste con tu propia tarjeta o transferiste desde tu cuenta, elige "Mi propia cuenta / Tarjeta externa". El gasto queda registrado como externo: no mueve el saldo de las cuentas del negocio y se muestra por separado en el reporte financiero.</p>
<h3>Qué cuentas aparecen en la lista</h3>
<p>La lista incluye las cuentas bancarias disponibles para tu usuario y tu sucursal. Si no encuentras la cuenta correcta, pide a un administrador que revise la asignación de cuentas o la configuración de sucursales.</p>
<h3>Errores frecuentes</h3>
<ul>
<li>Elegir una cuenta del negocio para un gasto pagado con tarjeta personal: el saldo de la empresa queda disminuido sin motivo.</li>
<li>Elegir una cuenta externa para un gasto de la empresa: el gasto no se refleja en el saldo bancario.</li>
<li>Registrar una transferencia como efectivo: el gasto se descuenta de la caja y el banco no se ajusta.</li>
<li>Cambiar de cuenta después de conciliar el estado de cuenta, sin revisar el movimiento anterior.</li>
</ul>
<h3>Recomendaciones</h3>
<ul>
<li>Ten a la mano el estado de cuenta o el comprobante para identificar la cuenta y la fecha exacta del cargo.</li>
<li>Usa la misma cuenta que aparece en el estado de cuenta; así la conciliación bancaria es directa.</li>
<li>Si el cargo fue en dólares o en otra moneda, registra el monto equivalente en pesos y anota la referencia en la descripción.</li>
<li>Revisa el saldo de la cuenta después de guardar si el gasto es de un monto alto.</li>
</ul>',
    40, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'expenses'
      AND title = 'Cómo registrar un gasto con tarjeta o transferencia'
);

-- ----------------------------------------------------------------------------
-- 5. How to separate business expenses from personal expenses
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'expenses',
    'Cómo separar los gastos del negocio de los gastos personales',
    'Usa el origen externo para registrar gastos pagados con dinero propio sin afectar la caja ni los saldos bancarios, y mantén limpio el flujo del negocio.',
    '<p>Mezclar gastos personales con gastos del negocio distorsiona la rentabilidad y complica la conciliación. El sistema permite registrar gastos externos para dejar constancia de ellos sin mover la caja ni las cuentas de la empresa.</p>
<h3>Qué es un gasto externo</h3>
<p>Es un gasto pagado con dinero que no pertenece al negocio: efectivo personal, tarjeta personal o una transferencia desde una cuenta propia. Se guarda en el historial con la etiqueta "Dinero externo" y no genera movimientos en la caja ni en las cuentas bancarias.</p>
<h3>Qué afecta y qué no</h3>
<ul>
<li>No descuenta efectivo de la sesión de caja ni modifica el corte.</li>
<li>No reduce el saldo de las cuentas bancarias del negocio.</li>
<li>Se muestra en el reporte financiero en el bloque de gastos externos, separado de los gastos internos y del flujo del negocio.</li>
<li>Sí requiere fecha, monto y categoría, y aparece en el historial como cualquier otro gasto.</li>
</ul>
<h3>Cuándo usarlo</h3>
<ul>
<li>Compras personales que hiciste durante la jornada y no tienen relación con la operación.</li>
<li>Gastos que pagaste de tu bolsillo y que la empresa no te va a reembolsar.</li>
<li>Gastos de un socio o colaborador que no salen de las cuentas del negocio.</li>
<li>Compras de prueba o anticipos personales que después se aclararán por otra vía.</li>
</ul>
<h3>Cómo registrarlo</h3>
<ol>
<li>Entra a Gastos y pulsa "Nuevo gasto".</li>
<li>Captura concepto, fecha, monto y categoría.</li>
<li>Si pagaste en efectivo, elige "Efectivo" y luego "De mi propio dinero / Efectivo externo".</li>
<li>Si pagaste con tarjeta o transferencia, elige ese método y luego "Mi propia cuenta / Tarjeta externa".</li>
<li>En la descripción anota quién pagó y el motivo, por ejemplo "Compra personal de Papelería".</li>
<li>Guarda el gasto y confirma en el detalle que el origen sea "Dinero propio / Externo".</li>
</ol>
<h3>Qué evitar</h3>
<ul>
<li>Registrar como externo un gasto que en realidad salió de la caja. El corte quedará con un faltante.</li>
<li>Registrar como externo un gasto del negocio para que no aparezca en los indicadores. La rentabilidad quedaría inflada.</li>
<li>Usar el origen externo como cajón de sastre para gastos sin comprobante.</li>
</ul>
<h3>Si la empresa te va a reembolsar</h3>
<p>Registra el gasto original como externo y conserva el comprobante. Cuando la empresa te pague, registra ese reembolso como un gasto interno con una categoría y descripción claras. Consulta a tu contador para definir el tratamiento fiscal correcto.</p>',
    50, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'expenses'
      AND title = 'Cómo separar los gastos del negocio de los gastos personales'
);

-- ----------------------------------------------------------------------------
-- 6. How to create and organize expense categories
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'expenses',
    'Cómo crear y organizar tus categorías de gastos',
    'Crea, edita y elimina categorías sin salir del formulario de gastos, migra los gastos asociados y adopta un catálogo que facilite tus reportes.',
    '<p>Las categorías agrupan los gastos por tipo y alimentan el desglose del reporte financiero. Las categorías son compartidas por toda la suscripción, así que lo que crees en una sucursal estará disponible en las demás.</p>
<h3>Abre la gestión de categorías</h3>
<ol>
<li>Entra a Gastos y pulsa "Nuevo gasto", o abre un gasto existente y pulsa "Editar gasto".</li>
<li>En el campo "Categoría" pulsa "Gestionar".</li>
<li>Se abre la ventana "Categorías de Gastos" con la lista completa.</li>
</ol>
<p>La gestión requiere el permiso correspondiente. Si el botón no aparece, pide a un administrador que lo habilite.</p>
<h3>Crea una categoría</h3>
<ol>
<li>En "Nueva categoría" escribe el nombre. Es obligatorio.</li>
<li>Agrega una descripción corta si necesitas aclarar su uso. Es opcional.</li>
<li>Pulsa el botón con el signo más.</li>
<li>La categoría aparece de inmediato en la lista y queda disponible para seleccionarla en el formulario.</li>
</ol>
<h3>Edita una categoría</h3>
<ol>
<li>Pulsa el lápiz en la fila de la categoría.</li>
<li>Ajusta el nombre o la descripción.</li>
<li>Confirma el cambio y cierra la edición.</li>
</ol>
<p>El nuevo nombre se aplica a todos los gastos que usan esa categoría, incluidos los del historial. Úsalo para corregir errores de escritura, no para cambiar el significado de la categoría.</p>
<h3>Elimina una categoría con gastos</h3>
<ol>
<li>Pulsa el icono de eliminar en la fila de la categoría.</li>
<li>Si la categoría tiene gastos, aparece la ventana "Gastos asociados detectados".</li>
<li>En "Mover gastos a:" selecciona la categoría destino.</li>
<li>Pulsa "Mover y Eliminar". Los gastos se reasignan y la categoría se elimina.</li>
</ol>
<p>Si la categoría no tiene gastos, solo se pide confirmación. La eliminación no se puede deshacer.</p>
<h3>Catálogo base sugerido</h3>
<ul>
<li>Renta y mantenimiento</li>
<li>Servicios (luz, agua, internet, teléfono)</li>
<li>Nómina y prestaciones</li>
<li>Insumos y mercancía</li>
<li>Marketing y publicidad</li>
<li>Impuestos y trámites</li>
<li>Transporte y logística</li>
<li>Otros gastos</li>
</ul>
<h3>Recomendaciones</h3>
<ul>
<li>Usa nombres cortos y distintos entre sí para evitar confusiones al capturar.</li>
<li>No crees una categoría por proveedor. Para eso usa el concepto o la descripción del gasto.</li>
<li>Antes de eliminar una categoría, revisa cuántos gastos la usan y elige una categoría destino coherente.</li>
<li>Revisa el catálogo cada cierre de mes y ajusta solo lo necesario para no romper la comparación histórica.</li>
</ul>',
    60, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'expenses'
      AND title = 'Cómo crear y organizar tus categorías de gastos'
);

-- ----------------------------------------------------------------------------
-- 7. What paid and pending mean in an expense
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'expenses',
    'Qué significa pagado y pendiente en un gasto',
    'Distingue un gasto ya pagado de una obligación pendiente, cambia el estatus desde el detalle y evita descuadres en la caja y en el reporte.',
    '<p>Cada gasto tiene un estatus que indica si el dinero ya salió o si solo se registró la obligación de pago. El estatus cambia la forma en que el gasto afecta los saldos y el reporte financiero, así que conviene revisarlo antes de modificarlo.</p>
<h3>Dónde se ve el estatus</h3>
<p>En el "Historial de gastos" aparece la columna "Estatus". En el detalle del gasto se muestra como una etiqueta junto al monto, la categoría y la persona que lo registró. Todo gasto nuevo se crea como "Pagado", porque el formulario de alta registra el pago en el momento.</p>
<h3>Cómo cambiar el estatus</h3>
<ol>
<li>Abre el detalle del gasto desde el historial.</li>
<li>Pulsa "Opciones" en la esquina superior derecha.</li>
<li>Selecciona "Marcar como Pendiente" o "Marcar como Pagado", según el estatus actual.</li>
</ol>
<p>La acción requiere permiso para editar gastos. El selector de estatus del formulario de edición está deshabilitado, así que este menú es la única forma de cambiarlo.</p>
<h3>Qué significa pagado</h3>
<p>El dinero ya salió del negocio. El gasto entra en los totales del reporte financiero y, si es interno y se pagó desde una cuenta bancaria, el saldo de esa cuenta ya está disminuido. Si el pago fue desde la caja, la salida ya está registrada en la sesión.</p>
<h3>Qué significa pendiente</h3>
<p>El gasto está registrado, pero el pago aún no se realiza. Los gastos pendientes no se suman al reporte financiero. Si el gasto era interno y tenía una cuenta bancaria asociada, al marcarlo como pendiente el monto regresa al saldo de esa cuenta.</p>
<h3>Cuidado con los gastos de caja</h3>
<p>El cambio rápido de estatus ajusta el saldo bancario, pero no mueve el efectivo de la caja. Si marcas como pendiente un gasto pagado desde la caja, el movimiento de salida permanece en la sesión y el efectivo esperado del corte no cambia.</p>
<ul>
<li>Para corregir un gasto de caja registrado por error, elimínalo: al borrarlo se elimina el movimiento y el efectivo se restituye en la sesión.</li>
<li>No uses el cambio de estatus para simular una devolución de efectivo.</li>
<li>Registra los gastos de caja cuando el dinero sale, no antes.</li>
</ul>
<h3>Cuándo usar cada estatus</h3>
<ul>
<li><strong>Pendiente:</strong> para obligaciones que ya conoces pero que pagarás después desde una cuenta bancaria, como una factura recibida. Regístralas con método tarjeta o transferencia y cuenta del negocio, y marca el gasto como pendiente.</li>
<li><strong>Pagado:</strong> cuando el dinero ya salió de la caja o de la cuenta. Es el estatus natural de los gastos de efectivo y de los cargos ya aplicados.</li>
<li><strong>Revisión:</strong> si el estatus no corresponde al comprobante, corrige el gasto cuanto antes. Al cierre del mes todos los gastos deben reflejar la realidad del pago.</li>
</ul>',
    70, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'expenses'
      AND title = 'Qué significa pagado y pendiente en un gasto'
);

-- ----------------------------------------------------------------------------
-- 8. How to search and sort the expense history
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'expenses',
    'Cómo buscar y ordenar el historial de gastos',
    'Encuentra un gasto por su concepto o descripción, ordena las columnas, interpreta el origen y el método de pago, y entiende por qué no ves todos los registros.',
    '<p>El "Historial de gastos" muestra los gastos de tu sucursal con búsqueda, ordenamiento, paginación y acciones por fila. Es la pantalla que usarás para revisar, editar o eliminar registros.</p>
<h3>Cómo buscar</h3>
<ol>
<li>Entra a Gastos desde el menú.</li>
<li>Escribe en el buscador una palabra del concepto o de la descripción, por ejemplo "renta" o "insumos".</li>
<li>La tabla se actualiza con los resultados que coinciden.</li>
<li>Borra el texto para volver a la lista completa.</li>
</ol>
<p>La búsqueda se aplica al concepto o folio y a la descripción. No busca por categoría, monto, método de pago ni nombre del usuario.</p>
<h3>Ordena las columnas</h3>
<p>Pulsa el encabezado de "Concepto", "Fecha", "Categoría", "Origen", "Monto", "Método de pago", "Estatus" o "Registrado por" para ordenar de forma ascendente o descendente. Un tercer clic quita el orden y regresa al orden predeterminado: fecha más reciente primero.</p>
<h3>Interpreta cada columna</h3>
<ul>
<li><strong>Concepto:</strong> la referencia o folio que se capturó al crear el gasto. Puede quedar vacío si no lo capturaste.</li>
<li><strong>Fecha:</strong> la fecha del gasto, no la fecha en que se capturó. Es la que usa el reporte financiero.</li>
<li><strong>Categoría:</strong> la categoría asignada, compartida por toda la suscripción.</li>
<li><strong>Descripción:</strong> el detalle capturado. Se muestra recortado; ábrelo completo en el detalle.</li>
<li><strong>Origen:</strong> "Caja del negocio", "Cuenta del negocio" o "Dinero externo". Indica de dónde sale o saldrá el dinero.</li>
<li><strong>Monto:</strong> el importe en pesos.</li>
<li><strong>Método de pago:</strong> efectivo, tarjeta o transferencia. Si el gasto se pagó desde una cuenta bancaria, se muestra la cuenta.</li>
<li><strong>Estatus:</strong> pagado o pendiente.</li>
<li><strong>Registrado por:</strong> el usuario que creó el gasto.</li>
</ul>
<h3>Paginación</h3>
<p>Elige 20, 50, 100 o 200 gastos por página. La barra inferior indica "Mostrando X a Y de Z gastos". Si no encuentras un registro, revisa que no estés en una página anterior o que la búsqueda no esté filtrando la lista.</p>
<h3>Abre el detalle y usa las acciones</h3>
<ul>
<li>Haz clic en la fila para abrir el detalle. La acción requiere permiso para ver detalles; si no lo tienes, la fila no abre.</li>
<li>Pulsa el botón de tres puntos de la fila para "Ver detalle", "Editar gasto" o "Eliminar", según tus permisos.</li>
<li>Marca las casillas de varias filas para eliminar en lote. La barra roja muestra cuántos gastos seleccionaste.</li>
</ul>
<h3>Si no ves todos los gastos</h3>
<ul>
<li>Si tu usuario tiene un rol y no cuenta con el permiso para ver todos los gastos, la lista solo muestra los registros que tú creaste. El propietario de la cuenta ve todos los gastos de la sucursal.</li>
<li>La lista siempre está limitada a la sucursal en la que tienes sesión.</li>
<li>Si esperas ver un gasto de otra persona, pide a un administrador que revise tus permisos o cambia a la sucursal correcta.</li>
</ul>',
    80, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'expenses'
      AND title = 'Cómo buscar y ordenar el historial de gastos'
);

-- ----------------------------------------------------------------------------
-- 9. How to edit an expense without unbalancing cash and bank
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'expenses',
    'Cómo editar un gasto sin descuadrar caja y banco',
    'Corrige monto, categoría, cuenta u origen conociendo qué saldos revierte y vuelve a aplicar el sistema, y evita cambios que alteren cortes ya revisados.',
    '<p>Editar un gasto no solo cambia sus datos: el sistema ajusta los saldos que el gasto había movido. Antes de guardar, revisa qué configuración tenía el gasto y cuál vas a dejar para anticipar el efecto en la caja y en el banco.</p>
<h3>Cómo abrir la edición</h3>
<ol>
<li>Entra a Gastos y localiza el gasto en el historial.</li>
<li>Abre el detalle y pulsa "Opciones", o usa el botón de tres puntos de la fila y elige "Editar gasto".</li>
<li>Ajusta los campos y pulsa "Actualizar gasto".</li>
</ol>
<p>La edición requiere permiso para editar gastos. Si el gasto pertenece a otra sucursal o a otro usuario y no tienes visibilidad, no podrás abrirlo.</p>
<h3>Qué puedes cambiar</h3>
<ul>
<li>Concepto, fecha del gasto, monto, categoría y descripción.</li>
<li>Método de pago, origen del dinero y cuenta de origen.</li>
<li>El estatus no se puede cambiar aquí: el selector está deshabilitado. Para cambiar entre pagado y pendiente usa "Opciones" en el detalle.</li>
</ul>
<h3>Cómo se ajustan los saldos</h3>
<ol>
<li>Si el gasto original era interno, estaba pagado y tenía una cuenta bancaria, el sistema devuelve el monto anterior a esa cuenta.</li>
<li>Si el gasto original tenía un movimiento de caja, el sistema actualiza ese movimiento con el nuevo monto o lo elimina si el gasto deja de ser una salida de caja.</li>
<li>Si la nueva configuración es un gasto interno pagado desde una cuenta bancaria, el sistema retira el nuevo monto de la cuenta seleccionada.</li>
<li>Si la nueva configuración es un gasto de caja pagado, el sistema registra o actualiza la salida de efectivo. Si el gasto no tenía un movimiento de caja previo y no existe una sesión para la fecha del gasto, el sistema muestra un error y no guarda los cambios.</li>
<li>Si la nueva configuración es un gasto externo, no se mueve ningún saldo.</li>
</ol>
<h3>Casos frecuentes</h3>
<ul>
<li><strong>Corregir el monto:</strong> la diferencia se ajusta automáticamente en la cuenta o en la caja. Verifica el nuevo saldo después de guardar.</li>
<li><strong>Cambiar la categoría o la descripción:</strong> no afecta saldos. Es el cambio más seguro.</li>
<li><strong>Cambiar de cuenta bancaria:</strong> el monto regresa a la cuenta anterior y se retira de la nueva.</li>
<li><strong>Cambiar de externo a negocio:</strong> el gasto empieza a afectar la caja o el banco. Revisa que el dinero realmente haya salido de una cuenta del negocio.</li>
<li><strong>Cambiar de caja a externo:</strong> el movimiento de caja se elimina y el efectivo se restituye en la sesión.</li>
<li><strong>Cambiar la fecha:</strong> el gasto se mueve de periodo en el reporte financiero. Si el periodo ya se cerró, coordina el cambio con el administrador.</li>
</ul>
<h3>Qué no cambia</h3>
<p>El usuario que registró el gasto, la sucursal y la fecha de creación no se editan desde este formulario. Si necesitas corregir esos datos, elimina el gasto y vuelve a crearlo con la información correcta, siempre que no afecte un corte cerrado.</p>
<h3>Recomendaciones</h3>
<ul>
<li>Revisa el detalle después de guardar: monto, origen, cuenta y estatus deben coincidir con el comprobante.</li>
<li>No edites gastos que ya forman parte de un corte de caja revisado sin avisar al administrador.</li>
<li>Si el cambio es de estatus, usa la acción del detalle; si es de datos o de origen, usa la edición.</li>
<li>Si el gasto es de otro periodo o de otro negocio, crea un gasto nuevo en lugar de reutilizar el registro.</li>
</ul>',
    90, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'expenses'
      AND title = 'Cómo editar un gasto sin descuadrar caja y banco'
);

-- ----------------------------------------------------------------------------
-- 10. How to delete expenses and restore balances
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'expenses',
    'Cómo eliminar gastos y recuperar el saldo',
    'Borra un gasto o varios en lote, entiende cómo se restituyen la cuenta bancaria y el efectivo de la caja, y evita eliminar registros que ya forman parte de un corte.',
    '<p>Eliminar un gasto revierte los movimientos que generó: devuelve el monto a la cuenta bancaria y retira la salida de la sesión de caja. La acción no se puede deshacer, así que confirma que el registro es incorrecto antes de borrarlo.</p>
<h3>Elimina un gasto</h3>
<ol>
<li>Entra a Gastos y localiza el gasto en el historial.</li>
<li>Pulsa el botón de tres puntos de la fila y elige "Eliminar". También puedes abrir el detalle y usar "Opciones".</li>
<li>Confirma la acción en la ventana de advertencia.</li>
</ol>
<h3>Eliminación masiva</h3>
<ol>
<li>Marca las casillas de los gastos que quieres borrar.</li>
<li>En la barra roja de acciones masivas pulsa "Eliminar".</li>
<li>Confirma la eliminación del lote.</li>
</ol>
<p>La eliminación requiere permiso para borrar gastos. Si la opción no aparece, pide a un administrador que lo habilite.</p>
<h3>Qué pasa con los saldos</h3>
<ul>
<li><strong>Gasto pagado con cuenta bancaria:</strong> el monto se devuelve al saldo de la cuenta. El mensaje de confirmación indica la cantidad y la cuenta.</li>
<li><strong>Gasto con movimiento de caja:</strong> el movimiento se elimina de la sesión y el efectivo se restituye en la caja en turno.</li>
<li><strong>Gasto externo:</strong> no mueve saldos, solo se borra del historial.</li>
<li><strong>Gasto pendiente:</strong> no tenía salida de dinero, así que no se devuelve ningún saldo bancario al eliminarlo.</li>
</ul>
<h3>Antes de eliminar</h3>
<ul>
<li>Abre el detalle y verifica concepto, monto, fecha, origen y cuenta. Si solo necesitas corregir un dato, edita el gasto en lugar de borrarlo.</li>
<li>Si el gasto pertenece a un corte de caja ya revisado, avisa al administrador: al borrarlo desaparece el movimiento y el corte cambia.</li>
<li>No elimines un gasto para "compensar" una venta o un pago. Los gastos son registros independientes.</li>
<li>Conserva el comprobante fuera del sistema si necesitas evidencia después del borrado.</li>
</ul>
<h3>Después de eliminar</h3>
<ul>
<li>Revisa el saldo de la cuenta o el efectivo esperado de la sesión para confirmar que la restitución se aplicó.</li>
<li>Consulta el reporte financiero del periodo para verificar que el gasto ya no suma.</li>
<li>Si borraste un gasto por error, vuelve a capturarlo con los mismos datos y el origen correcto. La aplicación no ofrece una opción para recuperarlo.</li>
</ul>',
    100, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'expenses'
      AND title = 'Cómo eliminar gastos y recuperar el saldo'
);
