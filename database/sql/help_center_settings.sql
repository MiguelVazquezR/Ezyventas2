-- ============================================================================
-- Help center - Guides for the Configuraciones (settings) module
--
-- Inserts 12 published guides for the "settings" module of the
-- help_center_articles table. They automatically appear in the
-- Configuraciones card of the help center.
--
-- Features:
--   - Idempotent: does not insert a guide when one with the same module and
--     title already exists, so the file can be run multiple times safely.
--   - Does not modify or delete existing records.
--   - Can be run locally and in production.
--
-- Usage:
--   php artisan tinker --execute="DB::unprepared(file_get_contents(database_path('sql/help_center_settings.sql')));"
--   or run the whole file with a MySQL or MariaDB client
--   (phpMyAdmin, HeidiSQL, TablePlus, DBeaver or command line).
--
-- The guides document the module as implemented in the application:
-- settings definitions, roles and permissions, users and print templates.
--
-- Encoding: UTF-8 (utf8mb4).
-- ============================================================================

SET NAMES utf8mb4;

-- ----------------------------------------------------------------------------
-- 1. How to navigate the settings module
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'settings',
    'Cómo navegar el módulo de configuraciones',
    'Conoce las cuatro secciones del módulo, cómo se agrupan las preferencias por pestañas y dónde guardar los cambios para que se apliquen en toda tu operación.',
    '<p>El grupo "Configuraciones" del menú lateral reúne las preferencias del sistema y la administración del equipo. Está formado por cuatro secciones que comparten la misma lógica de permisos.</p>
<h3>Las cuatro secciones</h3>
<ul>
<li><strong>Configuraciones:</strong> preferencias agrupadas por módulo. Cada tarjeta muestra su descripción, su nivel de afectación y el control para cambiar el valor.</li>
<li><strong>Roles y permisos:</strong> crea perfiles de acceso y define qué puede hacer cada uno.</li>
<li><strong>Usuarios:</strong> registra a las personas que usan el sistema y asígnales un rol.</li>
<li><strong>Plantillas personalizadas:</strong> diseña tickets, etiquetas, cotizaciones y recibos de servicio.</li>
</ul>
<h3>Recorre la página de configuraciones</h3>
<ol>
<li>Abre el menú lateral y entra a "Configuraciones". La página muestra una pestaña por cada módulo que tiene preferencias definidas, por ejemplo Clientes, Productos, Servicios o Historial de Ventas.</li>
<li>Selecciona una pestaña. Cada tarjeta corresponde a una preferencia e incluye una etiqueta con su nivel: "Suscripción (Global)", "Sucursal" o "Usuario (Personal)".</li>
<li>Ajusta los valores que necesites. Los campos que no puedes editar aparecen deshabilitados con la leyenda "Sin permisos para editar este nivel".</li>
<li>Pulsa "Guardar todos los cambios". El botón guarda de una vez los valores de todas las pestañas, no solo de la pestaña visible.</li>
</ol>
<h3>Qué tener en cuenta</h3>
<ul>
<li>El módulo aparece solo si tu plan incluye "Configuraciones" y tu rol tiene el permiso "Acceder a las configuraciones generales". Las demás secciones tienen sus propios permisos.</li>
<li>Las preferencias de nivel Sucursal corresponden a la sucursal activa. Si cambias de sucursal, verás y editarás los valores de esa sucursal.</li>
<li>Si una pestaña no tiene preferencias, muestra el mensaje "No hay configuraciones en este módulo".</li>
<li>Los botones "Nueva Configuración", el lápiz y el bote de basura son herramientas del superadmin principal y no aparecen en cuentas normales.</li>
</ul>',
    10, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM help_center_articles
    WHERE module = 'settings' AND title = 'Cómo navegar el módulo de configuraciones'
);

-- ----------------------------------------------------------------------------
-- 2. How to change the row click action of the main tables
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'settings',
    'Cómo cambiar la acción al hacer clic en una fila de la tabla',
    'Elige si al hacer clic en un registro se abre un panel lateral con el resumen o si el sistema navega a la vista completa de detalles. La preferencia es personal y se guarda por módulo.',
    '<p>Las tablas principales de Clientes, Productos, Servicios e Historial de Ventas permiten decidir qué ocurre al hacer clic sobre un registro. La preferencia se guarda por usuario, así que cada miembro del equipo puede trabajar con la acción que le resulte más cómoda.</p>
<h3>Ubica la preferencia</h3>
<ol>
<li>Entra a Configuraciones desde el menú lateral.</li>
<li>Abre la pestaña del módulo: "Clientes", "Productos", "Servicios" o "Historial de Ventas". En "Servicios" encontrarás una tarjeta para el catálogo y otra para las órdenes de servicio.</li>
<li>Localiza la tarjeta "Acción al clic en tabla principal de ...". La etiqueta "Usuario (Personal)" confirma que el cambio solo aplica a tu cuenta.</li>
</ol>
<h3>Opciones disponibles</h3>
<ul>
<li><strong>Vista lateral con algunos detalles:</strong> abre un panel lateral con la información resumida del registro, sin salir de la tabla. Es la opción predeterminada.</li>
<li><strong>Redirección a vista de detalles:</strong> navega a la pantalla completa del registro con toda su información y acciones.</li>
</ul>
<ol>
<li>Selecciona la opción en el desplegable.</li>
<li>Pulsa "Guardar todos los cambios". El sistema confirma con el mensaje "Configuraciones actualizadas con éxito".</li>
<li>Regresa a la tabla y haz clic sobre cualquier fila para comprobar el comportamiento.</li>
</ol>
<h3>Buenas prácticas</h3>
<ul>
<li>Los botones, enlaces y casillas que están dentro de la fila conservan su propia acción; la preferencia solo se aplica al hacer clic en el resto de la fila.</li>
<li>Si trabajas con muchas altas y ediciones, la vista lateral evita perder el contexto de la lista. Si revisas información extensa, la redirección te da la pantalla completa.</li>
<li>La preferencia no modifica la configuración de tus compañeros ni la de otras sucursales.</li>
</ul>',
    20, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM help_center_articles
    WHERE module = 'settings' AND title = 'Cómo cambiar la acción al hacer clic en una fila de la tabla'
);

-- ----------------------------------------------------------------------------
-- 3. How the three configuration levels work
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'settings',
    'Cómo funcionan los niveles de configuración',
    'Cada preferencia pertenece a un solo nivel: suscripción, sucursal o usuario. Entender esa etiqueta evita cambios que afecten a otras sucursales o a todo el equipo.',
    '<p>Cada preferencia del sistema se guarda en un único nivel. La etiqueta de la tarjeta indica a quién afecta el valor y qué permiso necesitas para modificarlo. No es una herencia de tres pasos: el valor se lee del nivel exacto de la preferencia y, si nunca se ha guardado, se usa el valor por defecto.</p>
<h3>Los tres niveles</h3>
<ul>
<li><strong>Suscripción (Global):</strong> el valor aplica a todas las sucursales de la cuenta. Editarlo requiere el permiso "Modificar las configuraciones a nivel de suscripción".</li>
<li><strong>Sucursal:</strong> el valor aplica solo a la sucursal activa. Editarlo requiere el permiso "Modificar las configuraciones a nivel de sucursal".</li>
<li><strong>Usuario (Personal):</strong> el valor aplica solo a tu cuenta y siempre puedes editarlo, sin permisos adicionales.</li>
</ul>
<h3>Cómo se guardan los cambios</h3>
<ol>
<li>Modifica los valores que necesites en cualquier pestaña.</li>
<li>Pulsa "Guardar todos los cambios". Se guardan todos los campos visibles de la página, incluidas las pestañas que no están abiertas.</li>
<li>Espera la confirmación "Configuraciones actualizadas con éxito".</li>
</ol>
<h3>Campos bloqueados y archivos</h3>
<ul>
<li>Si no tienes el permiso del nivel, el campo aparece deshabilitado con la leyenda "Sin permisos para editar este nivel". El sistema omite ese valor al guardar, aunque el formulario lo envíe.</li>
<li>En las preferencias de tipo archivo verás el enlace "Ver archivo actual". Si no eliges un archivo nuevo, se conserva el que ya estaba.</li>
<li>Los campos de lista permiten capturar varios valores separados por coma; los de selección muestran las opciones definidas para esa preferencia.</li>
<li>No hay un botón para restablecer al valor por defecto: si lo necesitas, captura de nuevo ese valor y guarda.</li>
</ul>
<h3>Recomendaciones</h3>
<ul>
<li>Antes de cambiar una preferencia de suscripción, confirma que el nuevo valor funciona para todas las sucursales.</li>
<li>Usa el nivel Usuario para ajustes de comodidad personal, como la acción al hacer clic en una fila.</li>
<li>Cambia de sucursal desde el selector de la barra superior para revisar los valores de esa sucursal antes de editarlos.</li>
</ul>',
    30, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM help_center_articles
    WHERE module = 'settings' AND title = 'Cómo funcionan los niveles de configuración'
);

-- ----------------------------------------------------------------------------
-- 4. How to create a role and assign permissions
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'settings',
    'Cómo crear un rol y asignarle permisos',
    'Crea perfiles de acceso por sucursal, marca los permisos que necesita cada puesto y guarda los cambios para que el equipo trabaje con el mínimo privilegio necesario.',
    '<p>Los roles agrupan permisos y se asignan a los usuarios. Cada sucursal tiene sus propios roles, así que un rol creado en una sucursal no está disponible en otra.</p>
<h3>Antes de empezar</h3>
<ul>
<li>Necesitas los permisos "Acceder a las configuraciones de roles y permisos" y "Crear roles, asignar y remover permisos".</li>
<li>Define el puesto y las tareas que debe realizar. Aplica el criterio de mínimo privilegio: otorga solo lo indispensable.</li>
</ul>
<h3>Crea el rol</h3>
<ol>
<li>Entra a Configuraciones y abre "Roles y permisos".</li>
<li>En la columna "Roles disponibles", pulsa el botón con el signo más. Se abre la ventana "Nuevo rol".</li>
<li>Escribe el "Nombre del rol". Debe ser único dentro de la sucursal; por ejemplo, "Cajero", "Gerente de sucursal" o "Almacén".</li>
<li>Pulsa "Crear rol". El rol se crea sin permisos y aparece en la lista.</li>
</ol>
<h3>Asigna los permisos</h3>
<ol>
<li>Selecciona el rol recién creado. A la derecha verás los permisos agrupados por módulo.</li>
<li>Despliega un grupo y marca las casillas que correspondan. La casilla del grupo selecciona o deselecciona todos los permisos de ese módulo.</li>
<li>Cada permiso muestra su descripción y, debajo, su nombre técnico.</li>
<li>Pulsa "Guardar cambios". El sistema confirma con "Permisos actualizados con éxito".</li>
</ol>
<h3>Qué tener en cuenta</h3>
<ul>
<li>Solo se listan los permisos de los módulos incluidos en tu plan, además de los permisos del grupo "Sistema".</li>
<li>El rol no cambia de nombre después de crearlo. Si el nombre ya no describe al puesto, crea un rol nuevo y reasigna a los usuarios.</li>
<li>Los usuarios con ese rol reciben los cambios en su siguiente inicio de sesión o al actualizar la página.</li>
</ul>',
    40, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM help_center_articles
    WHERE module = 'settings' AND title = 'Cómo crear un rol y asignarle permisos'
);

-- ----------------------------------------------------------------------------
-- 5. How to edit permissions or delete a role
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'settings',
    'Cómo editar permisos o eliminar un rol',
    'Actualiza los permisos de un rol existente, resuelve el bloqueo por usuarios asignados y elimina perfiles que ya no se usan sin afectar al administrador principal.',
    '<p>Los roles se ajustan cuando cambian las responsabilidades del equipo. Un rol puede acumular muchos permisos, así que revisa su alcance antes de asignarlo.</p>
<h3>Edita los permisos</h3>
<ol>
<li>Entra a "Roles y permisos" y selecciona el rol en la columna "Roles disponibles".</li>
<li>Marca o desmarca permisos. Usa la casilla del grupo para seleccionar un módulo completo.</li>
<li>Pulsa "Guardar cambios". Los usuarios con ese rol reciben el ajuste de inmediato.</li>
</ol>
<h3>Elimina un rol</h3>
<ol>
<li>Pasa el cursor sobre el rol y pulsa el icono del bote de basura.</li>
<li>Confirma la acción con "Sí, eliminar".</li>
</ol>
<p>Si el rol tiene usuarios asignados, el sistema no lo elimina y muestra el mensaje "No se puede eliminar un rol con usuarios asignados". En ese caso, edita primero cada usuario y cámbiale el rol; después repite la eliminación.</p>
<h3>Permisos y acciones de superadmin</h3>
<ul>
<li>Para eliminar roles necesitas el permiso "Eliminar roles".</li>
<li>El botón "Nuevo permiso" y los iconos para editar o eliminar permisos solo aparecen al superadmin principal (ID 1).</li>
<li>Al eliminar un permiso, los roles que lo tenían pierden esa capacidad. Verifica qué roles lo usan antes de borrarlo.</li>
<li>Conserva al menos un rol con "Acceder a las configuraciones de roles y permisos" para no quedarte sin administración del módulo.</li>
</ul>
<h3>Recomendaciones</h3>
<ul>
<li>Antes de retirar un permiso, confirma con el equipo qué tareas dependen de él.</li>
<li>Si un rol quedó sin uso, elimínalo para mantener la lista ordenada; antes reasigna a sus usuarios.</li>
<li>No compartas un mismo rol entre puestos con responsabilidades distintas: los permisos se vuelven difíciles de auditar.</li>
</ul>',
    50, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM help_center_articles
    WHERE module = 'settings' AND title = 'Cómo editar permisos o eliminar un rol'
);

-- ----------------------------------------------------------------------------
-- 6. How to create a user in the branch
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'settings',
    'Cómo crear un usuario en la sucursal',
    'Registra a un miembro del equipo con nombre, correo, rol y cuentas bancarias permitidas, y respeta el límite de usuarios de tu plan.',
    '<p>Cada usuario tiene una cuenta de acceso propia, un rol y una sucursal. Los usuarios que creas desde esta pantalla pertenecen a la sucursal activa.</p>
<h3>Antes de empezar</h3>
<ul>
<li>Necesitas los permisos "Ver listado de usuarios registrados en la sucursal" y "Crear usuarios".</li>
<li>Ten listo el rol que tendrá la persona. Si aún no existe, puedes crearlo desde el mismo formulario.</li>
<li>Revisa el límite de usuarios de tu plan. Si ya lo alcanzaste, aparece el aviso "Límite de usuarios alcanzado" y el botón para crear se deshabilita.</li>
</ul>
<h3>Crea el usuario</h3>
<ol>
<li>Entra a Configuraciones y abre "Usuarios".</li>
<li>Pulsa "Crear usuario".</li>
<li>Captura el "Nombre completo".</li>
<li>Escribe el "Correo electrónico". Debe ser único en el sistema y será el dato de acceso de la persona.</li>
<li>Selecciona el "Rol del usuario". El botón con el signo más permite crear un rol sin salir de la pantalla; el icono del libro muestra los permisos del rol seleccionado.</li>
<li>De forma opcional, elige las "Cuentas Bancarias Permitidas". Son las cuentas que la persona podrá consultar y administrar; si no eliges ninguna, no tendrá acceso a sus saldos.</li>
<li>Escribe la "Contraseña" y pulsa "Crear Usuario".</li>
</ol>
<h3>Después de guardar</h3>
<ul>
<li>El sistema confirma con "Usuario creado con éxito" y regresa al listado.</li>
<li>Comparte la contraseña por un medio seguro. Si necesitas cambiarla después, usa la opción de edición del usuario.</li>
<li>Si el plan llegó a su límite, solicita un aumento con el botón "Solicitar aumento", que abre WhatsApp con el mensaje preparado.</li>
</ul>
<h3>Buenas prácticas</h3>
<ul>
<li>Crea una cuenta por persona; no compartas usuarios entre varios empleados.</li>
<li>Asigna el rol más restrictivo que permita realizar el trabajo y ajústalo cuando cambie el puesto.</li>
<li>Revisa el correo antes de guardar: si ya existe en otra cuenta, el sistema rechazará el registro.</li>
</ul>',
    60, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM help_center_articles
    WHERE module = 'settings' AND title = 'Cómo crear un usuario en la sucursal'
);

-- ----------------------------------------------------------------------------
-- 7. How to edit, activate or delete users
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'settings',
    'Cómo editar, activar o eliminar usuarios',
    'Actualiza datos, cambia el rol, restablece la contraseña o desactiva el acceso de un miembro del equipo. El administrador principal está protegido contra estos cambios.',
    '<p>La lista de usuarios muestra nombre, email, rol, estatus y fecha de registro. Desde el menú de cada fila se administra la cuenta sin entrar a otras pantallas.</p>
<h3>Encuentra al usuario</h3>
<ol>
<li>Entra a Configuraciones y abre "Usuarios".</li>
<li>Usa el buscador "Buscar por nombre o email..." o pulsa los encabezados de columna para ordenar.</li>
<li>Pulsa el botón con tres puntos al final de la fila para abrir las acciones.</li>
</ol>
<h3>Edita la cuenta</h3>
<ol>
<li>Elige "Editar usuario".</li>
<li>Ajusta nombre, correo, rol o cuentas bancarias permitidas.</li>
<li>Escribe la "Nueva contraseña" solo si quieres cambiarla; si la dejas vacía, se conserva la actual.</li>
<li>Pulsa "Guardar Cambios". El sistema confirma con "Usuario actualizado con éxito".</li>
</ol>
<h3>Activa o desactiva el acceso</h3>
<ul>
<li>La acción "Desactivar usuario" cambia el estatus a "Inactivo" y la persona ya no puede entrar al sistema.</li>
<li>La acción "Activar usuario" devuelve el acceso sin perder su historial ni su rol.</li>
<li>El cambio es inmediato y se confirma con el mensaje "Usuario activado con éxito" o "Usuario desactivado con éxito".</li>
</ul>
<h3>Elimina un usuario</h3>
<ol>
<li>Elige "Eliminar usuario" y confirma con "Sí, eliminar".</li>
<li>La acción no se puede deshacer. Si la persona podría regresar, desactívala en lugar de eliminarla.</li>
</ol>
<h3>Protección del administrador principal</h3>
<p>El usuario principal aparece con la etiqueta "Súper Admin" y no tiene rol asignado. Sus acciones de editar, activar o desactivar y eliminar están deshabilitadas; si se intenta por otra vía, el sistema responde "No se puede eliminar al administrador principal" o "No se puede desactivar al administrador principal".</p>
<h3>Buenas prácticas</h3>
<ul>
<li>Desactiva la cuenta el último día de trabajo de la persona y elimínala solo cuando ya no necesites conservar su historial.</li>
<li>Al cambiar el rol de un usuario, verifica que el nuevo perfil tenga los permisos que necesita antes de que vuelva a operar.</li>
<li>Restablece la contraseña desde la edición del usuario y compártela por un canal seguro.</li>
</ul>',
    70, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM help_center_articles
    WHERE module = 'settings' AND title = 'Cómo editar, activar o eliminar usuarios'
);

-- ----------------------------------------------------------------------------
-- 8. How to design the sales ticket template
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'settings',
    'Cómo diseñar la plantilla de ticket de venta',
    'Configura el ancho del papel, los datos del negocio y los elementos del ticket. Usa variables dinámicas para que cada venta imprima su propia información.',
    '<p>Las plantillas de ticket definen cómo se imprime el comprobante de una venta en impresoras térmicas de 58 mm u 80 mm.</p>
<h3>Antes de empezar</h3>
<ul>
<li>Necesitas el permiso "Acceder al listado de plantillas" y, para crearla, "Crear plantillas".</li>
<li>Confirma el ancho del papel de tu impresora y el contexto en el que se usará el ticket.</li>
</ul>
<h3>Crea la plantilla</h3>
<ol>
<li>Entra a Configuraciones, abre "Plantillas personalizadas" y pulsa "Nueva plantilla".</li>
<li>Elige "Nuevo ticket de venta". Se abre el editor con la vista previa del papel.</li>
<li>En "Configuración ticket" captura el "Nombre" y el "Contexto de uso". El contexto indica en qué operación se ofrece la plantilla, por ejemplo "Punto de Venta (Ticket)", "Transacción / Venta" o "Corte de caja".</li>
<li>Selecciona una o varias "Sucursales". La plantilla solo estará disponible en las sucursales que marques.</li>
<li>Elige el "Ancho del Papel": 80 mm (predeterminado) o 58 mm.</li>
<li>Define las "Líneas finales (feed)", de 0 a 10, para dejar espacio en blanco antes del corte.</li>
</ol>
<h3>Agrega los elementos</h3>
<ul>
<li><strong>Texto:</strong> párrafos con datos fijos o variables entre llaves dobles, por ejemplo {{v.folio}} o {{v.total}}.</li>
<li><strong>Imagen URL</strong> y <strong>Subir Imagen:</strong> logotipo o gráfico. Puedes ajustar el ancho máximo en píxeles.</li>
<li><strong>Separador</strong> y <strong>Salto de Línea:</strong> ordenan visualmente el contenido.</li>
<li><strong>Código Barras</strong> y <strong>Código QR:</strong> ideales para el folio de la venta o el SKU.</li>
<li><strong>Tabla Venta:</strong> imprime la lista de productos con cantidades, precios y totales.</li>
</ul>
<ol>
<li>Pulsa un elemento para agregarlo al final del ticket.</li>
<li>Arrastra el elemento en la vista previa para ubicarlo. En el panel derecho ajusta la alineación y, en las imágenes, el tamaño.</li>
<li>Activa "Selección automática" si quieres que esta plantilla se proponga sola al imprimir.</li>
<li>Pulsa "Guardar". En una plantilla existente el botón cambia a "Actualizar".</li>
</ol>
<h3>Recomendaciones</h3>
<ul>
<li>Haz una impresión de prueba antes de usar la plantilla en caja.</li>
<li>No borres las variables entre llaves dobles: el sistema las reemplaza con los datos de cada venta.</li>
<li>Si el papel es de 58 mm, reduce la cantidad de columnas y el tamaño de los textos para evitar cortes.</li>
</ul>',
    80, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM help_center_articles
    WHERE module = 'settings' AND title = 'Cómo diseñar la plantilla de ticket de venta'
);

-- ----------------------------------------------------------------------------
-- 9. How to design the product label template
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'settings',
    'Cómo diseñar la plantilla de etiquetas de producto',
    'Ajusta las medidas en milímetros, la resolución y el espacio entre etiquetas para imprimir códigos de barras y precios con precisión en impresoras térmicas.',
    '<p>Las etiquetas se imprimen en impresoras térmicas que trabajan por milímetros y resolución. Una plantilla mal medida produce etiquetas cortadas o desalineadas.</p>
<h3>Antes de empezar</h3>
<ul>
<li>Necesitas el permiso para acceder a plantillas y, si vas a crearla, el permiso para crear plantillas.</li>
<li>Mide una etiqueta física y confirma la resolución de la impresora (203, 300 o 600 DPI).</li>
</ul>
<h3>Crea la plantilla</h3>
<ol>
<li>Entra a "Plantillas personalizadas" y elige "Nueva etiqueta" en el menú "Nueva plantilla".</li>
<li>Captura el "Nombre" y el "Contexto de uso"; para etiquetas de producto, el contexto habitual es "Producto / Etiqueta".</li>
<li>Selecciona las "Sucursales" donde se usará.</li>
<li>Captura el "Ancho (mm)" y el "Alto (mm)". El mínimo es 10 mm en ambos campos; los valores iniciales son 50 mm por 25 mm.</li>
<li>Elige la "Resolución (DPI)": 203, 300 o 600.</li>
<li>Define el "Espacio (GAP)" en milímetros, que corresponde a la separación entre etiquetas en el rollo. El valor inicial es 2 mm.</li>
</ol>
<h3>Agrega el contenido</h3>
<ul>
<li><strong>Texto:</strong> nombre, precio o cualquier dato fijo. Ajusta el tamaño de fuente y la alineación.</li>
<li><strong>Código de barras:</strong> captura el valor y elige la simbología que acepta tu lector.</li>
<li><strong>Código QR:</strong> útil para enlaces o información ampliada del producto.</li>
</ul>
<ol>
<li>Agrega el elemento desde el panel "Elementos".</li>
<li>Arrastra el elemento sobre la etiqueta o captura la "Posición (mm)" en los ejes X e Y.</li>
<li>Usa "Rotación (°)" cuando necesites texto vertical.</li>
<li>Activa "Selección automática" si quieres que la etiqueta se proponga sola al imprimir.</li>
<li>Pulsa "Guardar" o "Actualizar" según corresponda.</li>
</ol>
<h3>Recomendaciones</h3>
<ul>
<li>Imprime una etiqueta de prueba para verificar medidas, resolución y espacio entre etiquetas.</li>
<li>Si el texto sale cortado, reduce el tamaño de fuente o aumenta el área de la etiqueta.</li>
<li>Mantén el mismo GAP en todas las plantillas de la misma impresora para evitar desplazamientos.</li>
</ul>',
    90, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM help_center_articles
    WHERE module = 'settings' AND title = 'Cómo diseñar la plantilla de etiquetas de producto'
);

-- ----------------------------------------------------------------------------
-- 10. How to design quote and service receipt templates
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'settings',
    'Cómo diseñar plantillas de cotización y recibo de servicio',
    'Diseña documentos en Carta o A4 con encabezado, tabla de conceptos, firma y campos personalizados para cotizaciones y órdenes de servicio.',
    '<p>Las plantillas de cotización y de recibo de servicio se imprimen en hojas Carta o A4 y se construyen con bloques que fluyen de arriba hacia abajo, además de imágenes de posición libre.</p>
<h3>Antes de empezar</h3>
<ul>
<li>Necesitas el permiso para acceder a plantillas.</li>
<li>Para crear una cotización se requiere el permiso "Acceder a cotizaciones"; para el recibo de servicio, el permiso "Acceder a órdenes de servicio". Sin ellos, la opción no aparece en el menú "Nueva plantilla".</li>
</ul>
<h3>Crea la plantilla</h3>
<ol>
<li>Entra a "Plantillas personalizadas" y abre el menú "Nueva plantilla".</li>
<li>Elige "Nueva cotización (Carta/A4)" o "Nuevo recibo de servicio (Carta/A4)".</li>
<li>Captura el "Nombre" y selecciona las "Sucursales".</li>
<li>Define el "Tamaño": Carta o A4.</li>
<li>Elige la "Orientación" de la hoja.</li>
<li>Ajusta los "Márgenes"; el valor inicial es 2.5cm.</li>
</ol>
<h3>Agrega los bloques</h3>
<ul>
<li><strong>Texto Enriquecido:</strong> párrafos, condiciones, notas y datos del emisor.</li>
<li><strong>Tabla de Conceptos:</strong> lista de productos o servicios con cantidad, precio y total. Puedes elegir las columnas y mostrar el desglose de impuestos.</li>
<li><strong>2 Columnas:</strong> información lado a lado, por ejemplo emisor y cliente.</li>
<li><strong>Separador</strong> y <strong>Espaciado:</strong> controlan la línea divisoria y el espacio vertical.</li>
<li><strong>Firma:</strong> línea de firma para la autorización del cliente.</li>
<li><strong>Logo / Imagen</strong> y <strong>Adorno / Figura:</strong> se colocan en posición libre sobre la hoja.</li>
</ul>
<ol>
<li>Agrega los bloques en el orden en que deben imprimirse.</li>
<li>Si tu suscripción tiene campos personalizados para cotizaciones u órdenes de servicio, aparecen disponibles en el editor.</li>
<li>Pulsa "Guardar" o "Actualizar".</li>
</ol>
<h3>Recomendaciones</h3>
<ul>
<li>Haz una impresión de prueba en el mismo tamaño de hoja que usarás.</li>
<li>Deja márgenes suficientes para que la impresora no recorte el contenido.</li>
<li>Usa la tabla de conceptos en lugar de escribir los productos a mano para mantener los importes correctos.</li>
<li>Conserva la firma al final del documento y verifica que quede espacio para ella.</li>
</ul>',
    100, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM help_center_articles
    WHERE module = 'settings' AND title = 'Cómo diseñar plantillas de cotización y recibo de servicio'
);

-- ----------------------------------------------------------------------------
-- 11. How to assign templates to branches and set the default one
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'settings',
    'Cómo asignar plantillas a sucursales y marcar la predeterminada',
    'Define en qué sucursales está disponible cada plantilla y activa la selección automática para que el sistema proponga la correcta al imprimir.',
    '<p>Las plantillas se crean a nivel de suscripción, pero solo se ofrecen en las sucursales que tengas asignadas. La marca de selección automática indica cuál se propone al imprimir.</p>
<h3>Asigna sucursales</h3>
<ol>
<li>Entra a "Plantillas personalizadas".</li>
<li>Crea una plantilla nueva o elige "Editar plantilla" en el menú de la fila.</li>
<li>En el campo "Sucursales" marca una o varias sucursales. Es obligatorio elegir al menos una.</li>
<li>Guarda con "Guardar" o "Actualizar".</li>
</ol>
<p>En la tabla, la columna "Sucursales asignadas" muestra las sucursales de cada plantilla. Si no tiene ninguna, aparece "Ninguna" y la plantilla no se ofrecerá al imprimir.</p>
<h3>Marca la plantilla predeterminada</h3>
<ol>
<li>Dentro del editor, activa el interruptor "Selección automática". El texto de ayuda indica "Se seleccionará sola al imprimir".</li>
<li>Guarda los cambios.</li>
<li>En la tabla, la columna "Autoselección" muestra una marca verde cuando la plantilla está activa como predeterminada.</li>
</ol>
<h3>Buenas prácticas</h3>
<ul>
<li>Deja una sola plantilla con selección automática por contexto y sucursal. Si marcas varias, la elección al imprimir puede resultar ambigua.</li>
<li>Asigna la plantilla de ticket al contexto que realmente usas en caja para que aparezca en el momento de cobrar.</li>
<li>Si cambias de sucursal, revisa que la plantilla esté asignada también a la nueva sucursal.</li>
<li>El límite de plantillas depende de tu plan. Si aparece el aviso de límite, elimina una plantilla que no uses o solicita un aumento.</li>
</ul>',
    110, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM help_center_articles
    WHERE module = 'settings' AND title = 'Cómo asignar plantillas a sucursales y marcar la predeterminada'
);

-- ----------------------------------------------------------------------------
-- 12. Which permissions control the settings module
-- ----------------------------------------------------------------------------
INSERT INTO help_center_articles
    (module, title, excerpt, content, sort_order, is_published, published_at, created_at, updated_at)
SELECT
    'settings',
    'Qué permisos controlan el módulo de configuraciones',
    'Conoce cada permiso del grupo Configuraciones y del sistema para construir roles que administren preferencias, usuarios, roles y plantillas sin exceder su alcance.',
    '<p>El módulo se protege con permisos granulares. Cada página y cada acción verifica un permiso distinto, y la interfaz oculta los botones que el usuario no puede usar.</p>
<h3>Requisito del plan</h3>
<p>El grupo "Configuraciones" del menú aparece cuando tu suscripción incluye el módulo "Configuraciones" y el rol tiene el permiso de acceso correspondiente. Si el plan no incluye el módulo, el grupo se oculta del menú aunque el rol conserve los permisos.</p>
<h3>Permisos de configuraciones generales</h3>
<ul>
<li><strong>settings.generals.access:</strong> acceder a las configuraciones generales.</li>
<li><strong>settings.generals.update_branch:</strong> modificar las configuraciones de nivel sucursal.</li>
<li><strong>settings.generals.update_subscription:</strong> modificar las configuraciones de nivel suscripción. Otorga este permiso solo a administradores de confianza.</li>
</ul>
<h3>Permisos de roles y permisos</h3>
<ul>
<li><strong>settings.roles_permissions.access:</strong> ver la pantalla de roles y permisos.</li>
<li><strong>settings.roles_permissions.manage:</strong> crear roles y asignar o remover permisos.</li>
<li><strong>settings.roles_permissions.delete:</strong> eliminar roles sin usuarios asignados.</li>
</ul>
<h3>Permisos de usuarios</h3>
<ul>
<li><strong>settings.users.access:</strong> ver el listado de usuarios de la sucursal.</li>
<li><strong>settings.users.create:</strong> crear usuarios.</li>
<li><strong>settings.users.edit:</strong> editar usuarios.</li>
<li><strong>settings.users.delete:</strong> eliminar usuarios.</li>
<li><strong>settings.users.change_status:</strong> activar y desactivar usuarios. Un usuario desactivado ya no tiene acceso al sistema.</li>
</ul>
<h3>Permisos de plantillas</h3>
<ul>
<li><strong>settings.templates.access:</strong> ver el listado de plantillas.</li>
<li><strong>settings.templates.create:</strong> crear plantillas.</li>
<li><strong>settings.templates.edit:</strong> editar plantillas y cambiar la selección automática.</li>
<li><strong>settings.templates.delete:</strong> eliminar plantillas.</li>
</ul>
<h3>Permiso del sistema</h3>
<p><strong>system.branches.switch</strong> permite cambiar entre sucursales desde la barra superior. Como las preferencias de nivel Sucursal y los datos operativos dependen de la sucursal activa, otórgalo solo al personal que debe operar en varias sucursales.</p>
<h3>Acciones reservadas al superadmin principal</h3>
<ul>
<li>Los botones "Nueva Configuración", el lápiz y el bote de basura de cada tarjeta solo se muestran al usuario principal (ID 1).</li>
<li>El botón "Nuevo permiso" y los iconos para editar o eliminar permisos también están reservados a ese usuario.</li>
</ul>
<h3>Cómo armar un rol de administración</h3>
<p>Para un encargado de configuración, combina settings.generals.access, settings.generals.update_branch, settings.roles_permissions.access, settings.users.access, settings.users.create, settings.users.edit, settings.users.change_status, settings.templates.access y settings.templates.create. Agrega settings.generals.update_subscription, settings.roles_permissions.manage, settings.roles_permissions.delete, settings.users.delete y settings.templates.delete solo si debe administrar el alcance completo.</p>',
    120, 1, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM help_center_articles
    WHERE module = 'settings' AND title = 'Qué permisos controlan el módulo de configuraciones'
);

-- ============================================================================
-- End of settings guides
-- ============================================================================
