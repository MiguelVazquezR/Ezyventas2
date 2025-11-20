import { computed } from 'vue';

export function useTemplateRenderer() {
    
    // Helper interno para formatos
    const formatCurrency = (value) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value || 0);
    const formatDate = (dateString) => {
        if (!dateString) return 'N/A';
        const date = new Date(dateString);
        const userTimezoneOffset = date.getTimezoneOffset() * 60000;
        return new Date(date.getTime() + userTimezoneOffset).toLocaleDateString('es-MX', { year: 'numeric', month: 'long', day: 'numeric' });
    };

    /**
     * Reemplaza marcadores con datos reales
     */
    const replaceVariables = (text, quote) => {
        if (!text) return '';
        return text.replace(/{{(.*?)}}/g, (match, p1) => {
            const key = p1.trim();
            
            const map = {
                // --- VARIABLES GENERALES ---
                'folio': quote.folio,
                'fecha_creacion': formatDate(quote.created_at),
                'fecha_vencimiento': formatDate(quote.expiry_date),
                
                // --- VARIABLES DE COTIZACIÓN (Nuevas) ---
                'cotizacion.folio': quote.folio,
                'cotizacion.fecha_creacion': formatDate(quote.created_at),
                'cotizacion.fecha_vencimiento': formatDate(quote.expiry_date),
                'cotizacion.subtotal': formatCurrency(quote.subtotal),
                'cotizacion.impuestos': formatCurrency(quote.total_tax),
                'cotizacion.envio': formatCurrency(quote.shipping_cost),
                'cotizacion.descuento': formatCurrency(quote.total_discount),
                'cotizacion.total': formatCurrency(quote.total_amount),
                'cotizacion.notas': quote.notes || '',

                // --- DATOS CLIENTE ---
                'cliente.nombre': quote.recipient_name,
                'cliente.email': quote.recipient_email || 'N/A',
                'cliente.telefono': quote.recipient_phone || 'N/A',
                'cliente.direccion': quote.shipping_address || 'N/A',
                'cliente.empresa': quote.customer?.company_name || '',

                // --- DATOS EMPRESA ---
                'empresa.nombre': quote.branch?.subscription?.commercial_name || 'Mi Empresa',
                'sucursal.nombre': quote.branch?.name || '',
                'sucursal.direccion': quote.branch?.address ? Object.values(quote.branch.address).filter(Boolean).join(', ') : '',
                'sucursal.telefono': quote.branch?.contact_phone || '',
                'negocio.nombre': quote.branch?.subscription?.commercial_name || '',
                'negocio.razon_social': quote.branch?.subscription?.business_name || '',
                'negocio.direccion': quote.branch?.subscription?.address ? Object.values(quote.branch.subscription.address).filter(Boolean).join(', ') : '',
                'negocio.telefono': quote.branch?.subscription?.contact_phone || '',
            };
            
            // Soporte para campos personalizados: {{cotizacion.custom.clave}}
            if (key.startsWith('cotizacion.custom.') && quote.custom_fields) {
                const fieldKey = key.replace('cotizacion.custom.', '');
                const val = quote.custom_fields[fieldKey];
                if (val !== undefined && val !== null) {
                    return Array.isArray(val) ? val.join(', ') : (val === true ? 'Sí' : (val === false ? 'No' : val));
                }
            }

            return map[key] !== undefined ? map[key] : ''; // Devuelve cadena vacía si no encuentra, para limpiar
        });
    };

    /**
     * Genera el HTML para la tabla de productos
     */
    const renderQuoteTable = (element, quote) => {
        const headers = `
            <tr style="background-color: ${element.data.headerColor || '#f3f4f6'}; color: ${element.data.headerTextColor || '#111827'};">
                <th style="padding: 8px; border-bottom: 1px solid #e5e7eb; text-align: center; font-size: 12px;">Cant.</th>
                <th style="padding: 8px; border-bottom: 1px solid #e5e7eb; text-align: left; font-size: 12px;">Descripción</th>
                <th style="padding: 8px; border-bottom: 1px solid #e5e7eb; text-align: right; font-size: 12px;">P. Unit</th>
                <th style="padding: 8px; border-bottom: 1px solid #e5e7eb; text-align: right; font-size: 12px;">Total</th>
            </tr>
        `;

        const rows = quote.items.map(item => `
            <tr style="border-bottom: 1px solid #e5e7eb;">
                <td style="padding: 8px; text-align: center; font-size: 12px;">${Number(item.quantity)}</td>
                <td style="padding: 8px; font-size: 12px;">
                    <div style="font-weight: 500;">${item.description}</div>
                    ${item.variant_details ? `<div style="font-size: 10px; color: #6b7280;">(${Object.values(item.variant_details).join(', ')})</div>` : ''}
                </td>
                <td style="padding: 8px; text-align: right; font-size: 12px;">${formatCurrency(item.unit_price)}</td>
                <td style="padding: 8px; text-align: right; font-size: 12px;">${formatCurrency(item.line_total)}</td>
            </tr>
        `).join('');

        let html = `
            <table style="width: 100%; border-collapse: collapse; margin-bottom: 1rem;">
                <thead>${headers}</thead>
                <tbody>${rows}</tbody>
            </table>
        `;

        // --- AGREGAR DESGLOSE SI ESTÁ HABILITADO ---
        if (element.data.showBreakdown !== false) { // Default true si no existe
            html += `
                <div style="display: flex; justify-content: flex-end; margin-top: 0.5rem; font-size: 12px;">
                    <div style="width: 200px;">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
                            <span style="color: #6b7280;">Subtotal:</span>
                            <span>${formatCurrency(quote.subtotal)}</span>
                        </div>
                        ${Number(quote.total_discount) > 0 ? `
                        <div style="display: flex; justify-content: space-between; margin-bottom: 4px; color: #ef4444;">
                            <span>Descuento:</span>
                            <span>- ${formatCurrency(quote.total_discount)}</span>
                        </div>` : ''}
                        <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
                            <span>Impuestos:</span>
                            <span>${formatCurrency(quote.total_tax)}</span>
                        </div>
                        ${Number(quote.shipping_cost) > 0 ? `
                        <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
                            <span>Envío:</span>
                            <span>${formatCurrency(quote.shipping_cost)}</span>
                        </div>` : ''}
                        <div style="display: flex; justify-content: space-between; margin-top: 8px; padding-top: 8px; border-top: 1px solid #e5e7eb; font-weight: bold; font-size: 14px;">
                            <span>Total:</span>
                            <span>${formatCurrency(quote.total_amount)}</span>
                        </div>
                    </div>
                </div>
            `;
        }

        return html;
    };

    return {
        replaceVariables,
        renderQuoteTable
    };
}