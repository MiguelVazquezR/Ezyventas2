import { computed } from 'vue';

export function useTemplateRenderer() {
    
    const formatCurrency = (value) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value || 0);
    const formatDate = (dateString) => {
        if (!dateString) return 'N/A';
        const date = new Date(dateString);
        const userTimezoneOffset = date.getTimezoneOffset() * 60000;
        return new Date(date.getTime() + userTimezoneOffset).toLocaleDateString('es-MX', { year: 'numeric', month: 'long', day: 'numeric' });
    };

    /**
     * Obtiene la URL de la imagen del producto/servicio/variante
     */
    const getItemImage = (item) => {
        // CASO 1: Producto Simple o Servicio (La imagen está en el itemable directo)
        // Verificamos si itemable existe y tiene media
        if (item.itemable && item.itemable.media && item.itemable.media.length > 0) {
            // Priorizar colección general si existe
            const generalImg = item.itemable.media.find(m => m.collection_name === 'product-general-images');
            if (generalImg) return generalImg.original_url;
            
            // Fallback a la primera imagen cualquiera si no es variante
            if (!item.itemable_type.includes('ProductAttribute')) {
                 return item.itemable.media[0].original_url;
            }
        }

        // CASO 2: Variante de Producto (La imagen está en el producto PADRE)
        // item.itemable es ProductAttribute
        // item.itemable.product es el Product padre
        if (item.itemable_type && item.itemable_type.includes('ProductAttribute') && item.itemable?.product?.media) {
            const parentMedia = item.itemable.product.media;
            
            // Estrategia: Buscar imagen específica de la variante basada en las opciones seleccionadas
            if (item.variant_details && typeof item.variant_details === 'object') {
                // Obtenemos los valores de las opciones (ej. ["Rojo", "XL"])
                // Convertimos a string para comparación segura por si acaso llegan números
                const selectedOptions = Object.values(item.variant_details).map(v => String(v));

                // Buscamos en la colección de variantes una imagen que tenga el 'variant_option' coincidente
                const variantImg = parentMedia.find(m => {
                    return m.collection_name === 'product-variant-images' && 
                           m.custom_properties && 
                           m.custom_properties.variant_option &&
                           selectedOptions.includes(String(m.custom_properties.variant_option));
                });

                if (variantImg) return variantImg.original_url;
            }

            // Fallback 1: Buscar imagen general del padre
            const parentGeneralImg = parentMedia.find(m => m.collection_name === 'product-general-images');
            if (parentGeneralImg) return parentGeneralImg.original_url;

            // Fallback 2: Si no hay general, devolver la primera que encuentre del padre
            if (parentMedia.length > 0) return parentMedia[0].original_url;
        }

        return null;
    };

    const replaceVariables = (text, quote) => {
        if (!text) return '';
        return text.replace(/{{(.*?)}}/g, (match, p1) => {
            const key = p1.trim();
            
            const map = {
                // --- VARIABLES GENERALES ---
                'folio': quote.folio,
                'fecha_creacion': formatDate(quote.created_at),
                'fecha_vencimiento': formatDate(quote.expiry_date),
                
                // --- VARIABLES DE COTIZACIÓN ---
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
            
            if (key.startsWith('cotizacion.custom.') && quote.custom_fields) {
                const fieldKey = key.replace('cotizacion.custom.', '');
                const val = quote.custom_fields[fieldKey];
                if (val !== undefined && val !== null) {
                    return Array.isArray(val) ? val.join(', ') : (val === true ? 'Sí' : (val === false ? 'No' : val));
                }
            }

            return map[key] !== undefined ? map[key] : ''; 
        });
    };

    const renderQuoteTable = (element, quote) => {
        const showImages = element.data.showImages === true;
        
        let headers = `
            <tr style="background-color: ${element.data.headerColor || '#f3f4f6'}; color: ${element.data.headerTextColor || '#111827'};">
                ${showImages ? '<th style="padding: 8px; border-bottom: 1px solid #e5e7eb; text-align: center; font-size: 12px; width: 60px;">Img</th>' : ''}
                <th style="padding: 8px; border-bottom: 1px solid #e5e7eb; text-align: center; font-size: 12px; width: 50px;">Cant.</th>
                <th style="padding: 8px; border-bottom: 1px solid #e5e7eb; text-align: left; font-size: 12px;">Descripción</th>
                <th style="padding: 8px; border-bottom: 1px solid #e5e7eb; text-align: right; font-size: 12px; width: 80px;">P. Unit</th>
                <th style="padding: 8px; border-bottom: 1px solid #e5e7eb; text-align: right; font-size: 12px; width: 80px;">Total</th>
            </tr>
        `;

        const rows = quote.items.map(item => {
            const imageUrl = showImages ? getItemImage(item) : null;
            const imageCell = showImages 
                ? `<td style="padding: 8px; text-align: center; vertical-align: middle; border-bottom: 1px solid #e5e7eb;">
                    ${imageUrl ? `<img src="${imageUrl}" style="width: 40px; height: 40px; object-fit: cover; border-radius: 4px;" />` : ''}
                   </td>` 
                : '';

            return `
            <tr style="border-bottom: 1px solid #e5e7eb;">
                ${imageCell}
                <td style="padding: 8px; text-align: center; font-size: 12px; vertical-align: top;">${Number(item.quantity)}</td>
                <td style="padding: 8px; font-size: 12px; vertical-align: top;">
                    <div style="font-weight: 500;">${item.description}</div>
                    ${item.variant_details ? `<div style="font-size: 10px; color: #6b7280;">(${Object.values(item.variant_details).join(', ')})</div>` : ''}
                </td>
                <td style="padding: 8px; text-align: right; font-size: 12px; vertical-align: top;">${formatCurrency(item.unit_price)}</td>
                <td style="padding: 8px; text-align: right; font-size: 12px; vertical-align: top;">${formatCurrency(item.line_total)}</td>
            </tr>
            `;
        }).join('');

        let html = `
            <table style="width: 100%; border-collapse: collapse; margin-bottom: 1rem;">
                <thead>${headers}</thead>
                <tbody>${rows}</tbody>
            </table>
        `;

        if (element.data.showBreakdown !== false) { 
            html += `
                <div style="display: flex; justify-content: flex-end; margin-top: 0.5rem; font-size: 12px; page-break-inside: avoid;">
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