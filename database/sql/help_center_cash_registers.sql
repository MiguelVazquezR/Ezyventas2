-- ============================================================================
-- Help center - Guides for the Cajas (cash-registers) module
--
-- Inserts 12 published guides for the "cash-registers" module of the
-- help_center_articles table. The guides automatically appear in the
-- "Cajas" card of the help center.
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
-- 1. Create and manage cash registers
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'cash-registers',
    'Cómo crear y administrar tus cajas registradoras',
    'Da de alta cada terminal de cobro de tu sucursal, distingue el estatus de servicio del estado de sesión y aprende cuándo se puede eliminar una caja.',
    '<p>La pantalla Cajas registradoras reúne las terminales de cobro de tu sucursal. Cada caja tiene un nombre, un estatus de servicio y un estado de sesión que cambia según la operación. Esta guía cubre el alta, la edición y la eliminación de cajas.</p>
<h3>Antes de empezar</h3>
<ul>
<li>Para crear, editar o eliminar cajas necesitas el permiso "Administrar cajas registradoras". Para consultar el listado basta con "Ver listado de cajas registradoras".</li>
<li>La caja queda asignada a la sucursal de tu usuario. No se elige manualmente en el formulario.</li>
<li>Revisa el límite de cajas de tu plan. Cuando se alcanza, la pantalla muestra el aviso "Límite de cajas alcanzado" y el botón "Nueva caja" se deshabilita.</li>
</ul>
<h3>Crear una caja</h3>
<ol>
<li>Entra a Cajas registradoras y pulsa "Nueva caja".</li>
<li>Escribe el nombre en "Nombre de la caja". Usa un identificador que el equipo reconozca, por ejemplo Caja 1 o Mostrador.</li>
<li>Deja "Caja activa" encendido si la terminal se usará de inmediato.</li>
<li>Pulsa "Crear caja". La caja aparece en el listado con el estado de sesión "Cerrada".</li>
</ol>
<h3>Editar o eliminar una caja</h3>
<ol>
<li>Abre el menú de tres puntos de la fila.</li>
<li>Elige "Editar caja" para cambiar el nombre o el estatus de servicio, o "Eliminar" para borrarla.</li>
<li>Confirma la eliminación en el diálogo. La operación no se puede deshacer.</li>
</ol>
<h3>Cómo leer las columnas</h3>
<ul>
<li><strong>Nombre / Identificador:</strong> el nombre con el que el equipo reconoce la terminal.</li>
<li><strong>Sucursal asignada:</strong> la sucursal a la que pertenece la caja.</li>
<li><strong>Estatus de servicio:</strong> Activa o Inactiva. Es una decisión administrativa.</li>
<li><strong>Estado de sesión:</strong> Sesión abierta o Cerrada. Lo cambia la operación diaria, no el estatus de servicio.</li>
</ul>
<h3>Qué tener en cuenta</h3>
<ul>
<li>Una caja inactiva deja de ofrecerse al abrir turno desde el punto de venta, pero conserva su historial de cortes.</li>
<li>Una caja con cortes registrados no se puede eliminar. El sistema responde "No se puede eliminar una caja con sesiones de historial": desactívala en su lugar.</li>
<li>Puedes tener varias cajas en la misma sucursal, pero cada una admite una sola sesión abierta a la vez.</li>
<li>El alta de cajas depende del límite contratado. Si necesitas más, usa "Solicitar aumento" para contactar al equipo de soporte.</li>
</ul>
<h3>Problemas frecuentes</h3>
<ul>
<li><strong>No aparece "Nueva caja":</strong> tu usuario no tiene el permiso de administración o ya se alcanzó el límite del plan.</li>
<li><strong>El botón está deshabilitado:</strong> el contador de cajas llegó al máximo. Desactiva cajas en desuso o solicita un aumento.</li>
<li><strong>No puedes eliminar:</strong> la caja ya tiene historial. Desactívala para dejar de usarla sin perder los cortes anteriores.</li>
</ul>
<p>Para operar la caja durante el día, continúa con "Cómo abrir un turno y declarar los saldos iniciales de tus cuentas".</p>',
    10, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'cash-registers'
      AND title = 'Cómo crear y administrar tus cajas registradoras'
);

-- ----------------------------------------------------------------------------
-- 2. Open a shift and declare opening balances
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'cash-registers',
    'Cómo abrir un turno y declarar los saldos iniciales de tus cuentas',
    'Inicia una sesión desde la ficha de la caja, elige al operador, registra el fondo de efectivo y confirma el saldo real de cada cuenta bancaria antes de cobrar.',
    '<p>Abrir un turno crea una sesión de caja con su fondo de efectivo y una fotografía de los saldos bancarios. Esa fotografía es la base del arqueo y de la conciliación al cierre, así que conviene declararla con cuidado.</p>
<h3>Antes de empezar</h3>
<ul>
<li>Ten a la mano el efectivo con el que arranca la caja y el saldo real de cada cuenta bancaria de la sucursal.</li>
<li>La caja debe estar en estado de sesión "Cerrada". Si aparece "Sesión abierta", otra persona ya inició el turno y lo que corresponde es unirse.</li>
<li>El fondo de caja no es una venta ni un ingreso: es el punto de partida del conteo.</li>
</ul>
<h3>Abrir el turno paso a paso</h3>
<ol>
<li>Entra a Cajas registradoras y haz clic en la fila de la caja. Se abre la ficha con el botón "Iniciar turno".</li>
<li>Pulsa "Iniciar turno". Aparece el aviso "Nueva sesión" con el nombre de la caja.</li>
<li>En "Operador de la sesión" elige al usuario que abre el turno. Los usuarios que ya tienen un turno abierto aparecen con la etiqueta "Ocupado" y no se pueden seleccionar; los disponibles muestran "Libre".</li>
<li>En "Fondo de caja inicial (Efectivo)" escribe el total contado. Debe incluir billetes y monedas con los que trabajará el cajero.</li>
<li>En "Saldos iniciales" revisa cada cuenta bancaria de la sucursal, identificada como banco y nombre de la cuenta, y corrige el saldo si no coincide con el real.</li>
<li>Pulsa "Abrir caja". La caja pasa a "Sesión abierta" y queda disponible para cobrar.</li>
</ol>
<h3>Qué hace el sistema con los saldos</h3>
<ul>
<li>El efectivo declarado se guarda como fondo inicial de la sesión.</li>
<li>Los saldos bancarios declarados se aplican a las cuentas y se congelan en la sesión como saldo inicial.</li>
<li>Las cuentas que no declares heredan el saldo del último corte de esa caja. Si nunca hubo un corte, se usa el saldo actual de la cuenta.</li>
</ul>
<h3>Qué tener en cuenta</h3>
<ul>
<li>Una caja admite una sola sesión abierta. Mientras esté abierta no verás el botón "Iniciar turno".</li>
<li>Si dos personas intentan abrir la misma caja, la segunda recibe el aviso "Parece que otro usuario abrió caja antes que tú, puedes unirte a la sesión". En ese caso usa "Unirse" desde la ficha.</li>
<li>El historial de la caja muestra al usuario que ejecutó la apertura. Si abres turnos a nombre de otra persona, confirma quién queda registrado antes de continuar.</li>
<li>Al abrir el turno también puedes registrar los movimientos de efectivo y comenzar a vender desde el punto de venta.</li>
</ul>
<h3>Problemas frecuentes</h3>
<ul>
<li><strong>No aparece "Iniciar turno":</strong> la caja ya tiene una sesión abierta. Revisa los avatares de usuarios en la ficha o la columna Estado de sesión del listado.</li>
<li><strong>El operador aparece como Ocupado:</strong> ese usuario ya tiene un turno abierto en otra caja. Pídele que lo cierre o elige a otra persona.</li>
<li><strong>El saldo bancario no coincide:</strong> corrígelo en "Saldos iniciales". El valor que guardes será el saldo inicial de la conciliación de ese turno.</li>
</ul>
<p>Si el turno ya estaba abierto, revisa "Cómo unirse a una sesión de caja y trabajar con varios cajeros". Para el flujo desde el punto de venta, consulta "Cómo abrir y cerrar la caja del punto de venta".</p>',
    20, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'cash-registers'
      AND title = 'Cómo abrir un turno y declarar los saldos iniciales de tus cuentas'
);

-- ----------------------------------------------------------------------------
-- 3. Join a session and work with several cashiers
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'cash-registers',
    'Cómo unirse a una sesión de caja y trabajar con varios cajeros',
    'Varios usuarios pueden cobrar sobre el mismo turno: únete desde la ficha de la caja, comparte fondo y movimientos, y cierra el turno una sola vez.',
    '<p>Una sesión de caja no pertenece a una sola persona. Varios usuarios de la sucursal pueden unirse al mismo turno y cobrar sobre él. Todas las ventas y los movimientos se acumulan en una sola sesión, con un único arqueo al final.</p>
<h3>Antes de empezar</h3>
<ul>
<li>La caja debe tener una sesión abierta. Si está cerrada, primero hay que iniciar el turno.</li>
<li>Necesitas acceso al módulo de Cajas para entrar a la ficha de la caja.</li>
<li>Si ya formas parte de la sesión, el botón "Unirse" no aparece.</li>
</ul>
<h3>Unirte a la sesión paso a paso</h3>
<ol>
<li>Entra a Cajas registradoras y localiza la caja con la leyenda "Sesión abierta".</li>
<li>Haz clic en la fila para abrir la ficha. En la tarjeta de la sesión verás los avatares de los usuarios que ya están dentro.</li>
<li>Pulsa "Unirse", junto a los avatares.</li>
<li>Cuando tu avatar aparezca en la lista, quedas dentro del turno y puedes cobrar desde el punto de venta.</li>
</ol>
<h3>Qué implica compartir una sesión</h3>
<ul>
<li>El fondo inicial, los saldos bancarios y todos los movimientos son los mismos para el equipo.</li>
<li>Las ventas que cobres se suman a la sesión, sin importar quién la abrió.</li>
<li>El historial de la caja muestra al usuario que abrió la sesión en la columna "Abrió sesión"; el historial general lo muestra como "Operador".</li>
<li>Cualquier integrante puede registrar movimientos de efectivo si tiene el permiso correspondiente.</li>
<li>El botón "Cerrar caja" solo aparece a quienes están dentro de la sesión, y el corte se hace una sola vez para todos.</li>
</ul>
<h3>Qué tener en cuenta</h3>
<ul>
<li>No abras un turno nuevo para el segundo cajero: eso crearía dos sesiones y dos arqueos sobre la misma terminal, lo cual no es posible mientras la primera siga abierta.</li>
<li>Coordina con el equipo antes de registrar un retiro o un ingreso manual, porque afecta el efectivo esperado de todos.</li>
<li>Al terminar tu participación puedes salir sin cerrar el turno con "Desconexión personal". Las ventas que hiciste permanecen en la sesión y el resto del equipo sigue trabajando.</li>
<li>Si la sesión se cierra, el botón "Unirse" desaparece; para volver a cobrar hay que iniciar un turno nuevo.</li>
</ul>
<h3>Problemas frecuentes</h3>
<ul>
<li><strong>No veo el botón "Unirse":</strong> ya estás dentro de la sesión, la sesión se cerró o tu usuario no tiene acceso al módulo de Cajas.</li>
<li><strong>Mis ventas no aparecen en mi turno:</strong> probablemente abriste otra sesión en lugar de unirte. Revisa la ficha de la caja y el historial de cortes para confirmar en qué sesión quedaron.</li>
<li><strong>El equipo no ve mis movimientos:</strong> actualiza la pantalla de la ficha. Los movimientos y las ventas se acumulan en la misma sesión, pero la vista no siempre se refresca sola.</li>
</ul>
<p>Para decidir cómo terminar tu participación, consulta "Corte total o desconexión personal: cómo terminar tu turno". Para el flujo desde el punto de venta, revisa "Cómo abrir y cerrar la caja del punto de venta".</p>',
    30, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'cash-registers'
      AND title = 'Cómo unirse a una sesión de caja y trabajar con varios cajeros'
);

-- ----------------------------------------------------------------------------
-- 4. Full cut vs personal disconnect
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'cash-registers',
    'Corte total o desconexión personal: cómo terminar tu turno',
    'Cuando varios cajeros comparten la caja, el sistema te permite cerrar el turno para todos o solo salir tú. Aprende cuándo usar cada opción.',
    '<p>Cuando hay más de un cajero conectado a la misma sesión, el botón "Cerrar caja" abre el diálogo "Cierre de caja" con dos caminos: cerrar el turno para todo el equipo o salir solo tú. Elegir bien evita cortes incompletos y diferencias de caja.</p>
<h3>Cuándo aparece cada opción</h3>
<ul>
<li>Si la sesión tiene varios usuarios, verás el aviso "Conexiones múltiples activas" con los nombres de tus compañeros y las dos opciones.</li>
<li>Si eres el único usuario dentro de la sesión, no hay elección: el sistema va directo al conteo físico del corte.</li>
</ul>
<h3>Corte total de caja</h3>
<p>Es el cierre definitivo del turno. Requiere contar el efectivo y, al confirmarlo, desconecta a todos los usuarios de la sesión.</p>
<ol>
<li>Pulsa "Cerrar caja" y elige "Corte total de caja".</li>
<li>Lee la "Advertencia de sistema" y pulsa "Proceder al corte".</li>
<li>Revisa el resumen de efectivo, tarjeta y transferencia que muestra el diálogo.</li>
<li>Escribe el "Monto final físico en caja", agrega las "Notas de arqueo" si hace falta y pulsa "Finalizar turno".</li>
</ol>
<h3>Desconexión personal</h3>
<p>Te saca del turno sin cerrarlo. La caja sigue operando para los demás usuarios y el arqueo queda pendiente hasta que alguien haga el corte total.</p>
<ol>
<li>Pulsa "Cerrar caja" y elige "Desconexión personal".</li>
<li>La acción se ejecuta de inmediato, sin un segundo diálogo de confirmación.</li>
<li>Tu usuario deja de aparecer entre los participantes de la sesión.</li>
</ol>
<h3>Qué tener en cuenta</h3>
<ul>
<li>La desconexión personal no genera un corte ni libera la caja: la columna Estado de sesión seguirá en "Sesión abierta".</li>
<li>Tus ventas y movimientos permanecen en la sesión; no se borran al salir.</li>
<li>Antes de desconectarte, registra cualquier ingreso o retiro pendiente. Una vez fuera, deja de ser tu turno activo y no podrás volver a él sin unirte de nuevo.</li>
<li>El corte total cierra la sesión para todos y libera la caja para un turno nuevo. Después de ejecutarlo no se pueden registrar más movimientos en esa sesión.</li>
<li>Si haces el corte total, avisa al equipo antes de confirmarlo: el resto de los usuarios perderá acceso a la sesión de inmediato.</li>
</ul>
<h3>Problemas frecuentes</h3>
<ul>
<li><strong>No veo "Desconexión personal":</strong> eres el último usuario de la sesión. En ese caso solo existe el cierre definitivo con conteo.</li>
<li><strong>La caja sigue "Sesión abierta" después de salir:</strong> es el comportamiento esperado de la desconexión personal. El turno se cierra hasta que alguien ejecute el corte total.</li>
<li><strong>No aparece "Cerrar caja":</strong> tu usuario no forma parte de la sesión. Entra a la ficha de la caja y pulsa "Unirse".</li>
</ul>
<p>Para ejecutar el corte con el detalle del arqueo, revisa "Cómo cerrar el turno y hacer el arqueo de caja". Si solo quieres sumarte al turno, consulta "Cómo unirse a una sesión de caja y trabajar con varios cajeros".</p>',
    40, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'cash-registers'
      AND title = 'Corte total o desconexión personal: cómo terminar tu turno'
);

-- ----------------------------------------------------------------------------
-- 5. Close the shift and reconcile the cash count
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'cash-registers',
    'Cómo cerrar el turno y hacer el arqueo de caja',
    'Cuenta el efectivo, revisa el resumen del sistema, documenta la diferencia y finaliza el turno. El corte queda en el historial y actualiza los saldos bancarios.',
    '<p>El arqueo compara el efectivo contado con el efectivo que el sistema espera. Al finalizar, la sesión se cierra, se guardan la diferencia y las notas, y los saldos bancarios quedan conciliados.</p>
<h3>Antes de empezar</h3>
<ul>
<li>Debes formar parte de la sesión. El botón "Cerrar caja" aparece solo a los usuarios que están dentro del turno.</li>
<li>Cuenta el efectivo antes de abrir el diálogo. Incluye el fondo inicial y todos los billetes y monedas de la caja.</li>
<li>Si hay más cajeros conectados, decide primero si harás corte total o desconexión personal.</li>
</ul>
<h3>Cerrar el turno paso a paso</h3>
<ol>
<li>Entra a la ficha de la caja y pulsa "Cerrar caja".</li>
<li>Si hay varios usuarios, elige "Corte total de caja" y confirma con "Proceder al corte".</li>
<li>En la pantalla "Cierre y arqueo" revisa los totales del sistema: "Apertura inicial", "Ingresos por ventas", "Entradas manuales" y los retiros, además de los cobros con tarjeta y transferencia.</li>
<li>Escribe el total contado en "Monto final físico en caja".</li>
<li>Observa el indicador "Descuadre / Diferencia". Se calcula en vivo y muestra si el conteo coincide con lo esperado.</li>
<li>Documenta cualquier diferencia en "Notas de arqueo (Opcional)": explica qué pasó, si faltó cambio o si hubo un movimiento no registrado.</li>
<li>Pulsa "Finalizar turno".</li>
</ol>
<h3>Qué ocurre al finalizar</h3>
<ul>
<li>La sesión cambia a cerrada, la caja pasa a "Cerrada" y se libera para un turno nuevo.</li>
<li>Se guardan el efectivo contado, el efectivo esperado, la diferencia y las notas del arqueo.</li>
<li>Los saldos finales de las cuentas bancarias se congelan en el corte y se escriben en las cuentas, de modo que el siguiente turno arranca con ellos.</li>
<li>El corte aparece en el historial de cortes de la sucursal y puede consultarse o imprimirse en cualquier momento.</li>
</ul>
<h3>Qué tener en cuenta</h3>
<ul>
<li>El monto contado debe incluir el fondo inicial. No escribas solo el efectivo de las ventas.</li>
<li>No es obligatorio que la diferencia sea cero para cerrar, pero una diferencia sin explicar complica la auditoría. Si es grande, vuelve a contar y revisa los movimientos antes de finalizar.</li>
<li>El sistema solo considera las ventas cobradas en efectivo con estado completado. Las ventas a crédito y los pagos con tarjeta o transferencia no se suman al efectivo esperado.</li>
<li>Después del cierre no se pueden registrar movimientos en esa sesión. Si falta un movimiento, corrige el contado desde el historial y documenta el motivo.</li>
<li>Las notas del arqueo aparecen en el detalle del corte y en el reporte impreso, así que redáctalas para que otra persona pueda entender el cierre.</li>
</ul>
<h3>Problemas frecuentes</h3>
<ul>
<li><strong>El conteo no coincide con el sistema:</strong> revisa el desglose y los movimientos antes de finalizar. Si el error fue de captura, corrige el monto; si faltó registrar un retiro, hazlo antes de cerrar.</li>
<li><strong>No puedes finalizar:</strong> el campo "Monto final físico en caja" es obligatorio. Escribe cero solo si la caja quedó realmente vacía.</li>
<li><strong>La caja sigue ocupada:</strong> confirma que el corte se guardó. Si la sesión sigue "Sesión abierta", otro usuario está dentro o el cierre no se completó.</li>
</ul>
<p>Para entender el significado de la diferencia, consulta "Cómo interpretar la diferencia de caja (sobrante y faltante)". Para imprimir el comprobante, revisa "Cómo imprimir o guardar en PDF el reporte de un corte".</p>',
    50, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'cash-registers'
      AND title = 'Cómo cerrar el turno y hacer el arqueo de caja'
);

-- ----------------------------------------------------------------------------
-- 6. Understand the cash difference
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'cash-registers',
    'Cómo interpretar la diferencia de caja (sobrante y faltante)',
    'Aprende cómo se calcula la diferencia entre el efectivo esperado y el contado, qué la provoca y cómo investigarla antes de cerrar o después del corte.',
    '<p>La diferencia de caja es la distancia entre el efectivo que el sistema espera y el efectivo que realmente contaste. Un sobrante o un faltante no siempre significa un problema grave, pero siempre debe revisarse y documentarse.</p>
<h3>Dónde verla</h3>
<p>En el detalle de un corte, dentro de "Resumen financiero y movimientos", la tarjeta principal muestra "Diferencia de caja (Sobrante/Faltante)", con dos referencias: "Esperado sistema" y "Contado físico". Debajo aparece el "Desglose de cálculo".</p>
<h3>Cómo se calcula</h3>
<p>El efectivo esperado se compone así:</p>
<ul>
<li><strong>Fondo inicial en caja:</strong> el efectivo declarado al abrir el turno.</li>
<li><strong>Ventas cobradas en efectivo:</strong> pagos con método efectivo y estado completado.</li>
<li><strong>Ingresos manuales:</strong> entradas de efectivo registradas durante el turno.</li>
<li><strong>Retiros manuales:</strong> salidas de efectivo registradas durante el turno, que se restan.</li>
</ul>
<p>La fórmula es: esperado = fondo inicial + ventas en efectivo + ingresos manuales - retiros manuales. Después, diferencia = contado físico - esperado.</p>
<ul>
<li><strong>Diferencia positiva:</strong> sobrante. Hay más efectivo del que el sistema esperaba.</li>
<li><strong>Diferencia negativa:</strong> faltante. Hay menos efectivo del esperado.</li>
<li><strong>Diferencia en cero:</strong> la caja cuadra.</li>
</ul>
<h3>Causas frecuentes de un sobrante</h3>
<ul>
<li>Un retiro o gasto pagado desde la caja que no se registró como salida.</li>
<li>Cambio entregado de menos al cliente.</li>
<li>Redondeos o propinas que no se registraron.</li>
<li>Efectivo de otra caja o de uso personal que quedó mezclado en el cajón.</li>
</ul>
<h3>Causas frecuentes de un faltante</h3>
<ul>
<li>Cambio entregado de más.</li>
<li>Un gasto pagado con efectivo de la caja sin registrar el movimiento.</li>
<li>Un retiro parcial que no se capturó.</li>
<li>Pérdida, robo o un billete mal contado.</li>
<li>Un cobro con tarjeta o transferencia que se contó por error como efectivo, o al contrario.</li>
</ul>
<h3>Cómo investigar paso a paso</h3>
<ol>
<li>Vuelve a contar el efectivo, separando el fondo inicial del dinero de ventas.</li>
<li>Revisa el "Desglose de cálculo" para confirmar que el fondo y los totales son los que esperabas.</li>
<li>Recorre el "Registro de movimientos de caja": cada ingreso y retiro debe tener descripción, usuario y hora.</li>
<li>Contrasta los cobros en efectivo con los tickets de venta en "Detalle operativo".</li>
<li>Compara los cobros con tarjeta y transferencia con el "Resumen de cuentas bancarias" para descartar que el dinero esté en otro medio de pago.</li>
</ol>
<h3>Qué hacer según el momento</h3>
<ul>
<li><strong>Si la sesión sigue abierta:</strong> registra el ingreso o el retiro que faltó. El efectivo esperado se recalcula y la diferencia se corrige.</li>
<li><strong>Si el corte ya se cerró y el error fue de captura del contado:</strong> corrige el "Contado físico" desde el detalle del corte.</li>
<li><strong>Si el dinero realmente falta o sobra:</strong> documenta la explicación en las notas del arqueo y sigue el procedimiento interno de tu negocio.</li>
</ul>
<h3>Qué tener en cuenta</h3>
<ul>
<li>La diferencia de caja es solo de efectivo. Las diferencias de tarjeta y transferencia se analizan en las cuentas bancarias, no en este indicador.</li>
<li>Una diferencia no explica por sí sola un descuadre contable: las ventas a crédito no generan efectivo y no deben contarse en el cajón.</li>
<li>Las correcciones del contado no cambian las ventas ni los movimientos: solo ajustan el conteo y la diferencia.</li>
</ul>
<p>Para revisar el detalle del corte, consulta "Cómo consultar el historial de cortes de caja". Para comparar caja y banco, revisa "Cómo revisar los saldos bancarios de una sesión".</p>',
    60, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'cash-registers'
      AND title = 'Cómo interpretar la diferencia de caja (sobrante y faltante)'
);

-- ----------------------------------------------------------------------------
-- 7. Read the income breakdown
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'cash-registers',
    'Cómo leer el desglose de ingresos de una sesión de caja',
    'La tarjeta "Desglose de ingresos reales" agrupa los cobros por método de pago. Aprende qué incluye cada total y por qué no siempre coincide con las ventas.',
    '<p>El desglose de ingresos responde una pregunta concreta: cuánto dinero entró realmente en el turno y por qué medio. Es la base para explicar la parte en efectivo del arqueo y los movimientos de las cuentas bancarias.</p>
<h3>Dónde encontrarlo</h3>
<ol>
<li>Entra a Cajas registradoras y abre la ficha de la caja.</li>
<li>Pulsa "Cerrar caja" o consulta un corte cerrado desde el historial.</li>
<li>En el detalle del corte busca la tarjeta "Desglose de ingresos reales", con el subtítulo "Agrupados por método de pago".</li>
</ol>
<h3>Qué muestra cada método</h3>
<ul>
<li><strong>Efectivo:</strong> total de cobros en efectivo del turno. Es el monto que alimenta el efectivo esperado del arqueo.</li>
<li><strong>Tarjeta:</strong> cobros con tarjeta. Se reflejan en la cuenta bancaria asociada al cobro.</li>
<li><strong>Transferencia:</strong> cobros por transferencia. También se reflejan en la cuenta bancaria correspondiente.</li>
<li><strong>Total global:</strong> la suma de los métodos anteriores. No incluye el saldo de cliente.</li>
</ul>
<p>El saldo de cliente no aparece en este desglose porque no representa dinero cobrado: es un monedero o saldo a favor aplicado a una venta. Por eso tampoco suma al total global ni al efectivo esperado.</p>
<h3>Cobros contra ventas</h3>
<p>Esta tarjeta agrupa pagos completados, no mercancía. Un mismo turno puede tener ventas a crédito sin cobro, ventas pagadas en dos métodos o abonos de ventas anteriores. Todas esas situaciones hacen que el total de ingresos no coincida con el valor de las ventas.</p>
<p>Para revisar el detalle, usa la tarjeta "Detalle operativo":</p>
<ul>
<li>La tabla de cobros muestra hora, venta o folio, concepto, cliente, método y monto cobrado.</li>
<li>La tabla de ventas muestra folio, hora, cajero y valor de la mercancía vendida o apartada. Ese valor no necesariamente entró a la caja, por ejemplo si la venta quedó a crédito.</li>
</ul>
<h3>Cómo usarlo en la operación diaria</h3>
<ul>
<li>Si el efectivo contado no coincide, compara el total de "Efectivo" con el "Desglose de cálculo" del arqueo.</li>
<li>Si el banco no coincide, compara "Tarjeta" y "Transferencia" con el "Resumen de cuentas bancarias".</li>
<li>Si un cobro aparece en el método equivocado, revisa cómo se capturó en el punto de venta. Corregirlo en el corte no es posible: el método se define al cobrar.</li>
</ul>
<h3>Qué tener en cuenta</h3>
<ul>
<li>Solo se consideran pagos con estado completado. Un pago cancelado o pendiente no suma al desglose.</li>
<li>Los abonos de ventas anteriores también cuentan como ingreso del turno en el que se cobraron.</li>
<li>El total global no es una medida de utilidad: es dinero cobrado, sin restar gastos ni costo de la mercancía.</li>
</ul>
<h3>Problemas frecuentes</h3>
<ul>
<li><strong>Hay ventas pero el desglose está en cero:</strong> las ventas quedaron a crédito o sin pago registrado. Revisa la tabla de ventas en "Detalle operativo".</li>
<li><strong>El total es menor al esperado:</strong> algún cobro se registró con otro método o quedó pendiente. Verifica los pagos uno por uno.</li>
<li><strong>El desglose suma distinto al banco:</strong> el cobro con tarjeta o transferencia pudo quedar asociado a otra cuenta. Revisa el resumen bancario del corte.</li>
</ul>
<p>Para profundizar en las diferencias entre ventas, pagos y gastos, consulta "Por qué las ventas, los pagos y los gastos no coinciden". Para la conciliación bancaria, revisa "Cómo revisar los saldos bancarios de una sesión".</p>',
    70, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'cash-registers'
      AND title = 'Cómo leer el desglose de ingresos de una sesión de caja'
);

-- ----------------------------------------------------------------------------
-- 8. Bank account balances of a session
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'cash-registers',
    'Cómo revisar los saldos bancarios de una sesión',
    'La tarjeta "Resumen de cuentas bancarias" muestra cómo se movió cada cuenta durante el turno y por qué el saldo final se arrastra al siguiente corte.',
    '<p>Además del efectivo, cada turno mueve dinero en las cuentas bancarias. La tarjeta "Resumen de cuentas bancarias" muestra el saldo inicial, el saldo final y la variación de cada cuenta durante la sesión.</p>
<h3>Dónde encontrarlo</h3>
<ol>
<li>Entra a Cajas registradoras y abre la ficha de la caja.</li>
<li>Consulta la sesión abierta o abre un corte cerrado desde el historial de cortes.</li>
<li>Busca la tarjeta "Resumen de cuentas bancarias", con el subtítulo "Variación de saldos durante la sesión".</li>
</ol>
<h3>Cómo leer la tabla</h3>
<ul>
<li><strong>Cuenta:</strong> banco y nombre de la cuenta.</li>
<li><strong>Saldo inicial:</strong> el saldo declarado o heredado al abrir el turno.</li>
<li><strong>Saldo final:</strong> el resultado de sumar y restar los movimientos del turno.</li>
<li><strong>Diferencia:</strong> saldo final menos saldo inicial. En verde si subió, en rojo si bajó.</li>
</ul>
<h3>Cómo se calcula el saldo final</h3>
<p>Para cada cuenta, el sistema parte del saldo inicial y aplica:</p>
<ul>
<li>Más los cobros con tarjeta y transferencia asociados a esa cuenta.</li>
<li>Menos los gastos pagados con tarjeta o transferencia desde esa cuenta.</li>
<li>Más las transferencias recibidas de otras cuentas.</li>
<li>Menos las transferencias enviadas a otras cuentas.</li>
</ul>
<p>El efectivo no entra en este cálculo: se controla en el arqueo de caja.</p>
<h3>Sesiones abiertas y sesiones cerradas</h3>
<ul>
<li>Mientras la sesión está abierta, el saldo final se recalcula con los movimientos registrados hasta ese momento.</li>
<li>Cuando se cierra el turno, el saldo final se congela en el corte y se escribe en la cuenta bancaria. El siguiente turno arranca con ese saldo.</li>
<li>En un corte ya cerrado, la tabla muestra el saldo congelado del cierre, no un recálculo posterior.</li>
</ul>
<h3>Quién ve cada cuenta</h3>
<ul>
<li>El propietario de la cuenta, es decir el usuario sin rol asignado, ve todas las cuentas incluidas en la sesión.</li>
<li>Un usuario con rol ve únicamente las cuentas bancarias que tiene asignadas. Si una cuenta no le corresponde, no aparece en su resumen.</li>
<li>Si no hay cuentas vinculadas a la sucursal, la tarjeta muestra "No se detectaron cuentas bancarias en esta sucursal".</li>
</ul>
<h3>Qué tener en cuenta</h3>
<ul>
<li>Una diferencia bancaria no es una diferencia de caja: no se corrige con el contado físico del arqueo.</li>
<li>Los gastos se atribuyen a la sesión por la fecha y hora en que se registraron, no por la fecha contable del gasto.</li>
<li>Si un cobro con tarjeta se asoció a la cuenta equivocada, la diferencia aparecerá en dos cuentas a la vez.</li>
<li>Las transferencias entre cuentas propias mueven el saldo de ambas, pero no cambian el total del negocio.</li>
</ul>
<h3>Problemas frecuentes</h3>
<ul>
<li><strong>Una cuenta no aparece:</strong> no está vinculada a la sucursal o tu usuario no tiene esa cuenta asignada.</li>
<li><strong>El saldo final no coincide con el banco:</strong> revisa cobros con tarjeta y transferencia, gastos pagados desde la cuenta y transferencias internas del turno.</li>
<li><strong>El saldo inicial es distinto al que declaraste:</strong> confirma qué valor se capturó al abrir el turno. Ese valor queda congelado como saldo inicial de la sesión.</li>
</ul>
<p>Para la conciliación completa entre caja, banco y gastos, consulta "Cómo conciliar caja, banco y gastos externos". Si el problema viene de un gasto, revisa "Cómo editar un gasto sin descuadrar caja y banco".</p>',
    80, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'cash-registers'
      AND title = 'Cómo revisar los saldos bancarios de una sesión'
);

-- ----------------------------------------------------------------------------
-- 9. Fix the counted cash of a closed cut
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'cash-registers',
    'Cómo corregir el contado físico de un corte cerrado',
    'Si el conteo se capturó mal, puedes ajustar el contado desde el detalle del corte. La diferencia se recalcula, pero el efectivo esperado y los movimientos no cambian.',
    '<p>Cuando un corte ya se cerró con un monto contado equivocado, no hace falta reabrir la sesión: el detalle del corte permite corregir el "Contado físico". La corrección ajusta la diferencia y se refleja en el reporte, sin tocar ventas ni movimientos.</p>
<h3>Cuándo usar esta corrección</h3>
<ul>
<li>El monto se capturó con un dígito de más o de menos.</li>
<li>Al recontar el efectivo se encontró un billete que no se incluyó.</li>
<li>Se identificó que el conteo mezcló dinero de otra caja o un fondo personal.</li>
</ul>
<p>No uses esta corrección para ocultar un faltante real ni para forzar que la caja cuadre. En esos casos el ajuste debe documentarse y seguir el procedimiento interno del negocio.</p>
<h3>Corregir el contado paso a paso</h3>
<ol>
<li>Entra a Historial de cortes de caja y abre el corte con el menú de tres puntos, opción "Ver detalles".</li>
<li>Busca la tarjeta "Resumen financiero y movimientos" y localiza el bloque "Contado físico".</li>
<li>Pasa el cursor sobre el monto y pulsa el lápiz. Aparece el campo editable con el valor actual.</li>
<li>Escribe el monto correcto.</li>
<li>Pulsa el botón verde con la marca de verificación para guardar, o la cruz gris para cancelar.</li>
<li>Confirma que el indicador "Diferencia de caja (Sobrante/Faltante)" se actualizó con el nuevo valor.</li>
</ol>
<h3>Qué cambia y qué no</h3>
<ul>
<li><strong>Cambia:</strong> el contado físico y, como consecuencia, la diferencia de caja.</li>
<li><strong>No cambia:</strong> el "Esperado sistema", que se calcula con el fondo inicial, las ventas en efectivo y los movimientos manuales.</li>
<li><strong>No cambia:</strong> los saldos bancarios, las ventas, los movimientos de efectivo ni las notas del arqueo.</li>
<li><strong>No reabre</strong> la sesión: el turno sigue cerrado y no admite nuevos movimientos.</li>
</ul>
<h3>Qué tener en cuenta</h3>
<ul>
<li>La corrección no elimina la diferencia original del historial ni deja un registro de cambios en pantalla. Si tu operación lo requiere, anota el motivo y la fecha en tu bitácora interna.</li>
<li>El reporte impreso y el detalle del corte toman el valor actualizado, así que una reimpresión mostrará el nuevo contado.</li>
<li>Cualquier usuario con acceso al detalle del corte puede ajustar el contado. Confirma con tu supervisor antes de hacerlo.</li>
<li>Si el error está en un movimiento o en una venta, esta corrección no lo resuelve: revisa el flujo del movimiento o el cobro correspondiente.</li>
</ul>
<h3>Problemas frecuentes</h3>
<ul>
<li><strong>No aparece el lápiz:</strong> el cursor debe estar sobre el monto de "Contado físico". En pantallas táctiles, toca el número directamente.</li>
<li><strong>El monto no se guarda:</strong> revisa que el valor sea un número válido y que la sesión esté cerrada. Si el error persiste, recarga la página.</li>
<li><strong>La diferencia sigue igual:</strong> confirma que el nuevo contado sea distinto al anterior. Si el valor guardado es el mismo, la diferencia no cambia.</li>
</ul>
<p>Para entender el origen de la diferencia antes de ajustar, consulta "Cómo interpretar la diferencia de caja (sobrante y faltante)". Para revisar otros cortes, usa "Cómo consultar el historial de cortes de caja".</p>',
    90, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'cash-registers'
      AND title = 'Cómo corregir el contado físico de un corte cerrado'
);

-- ----------------------------------------------------------------------------
-- 10. Browse the cut history
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'cash-registers',
    'Cómo consultar el historial de cortes de caja',
    'Busca y ordena los turnos cerrados de tu sucursal, revisa folio, operador, fondo inicial y diferencia, y abre el detalle o el reporte de cada corte.',
    '<p>El Historial de cortes de caja concentra los turnos cerrados de tu sucursal. Desde ahí puedes auditar diferencias, abrir el detalle de un corte, reimprimirlo o comparar el desempeño de cada caja.</p>
<h3>Antes de empezar</h3>
<ul>
<li>Necesitas el permiso "Ver historial de cortes de caja (sesiones)" para entrar a la pantalla.</li>
<li>El historial muestra únicamente sesiones cerradas. Los turnos abiertos se consultan desde la ficha de la caja.</li>
<li>Solo verás cortes de tu sucursal.</li>
</ul>
<h3>Consultar los cortes paso a paso</h3>
<ol>
<li>Entra a Historial de cortes de caja.</li>
<li>Usa el buscador "Buscar por cajero o caja" para filtrar por nombre. La lista se actualiza al escribir.</li>
<li>Ordena con los encabezados de columna: folio, caja, fecha de cierre, operador, fondo inicial, dinero en caja esperado o diferencia.</li>
<li>Haz clic en una fila para abrir el detalle del corte, o usa el menú de tres puntos.</li>
<li>Ajusta las filas por página con el selector: 20, 50, 100 o 200 registros.</li>
</ol>
<h3>Qué significa cada columna</h3>
<ul>
<li><strong>Folio:</strong> número de la sesión. Sirve para identificarla en reportes y auditorías.</li>
<li><strong>Caja:</strong> terminal donde se hizo el turno.</li>
<li><strong>Fecha de cierre:</strong> fecha y hora en que se finalizó el corte.</li>
<li><strong>Operador:</strong> usuario que abrió la sesión.</li>
<li><strong>Fondo inicial:</strong> efectivo con el que arrancó el turno.</li>
<li><strong>Dinero en caja esperado:</strong> efectivo que el sistema calculó al cierre.</li>
<li><strong>Diferencia:</strong> resultado del arqueo. Positivo es sobrante y negativo es faltante.</li>
</ul>
<h3>Acciones disponibles</h3>
<ul>
<li><strong>Ver detalles:</strong> abre el corte con el resumen financiero, el desglose de ingresos, los movimientos y los saldos bancarios.</li>
<li><strong>Imprimir reporte:</strong> abre el reporte en una pestaña nueva, listo para imprimir o guardar en PDF.</li>
</ul>
<h3>Historial desde la ficha de la caja</h3>
<p>La ficha de cada caja incluye su propio "Historial de cortes de caja" con folio, quién abrió la sesión, fecha de cierre y diferencia. Es útil para revisar la evolución de una terminal específica sin salir de su ficha.</p>
<h3>Qué tener en cuenta</h3>
<ul>
<li>El buscador filtra por cajero o caja, no por monto ni por rango de fechas. Para análisis por periodo, usa el reporte financiero.</li>
<li>Un corte cerrado no cambia sus ventas ni sus movimientos; solo el contado físico puede corregirse desde el detalle.</li>
<li>Los cortes se conservan aunque la caja se desactive. Solo se pierden si se elimina una caja sin historial.</li>
<li>La diferencia mostrada en la lista es la del último guardado. Si corregiste el contado, la fila se actualiza al recargar.</li>
</ul>
<h3>Problemas frecuentes</h3>
<ul>
<li><strong>No aparece un corte reciente:</strong> confirma que la sesión esté cerrada y que pertenezca a tu sucursal. Recarga la pantalla.</li>
<li><strong>La búsqueda no encuentra al cajero:</strong> el operador registrado es quien abrió la sesión, no necesariamente quien hizo el corte.</li>
<li><strong>No puedes abrir el detalle:</strong> tu usuario no tiene el permiso de acceso al historial de sesiones.</li>
</ul>
<p>Para interpretar los montos del corte, consulta "Cómo interpretar la diferencia de caja (sobrante y faltante)". Para corregir un conteo, revisa "Cómo corregir el contado físico de un corte cerrado".</p>',
    100, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'cash-registers'
      AND title = 'Cómo consultar el historial de cortes de caja'
);

-- ----------------------------------------------------------------------------
-- 11. Print or save the cut report as PDF
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'cash-registers',
    'Cómo imprimir o guardar en PDF el reporte de un corte',
    'Abre el reporte de corte, revisa sus secciones y guárdalo en PDF desde el diálogo de impresión del navegador para tu archivo contable.',
    '<p>Cada corte cerrado tiene un reporte imprimible con el resumen financiero, los ingresos, los movimientos, los saldos bancarios, las ventas y las notas. Puedes imprimirlo en papel o guardarlo en PDF para tu archivo contable.</p>
<h3>Abrir el reporte</h3>
<ol>
<li>Entra a Historial de cortes de caja.</li>
<li>Localiza el corte y abre el menú de tres puntos de la fila.</li>
<li>Elige "Imprimir reporte". El reporte se abre en una pestaña nueva.</li>
</ol>
<p>También puedes abrir el detalle del corte y pulsar "Imprimir reporte" en la parte superior de la pantalla.</p>
<h3>Guardar en PDF o imprimir</h3>
<ol>
<li>En la vista del reporte pulsa "Imprimir / Guardar PDF".</li>
<li>Se abre el diálogo de impresión del navegador.</li>
<li>Para imprimir en papel, elige la impresora y pulsa "Imprimir".</li>
<li>Para guardar en PDF, selecciona "Guardar como PDF" o "Microsoft Print to PDF" en la lista de destinos y pulsa "Guardar".</li>
<li>Elige la carpeta y el nombre del archivo, por ejemplo Corte 145 - Caja 1 - 2026-10-17.</li>
</ol>
<h3>Qué contiene el reporte</h3>
<ul>
<li>Encabezado con el nombre comercial, la sucursal, el título "Reporte de Corte de Caja", el número de sesión, la caja y quién abrió la sesión.</li>
<li>Lista de participantes de la sesión.</li>
<li>"Resumen financiero": apertura y cierre, fondo inicial, ventas en efectivo, otros ingresos, egresos y retiros, total esperado, total contado y diferencia.</li>
<li>"Desglose de Ingresos" agrupado por método de pago, con el total de ingresos.</li>
<li>"Movimientos de Efectivo" con cada ingreso y retiro.</li>
<li>"Resumen de Cuentas Bancarias" con saldo inicial y final por cuenta.</li>
<li>"Ventas de la Sesión" con el detalle de las operaciones.</li>
<li>"Notas del Corte", si se capturaron al cerrar el turno.</li>
</ul>
<h3>Qué tener en cuenta</h3>
<ul>
<li>El reporte se genera con los datos actuales del corte. Si corregiste el contado físico, la reimpresión mostrará el valor corregido.</li>
<li>El botón de impresión no aparece en el papel: está oculto en la versión impresa.</li>
<li>Para que se vean los colores de la diferencia y de los encabezados, activa la opción de gráficos de fondo en el diálogo de impresión.</li>
<li>Guarda el PDF con el folio del corte en el nombre para facilitar la búsqueda posterior.</li>
<li>El reporte solo está disponible para cortes cerrados. Los turnos abiertos se consultan en la ficha de la caja.</li>
</ul>
<h3>Problemas frecuentes</h3>
<ul>
<li><strong>No se abre la pestaña:</strong> el navegador bloqueó la ventana emergente. Permite las ventanas emergentes para este sitio e inténtalo de nuevo.</li>
<li><strong>La página aparece en blanco:</strong> recarga el reporte. Si persiste, verifica tu conexión y vuelve a abrirlo desde el historial.</li>
<li><strong>El PDF sale cortado:</strong> ajusta la escala en el diálogo de impresión o desactiva los encabezados y pies de página del navegador.</li>
<li><strong>No aparece "Imprimir reporte":</strong> tu usuario no tiene el permiso de acceso al historial de cortes.</li>
</ul>
<p>Para entender los montos del reporte, consulta "Cómo interpretar la diferencia de caja (sobrante y faltante)". Para ubicar un corte por folio o cajero, revisa "Cómo consultar el historial de cortes de caja".</p>',
    110, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'cash-registers'
      AND title = 'Cómo imprimir o guardar en PDF el reporte de un corte'
);

-- ----------------------------------------------------------------------------
-- 12. Recover a register that is still open
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'cash-registers',
    'Qué hacer si una caja quedó con la sesión abierta',
    'Si la caja sigue en "Sesión abierta" y no puedes iniciar turno, únete a la sesión o ciérrala correctamente. Aquí tienes las salidas y sus límites.',
    '<p>Una sesión sigue abierta hasta que se hace el corte total. Si alguien cerró el navegador, cambió de equipo o se fue sin cerrar, la caja queda marcada como "Sesión abierta" y el botón "Iniciar turno" desaparece. Esta guía explica cómo recuperar la operación.</p>
<h3>Cómo confirmar el problema</h3>
<ul>
<li>En Cajas registradoras, la columna Estado de sesión muestra "Sesión abierta".</li>
<li>Al abrir la ficha de la caja no aparece "Iniciar turno", pero sí los avatares de los usuarios conectados.</li>
<li>En el punto de venta puede aparecer la leyenda "Terminal bloqueada" o la opción de unirse a una sesión activa.</li>
</ul>
<h3>Si la sesión la dejaste abierta tú</h3>
<ol>
<li>Entra a la ficha de la caja desde Cajas registradoras.</li>
<li>Si tu usuario ya no figura entre los participantes, pulsa "Unirse".</li>
<li>Cuando estés dentro, pulsa "Cerrar caja" y completa el arqueo con "Finalizar turno".</li>
</ol>
<h3>Si la sesión la abrió otra persona</h3>
<ol>
<li>Habla con esa persona para confirmar si el turno sigue en operación.</li>
<li>Si necesitas cobrar, pulsa "Unirse" en la ficha de la caja. Trabajarás sobre el mismo turno y el mismo arqueo.</li>
<li>Si el turno debe terminar, cualquier integrante puede ejecutar "Corte total de caja" con el conteo físico. Avisa al resto antes de confirmarlo.</li>
</ol>
<h3>Si el sistema dice que ya tienes una sesión activa</h3>
<p>El aviso "Ya tienes una sesión activa" aparece cuando tu usuario ya está dentro de otro turno. No se puede abrir una segunda sesión al mismo tiempo.</p>
<ol>
<li>Revisa el listado de cajas y busca la que tenga "Sesión abierta" con tu usuario dentro.</li>
<li>Entra a esa ficha y cierra el turno o usa "Desconexión personal" si el resto del equipo continuará.</li>
<li>Después podrás abrir un turno nuevo en la caja que necesites.</li>
</ol>
<h3>Qué tener en cuenta</h3>
<ul>
<li>No existe un botón de administración para cerrar el turno de otra persona. El cierre solo lo puede ejecutar un usuario que forme parte de la sesión, por eso unirse es el camino habitual.</li>
<li>No elimines la caja para intentar liberarla: el sistema lo impide cuando existe historial de sesiones.</li>
<li>Una caja inactiva no se ofrece al iniciar turno desde el punto de venta. Si la terminal no aparece, revisa su "Estatus de servicio" en el listado.</li>
<li>Si el punto de venta muestra "No hay cajas disponibles para operar en esta sucursal", crea una caja o reactiva una existente, y confirma que no tenga una sesión abierta.</li>
<li>Para no repetir el problema, cierra el turno con "Finalizar turno" o sal con "Desconexión personal" antes de abandonar la caja. Cerrar el navegador no cierra la sesión.</li>
</ul>
<h3>Problemas frecuentes</h3>
<ul>
<li><strong>La sesión no tiene usuarios visibles:</strong> abre la ficha y pulsa "Unirse". Si el sistema no te permite, inicia el turno desde el punto de venta con "Inicializar caja" solo si la caja aparece disponible.</li>
<li><strong>No puedo unirme:</strong> verifica que la sesión siga abierta y que tengas acceso al módulo de Cajas. Si otra persona acaba de cerrarla, recarga el listado.</li>
<li><strong>La caja no aparece en el punto de venta:</strong> está inactiva o tiene una sesión abierta. Revisa el listado de Cajas antes de crear una nueva.</li>
</ul>
<p>Para el cierre correcto, consulta "Cómo cerrar el turno y hacer el arqueo de caja". Para el trabajo en equipo, revisa "Cómo unirse a una sesión de caja y trabajar con varios cajeros".</p>',
    120, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'cash-registers'
      AND title = 'Qué hacer si una caja quedó con la sesión abierta'
);
