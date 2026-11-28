# Modo Caja Inteligente — guía de uso

> Para el cajero y para el administrador del negocio. Explica qué hace el modo, cómo se enciende, cómo se usa en el
> mostrador, cómo se pesa a granel y qué hacer cuando algo no sale.
>
> Estado: fases 0–4 implementadas (27 de noviembre de 2026). La báscula viene de la Fase 1 y el aprendizaje de la
> Fase 4; el detalle técnico está en [`HANDOFF-fase-4-5.md`](HANDOFF-fase-4-5.md) y en
> [`fase-4-reporte.md`](fase-4-reporte.md). Si tú instalas el sistema, tu documento es
> [`despliegue.md`](despliegue.md).

---

## 1. Qué es y qué no hace

El Modo Caja Inteligente es una ayuda visual para el mostrador. La cámara mira la superficie, el sistema dibuja un
recuadro sobre cada producto y lo pinta de **verde**, **amarillo** o **rojo** según qué tan seguro está de qué producto
es. Si el producto está verde y el agregado automático está encendido, entra solo al carrito; si está amarillo, eliges
tú entre los candidatos; si está rojo, lo buscas por código o nombre, o lo das de alta en ese momento. Todo lo que
agrega pasa por el mismo carrito y el mismo cobro de siempre; el modo no tiene una caja paralela.

La báscula para productos a granel vive en el mismo módulo: marcas un producto como granel y con unidad `Kg` o `Grs`,
lo pones en la báscula y el panel calcula la cantidad y el total con el precio por unidad del producto. Además, el modo
**aprende con el uso**: cada vez que escaneas, das de alta o corriges un producto, guarda una muestra de cómo se ve ese
producto en tu mostrador, y con esas muestras reconoce mejor la próxima vez.

**Lo que no hace:**

- No cobra distinto ni cambia precios. El total sigue siendo cantidad por precio, y el cobro es el mismo
  (`TransactionPaymentService`).
- No reemplaza al cajero: propone, y tú confirmas, corriges o descartas.
- No funciona «solo» si no hay modelos publicados: en ese caso usa una cámara y un motor simulados, y la interfaz lo
  avisa. No es una cámara real.
- No guarda video ni el fotograma completo. Guarda contadores del día y, sólo si está activado `save_crops`, recortes
  del producto de 224×224 px.
- No pide permisos nuevos: lo usa cualquier usuario que pueda entrar al Punto de venta.
- No enciende nada por su cuenta: el negocio tiene que habilitarlo desde «Preferencias de caja».

---

## 2. Antes de empezar

**Quién puede usarlo.** Cualquier usuario que pueda entrar al Punto de venta (permiso `pos.access`, el que ya existía).
No se crearon permisos nuevos para el modo ni para la báscula. El interruptor del negocio lo ve y lo cambia cualquier
usuario del POS, así que lo normal es que lo encienda el administrador una vez y no se vuelva a tocar.

**La preferencia es del negocio, no de la caja.** Se guarda por suscripción y aplica a todas las sucursales y terminales.
Si la apagas, el botón del modo desaparece en todas las cajas (al recargar la página). El encendido/apagado del modo en
cada terminal sí es local: una caja puede tenerlo encendido y otra apagado.

| Interruptor | Dónde está | Qué controla |
|---|---|---|
| «Modo Caja Inteligente» | Preferencias de caja | Muestra el botón del modo en la barra del POS de todas las cajas |
| «Báscula para productos a granel» | Preferencias de caja | Muestra el panel de pesado y el acceso a «Productos a granel» |
| Botón del chip | Barra del POS, en cada caja | Enciende o apaga la cámara en esa terminal |
| Tipo de báscula | Panel de la báscula, en cada caja | Recuerda si esa terminal usa simulador, captura manual o Web Serial |

**Lo que necesitas para que funcione de verdad:**

- Una cámara conectada y con permiso en el navegador.
- HTTPS (o `localhost`): sin contexto seguro el navegador no da la cámara ni el puerto serie.
- Chrome o Edge actualizados si vas a usar la báscula por cable USB (Web Serial). La cámara funciona en los navegadores
  modernos.
- Los modelos publicados en `/models`. Si no están, el modo arranca en simulado y lo dice.

---

## 3. Encender el modo

1. Entra al Punto de venta con una sesión de caja abierta.
2. Pulsa **«Preferencias de caja»** (icono de controles deslizantes) en la barra superior.
3. Activa el interruptor **«Modo Caja Inteligente»**.
4. Pulsa **«Guardar»**. Verás el aviso «Preferencias guardadas».
5. En la barra del POS aparece el botón del chip. Su tooltip dice **«Activar el Modo Caja Inteligente»**. Púlsalo.
6. Abajo a la izquierda se abre el panel **«Modo Caja Inteligente»** con el estado y la versión del modelo.

Para apagarlo en esa caja, pulsa el botón de encendido del panel (tooltip **«Apagar el Modo Caja Inteligente»**) o el
mismo botón del chip, cuyo tooltip pasa a **«Apagar el Modo Caja Inteligente»**. La terminal recuerda el estado al salir
y volver al POS.

### Los estados del panel

| Estado | Qué significa | Qué ves |
|---|---|---|
| **Apagado** | El modo no está encendido en esta terminal. No hay panel en pantalla y el botón del chip no está resaltado. | Nada del módulo |
| **Cargando motor** | Está descargando los modelos y la galería, o abriendo la cámara. | Punto ámbar y el texto «Cargando motor» |
| **Listo** | La cámara analiza y el semáforo funciona. | Punto verde, y en la barra «Cámara Activa» y «Modelo Listo» |
| **En pausa** | El análisis está detenido a propósito; el panel y los pendientes siguen ahí. | Texto «En pausa» |
| **Error** | El motor no arrancó (por ejemplo, faltan los archivos de los modelos). | Punto rojo y el texto «Error»; el POS sigue funcionando |

### El interruptor de análisis

En la cabecera del panel hay un botón con icono de pausa o de reproducción. Su etiqueta accesible es
**«Pausar el análisis»** o **«Reanudar el análisis»**. Al pausar, el estado pasa a **En pausa** y la cámara deja de
analizar, pero no se cierra la sesión ni se pierde la venta en curso. Es lo que usarás mientras siembras el catálogo o
cuando quieras dejar la cámara quieta un momento.

### Los ajustes del semáforo

El botón con icono de controles (etiqueta **«Ajustes del modo»**) abre tres campos y un interruptor:

| Campo | Valor por defecto | Qué significa |
|---|---:|---|
| «Verde desde» | 0.85 | Confianza mínima para pintar verde |
| «Margen 2.º» | 0.08 | Ventaja mínima sobre el segundo candidato para que sea verde |
| «Amarillo desde» | 0.65 | Confianza mínima para pintar amarillo; por debajo, rojo |
| «Agregar automáticamente los productos verdes» | Encendido | Si lo apagas, incluso los verdes pasan al selector para que los confirmes |

Pulsa **«Guardar»** para aplicar los umbrales (se usan en el siguiente análisis) o **«Cancelar»** para salir sin
cambiar nada. El umbral amarillo no puede ser mayor que el verde: si lo intentas, el aviso dirá «No se guardaron los
ajustes».

---

## 4. La vista del mostrador

Cada producto detectado lleva un recuadro con una etiqueta. Los colores y las etiquetas son los mismos en toda la
pantalla.

| Color | Etiqueta | Qué hace el cajero |
|---|---|---|
| Verde | **Producto identificado** | Nada: si el agregado automático está encendido, el producto ya entró al carrito. Si lo apagaste, aparece en el selector para que lo confirmes. |
| Amarillo | **Verificar producto** | Elegir el producto correcto en el selector **«¿Cuál es?»**, o pulsar **«No es ninguno»** si ninguno corresponde. |
| Rojo | **Producto no identificado** | Abrir el panel **«Producto desconocido»**, escanear el código o buscarlo por nombre; si no existe, darlo de alta o descartarlo. |

En el selector amarillo, cada candidato muestra el nombre, el porcentaje de confianza y el precio. Puedes confirmar
también con las teclas **1**, **2** o **3** (sólo cuando no estás escribiendo en un campo). El producto elegido entra al
carrito por la misma vía que las tarjetas del catálogo.

**El agregado automático** está encendido por defecto. Con él apagado, ningún producto entra sin que tú lo confirmes,
aunque esté verde: es la forma de revisar todo durante los primeros días. Si el producto es a granel y la báscula está
encendida, no entra directo: se abre el panel de pesado para que pongas el peso.

**La barra de indicadores del día** aparece arriba del POS cuando el modo está activo y hay una sesión de caja abierta.
Muestra:

| Indicador | Qué es |
|---|---|
| «Cámara Activa / En pausa / Cargando / Error» | Estado de la cámara |
| «Modelo Listo / Cargando / Error» | Estado del motor de reconocimiento |
| «Báscula Habilitada» | Sólo si el negocio tiene la báscula encendida |
| «Detectados», «Auto», «Confirmados», «Corregidos», «Desconocidos» | Contadores de la venta en curso |
| «Tiempo» | Duración de la venta en curso (minutos:segundos) |
| «Latencia» | Milisegundos del último análisis |
| «Precisión» | Porcentaje del día: automáticos entre automáticos + confirmados + correcciones |
| «Corregidos hoy» | Correcciones del cajero registradas en el día |
| «Desconocidos hoy» | Desconocidos registrados en el día |
| «Ventas con modo» | Ventas cerradas con el modo activo |
| «Promedio» | Segundos promedio por venta con el modo |

La «Precisión» del día se calcula por sucursal y por día natural. Una corrección baja la precisión: es normal al
principio, cuando el modo todavía no conoce tus productos.

En la esquina inferior derecha de la vista previa verás «ONNX · galería N» cuando el motor real está cargado (hay
modelos publicados), o «Motor simulado» cuando no los hay. Al lado aparecen los milisegundos del último análisis («—» si
todavía no hay ninguno).

---

## 5. Producto desconocido

1. Cuando un producto sale rojo, el panel muestra el bloque **«Producto desconocido»**.
2. Escanea el código con el lector, o escribe el código o el nombre en el campo **«Código o nombre del producto»**.
3. Si aparece en la lista de resultados, pulsa el producto: entra al carrito y el bloque se cierra.
4. Si no existe, pulsa **«Alta rápida»**, llena el formulario y guárdalo. El producto se crea y entra al carrito.
5. Si no quieres registrarlo, pulsa **«Descartar»**. El bloque se cierra y el producto no entra.

La búsqueda filtra los productos que el POS tiene cargados en esa pantalla (nombre y SKU). Si el producto no está en
esa lista, usa «Alta rápida».

**Cada desconocido que resuelves deja una muestra.** Si hay cámara real y modelos publicados, el modo guarda el recorte
de lo que la cámara vio, con origen «Escaneo en caja» o «Alta rápida». No verás un aviso: la muestra se guarda en
segundo plano y la galería del motor se actualiza sola. Eso es lo que hace que el mismo producto salga verde la próxima
vez.

---

## 6. Corregir también es enseñar

Hay dos formas de corregir al modo:

1. **Elegir otro candidato.** Con una fila amarilla, pulsa un candidato que **no** sea el primero. Cuenta como
   corrección y, si hay cámara real y modelos publicados, guarda una muestra con origen «Corrección del cajero».
2. **Quitar una línea que puso el modo.** Si el modo agregó un producto equivocado, bórralo del carrito como cualquier
   otra línea. También cuenta como corrección y guarda la muestra del recorte que provocó el error (con las mismas
   condiciones: cámara real, modelos publicados y un fotograma reciente).

Una corrección **no es un castigo**: es la señal más valiosa que recibe el modo, porque le dice «esto no era, esto sí
es». La verás en «Corregidos» de la barra de indicadores y en el pie del panel («N corregidos»), se resta en la
«Precisión» del día y, cuando hay cámara y modelos, deja una muestra en la galería del producto.

Quitar una línea que agregaste tú a mano, o cambiar una cantidad, no cuenta como corrección del modo.

---

## 7. Calibrar el mostrador vacío

El modo compara lo que ve ahora contra una **referencia del mostrador vacío** de esa cámara: lo que cambió respecto a
la referencia es lo que hay encima. A esa estrategia se le llama `diff`.

1. Retira **todo** del mostrador: productos, manos, objetos personales.
2. Pulsa **«Recalibrar mostrador»**, en la esquina superior derecha de la vista previa.
3. Espera el aviso. Si salió bien: «Mostrador calibrado». Si salió mal: «No se pudo calibrar», con el detalle «Retira
   los productos del mostrador y vuelve a intentarlo».

**Cuándo recalibrar:** cuando muevas la cámara, cambies de lugar el mostrador, cambie la luz de forma notable (por
ejemplo, entra el sol de la tarde y antes no entraba) o notes que el modo marca cosas que no están. En esta fase hay una
sola cámara por defecto («Cámara 1») y la calibración se aplica a esa.

**Cómo saber que no hay cámara real.** El panel muestra una franja ámbar con un mensaje del tipo «No hay ninguna cámara
conectada en esta computadora», «El navegador bloqueó el acceso a la cámara…» o «La cámara requiere HTTPS o localhost»,
y debajo: «Se está usando una cámara simulada para probar el modo; conecta una cámara y vuelve a activarlo para usar la
imagen real». En ese estado la vista previa muestra una cuadrícula, no video. El botón «Recalibrar mostrador» sólo
existe con el motor real (modelos publicados); si el motor es el simulado, no aparece. La cámara simulada se calibra
sola y dibuja tres productos de ejemplo: sirve para probar la pantalla, no para reconocer tu catálogo.

---

## 8. Aprendizaje: sembrar el catálogo y revisar las muestras

El botón del icono de base de datos del panel (etiqueta **«Abrir el aprendizaje con el uso»**) abre la sección de
aprendizaje. Arriba verás cuántas muestras hay en la galería («N muestras en la galería»), si vienen de la copia guardada
en este terminal («· desde este terminal») y cuántos productos están pendientes («N sin muestras»).

### Sembrar el catálogo

1. Comprueba que el modo esté **Listo** y con modelos publicados. Si el motor es el simulado, verás el texto «El
   sembrado calcula los vectores en el navegador, así que necesita los modelos publicados en /models.» y no habrá botón.
2. Pulsa **«Sembrar catálogo»** (si no hay pendientes, el botón dice **«Actualizar galería»**).
3. El análisis se pausa mientras dura y se reanuda al terminar. La barra avanza y muestra «Procesando …», además de
   «guardadas · omitidas · con error» y los pendientes que quedan.
4. Puedes pulsar **«Detener»**: se guarda lo hecho y al volver a lanzarlo continúa donde se quedó.
5. Al terminar verás «Galería del catálogo sembrada» con el resumen «X imágenes guardadas · Y omitidas · Z con error».

Se siembra en **lotes de 50 productos**, con reanudación. Cada imagen tarda alrededor de un segundo (el servidor acepta
hasta 60 muestras por minuto), así que un lote de 50 ronda el minuto. Se usan las fotos reales del catálogo: la imagen
principal si el producto no tiene variantes, o una por variante con foto si las tiene. Los productos sin foto real no
se siembran.

Si la caja está **en pausa**, el terminal siembra solo lo pendiente al abrir el panel o la sección de aprendizaje. Con el
análisis en marcha no lo hace para no quitarle recursos a la venta; sólo muestra el contador.

### Ver y borrar muestras

En **«Productos con muestras»** aparecen, hasta 12, los productos que ya tienen alguna, con su contador en formato
`3/10`. Pulsa el ojo para abrir la lista. Cada muestra dice su origen («Imagen del catálogo», «Escaneo en caja», «Alta
rápida», «Corrección del cajero»), la fecha, la calidad si la hay y si tiene recorte guardado. Si una muestra no
corresponde, bórrala con la papelera: la galería deja de usarla en el siguiente análisis.

**El tope es de 10 muestras por producto.** Cuando el producto llega a 10, el servidor quita la de menor calidad o, si
no hay calidad, la más antigua, y guarda la nueva. Las correcciones del cajero entran aunque el producto ya tenga 10,
porque son la señal más valiosa. En la lista nunca verás más de 10 por producto.

---

## 9. La báscula

### Encenderla

1. Abre **«Preferencias de caja»**.
2. Activa **«Báscula para productos a granel»** y pulsa **«Guardar»**.
3. En la barra del POS aparece **«Productos a granel»** (icono de caja). También puedes tocar un producto a granel del
   catálogo y el panel de pesado se abre solo.

La báscula funciona con o sin el Modo Caja Inteligente. Cada cajero conserva el tipo de conexión elegido en esa terminal;
los perfiles y la estabilidad se guardan por negocio.

### Elegir el tipo de conexión

En el panel de la báscula, el selector **«Tipo de báscula»** ofrece:

| Opción | Para qué |
|---|---|
| «Báscula simulada (desarrollo)» | Probar sin hardware; trae botones de peso de ejemplo |
| «Captura manual del peso» | Escribir el peso a mano, siempre disponible |
| «Báscula por cable USB (Web Serial)» | Leer el puerto serie; requiere Chrome o Edge sobre HTTPS |

Pulsa **«Conectar»** para abrir el puerto. El estado se ve como **«Conectada»** o **«Desconectada»**. Si eliges Web
Serial en un navegador que no lo soporta, verás: «Este navegador no admite Web Serial. Usa Chrome o Edge actualizado
sobre HTTPS, o cambia a captura manual.»

### Configurar perfiles y estabilidad

El botón con icono de engrane (etiqueta **«Configurar báscula»**) abre **«Configuración de la báscula»**:

| Sección | Campo | Valor por defecto |
|---|---|---|
| Tipo de conexión | Simulada, manual o Web Serial | Simulada |
| Lectura estable | «Lecturas consecutivas» (2 a 20) | 5 |
| Lectura estable | «Tolerancia (gramos)» (1 a 100) | 3 |
| Cada perfil | Nombre | «Báscula 1», «Báscula 2»… |
| Cada perfil | «Baudios» | 9600 |
| Cada perfil | «Bits de datos» | 8 |
| Cada perfil | «Paridad» | `none` (la interfaz muestra el valor tal cual) |
| Cada perfil | «Bits de parada» | 1 |
| Cada perfil | «Terminador de línea» | `LF (\n)` |
| Cada perfil | «Unidad por defecto» | kg |
| Cada perfil | «Expresión regular de la trama» y grupos de peso, unidad y estabilidad | Patrón de ejemplo |

Puedes guardar hasta **10 perfiles**; el que está marcado en verde es el activo. El botón de copiar duplica un perfil y
la papelera lo elimina. **«Modo diagnóstico (líneas crudas del puerto)»** muestra lo que manda la báscula, para ajustar
el patrón.

Un peso es estable cuando varía menos que la tolerancia durante esas lecturas seguidas.

### Pesar y agregar

1. Elige el producto a granel (debe estar marcado como granel y con unidad `Kg` o `Grs`; si no, no aparece en la lista).
2. Pon el producto en la báscula. El panel muestra «Peso en báscula» y la etiqueta **«Peso estable»** o
   **«Peso inestable»**.
3. Si hace falta, pulsa **«Tara»** con la báscula vacía.
4. Revisa «Precio por …», «Cantidad» y «Total».
5. Cuando el peso esté estable, pulsa **«Agregar …»** (o Enter). Con peso inestable o sin peso, el botón está
   deshabilitado y el aviso te pide esperar.

Con captura manual, escribe el peso en **«Peso manual»** y pulsa **«Capturar»**. Con la báscula simulada, el bloque
«Simulador (desarrollo)» trae los botones 0.250, 0.750, 1.500, «Inestable» y «Cero».

El total se calcula con el precio de **una unidad** del producto. Si el producto se vende en `Grs` y la báscula entrega
kilos, la cantidad se convierte a gramos antes de multiplicar.

---

## 10. Problemas frecuentes

| Síntoma | Qué hacer |
|---|---|
| Todo sale «Producto no identificado» | La galería está vacía (0 muestras) o el motor es el simulado. Siembra el catálogo y resuelve algunos desconocidos. Si abajo dice «Motor simulado», faltan los modelos publicados: avisa a quien instaló el sistema. |
| Reconoce los productos de antes, pero no los nuevos | Las muestras viejas son de otra versión del modelo y no se usan. Vuelve a sembrar el catálogo y resuelve los productos nuevos. |
| No aparece la cámara y sale una franja ámbar | Revisa el permiso de cámara en el candado de la barra de direcciones, que no la use otro programa y que entres por HTTPS. El mensaje de la franja dice la causa. |
| La báscula no conecta | Elige «Báscula por cable USB (Web Serial)», usa Chrome o Edge sobre HTTPS y revisa el perfil (baudios, terminador y patrón). El «Modo diagnóstico» muestra las líneas crudas. |
| Sale «El Modo Caja Inteligente no está habilitado para este negocio.» | La preferencia del negocio está apagada. Entra a «Preferencias de caja», enciende «Modo Caja Inteligente», guarda y recarga el POS. |
| «No se guardaron los ajustes» | Revisa los umbrales: deben estar entre 0 y 1 y el amarillo no puede ser mayor que el verde. |
| El sembrado dice que necesita modelos | No hay modelos publicados en `/models`. Que los publique quien instala el sistema y vuelve a activar el modo. |
| El sembrado no baja el contador de pendientes | Un producto con cuatro variantes con foto consume cuatro muestras. Revisa «Productos con muestras» y el contador de cada producto. |
| El análisis va lento (más de un segundo por análisis) | Anótalo y avisa a soporte: en el panel se ven los milisegundos. Es un dato del equipo, no del negocio. |

---

## 11. Qué datos se guardan y con qué límites

| Dato | Dónde | Límite |
|---|---|---|
| Muestras (vectores) | Base de datos del negocio | 10 por producto y versión del modelo. **No se borran por edad.** |
| Recortes de las muestras | Disco privado del servidor, nunca en la carpeta pública | Sólo si `save_crops` está encendido (por defecto sí); 224×224 px y 80 KB como máximo. Se borran a los **30 días** por defecto (`retention_days`). |
| Eventos de detección y venta | Base de datos del negocio | Sólo contadores, sin imágenes. Se borran a los **30 días** por defecto con la purga diaria. |
| Preferencias y ajustes | Base de datos del negocio (una fila por suscripción) | Se conservan hasta que se apague el módulo o se borre la suscripción. |
| Estado encendido/apagado y tipo de báscula | `localStorage` de esa terminal | Se queda en el navegador de esa caja; el servidor no lo usa para autorizar nada. |

Los recortes y los eventos se purgan a diario a las 02:30 con `php artisan smartpos:purge`. **Los vectores no se borran
por antigüedad**: una muestra sólo desaparece si la borras a mano o si el producto llega a 10 y la rotación la expulsa.

No hay botón para cambiar `save_crops` ni `retention_days` en esta fase: los ajusta quien instala el sistema (ver
[`despliegue.md`](despliegue.md)). Tampoco se guarda el video de la cámara.

---

## 12. Para saber más

- [`despliegue.md`](despliegue.md): instalación, modelos, permisos, tareas programadas y diagnóstico.
- [`decisiones.md`](decisiones.md): por qué el modo no tiene permisos propios y por qué el negocio lo enciende desde el
  POS (D1 a D14).
- [`modelos.md`](modelos.md): de dónde salen los modelos y cómo se verifican.
- [`fase-4-reporte.md`](fase-4-reporte.md): qué hace exactamente el aprendizaje y cómo probarlo paso a paso.
