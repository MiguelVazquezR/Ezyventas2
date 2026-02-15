import { computed } from 'vue';

/**
 * Define las variables estáticas base organizadas por contexto.
 */
const getAllVariables = () => ({
    negocio: {
        group: 'Negocio',
        items: [
            { label: 'Nombre del negocio', value: '{{negocio.nombre}}' },
            { label: 'Razón social', value: '{{negocio.razon_social}}' },
            { label: 'Dirección', value: '{{negocio.direccion}}' },
            { label: 'Teléfono', value: '{{negocio.telefono}}' },
        ]
    },
    sucursal: {
        group: 'Sucursal',
        items: [
            { label: 'Nombre sucursal', value: '{{sucursal.nombre}}' },
            { label: 'Dirección', value: '{{sucursal.direccion}}' },
            { label: 'Teléfono', value: '{{sucursal.telefono}}' },
        ]
    },
    cliente: {
        group: 'Cliente (Datos Generales)',
        items: [
            { label: 'Nombre completo', value: '{{cliente.nombre}}' },
            { label: 'Teléfono', value: '{{cliente.telefono}}' },
            { label: 'Email', value: '{{cliente.email}}' },
            { label: 'Empresa', value: '{{cliente.empresa}}' },
            { label: 'Dirección', value: '{{cliente.direccion}}' },
            { label: 'RFC / Tax ID', value: '{{cliente.rfc}}' },
        ]
    },
    // --- GRUPO ACTUALIZADO: ESTADO DE CUENTA ---
    cliente_estado: {
        group: 'Cliente (Financiero / Estado)',
        items: [
            { label: 'Saldo Actual', value: '{{c.saldo_actual}}' },
            { label: 'Crédito Disponible', value: '{{c.credito_disponible}}' },
            { label: 'Límite de Crédito', value: '{{c.limite_credito}}' },
            { label: 'Total Deuda Vencida', value: '{{c.total_deuda}}' },
            { label: 'Conteo Ventas Pendientes', value: '{{c.conteo_ventas_pendientes}}' },
            { label: 'Último abono a deuda general', value: '{{c.ultimo_abono}}' },
            // Variables de TABLAS
            { label: 'Tabla: Último Pago Detallado', value: '{{c.tabla_ultimo_pago}}' },
            { label: 'Tabla: Ventas Pendientes', value: '{{c.tabla_ventas_pendientes}}' },
        ]
    },
    // -------------------------------------
    cotizacion: {
        group: 'Cotización',
        items: [
            { label: 'Folio', value: '{{cotizacion.folio}}' },
            { label: 'Fecha creación', value: '{{cotizacion.fecha_creacion}}' },
            { label: 'Fecha vencimiento', value: '{{cotizacion.fecha_vencimiento}}' },
            { label: 'Subtotal', value: '{{cotizacion.subtotal}}' },
            { label: 'Impuestos', value: '{{cotizacion.impuestos}}' },
            { label: 'Envío', value: '{{cotizacion.envio}}' },
            { label: 'Descuento', value: '{{cotizacion.descuento}}' },
            { label: 'Total', value: '{{cotizacion.total}}' },
            { label: 'Notas', value: '{{cotizacion.notas}}' },
        ]
    },
    transaccion: {
        group: 'Venta / Ticket',
        items: [
            { label: 'Folio venta', value: '{{v.folio}}' },
            { label: 'Fecha', value: '{{v.fecha}}' },
            { label: 'Hora', value: '{{v.hora}}' },
            { label: 'Subtotal', value: '{{v.subtotal}}' },
            { label: 'Total', value: '{{v.total}}' },
            { label: 'Total pagado', value: '{{v.total_pagado}}' },
            { label: 'Último pago registrado', value: '{{v.ultimo_pago}}' },
            { label: 'Restante por pagar', value: '{{v.restante_por_pagar}}' },
            { label: 'Descuentos', value: '{{v.descuentos}}' },
            { label: 'Cambio', value: '{{v.cambio}}' },
            { label: 'Métodos de pago', value: '{{v.metodos_pago}}' },
            { label: 'Cajero', value: '{{vendedor.nombre}}' },
            { label: 'Fecha vencimiento de apartado', value: '{{v.fecha_vencimiento_apartado}}' },
        ]
    },
    orden_servicio: {
        group: 'Orden de Servicio',
        items: [
            { label: 'Folio OS', value: '{{os.folio}}' },
            { label: 'Fecha recepción', value: '{{os.fecha_recepcion}}' },
            { label: 'Equipo', value: '{{os.item_description}}' },
            { label: 'Problemas', value: '{{os.problemas_reportados}}' },
            { label: 'Diagnóstico', value: '{{os.diagnostico}}' },
            { label: 'Fecha promesa', value: '{{os.fecha_promesa}}' },
        ]
    },
    producto: { 
        group: 'Producto',
        items: [
            { label: 'Nombre', value: '{{p.nombre}}' },
            { label: 'Descripción', value: '{{p.descripcion}}' },
            { label: 'Precio', value: '{{p.precio}}' },
            { label: 'SKU', value: '{{p.sku}}' },
            { label: 'Código barras', value: '{{p.codigo_barras}}' },
        ]
    }
});

/**
 * Composable para gestionar variables de plantilla filtradas por contexto.
 * @param {Function} customFieldDefinitionsGetter Getter para campos personalizados.
 * @param {String} context 'cotizacion' | 'ticket' | 'etiqueta' | 'general'
 */
export function useTemplateVariables(customFieldDefinitionsGetter, context = 'general') {

    const placeholderOptions = computed(() => {
        const allVars = getAllVariables();
        const activeGroups = [];

        // Lógica actualizada:
        if (context === 'cotizacion') {
            activeGroups.push(allVars.negocio, allVars.sucursal, allVars.cliente, allVars.cotizacion);
        } else {
            // Base para tickets, etiquetas y clientes
            activeGroups.push(allVars.negocio, allVars.sucursal, allVars.cliente);

            // Variables específicas según lo que estemos editando
            activeGroups.push(allVars.cliente_estado);
            
            activeGroups.push(
                allVars.transaccion, 
                allVars.orden_servicio, 
                allVars.producto
            );
        }

        // Procesar campos personalizados
        const definitions = (typeof customFieldDefinitionsGetter === 'function' ? customFieldDefinitionsGetter() : customFieldDefinitionsGetter) || [];
        const customFieldsByModule = {};

        if (Array.isArray(definitions)) {
            definitions.forEach(field => {
                if (!customFieldsByModule[field.module]) {
                    customFieldsByModule[field.module] = [];
                }
                customFieldsByModule[field.module].push(field);
            });
        }

        // Añadir campos personalizados según el contexto
        if (context === 'cotizacion') {
            if (customFieldsByModule['quotes']) {
                activeGroups.push({
                    group: 'Campos Personalizados (Cotización)',
                    items: customFieldsByModule['quotes'].map(field => ({
                        label: field.name,
                        value: `{{cotizacion.custom.${field.key}}}`
                    }))
                });
            }
        } else {
            if (customFieldsByModule['service_orders']) {
                 activeGroups.push({
                    group: 'Campos Personalizados (OS)',
                    items: customFieldsByModule['service_orders'].map(field => ({
                        label: field.name,
                        value: `{{os.custom.${field.key}}}`
                    }))
                });
            }
        }

        return activeGroups;
    });

    return { placeholderOptions };
}