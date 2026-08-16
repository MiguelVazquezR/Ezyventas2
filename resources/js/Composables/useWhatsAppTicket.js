/**
 * Composable para generar y abrir un ticket de compra formateado para WhatsApp
 * a partir de un enlace wa.me.
 *
 * Flujo:
 * 1. buildTicketMessage(ticket)  — construye el mensaje formateado (negritas *texto*,
 *    emojis en encabezados y bloque de código ``` para alinear las columnas).
 * 2. buildWhatsAppUrl(phone, message) — construye el enlace
 *    https://wa.me/{phone}?text={encodeURIComponent(message)}.
 * 3. enviarTicketWhatsApp(phone, ticket) — abre el enlace en una nueva pestaña
 *    con window.open(url, '_blank') y devuelve la referencia de la ventana
 *    (null si el navegador bloqueó el popup).
 *
 * Nota sobre el número: el enlace wa.me apunta al teléfono del CLIENTE (quien recibe
 * el ticket). Quien lo envía es el número del negocio/suscriptor, es decir, la persona
 * que hace clic debe estar logueada en la cuenta de WhatsApp del negocio.
 */

// --- Datos de prueba (hardcoded) ---
const demoTicket = {
    businessName: 'Mi Negocio', // (El que tenga registrado cada suscriptor)
    title: 'TICKET DE VENTA',
    date: '11/08/2026 - 14:35',
    folio: 'V-00492',
    customer: 'Público en General', // o el nombre del cliente si se selecciona
    items: [
        { cantidad: 2, descripcion: 'Agua Ciel 1L', total: '$30.00' },
        { cantidad: 1, descripcion: 'Galletas Choc', total: '$22.50' },
    ],
    totalPaid: '$52.50 MXN',
    paymentMethod: 'Efectivo (Recibido: $100.00 | Cambio: $47.50)',
    address: 'Av. Principal #123, Col. Centro',
    finalMessage: '¡Gracias por tu compra!',
};

/**
 * Construye el mensaje formateado para WhatsApp.
 * Dentro del bloque de código ``` las columnas se alinean calculando el ancho
 * de cada columna según el contenido más largo (monospace garantiza la alineación).
 *
 * @param {Object} ticket - Datos del ticket (ver demoTicket para la estructura).
 * @returns {string} Mensaje listo para encodeURIComponent.
 */
function buildTicketMessage(ticket) {
    const {
        businessName,
        title,
        date,
        folio,
        customer,
        items,
        totalPaid,
        paymentMethod,
        address,
        finalMessage,
    } = ticket;

    // --- Columnas del bloque de código (Cant | Producto | Total) ---
    // Truncamos descripciones largas para mantener el bloque alineado en WhatsApp.
    const MAX_DESC_LENGTH = 24;
    const descripciones = items.map((i) =>
        i.descripcion.length > MAX_DESC_LENGTH
            ? `${i.descripcion.slice(0, MAX_DESC_LENGTH - 1)}…`
            : i.descripcion
    );

    const cantWidth = Math.max(4, ...items.map((i) => String(i.cantidad).length));
    const descWidth = Math.max(8, ...descripciones.map((d) => d.length));
    const totalWidth = Math.max(5, ...items.map((i) => i.total.length));

    const header = `${'Cant'.padEnd(cantWidth)} ${'Producto'.padEnd(descWidth)} ${'Total'.padStart(totalWidth)}`;
    const itemLines = items
        .map((i, idx) => {
            const cantidad = String(i.cantidad).padEnd(cantWidth);
            const descripcion = descripciones[idx].padEnd(descWidth);
            const total = i.total.padStart(totalWidth);
            return `${cantidad} ${descripcion} ${total}`;
        })
        .join('\n');

    // Símbolos de texto puro (sin emojis): » (U+00BB) y • (U+2022) son caracteres
    // de texto incluidos en TODAS las fuentes (Arial, Tahoma, Segoe UI, etc.) y
    // no tienen variante emoji, por lo que nunca generan el glifo "tofu" (□).
    const lines = [
        `» *${title}* «`,
        `• *${businessName}*`,
        `• Fecha: *${date}*`,
        `• Folio: *${folio}*`,
        `• Cliente: *${customer}*`,
        '',
        '» *Detalle de compra* «',
        '```',
        header,
        itemLines,
        '```',
        `• Total pagado: *${totalPaid}*`,
    ];

    // Líneas opcionales: solo se incluyen si hay datos reales disponibles.
    if (paymentMethod) lines.push(`• Método de pago: *${paymentMethod}*`);
    if (address) lines.push(`• Dirección: *${address}*`);

    lines.push('', `» ${finalMessage} «`);

    return lines.join('\n');
}

/**
 * Construye el enlace wa.me con el mensaje codificado.
 * Teléfonos nacionales de 10 dígitos llevan el prefijo de México (52),
 * misma convención que usa el panel de órdenes de servicio.
 *
 * @param {string} phone - Teléfono destino (cliente) en formato internacional sin '+'.
 * @param {string} message - Mensaje sin codificar.
 * @returns {string} URL completa: https://wa.me/{phone}?text={encoded}
 */
function buildWhatsAppUrl(phone, message) {
    let cleanPhone = String(phone).replace(/\D/g, '');

    // Convención del proyecto (OrderSummaryPanel): teléfono nacional de 10 dígitos
    // lleva el prefijo de México (52) en wa.me.
    if (cleanPhone.length === 10) {
        cleanPhone = `52${cleanPhone}`;
    }

    return `https://wa.me/${cleanPhone}?text=${encodeURIComponent(message)}`;
}

/**
 * Genera y abre el ticket de compra en WhatsApp (nueva pestaña).
 * Usa el demo hardcoded por defecto si no se pasa un ticket real.
 *
 * @param {string} phone - Teléfono del cliente que recibirá el ticket.
 * @param {Object} [ticket=demoTicket] - Datos del ticket (opcional, default demo).
 * @returns {Window|null} Referencia de la ventana abierta, o null si fue bloqueada.
 */
function enviarTicketWhatsApp(phone, ticket = demoTicket) {
    const message = buildTicketMessage(ticket);
    const url = buildWhatsAppUrl(phone, message);

    return window.open(url, '_blank');
}

export function useWhatsAppTicket() {
    return {
        demoTicket,
        buildTicketMessage,
        buildWhatsAppUrl,
        enviarTicketWhatsApp,
    };
}
