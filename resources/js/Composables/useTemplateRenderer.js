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

    const replaceVariables = (text, dataSource, context = 'quote') => {
        if (!text) return '';
        return text.replace(/{{(.*?)}}/g, (match, p1) => {
            const key = p1.trim();

            // --- MAPA COMÚN (negocio, sucursal, cliente) ---
            const branch = dataSource?.branch;
            const subscription = branch?.subscription;
            const customer = dataSource?.customer;

            const commonMap = {
                'negocio.nombre': subscription?.commercial_name || '',
                'negocio.razon_social': subscription?.business_name || '',
                'negocio.direccion': subscription?.address ? Object.values(subscription.address).filter(Boolean).join(', ') : '',
                'negocio.telefono': subscription?.contact_phone || '',
                'sucursal.nombre': branch?.name || '',
                'sucursal.direccion': branch?.address ? Object.values(branch.address).filter(Boolean).join(', ') : '',
                'sucursal.telefono': branch?.contact_phone || '',
                'cliente.nombre': customer?.name || dataSource?.customer_name || dataSource?.recipient_name || '',
                'cliente.email': customer?.email || dataSource?.customer_email || dataSource?.recipient_email || '',
                'cliente.telefono': customer?.phone || dataSource?.customer_phone || dataSource?.recipient_phone || '',
                'cliente.direccion': customer?.address ? Object.values(customer.address).filter(Boolean).join(', ') : (dataSource?.shipping_address || ''),
                'cliente.empresa': customer?.company_name || '',
                'cliente.rfc': customer?.tax_id || '',
                'empresa.nombre': subscription?.commercial_name || '',
            };

            if (context === 'service_order') {
                const osMap = {
                    'os.folio': dataSource?.folio || '',
                    'os.fecha_recepcion': formatDate(dataSource?.received_at),
                    'os.hora_recepcion': dataSource?.received_at ? new Date(dataSource.received_at).toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit' }) : '',
                    'os.fecha_hora_recepcion': dataSource?.received_at ? formatDate(dataSource.received_at) + ' ' + new Date(dataSource.received_at).toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit' }) : '',
                    'os.subtotal': formatCurrency(dataSource?.subtotal),
                    'os.descuento': formatCurrency(dataSource?.discount_amount),
                    'os.total': formatCurrency(dataSource?.final_total),
                    'os.problemas_reportados': dataSource?.reported_problems || '',
                    'os.item_description': dataSource?.item_description || '',
                    'os.diagnostico': dataSource?.technician_diagnosis || '',
                    'os.fecha_promesa': dataSource?.promised_at ? formatDate(dataSource.promised_at) : '',
                };
                // Check custom fields for service orders
                if (key.startsWith('os.custom.') && dataSource?.custom_fields) {
                    const fieldKey = key.replace('os.custom.', '');
                    const val = dataSource.custom_fields[fieldKey];
                    if (val !== undefined && val !== null) {
                        return Array.isArray(val) ? val.join(', ') : (val === true ? 'Sí' : (val === false ? 'No' : val));
                    }
                }
                if (osMap[key] !== undefined) return osMap[key];
            } else {
                // Quote context
                const quoteMap = {
                    'folio': dataSource?.folio,
                    'fecha_creacion': formatDate(dataSource?.created_at),
                    'fecha_vencimiento': formatDate(dataSource?.expiry_date),
                    'cotizacion.folio': dataSource?.folio,
                    'cotizacion.fecha_creacion': formatDate(dataSource?.created_at),
                    'cotizacion.fecha_vencimiento': formatDate(dataSource?.expiry_date),
                    'cotizacion.subtotal': formatCurrency(dataSource?.subtotal),
                    'cotizacion.impuestos': formatCurrency(dataSource?.total_tax),
                    'cotizacion.envio': formatCurrency(dataSource?.shipping_cost),
                    'cotizacion.descuento': formatCurrency(dataSource?.total_discount),
                    'cotizacion.total': formatCurrency(dataSource?.total_amount),
                    'cotizacion.notas': dataSource?.notes || '',
                };
                if (key.startsWith('cotizacion.custom.') && dataSource?.custom_fields) {
                    const fieldKey = key.replace('cotizacion.custom.', '');
                    const val = dataSource.custom_fields[fieldKey];
                    if (val !== undefined && val !== null) {
                        return Array.isArray(val) ? val.join(', ') : (val === true ? 'Sí' : (val === false ? 'No' : val));
                    }
                }
                if (quoteMap[key] !== undefined) return quoteMap[key];
            }

            // Common map fallback
            if (commonMap[key] !== undefined) return commonMap[key];

            return '';
        });
    };

    const renderQuoteTable = (elementData, dataSource, context = 'quote') => {
        const showImages = elementData.showImages === true;
        const items = dataSource?.items || [];

        let headers = `
            <tr style="background-color: ${elementData.headerColor || '#f3f4f6'}; color: ${elementData.headerTextColor || '#111827'};">
                ${showImages ? '<th style="padding: 8px; border-bottom: 1px solid #e5e7eb; text-align: center; font-size: 12px; width: 60px;">Img</th>' : ''}
                <th style="padding: 8px; border-bottom: 1px solid #e5e7eb; text-align: center; font-size: 12px; width: 50px;">Cant.</th>
                <th style="padding: 8px; border-bottom: 1px solid #e5e7eb; text-align: left; font-size: 12px;">Descripción</th>
                <th style="padding: 8px; border-bottom: 1px solid #e5e7eb; text-align: right; font-size: 12px; width: 80px;">P. Unit</th>
                <th style="padding: 8px; border-bottom: 1px solid #e5e7eb; text-align: right; font-size: 12px; width: 80px;">Total</th>
            </tr>
        `;

        const getItemTypeLabel = (item) => {
            if (!item.itemable_type) return 'Servicio';
            return item.itemable_type.includes('Product') ? 'Producto' : 'Servicio';
        };

        const rows = items.map(item => {
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
                    <div style="font-weight: 500;">${getItemTypeLabel(item)}: ${item.description}</div>
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

        if (elementData.showBreakdown !== false) {
            if (context === 'service_order') {
                html += `
                    <div style="display: flex; justify-content: flex-end; margin-top: 0.5rem; font-size: 12px; page-break-inside: avoid;">
                        <div style="width: 200px;">
                            <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
                                <span style="color: #6b7280;">Subtotal:</span>
                                <span>${formatCurrency(dataSource.subtotal)}</span>
                            </div>
                            ${Number(dataSource.discount_amount) > 0 ? `
                            <div style="display: flex; justify-content: space-between; margin-bottom: 4px; color: #ef4444;">
                                <span>Descuento:</span>
                                <span>- ${formatCurrency(dataSource.discount_amount)}</span>
                            </div>` : ''}
                            <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
                                <span>Impuestos:</span>
                                <span>${formatCurrency(dataSource.total_tax || 0)}</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; margin-top: 8px; padding-top: 8px; border-top: 1px solid #e5e7eb; font-weight: bold; font-size: 14px;">
                                <span>Total:</span>
                                <span>${formatCurrency(dataSource.final_total)}</span>
                            </div>
                        </div>
                    </div>
                `;
            } else {
                html += `
                    <div style="display: flex; justify-content: flex-end; margin-top: 0.5rem; font-size: 12px; page-break-inside: avoid;">
                        <div style="width: 200px;">
                            <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
                                <span style="color: #6b7280;">Subtotal:</span>
                                <span>${formatCurrency(dataSource.subtotal)}</span>
                            </div>
                            ${Number(dataSource.total_discount) > 0 ? `
                            <div style="display: flex; justify-content: space-between; margin-bottom: 4px; color: #ef4444;">
                                <span>Descuento:</span>
                                <span>- ${formatCurrency(dataSource.total_discount)}</span>
                            </div>` : ''}
                            <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
                                <span>Impuestos:</span>
                                <span>${formatCurrency(dataSource.total_tax)}</span>
                            </div>
                            ${Number(dataSource.shipping_cost) > 0 ? `
                            <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
                                <span>Envío:</span>
                                <span>${formatCurrency(dataSource.shipping_cost)}</span>
                            </div>` : ''}
                            <div style="display: flex; justify-content: space-between; margin-top: 8px; padding-top: 8px; border-top: 1px solid #e5e7eb; font-weight: bold; font-size: 14px;">
                                <span>Total:</span>
                                <span>${formatCurrency(dataSource.total_amount)}</span>
                            </div>
                        </div>
                    </div>
                `;
            }
        }

        return html;
    };

    return {
        replaceVariables,
        renderQuoteTable
    };
}