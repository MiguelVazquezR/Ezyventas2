/**
 * Composable para generar y abrir un ticket de compra formateado para WhatsApp
 * a partir de un enlace wa.me.
 *
 * El ticket es adaptable al tipo de venta (contado / crédito / apartado):
 * - contado:  total de venta + pagado (puede ser varios métodos) + cambio si aplica.
 * - crédito:  total de venta + abono + restante a pagar + vencimiento.
 * - apartado: total de venta + abono (forzoso) + restante a pagar + vencimiento.
 *
 * Flujo:
 * 1. buildTicketMessage(ticket)  — construye el mensaje formateado (negritas *texto*,
 *    símbolos » y • en encabezados y bloque de código ``` para alinear las columnas).
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
    saleType: 'apartado', // 'contado' | 'credito' | 'apartado'
    saleTypeLabel: 'Apartado',
    date: '11/08/2026 - 14:35',
    folio: 'V-00492',
    customer: 'María Pérez', // o 'Público en General' si no hay cliente
    items: [
        { cantidad: 2, descripcion: 'Agua Ciel 1L', total: '$30.00' },
        { cantidad: 1, descripcion: 'Galletas Choc', total: '$22.50' },
    ],
    total: '$52.50 MXN',
    totalPaid: '$30.00 MXN',
    remainingDue: '$22.50 MXN',
    expirationDate: '30/09/2026',
    paymentMethod: 'Efectivo: $30.00',
    address: 'Av. Principal #123, Col. Centro',
    finalMessage: '¡Gracias por tu compra!',
};

/**
 * Construye el mensaje formateado para WhatsApp, adaptable al tipo de venta.
 * Dentro del bloque de código ``` las columnas se alinean calculando el ancho
 * de cada columna según el contenido más largo (monospace garantiza la alineación).
 *
 * @param {Object} ticket - Datos del ticket (ver demoTicket para la estructura).
 *   Campos clave: saleType/saleTypeLabel (contado|credito|apartado), total,
 *   totalPaid (pagado o abono), remainingDue (restante a pagar, opcional),
 *   expirationDate (vencimiento, opcional), paymentMethod (con montos).
 * @returns {string} Mensaje listo para encodeURIComponent.
 */
function buildTicketMessage(ticket) {
    const {
        businessName,
        title,
        saleType,
        saleTypeLabel,
        date,
        folio,
        customer,
        items,
        total,
        totalPaid,
        remainingDue,
        expirationDate,
        paymentMethod,
        address,
        finalMessage = '¡Gracias por tu compra!',
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
    ];

    // Tipo de venta (contado / crédito / apartado).
    if (saleTypeLabel) lines.push(`• Tipo de venta: *${saleTypeLabel}*`);

    lines.push(
        `• Fecha: *${date}*`,
        `• Folio: *${folio}*`,
        `• Cliente: *${customer}*`,
        '',
        '» *Detalle de compra* «',
        '```',
        header,
        itemLines,
        '```',
    );

    // Montos según el tipo de venta.
    if (total) lines.push(`• Total de venta: *${total}*`);
    if (totalPaid) {
        const paidLabel = ['credito', 'apartado'].includes(saleType) ? 'Abono' : 'Pagado';
        lines.push(`• ${paidLabel}: *${totalPaid}*`);
    }
    if (remainingDue) lines.push(`• Restante a pagar: *${remainingDue}*`);
    if (expirationDate) lines.push(`• Vencimiento: *${expirationDate}*`);

    // Líneas opcionales: solo se incluyen si hay datos reales disponibles.
    if (paymentMethod) lines.push(`• Método de pago: *${paymentMethod}*`);
    if (address) lines.push(`• Dirección: *${address}*`);

    // Mensaje de cierre con recordatorio si queda saldo pendiente.
    let closing = finalMessage;
    if (remainingDue) {
        closing = expirationDate
            ? `${finalMessage} Recuerda liquidar tu saldo antes del ${expirationDate}.`
            : `${finalMessage} Queda un saldo pendiente de ${remainingDue}.`;
    }

    lines.push('', `» ${closing} «`);

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

// --- Datos de prueba (hardcoded) para el ticket de abono ---
const demoAbonoTicket = {
    kind: 'abono',
    scope: 'general', // 'transaction' | 'general'
    businessName: 'Mi Negocio',
    date: '29/08/2026 - 14:35',
    customer: 'María Pérez',
    // scope 'transaction':
    folio: null,
    saleTotal: null,
    previousDue: null,
    abonado: null,
    remainingDue: null,
    liquidated: false,
    expirationDate: null,
    // scope 'general':
    totalAbonado: '$50.00 MXN',
    paymentMethod: 'Efectivo: $50.00',
    breakdown: [
        { folio: 'V-00492', abonado: '$20.00', restante: '$0.00', liquidada: true },
        { folio: 'V-00495', abonado: '$30.00', restante: '$15.00', liquidada: false },
    ],
    liquidatedFolios: ['V-00492'],
    totalRemaining: '$15.00 MXN',
    nextExpiration: '30/09/2026',
    balanceCredit: null,
};

/**
 * Construye el mensaje de ABONO formateado para WhatsApp.
 * Soporta abono a una venta en particular (scope: 'transaction') y
 * abono general a la cuenta del cliente (scope: 'general').
 *
 * @param {Object} payload - Datos del ticket de abono (ver demoAbonoTicket).
 * @returns {string} Mensaje listo para encodeURIComponent.
 */
function buildAbonoMessage(payload) {
    const {
        businessName,
        date,
        customer,
        // Abono a venta particular
        folio,
        saleTotal,
        previousDue,
        abonado,
        remainingDue,
        liquidated,
        expirationDate,
        paymentMethod,
        // Abono general
        totalAbonado,
        breakdown = [],
        liquidatedFolios = [],
        totalRemaining,
        nextExpiration,
        balanceCredit,
    } = payload;

    const lines = [
        '» *TICKET DE ABONO* «',
        `• *${businessName}*`,
        '• Tipo de venta: *Abono*',
        `• Fecha: *${date}*`,
        `• Cliente: *${customer}*`,
        '',
        '» *Detalle del abono* «',
    ];

    if (folio) {
        // --- Abono a una venta en particular ---
        lines.push(`• Folio de venta: *${folio}*`);
        if (saleTotal) lines.push(`• Total de la venta: *${saleTotal}*`);
        if (previousDue) lines.push(`• Monto anterior: *${previousDue}*`);
        if (abonado) lines.push(`• Abonado: *${abonado}*`);
        if (remainingDue) lines.push(`• Restante a pagar: *${remainingDue}*`);
        if (paymentMethod) lines.push(`• Método de pago: *${paymentMethod}*`);
        if (!liquidated && expirationDate) lines.push(`• Vencimiento: *${expirationDate}*`);
    } else {
        // --- Abono general a la cuenta del cliente ---
        if (totalAbonado) lines.push(`• Total abonado: *${totalAbonado}*`);
        if (paymentMethod) lines.push(`• Método de pago: *${paymentMethod}*`);

        if (breakdown.length > 0) {
            const block = buildAbonoBreakdownBlock(breakdown);
            lines.push('', '» *Aplicado a ventas* «', '```', ...block, '```');
        }
        if (liquidatedFolios.length > 0) {
            lines.push(`• Ventas liquidadas: *${liquidatedFolios.join(', ')}*`);
        }
        if (totalRemaining) lines.push(`• Restante total: *${totalRemaining}*`);
        if (nextExpiration) lines.push(`• Próximo vencimiento: *${nextExpiration}*`);
        if (balanceCredit) lines.push(`• Saldo a favor: *${balanceCredit}*`);
    }

    const closing = liquidated
        ? '¡Gracias por tu abono! Tu venta quedó liquidada.'
        : '¡Gracias por tu abono!';

    lines.push('', `» ${closing} «`);

    return lines.join('\n');
}

/**
 * Construye el bloque monospace "Venta | Abono | Restante" alineado por columnas.
 *
 * @param {Array} breakdown - Filas { folio, abonado, restante, liquidada } ya formateadas.
 * @returns {string[]} Líneas del bloque (header + filas).
 */
function buildAbonoBreakdownBlock(breakdown) {
    const rows = breakdown.map((row) => ({
        folio: String(row.folio),
        abonado: String(row.abonado),
        restante: String(row.restante),
    }));

    const folioWidth = Math.max(5, ...rows.map((r) => r.folio.length));
    const abonadoWidth = Math.max(5, ...rows.map((r) => r.abonado.length));
    const restanteWidth = Math.max(8, ...rows.map((r) => r.restante.length));

    const header = `${'Venta'.padEnd(folioWidth)} ${'Abono'.padStart(abonadoWidth)} ${'Restante'.padStart(restanteWidth)}`;
    const body = rows.map(
        (r) => `${r.folio.padEnd(folioWidth)} ${r.abonado.padStart(abonadoWidth)} ${r.restante.padStart(restanteWidth)}`
    );

    return [header, ...body];
}

/**
 * Genera y abre el ticket de abono en WhatsApp (nueva pestaña).
 *
 * @param {string} phone - Teléfono del cliente que recibirá el ticket.
 * @param {Object} payload - Datos del ticket de abono.
 * @returns {Window|null} Referencia de la ventana abierta, o null si fue bloqueada.
 */
function enviarAbonoWhatsApp(phone, payload) {
    const message = buildAbonoMessage(payload);
    const url = buildWhatsAppUrl(phone, message);

    return window.open(url, '_blank');
}

export function useWhatsAppTicket() {
    return {
        demoTicket,
        demoAbonoTicket,
        buildTicketMessage,
        buildAbonoMessage,
        buildWhatsAppUrl,
        enviarTicketWhatsApp,
        enviarAbonoWhatsApp,
    };
}
