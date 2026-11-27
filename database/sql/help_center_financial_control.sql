-- ============================================================================
-- Help center - Guides for the Reporte financiero (financial-control) module
--
-- Inserts 10 published guides for the "financial-control" module of the
-- help_center_articles table. The guides automatically appear in the
-- "Reporte financiero" card of the help center.
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
-- 1. How to read the financial report
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'financial-control',
    'Cómo leer tu reporte financiero: guía rápida',
    'Conoce el orden de lectura del reporte financiero: periodo, comparación, tarjetas, gráfica, paneles de desglose, detalles y exportación. Incluye una rutina rápida para revisar el día o el mes.',
    '<p>La pantalla Control financiero, que aparece en el menú como Reporte financiero, reúne las ventas, los pagos, los gastos y los indicadores de rentabilidad de la sucursal en la que tienes sesión. Úsala para revisar el día, cerrar la semana o preparar el cierre del mes.</p>
<h3>Antes de empezar</h3>
<ul>
<li>Todos los montos corresponden a tu sucursal. Si trabajas en varias, cambia de sucursal para consultar otra.</li>
<li>El acceso al reporte depende del permiso "Acceder a reportes financieros". Si no ves la opción en el menú, solicita el permiso a un administrador.</li>
<li>El periodo y la comparación se muestran debajo del título. Confírmalos antes de interpretar las cifras.</li>
</ul>
<h3>Orden de lectura recomendado</h3>
<ol>
<li><strong>Periodo y comparación:</strong> revisa las fechas del periodo actual y del periodo contra el que se compara.</li>
<li><strong>Tarjetas:</strong> ventas totales, total de pagos, total de gastos, ganancia neta, flujo de dinero neto, ticket promedio y margen de utilidad. Cada tarjeta muestra el monto actual, la variación y el valor del periodo anterior.</li>
<li><strong>Gráfica de operaciones:</strong> compara la evolución de ventas, pagos, gastos del negocio y flujo del negocio dentro del periodo.</li>
<li><strong>Paneles de desglose:</strong> métodos de pago, gastos por categoría y cuentas bancarias.</li>
<li><strong>Detalles:</strong> haz clic en las tarjetas de ventas, pagos o gastos para abrir el detalle. Los totales de los paneles se calculan con todos los registros del periodo; las tablas muestran hasta 1,000 movimientos.</li>
<li><strong>Exportación:</strong> usa "Exportar reporte" para descargar el Excel con el detalle completo y las hojas por canal.</li>
</ol>
<h3>Rutina rápida de 5 minutos</h3>
<ol>
<li>Selecciona el periodo que quieres revisar.</li>
<li>Lee las tarjetas y detecta cambios grandes contra el periodo anterior.</li>
<li>Abre el detalle de pagos y de gastos si alguna cifra llama tu atención.</li>
<li>Revisa la gráfica para ubicar el día o la semana que explica el cambio.</li>
<li>Exporta el reporte si necesitas compartirlo o conciliarlo con banco y caja.</li>
</ol>
<h3>Qué tener en cuenta</h3>
<ul>
<li>Las ventas se miden por la fecha de la venta; los pagos, por la fecha del pago; los gastos, por la fecha del gasto.</li>
<li>Una venta a crédito suma en ventas, pero no en pagos hasta que se cobra.</li>
<li>Los gastos pagados con dinero externo se muestran aparte y no afectan el flujo del negocio.</li>
<li>Los pagos con saldo a favor e intercambio liquidan ventas, pero no representan efectivo nuevo.</li>
<li>Las ventas canceladas, reembolsadas y cambiadas no se incluyen en los totales.</li>
</ul>
<h3>Si una cifra no coincide</h3>
<p>Revisa la guía "Qué hacer si un monto no cuadra". Ahí encontrarás el orden de revisión y las causas más comunes.</p>',
    10, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'financial-control'
      AND title = 'Cómo leer tu reporte financiero: guía rápida'
);

-- ----------------------------------------------------------------------------
-- 2. What each indicator means
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'financial-control',
    'Qué significa cada indicador del reporte',
    'Glosario práctico de ventas totales, pagos, gastos, ganancia neta, flujo de dinero, ticket promedio y margen de utilidad. Incluye las fórmulas y qué registros entran en cada cálculo.',
    '<p>Cada tarjeta del reporte mide una cosa distinta. Esta guía explica qué incluye cada indicador y cómo se calcula, para que puedas comparar sin confundir ventas con cobros ni gastos del negocio con gastos personales.</p>
<h3>Ventas totales</h3>
<ul>
<li>Es la suma de subtotal menos descuentos más impuestos de las ventas del periodo.</li>
<li>Se agrupa por la fecha de la venta.</li>
<li>Incluye ventas a crédito, apartados y ventas con pagos pendientes.</li>
<li>No incluye ventas canceladas, reembolsadas ni cambiadas.</li>
</ul>
<h3>Total de pagos</h3>
<ul>
<li>Es la suma de los pagos completados de ventas válidas del periodo.</li>
<li>Se agrupa por la fecha del pago, no por la fecha de la venta.</li>
<li>Incluye efectivo, tarjeta, transferencia, saldo a favor e intercambio.</li>
<li>No incluye pagos fallidos, pagos en proceso ni pagos de ventas canceladas, reembolsadas o cambiadas.</li>
</ul>
<h3>Total de gastos</h3>
<ul>
<li>Es la suma de los gastos pagados con dinero del negocio: caja o cuentas bancarias.</li>
<li>Se agrupa por la fecha del gasto.</li>
<li>No incluye gastos pendientes de pago.</li>
<li>Los gastos pagados con dinero externo se muestran aparte, en la línea con el signo + y en el detalle de gastos.</li>
</ul>
<h3>Ganancia neta</h3>
<ul>
<li><strong>Número grande:</strong> ventas totales menos gastos del negocio.</li>
<li><strong>Línea total:</strong> ventas totales menos todos los gastos, incluidos los externos.</li>
<li>Se basa en las ventas, no en los pagos. Una venta a crédito cuenta aquí aunque todavía no se haya cobrado.</li>
</ul>
<h3>Flujo de dinero neto</h3>
<ul>
<li><strong>Número grande:</strong> total de pagos menos gastos del negocio.</li>
<li><strong>Línea total:</strong> total de pagos menos todos los gastos, incluidos los externos.</li>
<li>Se basa en los pagos, no en las ventas. Una venta a crédito no suma aquí hasta que se cobra.</li>
<li>Los pagos con saldo a favor e intercambio liquidan ventas, pero no representan efectivo nuevo; tómalo en cuenta al comparar con banco y caja.</li>
</ul>
<h3>Ticket promedio</h3>
<ul>
<li>Es el resultado de dividir las ventas totales entre el número de ventas válidas del periodo.</li>
<li>No usa pagos ni gastos.</li>
<li>Las ventas canceladas, reembolsadas y cambiadas no se cuentan.</li>
<li>Sirve para medir el monto promedio por venta, no por cliente.</li>
</ul>
<h3>Margen de utilidad</h3>
<ul>
<li><strong>Número grande:</strong> ganancia del negocio dividida entre ventas totales, multiplicada por 100.</li>
<li><strong>Línea total:</strong> ganancia total, que incluye gastos externos, dividida entre ventas totales, multiplicada por 100.</li>
<li>Si las ventas son cero, el margen se muestra en cero.</li>
</ul>
<h3>Cómo leer las variaciones</h3>
<ul>
<li>El monto y el porcentaje de cada tarjeta comparan contra el periodo anterior que se indica en la cabecera.</li>
<li>El color verde indica una variación favorable y el rojo una variación desfavorable. En gastos, una disminución se muestra en verde.</li>
<li>Si el periodo anterior es cero, el porcentaje puede mostrar 100% cuando hay monto actual. Revisa siempre el monto y el contexto, no solo el porcentaje.</li>
<li>La comparación exacta depende del selector de periodo. Consulta la guía "Cómo cambiar el periodo y comparar resultados".</li>
</ul>
<h3>Relación entre indicadores</h3>
<ul>
<li>Ventas y pagos pueden diferir por ventas a crédito, abonos y pagos de ventas de periodos anteriores.</li>
<li>Ganancia y flujo pueden diferir porque la primera usa ventas y el segundo usa pagos.</li>
<li>El margen del negocio y el margen total pueden diferir cuando hay gastos externos.</li>
<li>El total de gastos y los gastos externos se complementan: juntos forman el gasto total del propietario.</li>
</ul>',
    20, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'financial-control'
      AND title = 'Qué significa cada indicador del reporte'
);

-- ----------------------------------------------------------------------------
-- 3. How to change the period and compare results
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'financial-control',
    'Cómo cambiar el periodo y comparar resultados',
    'Aprende a usar Día, Semana, Mes, Año y Personalizado, y contra qué periodo se compara cada opción. Incluye recomendaciones para comparar resultados sin sacar conclusiones equivocadas.',
    '<p>El selector de periodo cambia todos los datos del reporte. La comparación también cambia: no siempre es el mes anterior; depende del rango que elijas.</p>
<h3>Opciones del selector</h3>
<ul>
<li><strong>Día:</strong> hoy. La comparación es contra el día anterior.</li>
<li><strong>Semana:</strong> de lunes a domingo de la semana en curso. La comparación es contra la semana anterior.</li>
<li><strong>Mes:</strong> del primer al último día del mes en curso. La comparación es contra el mes anterior completo.</li>
<li><strong>Año:</strong> del 1 de enero al 31 de diciembre del año en curso. La comparación es contra el año anterior.</li>
<li><strong>Personalizado:</strong> tú eliges la fecha inicial y la final. La comparación es contra un periodo de la misma duración inmediatamente anterior.</li>
</ul>
<h3>Contra qué periodo se compara</h3>
<ul>
<li>La cabecera muestra el periodo actual y el periodo de comparación con fechas exactas.</li>
<li>Las tarjetas indican el valor anterior y la etiqueta del periodo comparado, por ejemplo "mes anterior" o "periodo anterior".</li>
<li>En un rango personalizado, si eliges del 10 al 14 de octubre, la comparación es del 5 al 9 de octubre: cinco días inmediatamente antes.</li>
<li>En un rango personalizado, si eliges del 1 al 15 de octubre, la comparación es del 16 al 30 de septiembre. No es el mes completo de septiembre.</li>
</ul>
<h3>Cómo elegir el periodo adecuado</h3>
<ul>
<li><strong>Cierre del día:</strong> usa Día. Compara contra ayer para detectar cambios de último momento.</li>
<li><strong>Revisión semanal:</strong> usa Semana. Compara contra la semana anterior y revisa el detalle de pagos y gastos.</li>
<li><strong>Cierre mensual:</strong> usa Mes. Compara contra el mes anterior completo para ver la tendencia.</li>
<li><strong>Planeación anual:</strong> usa Año. Compara contra el año anterior y revisa el margen y el flujo.</li>
<li><strong>Campañas o periodos irregulares:</strong> usa Personalizado. Procura que el periodo de comparación tenga la misma duración.</li>
</ul>
<h3>Periodos parciales</h3>
<ul>
<li>Las opciones Día, Semana, Mes y Año usan el periodo calendario completo. Los días futuros no tienen datos, así que no afectan los montos.</li>
<li>Si quieres comparar el mes en curso contra el mismo tramo del mes anterior, usa Personalizado. Por ejemplo, del 1 al 15 de octubre contra el 1 al 15 de septiembre.</li>
<li>No compares un periodo parcial contra un periodo completo: la diferencia puede deberse al tiempo y no al desempeño.</li>
</ul>
<h3>Buenas prácticas de comparación</h3>
<ul>
<li>Compara periodos de la misma duración y, si es posible, del mismo día de la semana.</li>
<li>Ten en cuenta promociones, días festivos, quincenas y temporadas altas.</li>
<li>Revisa el monto además del porcentaje. Un porcentaje alto sobre una base pequeña puede exagerar el cambio.</li>
<li>Si el periodo anterior tuvo cero ventas o cero gastos, el porcentaje puede no ser representativo. Revisa el contexto.</li>
<li>Para tendencias de varios meses, exporta el reporte y compara los totales mensuales en Excel.</li>
</ul>
<h3>Ejemplo</h3>
<p>Si estás revisando la semana del 12 al 18 de octubre, la comparación es la semana del 5 al 11 de octubre. Si una tarjeta muestra un aumento de 20%, significa que el valor actual es 20% mayor que el de la semana anterior. Antes de concluir que el negocio creció, revisa si hubo una promoción, un día festivo o una venta grande en una sola fecha.</p>',
    30, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'financial-control'
      AND title = 'Cómo cambiar el periodo y comparar resultados'
);

-- ----------------------------------------------------------------------------
-- 4. Why sales, payments and expenses do not match
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'financial-control',
    'Por qué las ventas, los pagos y los gastos no coinciden',
    'Ventas, pagos y gastos se miden con fechas y criterios distintos. Conoce las causas más comunes de diferencia y cómo revisarlas con el detalle y el Excel del reporte.',
    '<p>Es normal que las ventas, los pagos y los gastos no coincidan. Cada indicador responde a una pregunta diferente y se calcula con una fecha diferente. Esta guía explica las diferencias más comunes para que puedas revisarlas sin confundirlas con errores.</p>
<h3>Tres fechas distintas</h3>
<ul>
<li><strong>Ventas:</strong> se agrupan por la fecha de la venta.</li>
<li><strong>Pagos:</strong> se agrupan por la fecha del pago.</li>
<li><strong>Gastos:</strong> se agrupan por la fecha del gasto.</li>
</ul>
<p>Una venta puede ocurrir en un periodo y cobrarse en otro. Un abono puede corresponder a una venta de la semana pasada. Un gasto puede registrarse hoy y pagarse mañana.</p>
<h3>Causas comunes de diferencia</h3>
<ol>
<li><strong>Ventas a crédito:</strong> la venta suma en ventas totales, pero no en pagos hasta que el cliente paga.</li>
<li><strong>Abonos de ventas anteriores:</strong> el pago aparece en el periodo en que se cobra, aunque la venta sea de otro periodo.</li>
<li><strong>Saldo a favor e intercambio:</strong> son pagos válidos que liquidan ventas, pero no representan efectivo nuevo. Aumentan el total de pagos sin aumentar el dinero disponible en banco o caja.</li>
<li><strong>Ventas canceladas, reembolsadas o cambiadas:</strong> no se incluyen en ventas ni en pagos. Si una venta cambió de estado, puede desaparecer de los totales.</li>
<li><strong>Gastos externos:</strong> se pagaron con dinero personal y se muestran aparte. No afectan el flujo del negocio, pero sí la ganancia total.</li>
<li><strong>Gastos pendientes:</strong> solo se incluyen los gastos pagados. Un gasto pendiente empieza a contar cuando se marca como pagado.</li>
<li><strong>Detalle limitado a 1,000 movimientos:</strong> las tablas de detalle muestran hasta 1,000 registros, pero los totales se calculan con todos los datos del periodo.</li>
</ol>
<h3>Ejemplo con números</h3>
<p>Supón que en el periodo vendiste $10,000. De esa cantidad, $2,000 fueron a crédito y $1,000 se pagaron con saldo a favor. El resto, $7,000, se pagó con efectivo, tarjeta o transferencia.</p>
<ul>
<li><strong>Ventas totales:</strong> $10,000.</li>
<li><strong>Total de pagos:</strong> $8,000, formados por $7,000 de efectivo, tarjeta o transferencia y $1,000 de saldo a favor. Los $2,000 a crédito todavía no se cobran.</li>
<li><strong>Gastos del negocio:</strong> $3,000.</li>
<li><strong>Gastos externos:</strong> $500.</li>
<li><strong>Ganancia del negocio:</strong> $10,000 menos $3,000 = $7,000.</li>
<li><strong>Ganancia total:</strong> $10,000 menos $3,500 = $6,500.</li>
<li><strong>Flujo del negocio:</strong> $8,000 menos $3,000 = $5,000.</li>
<li><strong>Flujo total:</strong> $8,000 menos $3,500 = $4,500.</li>
</ul>
<p>Ninguna cifra es incorrecta: cada una responde a una pregunta distinta. Si quieres saber si el negocio es rentable, mira la ganancia. Si quieres saber si hay dinero disponible, mira el flujo.</p>
<h3>Cómo revisar una diferencia</h3>
<ol>
<li>Confirma la sucursal y el periodo seleccionado.</li>
<li>Compara ventas contra pagos. Si las ventas son mayores, revisa las ventas a crédito y los saldos pendientes de clientes.</li>
<li>Revisa el detalle de pagos y verifica si hay abonos de ventas anteriores o pagos con saldo a favor e intercambio.</li>
<li>Abre el detalle de gastos y separa los gastos del negocio de los externos.</li>
<li>Si el detalle de la tabla no incluye todos los registros, exporta el reporte a Excel para revisar el periodo completo.</li>
<li>Si la diferencia sigue sin explicación, revisa la guía "Qué hacer si un monto no cuadra".</li>
</ol>
<h3>Qué no debe hacer</h3>
<ul>
<li>No sumes ventas y pagos como si fueran lo mismo.</li>
<li>No restes gastos externos del flujo del negocio si quieres medir el dinero de la operación.</li>
<li>No compares un periodo parcial contra un periodo completo.</li>
<li>No concluyas que hay un error solo porque dos tarjetas muestran montos distintos.</li>
</ul>',
    40, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'financial-control'
      AND title = 'Por qué las ventas, los pagos y los gastos no coinciden'
);

-- ----------------------------------------------------------------------------
-- 5. How to read the operations chart
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'financial-control',
    'Cómo interpretar la gráfica de operaciones',
    'La gráfica compara ventas, pagos, gastos del negocio y flujo del negocio. Conoce qué serie usa cada fecha, cómo se agrupan las barras y por qué los gastos externos no aparecen.',
    '<p>La gráfica "Resumen comparativo de operaciones" muestra cuatro series en el mismo eje de tiempo. Sirve para ubicar cuándo ocurrió un cambio, no solo cuánto cambió el total del periodo.</p>
<h3>Las cuatro series</h3>
<ul>
<li><strong>Ventas totales:</strong> monto vendido en cada intervalo, agrupado por la fecha de la venta. No incluye ventas canceladas, reembolsadas ni cambiadas.</li>
<li><strong>Total de pagos:</strong> pagos completados de ventas válidas, agrupados por la fecha del pago. Incluye saldo a favor e intercambio.</li>
<li><strong>Gastos del negocio:</strong> gastos pagados con dinero del negocio, agrupados por la fecha del gasto. No incluye gastos externos.</li>
<li><strong>Flujo del negocio:</strong> pagos menos gastos del negocio en cada intervalo. Puede ser negativo si los gastos superan los pagos.</li>
</ul>
<h3>Cómo se agrupan las barras</h3>
<ul>
<li><strong>Un día:</strong> la gráfica muestra las horas en orden operativo, de 5:00 a 23:00 y luego de 0:00 a 4:00.</li>
<li><strong>Una semana:</strong> muestra los días de la semana, de lunes a domingo.</li>
<li><strong>Un mes:</strong> muestra las semanas del periodo con su rango de fechas.</li>
<li><strong>Un año:</strong> muestra los meses del año.</li>
<li><strong>Un rango personalizado:</strong> el agrupamiento se adapta a la duración. Los periodos cortos usan horas o días; los largos, semanas o meses.</li>
</ul>
<h3>Qué mirar primero</h3>
<ol>
<li>Compara la barra de ventas con la de pagos. Si las ventas son mucho mayores, puede haber ventas a crédito o cobros pendientes.</li>
<li>Revisa si los pagos se concentran en un día distinto al de las ventas. Eso explica diferencias entre el total de ventas y el total de pagos.</li>
<li>Busca picos de gastos del negocio. Si un gasto grande cae en un solo día, el flujo de ese día puede volverse negativo.</li>
<li>Observa la tendencia general, no solo una barra. Un día atípico puede distorsionar la lectura del periodo.</li>
<li>Si necesitas el detalle de un pico, abre el modal de ventas, pagos o gastos y ordena por fecha o monto.</li>
</ol>
<h3>Ejemplo</h3>
<p>Si en una semana las ventas se concentran el lunes y los pagos aparecen el viernes, es probable que varias ventas se hayan cobrado después. La ganancia de la semana puede ser positiva, pero el flujo del lunes puede ser bajo o negativo. Ninguna de las dos lecturas es incorrecta: una mide rentabilidad y la otra mide liquidez.</p>
<h3>Por qué los gastos externos no aparecen</h3>
<p>La serie de gastos y el flujo usan solo gastos pagados con dinero del negocio. Los gastos externos se pagaron con dinero personal y se muestran en la tarjeta de gastos y en el detalle de gastos. Si quieres ver el efecto completo del propietario, revisa la línea total de ganancia y flujo, y el panel de gastos externos.</p>
<h3>Buenas prácticas</h3>
<ul>
<li>Usa la gráfica junto con las tarjetas, no en lugar de ellas. Las tarjetas dan el total; la gráfica muestra el comportamiento.</li>
<li>Recuerda que ventas, pagos y gastos usan fechas distintas.</li>
<li>Los intervalos sin datos aparecen en cero; eso no significa que el negocio haya dejado de operar.</li>
<li>Si el periodo es muy largo, la gráfica agrupa la información. Para el detalle exacto, exporta el reporte.</li>
</ul>',
    50, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'financial-control'
      AND title = 'Cómo interpretar la gráfica de operaciones'
);

-- ----------------------------------------------------------------------------
-- 6. How to review sales, payments and expenses detail
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'financial-control',
    'Cómo revisar el detalle de ventas, pagos y gastos',
    'Aprende a abrir y usar los detalles del reporte, qué incluye cada tabla, cómo se calculan los totales de los paneles y cuándo conviene exportar el periodo completo.',
    '<p>Las tarjetas del reporte abren el detalle de ventas, pagos o gastos. Estas tablas sirven para revisar movimiento por movimiento, detectar diferencias y preparar la conciliación.</p>
<h3>Cómo abrir cada detalle</h3>
<ul>
<li>Haz clic en la tarjeta <strong>Ventas totales</strong> para abrir el detalle de ventas del periodo.</li>
<li>Haz clic en la tarjeta <strong>Total de pagos</strong> para abrir el detalle de pagos recibidos.</li>
<li>Haz clic en la tarjeta <strong>Total de gastos</strong> para abrir el detalle de gastos totales.</li>
<li>En las tablas puedes ordenar por las columnas principales y cambiar de página. Pasa el cursor sobre los íconos para ver información adicional.</li>
</ul>
<h3>Detalle de ventas</h3>
<ul>
<li>Muestra folio, fecha, cliente, canal, total y estado de cada venta.</li>
<li>Solo incluye ventas válidas: no aparecen canceladas, reembolsadas ni cambiadas.</li>
<li>El total de la venta es subtotal menos descuento más impuestos.</li>
<li>La tabla muestra hasta 1,000 ventas. Si el periodo tiene más, exporta el reporte para ver todo el detalle.</li>
</ul>
<h3>Detalle de pagos</h3>
<ul>
<li>Arriba se muestran los totales por método de pago: efectivo, tarjeta, transferencia, saldo a favor e intercambio.</li>
<li>Esos totales se calculan con todos los pagos completados del periodo, no solo con las filas visibles.</li>
<li>La tabla muestra fecha, folio de la venta, cliente, método, cuenta bancaria cuando aplica y monto.</li>
<li>Incluye pagos con saldo a favor e intercambio, porque son pagos válidos que liquidan ventas.</li>
<li>No incluye pagos de ventas canceladas, reembolsadas o cambiadas, ni pagos fallidos o en proceso.</li>
<li>La tabla muestra hasta 1,000 movimientos. Los totales por método pueden incluir más registros que los visibles.</li>
</ul>
<h3>Detalle de gastos</h3>
<ul>
<li>Arriba se muestran dos paneles: gastos pagados con dinero del negocio y gastos pagados con dinero externo.</li>
<li>Cada panel desglosa los gastos por método de pago.</li>
<li>Los totales de los paneles se calculan con todos los gastos pagados del periodo, no solo con las filas visibles.</li>
<li>La tabla muestra folio, fecha, categoría, descripción, método, origen del dinero y monto.</li>
<li>La columna Origen indica si el gasto salió de caja o cuenta del negocio, o si fue un gasto externo.</li>
<li>Solo aparecen gastos pagados. Los gastos pendientes no se incluyen.</li>
<li>La tabla muestra hasta 1,000 gastos.</li>
</ul>
<h3>Cómo usar los detalles para revisar</h3>
<ol>
<li>Ordena por fecha para ubicar el día que explica un cambio en la gráfica.</li>
<li>Ordena por monto para encontrar movimientos grandes o atípicos.</li>
<li>En pagos, revisa si el método coincide con el movimiento de banco o caja.</li>
<li>En gastos, separa los del negocio de los externos antes de comparar con tus cuentas.</li>
<li>Si la tabla muestra el aviso de 1,000 registros, exporta el reporte para revisar el detalle completo.</li>
</ol>
<h3>Cuándo exportar en lugar de revisar en pantalla</h3>
<ul>
<li>Cuando el periodo tiene más de 1,000 ventas, pagos o gastos.</li>
<li>Cuando necesitas compartir el detalle con otra persona.</li>
<li>Cuando vas a conciliar con estados de cuenta bancarios o cortes de caja.</li>
<li>Cuando necesitas filtrar, ordenar o hacer cálculos propios en Excel.</li>
</ul>
<h3>Importante</h3>
<p>Los totales de los paneles y de las tarjetas se calculan con todos los datos del periodo. Las tablas de detalle tienen un límite de 1,000 filas por rendimiento. Por eso, la suma de las filas visibles puede ser menor que el total del panel. No es un error: usa la exportación para el detalle completo.</p>',
    60, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'financial-control'
      AND title = 'Cómo revisar el detalle de ventas, pagos y gastos'
);

-- ----------------------------------------------------------------------------
-- 7. How to export the financial report to Excel
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'financial-control',
    'Cómo exportar el reporte financiero a Excel',
    'Descarga el reporte con el resumen general, los pagos recibidos, las ventas por canal y el detalle de gastos. Conoce qué incluye cada hoja y cómo conciliarla con la pantalla.',
    '<p>El botón "Exportar reporte" descarga un archivo de Excel con la información del periodo seleccionado. La exportación no tiene el límite de 1,000 filas de las tablas de detalle, así que es la opción recomendada para revisar periodos largos o conciliar con banco y caja.</p>
<h3>Cómo exportar</h3>
<ol>
<li>Selecciona el periodo en el reporte: Día, Semana, Mes, Año o Personalizado.</li>
<li>Confirma las fechas en la cabecera.</li>
<li>Haz clic en "Exportar reporte".</li>
<li>Espera la descarga. El archivo se llama "Reporte financiero" seguido del rango de fechas.</li>
<li>Si cambias el periodo después de exportar, vuelve a generar el archivo para que las fechas coincidan.</li>
</ol>
<h3>Hojas del archivo</h3>
<ul>
<li><strong>Resumen general:</strong> totales del periodo por método de pago y por origen del dinero.</li>
<li><strong>Pagos recibidos:</strong> detalle de cada pago completado del periodo.</li>
<li><strong>Ventas por canal:</strong> una hoja por cada canal con ventas válidas en el periodo, por ejemplo Punto de venta, Tienda en línea u Orden de servicio.</li>
<li><strong>Detalle de gastos:</strong> todos los gastos pagados del periodo, con su origen.</li>
</ul>
<h3>Resumen general</h3>
<p>El resumen tiene las columnas Concepto, Efectivo, Tarjeta, Transferencia, Saldo, Intercambio y Total. Las filas son:</p>
<ul>
<li><strong>Ventas totales:</strong> monto vendido en el periodo. Solo se muestra en la columna Total, porque una venta puede tener varios métodos de pago.</li>
<li><strong>Pagos recibidos:</strong> pagos completados por método, incluidos saldo a favor e intercambio.</li>
<li><strong>Gastos del negocio:</strong> gastos pagados con dinero del negocio, por método.</li>
<li><strong>Gastos externos:</strong> gastos pagados con dinero personal, por método.</li>
<li><strong>Gastos totales:</strong> suma de gastos del negocio y externos.</li>
<li><strong>Ganancia del negocio:</strong> ventas totales menos gastos del negocio.</li>
<li><strong>Ganancia total:</strong> ventas totales menos todos los gastos, incluidos los externos.</li>
<li><strong>Flujo del negocio:</strong> pagos recibidos menos gastos del negocio.</li>
<li><strong>Flujo total:</strong> pagos recibidos menos todos los gastos.</li>
</ul>
<h3>Pagos recibidos</h3>
<ul>
<li>Incluye fecha de pago, folio de la venta asociada, fecha de la venta, cliente, método de pago, monto y tipo de pago.</li>
<li>Solo incluye pagos completados de ventas válidas.</li>
<li>Incluye saldo a favor e intercambio.</li>
<li>No incluye pagos de ventas canceladas, reembolsadas o cambiadas, ni pagos fallidos o en proceso.</li>
<li>Los pagos están ordenados del más reciente al más antiguo.</li>
</ul>
<h3>Ventas por canal</h3>
<ul>
<li>Se crea una hoja por cada canal con ventas válidas en el periodo.</li>
<li>Cada hoja incluye folio, fecha, cliente, vendedor, subtotal, descuento, impuestos, total de la venta, total pagado, pendiente, métodos de pago, conceptos y estado.</li>
<li>No incluye ventas canceladas, reembolsadas ni cambiadas.</li>
<li>El total pagado considera los pagos completados de la venta.</li>
<li>Si un canal no tiene ventas válidas en el periodo, no se crea su hoja.</li>
</ul>
<h3>Detalle de gastos</h3>
<ul>
<li>Incluye fecha, folio, categoría, descripción, monto, método de pago, origen y usuario que registró el gasto.</li>
<li>La columna Origen indica Negocio o Externo.</li>
<li>Solo incluye gastos pagados. Los pendientes no aparecen.</li>
<li>Los gastos externos se incluyen con Origen = Externo para que puedas separarlos del flujo del negocio.</li>
</ul>
<h3>Diferencias con la pantalla</h3>
<ul>
<li>La pantalla muestra hasta 1,000 filas en los detalles; el Excel incluye todos los registros del periodo.</li>
<li>El resumen general usa los mismos criterios que las tarjetas y la gráfica: ventas válidas, pagos completados y gastos pagados.</li>
<li>Los gastos externos aparecen separados en el resumen y marcados en el detalle.</li>
<li>Las columnas de métodos de pago del resumen pueden estar vacías en la fila de ventas totales porque la venta no tiene un método único.</li>
</ul>
<h3>Buenas prácticas</h3>
<ul>
<li>Usa el resumen general para revisar totales y el detalle para explicar diferencias.</li>
<li>Filtra por fecha, método u origen para conciliar con banco y caja.</li>
<li>Conserva el archivo con el rango de fechas en el nombre para identificarlo después.</li>
<li>No sumes la hoja de ventas y la de pagos como si fueran lo mismo: miden cosas distintas.</li>
<li>Si vas a compartir el archivo, revisa que no contenga datos de otra sucursal.</li>
</ul>',
    70, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'financial-control'
      AND title = 'Cómo exportar el reporte financiero a Excel'
);

-- ----------------------------------------------------------------------------
-- 8. How to reconcile cash, bank and external expenses
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'financial-control',
    'Cómo conciliar caja, banco y gastos externos',
    'Compara los pagos y gastos del reporte con tus cuentas reales. Aprende a separar caja, banco, saldo a favor y gastos externos para encontrar diferencias sin mezclar el dinero del negocio con el personal.',
    '<p>Conciliar es comparar lo que muestra el reporte con lo que realmente entró o salió de caja y de tus cuentas bancarias. El reporte separa el dinero del negocio del dinero externo, pero tú debes revisar que los movimientos coincidan.</p>
<h3>Antes de conciliar</h3>
<ul>
<li>Selecciona el mismo periodo que vas a revisar en banco o en el corte de caja.</li>
<li>Confirma que estás en la sucursal correcta.</li>
<li>Ten a la mano el estado de cuenta bancario, el corte de caja y los comprobantes de gastos.</li>
<li>Exporta el reporte a Excel para trabajar con el detalle completo.</li>
</ul>
<h3>Pagos por método</h3>
<ol>
<li>Abre el detalle de pagos y revisa los totales por método: efectivo, tarjeta, transferencia, saldo a favor e intercambio.</li>
<li>Compara <strong>efectivo</strong> con el corte de caja del periodo.</li>
<li>Compara <strong>tarjeta</strong> y <strong>transferencia</strong> con los abonos del estado de cuenta bancario.</li>
<li>No incluyas <strong>saldo a favor</strong> ni <strong>intercambio</strong> en la conciliación de caja o banco: son pagos válidos, pero no representan dinero nuevo.</li>
<li>Si un pago tiene cuenta bancaria asociada, verifica que sea la cuenta correcta.</li>
</ol>
<h3>Gastos del negocio</h3>
<ol>
<li>Abre el detalle de gastos y revisa el panel de gastos pagados con dinero del negocio.</li>
<li>Separa los gastos por método: efectivo, tarjeta y transferencia.</li>
<li>Compara los gastos en efectivo con las salidas de caja.</li>
<li>Compara los gastos con tarjeta o transferencia con los cargos del estado de cuenta.</li>
<li>Verifica que cada gasto esté marcado como pagado. Los pendientes no se incluyen en el reporte.</li>
</ol>
<h3>Gastos externos</h3>
<ul>
<li>Son gastos pagados con dinero personal, no con caja o cuentas del negocio.</li>
<li>Aparecen en el panel de gastos externos y en la columna Origen del detalle.</li>
<li>No los busques en el estado de cuenta del negocio ni en el corte de caja.</li>
<li>Si el propietario puso dinero para un gasto del negocio, regístralo como gasto externo para que no altere el flujo del negocio.</li>
<li>Si un gasto externo debe reembolsarse al propietario, gestiónalo fuera del reporte y documenta el acuerdo.</li>
</ul>
<h3>Ventas a crédito y abonos</h3>
<ul>
<li>Una venta a crédito suma en ventas totales, pero no en pagos hasta que el cliente paga.</li>
<li>Un abono puede aparecer en un periodo distinto al de la venta.</li>
<li>Si el total de pagos es mayor que el de ventas, revisa si hubo abonos de ventas anteriores.</li>
<li>Para revisar saldos pendientes, consulta el estado de cuenta del cliente en el módulo Clientes.</li>
</ul>
<h3>Cuentas bancarias</h3>
<p>El panel de cuentas bancarias muestra el saldo actual de las cuentas a las que tienes acceso y permite consultar el historial o hacer una transferencia entre cuentas del negocio. El saldo del panel no es el movimiento del periodo: úsalo para conocer el dinero disponible y usa el historial para revisar movimientos concretos.</p>
<h3>Diferencias comunes</h3>
<ul>
<li><strong>Días de corte distintos:</strong> un pago puede registrarse hoy y abonarse al banco mañana.</li>
<li><strong>Transferencias entre cuentas:</strong> mueven dinero entre cuentas del negocio y pueden aparecer como cargo y abono.</li>
<li><strong>Propinas o redondeos:</strong> revisa cómo se registraron para que coincidan con caja.</li>
<li><strong>Pagos con saldo a favor o intercambio:</strong> no generan entrada de efectivo.</li>
<li><strong>Ventas canceladas, reembolsadas o cambiadas:</strong> no se incluyen en los totales; revisa que el estado sea correcto.</li>
<li><strong>Gastos externos:</strong> no salen de las cuentas del negocio.</li>
</ul>
<h3>Orden recomendado</h3>
<ol>
<li>Confirma el periodo y la sucursal.</li>
<li>Concilia efectivo contra caja.</li>
<li>Concilia tarjeta y transferencia contra banco.</li>
<li>Concilia gastos del negocio contra caja y banco.</li>
<li>Revisa gastos externos por separado.</li>
<li>Documenta las diferencias y su causa antes de ajustar cualquier registro.</li>
</ol>',
    80, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'financial-control'
      AND title = 'Cómo conciliar caja, banco y gastos externos'
);

-- ----------------------------------------------------------------------------
-- 9. What to do if an amount does not match
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'financial-control',
    'Qué hacer si un monto no cuadra',
    'Checklist para revisar diferencias en el reporte: sucursal, periodo, ventas canceladas o reembolsadas, ventas a crédito, pagos con saldo, gastos externos y límite de 1,000 filas en los detalles.',
    '<p>Antes de reportar un error, revisa el orden de esta lista. La mayoría de las diferencias se explican por la fecha, el estado de la venta o el origen del dinero.</p>
<h3>1. Confirma la sucursal</h3>
<ul>
<li>El reporte usa la sucursal de tu sesión. Si trabajas en varias, cambia de sucursal y vuelve a revisar.</li>
<li>Compara los folios y clientes para asegurarte de que estás en la sucursal correcta.</li>
</ul>
<h3>2. Confirma el periodo y la comparación</h3>
<ul>
<li>Revisa las fechas de la cabecera. Un día, una semana o un mes pueden empezar y terminar en fechas distintas a las que tienes en mente.</li>
<li>Confirma contra qué periodo se está comparando. En Personalizado, la comparación es un periodo de la misma duración inmediatamente anterior.</li>
<li>Si cambiaste el selector, espera a que el reporte se actualice antes de comparar.</li>
</ul>
<h3>3. Revisa el estado de las ventas</h3>
<ul>
<li>Las ventas canceladas, reembolsadas y cambiadas no se incluyen en ventas totales ni en pagos.</li>
<li>Si una venta cambió de estado, puede desaparecer de los totales del periodo.</li>
<li>Abre el historial de ventas y confirma el estado actual.</li>
</ul>
<h3>4. Separa ventas de pagos</h3>
<ul>
<li>Una venta a crédito suma en ventas, pero no en pagos hasta que se cobra.</li>
<li>Un abono puede aparecer en un periodo distinto al de la venta.</li>
<li>Si los pagos son mayores que las ventas, revisa abonos de ventas anteriores.</li>
<li>Si las ventas son mayores que los pagos, revisa saldos pendientes de clientes.</li>
</ul>
<h3>5. Revisa saldo a favor e intercambio</h3>
<ul>
<li>Son pagos válidos que liquidan ventas, pero no representan dinero nuevo.</li>
<li>Aumentan el total de pagos y el flujo, pero no deben aparecer como abonos en banco.</li>
<li>Si estás conciliando caja o banco, sepáralos del efectivo, tarjeta y transferencia.</li>
</ul>
<h3>6. Revisa el origen de los gastos</h3>
<ul>
<li>El total de gastos de la tarjeta principal incluye solo gastos pagados con dinero del negocio.</li>
<li>Los gastos externos aparecen en la línea con el signo + y en el panel de gastos externos.</li>
<li>Los gastos pendientes no se incluyen. Si un gasto no aparece, confirma que esté marcado como pagado.</li>
<li>Confirma la fecha del gasto, no la fecha en que lo registraste.</li>
</ul>
<h3>7. Revisa el límite de 1,000 filas</h3>
<ul>
<li>Las tablas de detalle muestran hasta 1,000 ventas, pagos o gastos.</li>
<li>Los totales de las tarjetas y de los paneles se calculan con todos los datos del periodo.</li>
<li>Por eso, la suma de las filas visibles puede ser menor que el total del panel.</li>
<li>Exporta el reporte a Excel para revisar el detalle completo.</li>
</ul>
<h3>8. Revisa el Excel contra la pantalla</h3>
<ul>
<li>Confirma que el archivo se generó con el mismo periodo que estás viendo.</li>
<li>El Excel incluye todos los registros del periodo, no solo las primeras 1,000 filas.</li>
<li>El resumen general usa ventas válidas, pagos completados y gastos pagados.</li>
<li>Las hojas de ventas excluyen canceladas, reembolsadas y cambiadas.</li>
</ul>
<h3>Casos frecuentes</h3>
<ul>
<li><strong>El porcentaje muestra 100%:</strong> el periodo anterior no tenía monto. Revisa el monto actual y el contexto, no solo el porcentaje.</li>
<li><strong>La ganancia es positiva pero el flujo es negativo:</strong> hay ventas a crédito o gastos pagados que aún no se cobraron. La ganancia usa ventas; el flujo usa pagos.</li>
<li><strong>El margen del negocio es distinto al margen total:</strong> hay gastos externos. El margen total los incluye.</li>
<li><strong>Un pago no aparece en el detalle:</strong> puede ser un pago fallido o en proceso, de una venta cancelada, reembolsada o cambiada, o estar fuera del periodo por su fecha de pago.</li>
<li><strong>Un gasto no aparece en el detalle:</strong> puede estar pendiente, fuera del periodo por su fecha de gasto, o ser un gasto externo que está en el otro panel.</li>
<li><strong>El reporte está vacío:</strong> confirma que la sucursal tenga movimientos en el periodo y que tu usuario tenga acceso al reporte.</li>
<li><strong>No veo la opción en el menú:</strong> solicita el permiso "Acceder a reportes financieros" a un administrador.</li>
</ul>
<h3>Cuándo escalar</h3>
<p>Si después de revisar esta lista la diferencia continúa, reúne la sucursal, el periodo, el nombre del indicador, el monto que esperabas, el monto que muestra el reporte y una captura de pantalla del detalle o del Excel. Con esa información será más fácil identificar la causa.</p>',
    90, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'financial-control'
      AND title = 'Qué hacer si un monto no cuadra'
);

-- ----------------------------------------------------------------------------
-- 10. Weekly routine to review your finances
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'financial-control',
    'Rutina semanal para revisar tus finanzas',
    'Una rutina práctica para revisar el reporte todos los días, cerrar la semana y preparar el mes. Incluye checklist, indicadores clave y recomendaciones para dueños y administradores.',
    '<p>Revisar el reporte con una rutina fija evita sorpresas al cierre del mes. No necesitas revisar todo todos los días: elige un momento, sigue el mismo orden y anota las acciones.</p>
<h3>Revisión diaria de 5 minutos</h3>
<ol>
<li>Selecciona Día.</li>
<li>Revisa ventas totales, total de pagos y total de gastos.</li>
<li>Confirma que el efectivo del día coincida con el corte de caja.</li>
<li>Si hay una diferencia grande, abre el detalle de pagos o de gastos.</li>
<li>Anota cualquier movimiento inusual para revisarlo al cierre de la semana.</li>
</ol>
<h3>Revisión semanal</h3>
<ol>
<li>Selecciona Semana. La comparación será contra la semana anterior.</li>
<li>Revisa las tarjetas y anota las variaciones más importantes.</li>
<li>Compara ventas contra pagos. Si las ventas son mayores, revisa ventas a crédito y saldos pendientes.</li>
<li>Abre el detalle de pagos y revisa los totales por método. Compara efectivo con caja y tarjeta o transferencia con banco.</li>
<li>Abre el detalle de gastos y separa gastos del negocio de gastos externos.</li>
<li>Revisa la gráfica para ubicar el día que explica el cambio.</li>
<li>Exporta el reporte y guárdalo con la fecha de la semana.</li>
<li>Define una acción concreta para la siguiente semana.</li>
</ol>
<h3>Cierre mensual</h3>
<ol>
<li>Para cerrar el mes, usa Personalizado y selecciona el mes que acaba de terminar. El selector Mes siempre muestra el mes en curso.</li>
<li>Revisa ventas, pagos, gastos, ganancia, flujo, ticket promedio y margen.</li>
<li>La comparación automática será contra el periodo inmediatamente anterior. Si necesitas comparar contra el mismo mes del año pasado, revisa ese mes por separado.</li>
<li>Concilia banco y caja con el detalle de pagos y gastos.</li>
<li>Revisa gastos externos y define si deben reembolsarse o registrarse de otra forma.</li>
<li>Exporta el reporte y compártelo con contabilidad si aplica.</li>
<li>Revisa los precios, promociones y gastos recurrentes para el siguiente mes.</li>
</ol>
<h3>Checklist semanal</h3>
<ul>
<li>Periodo y sucursal confirmados.</li>
<li>Ventas y pagos revisados por separado.</li>
<li>Pagos por método comparados con caja y banco.</li>
<li>Gastos del negocio y externos revisados.</li>
<li>Gastos pendientes identificados.</li>
<li>Ventas canceladas, reembolsadas o cambiadas revisadas.</li>
<li>Reporte exportado y guardado.</li>
<li>Acciones de la semana definidas.</li>
</ul>
<h3>Indicadores clave</h3>
<ul>
<li><strong>Ventas totales:</strong> indica el volumen del negocio.</li>
<li><strong>Ganancia neta:</strong> indica si las ventas cubren los gastos del negocio.</li>
<li><strong>Flujo de dinero neto:</strong> indica si hay liquidez para operar.</li>
<li><strong>Ticket promedio:</strong> indica cuánto gasta un cliente por venta.</li>
<li><strong>Margen de utilidad:</strong> indica qué porcentaje de las ventas se convierte en ganancia.</li>
<li><strong>Gastos externos:</strong> indican cuánto dinero personal se está usando en el negocio.</li>
</ul>
<h3>Roles recomendados</h3>
<ul>
<li><strong>Dueño o socio:</strong> revisa la semana y el cierre mensual, aprueba acciones y revisa gastos externos.</li>
<li><strong>Administrador de sucursal:</strong> revisa el día, concilia caja y banco, y da seguimiento a pagos pendientes.</li>
<li><strong>Contabilidad:</strong> recibe el Excel del cierre mensual y revisa impuestos, gastos y documentos.</li>
</ul>
<h3>Buenas prácticas</h3>
<ul>
<li>Revisa el reporte en el mismo horario y con el mismo orden.</li>
<li>Anota las diferencias y su causa antes de corregir registros.</li>
<li>No mezcles dinero personal con dinero del negocio; usa gastos externos cuando corresponda.</li>
<li>No compares periodos de distinta duración.</li>
<li>Guarda una copia del Excel de cada cierre mensual.</li>
<li>Si detectas un patrón, revisa procesos: cobros, registro de gastos, cancelaciones o cambios.</li>
</ul>
<h3>Qué evitar</h3>
<ul>
<li>Revisar solo el total de ventas y ignorar pagos y gastos.</li>
<li>Tomar decisiones con un solo día de datos.</li>
<li>Asumir que una diferencia es un error sin revisar fechas y estados.</li>
<li>Dejar el cierre mensual para el último momento.</li>
</ul>',
    100, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM help_center_articles
    WHERE module = 'financial-control'
      AND title = 'Rutina semanal para revisar tus finanzas'
);
