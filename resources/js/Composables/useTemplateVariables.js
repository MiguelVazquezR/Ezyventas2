import { computed } from 'vue';

/**
 * Define las variables estáticas base para todos los tipos de plantillas,
 * ahora organizadas por su modelo/entidad lógica.
 */
const getBaseVariables = () => ([
    {
        group: 'Negocio',
        items: [
            { label: 'Nombre del negocio', value: '{{negocio.nombre}}' },
            { label: 'Razón social', value: '{{negocio.razon_social}}' },
            { label: 'Dirección del negocio', value: '{{negocio.direccion}}' },
            { label: 'Teléfono del negocio', value: '{{negocio.telefono}}' },
        ]
    },
    {
        group: 'Sucursal',
        items: [
            { label: 'Nombre sucursal', value: '{{sucursal.nombre}}' },
            { label: 'Dirección sucursal', value: '{{sucursal.direccion}}' },
            { label: 'Teléfono sucursal', value: '{{sucursal.telefono}}' },
        ]
    },
    {
        group: 'Transacción (Venta)',
        items: [
            { label: 'Folio', value: '{{v.folio}}' }, { label: 'Fecha', value: '{{v.fecha}}' }, { label: 'Hora', value: '{{v.hora}}' },
            { label: 'Fecha y hora', value: '{{v.fecha_hora}}' }, { label: 'Subtotal', value: '{{v.subtotal}}' }, { label: 'Descuentos', value: '{{v.descuentos}}' },
            { label: 'Impuestos', value: '{{v.impuestos}}' }, { label: 'Total', value: '{{v.total}}' },
            { label: 'Métodos de pago', value: '{{v.metodos_pago}}' },
            { label: 'Total pagado', value: '{{v.total_pagado}}' },
            { label: 'Notas de venta', value: '{{v.notas_venta}}' },
        ]
    },
    {
        group: 'Orden de Servicio',
        items: [
            { label: 'Folio (OS)', value: '{{os.folio}}' }, { label: 'Fecha recepción', value: '{{os.fecha_recepcion}}' }, { label: 'Hora recepción', value: '{{os.hora_recepcion}}' },
            { label: 'Fecha y Hora recepción', value: '{{os.fecha_hora_recepcion}}' }, { label: 'Problemas reportados', value: '{{os.problemas_reportados}}' },
            { label: 'Equipo/Máquina', value: '{{os.item_description}}' }, { label: 'Subtotal (OS)', value: '{{os.subtotal}}' }, { label: 'Descuento (OS)', value: '{{os.descuento}}' },
            { label: 'Total (OS)', value: '{{os.total}}' },
            // Las variables de pago se movieron a 'Transacción' ya que dependen de ella.
        ]
    },
    {
        group: 'Cliente',
        items: [
            { label: 'Nombre del cliente', value: '{{cliente.nombre}}' },
            { label: 'Teléfono del cliente', value: '{{cliente.telefono}}' },
            { label: 'Email del cliente', value: '{{cliente.email}}' },
            { label: 'Empresa del cliente', value: '{{cliente.empresa}}' },
        ]
    },
    {
        group: 'Vendedor',
        items: [{ label: 'Nombre del vendedor', value: '{{vendedor.nombre}}' }]
    },
    {
        group: 'Producto (para etiquetas)',
        items: [
            { label: 'Nombre producto', value: '{{p.nombre}}' },
            { label: 'Precio producto', value: '{{p.precio}}' },
            { label: 'SKU producto', value: '{{p.sku}}' },
        ]
    },
]);

/**
 * Composable de Vue para gestionar las variables de plantilla.
 * Acepta una función getter para los campos personalizados para mantener la reactividad.
 * @param {import('vue').ComputedRef<Array> | Function} customFieldDefinitionsGetter - Una función que devuelve el array de definiciones de campos personalizados (ej. () => props.customFieldDefinitions).
 */
export function useTemplateVariables(customFieldDefinitionsGetter) {

    const placeholderOptions = computed(() => {
        const options = getBaseVariables();
        const customFieldsByModule = {};

        // Llama al getter para obtener el array actual. Asegura que sea un array.
        const definitions = (typeof customFieldDefinitionsGetter === 'function' ? customFieldDefinitionsGetter() : customFieldDefinitionsGetter) || [];

        if (Array.isArray(definitions)) {
            definitions.forEach(field => {
                if (!customFieldsByModule[field.module]) {
                    customFieldsByModule[field.module] = [];
                }
                customFieldsByModule[field.module].push(field);
            });
        }

        // Añadir campos personalizados de Órdenes de Servicio
        if (customFieldsByModule['service_orders']) {
            options.push({
                group: 'Campos Personalizados (Orden de Servicio)',
                items: customFieldsByModule['service_orders'].map(field => ({
                    label: field.name,
                    value: `{{os.custom.${field.key}}}`
                }))
            });
        }
        
        // Aquí puedes añadir más lógica para otros módulos (ej. 'products') si los implementas en el backend
        // if (customFieldsByModule['products']) { ... }

        return options;
    });

    return { placeholderOptions };
}