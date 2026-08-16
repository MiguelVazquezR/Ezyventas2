// SAT CFDI 4.0 catalogs shared by the invoice form partials.
// Source: catálogos SAT (c_UsoCFDI, c_FormaPago, c_MetodoPago, c_RegimenFiscal,
// c_ObjetoImp, c_ClaveUnidad, c_TipoDeComprobante, c_Exportacion, c_TipoRelacion).

// ── Uso de CFDI (c_UsoCFDI) ──
export const cfdiUseOptions = [
    { label: 'G01 - Adquisición de mercancías', value: 'G01' },
    { label: 'G02 - Devoluciones, descuentos o bonificaciones', value: 'G02' },
    { label: 'G03 - Gastos en general', value: 'G03' },
    { label: 'I01 - Construcciones', value: 'I01' },
    { label: 'I02 - Mobiliario y equipo de oficina', value: 'I02' },
    { label: 'I03 - Equipo de transporte', value: 'I03' },
    { label: 'I04 - Equipo de cómputo', value: 'I04' },
    { label: 'D01 - Honorarios médicos', value: 'D01' },
    { label: 'D02 - Gastos médicos por incapacidad', value: 'D02' },
    { label: 'D03 - Gastos funerales', value: 'D03' },
    { label: 'D04 - Donativos', value: 'D04' },
    { label: 'D05 - Intereses hipotecarios', value: 'D05' },
    { label: 'D06 - Aportaciones SAR', value: 'D06' },
    { label: 'D07 - Primas seguros médicos', value: 'D07' },
    { label: 'D08 - Transportación escolar', value: 'D08' },
    { label: 'D09 - Depósitos en ahorro', value: 'D09' },
    { label: 'D10 - Servicios educativos', value: 'D10' },
    { label: 'P01 - Por definir', value: 'P01' },
    { label: 'CP01 - Pagos', value: 'CP01' },
    { label: 'S01 - Sin efectos fiscales', value: 'S01' },
];

// ── Forma de pago (c_FormaPago) ──
export const paymentFormOptions = [
    { label: '01 - Efectivo', value: '01' },
    { label: '02 - Cheque nominativo', value: '02' },
    { label: '03 - Transferencia electrónica', value: '03' },
    { label: '04 - Tarjeta de crédito', value: '04' },
    { label: '28 - Tarjeta de débito', value: '28' },
    { label: '99 - Por definir', value: '99' },
];

// ── Método de pago (c_MetodoPago) ──
export const paymentMethodOptions = [
    { label: 'PUE - Pago en una sola exhibición', value: 'PUE' },
    { label: 'PPD - Pago en parcialidades o diferido', value: 'PPD' },
];

// ── Régimen fiscal (c_RegimenFiscal) ──
export const taxRegimeOptions = [
    { value: '601', label: '601 - General de Ley Personas Morales' },
    { value: '603', label: '603 - Personas Morales con Fines no Lucrativos' },
    { value: '605', label: '605 - Sueldos y Salarios' },
    { value: '606', label: '606 - Arrendamiento' },
    { value: '607', label: '607 - Régimen de Enajenación o Adquisición de Bienes' },
    { value: '608', label: '608 - Demás ingresos' },
    { value: '609', label: '609 - Consolidación' },
    { value: '610', label: '610 - Residentes en el Extranjero' },
    { value: '611', label: '611 - Ingresos por Dividendos (socios y accionistas)' },
    { value: '612', label: '612 - Personas Físicas con Actividades Empresariales y Profesionales' },
    { value: '614', label: '614 - Ingresos por intereses' },
    { value: '615', label: '615 - Régimen de los ingresos por obtención de premios' },
    { value: '616', label: '616 - Sin obligaciones fiscales' },
    { value: '621', label: '621 - Incorporación Fiscal' },
    { value: '625', label: '625 - Régimen de las actividades empresariales con ingresos a través de Plataformas Tecnológicas' },
    { value: '626', label: '626 - Régimen Simplificado de Confianza' },
    { value: '628', label: '628 - Hidrocarburos' },
    { value: '629', label: '629 - De los regímenes fiscales preferentes y de las empresas multinacionales' },
    { value: '630', label: '630 - Enajenación de acciones en bolsa de valores' },
];

// Resuelve "clave - nombre" (si la clave no está en el catálogo, devuelve solo la clave)
export const getRegimeLabel = (code) => {
    if (!code) return '';
    return taxRegimeOptions.find(o => o.value === code)?.label || code;
};

// ── Objeto de impuesto (c_ObjetoImp) ──
export const objetoImpOptions = [
    { label: '02 - Sí objeto de impuesto', value: '02', description: 'Aplica en más del 90% de los casos. Úsalo cuando la venta o servicio esté sujeto a IVA (16%, 0% o exento) o IEPS.' },
    { label: '01 - No objeto de impuesto', value: '01', description: 'Operaciones que no están sujetas a las leyes mexicanas de IVA/IEPS (ej. servicios o productos prestados/vendidos 100% en el extranjero).' },
    { label: '03 - Sí objeto (sin desglose)', value: '03', description: 'Operaciones gravadas donde la norma permite omitir el desglose de impuesto por concepto (ej. ciertas ventas al público en general).' },
    { label: '04 - Sí objeto y no causa impuesto', value: '04', description: 'Operaciones que entran en la norma de impuestos pero por disposición legal específica no generan el cobro directo del mismo.' },
];

// ── Tasa IVA ──
export const taxRateOptions = [
    { label: '16%', value: 0.16 },
    { label: '0%', value: 0 },
    { label: 'Exento', value: 'Exento' },
];

// ── Clave unidad (c_ClaveUnidad) ──
export const satUnitOptions = [
    { value: 'H87', label: 'H87 - Pieza', description: 'Artículos individuales / Productos físicos' },
    { value: 'E48', label: 'E48 - Unidad de servicio', description: 'Servicios (consultoría, desarrollo, honorarios, comisiones)' },
    { value: 'KGM', label: 'KGM - Kilogramo', description: 'Materiales, alimentos a granel, peso' },
    { value: 'LTR', label: 'LTR - Litro', description: 'Líquidos, insumos' },
    { value: 'MTR', label: 'MTR - Metro', description: 'Telas, cables, construcción' },
    { value: 'XBX', label: 'XBX - Caja', description: 'Empaques o ventas agrupadas' },
    { value: 'XPK', label: 'XPK - Paquete', description: 'Kits o venta agrupada' },
    { value: 'DAY', label: 'DAY - Día', description: 'Arrendamiento de equipo, hospedaje' },
    { value: 'HUR', label: 'HUR - Hora', description: 'Soporte por tiempo, asesorías' },
    { value: 'MON', label: 'MON - Mes', description: 'Suscripciones, rentas' },
    { value: 'ACT', label: 'ACT - Actividad', description: 'Tareas de mantenimiento o servicios por avance' },
];

// ── Tipo de comprobante (c_TipoDeComprobante) ──
export const comprobanteTypeOptions = [
    { value: 'I', label: 'I - Ingreso', description: 'Factura estándar' },
    { value: 'E', label: 'E - Egreso', description: 'Nota de crédito' },
    { value: 'P', label: 'P - Pago', description: 'Recibo de pago de factura PPD' },
    { value: 'T', label: 'T - Traslado', description: 'Carta porte / traslado' },
];

// Lookup helpers — when using optionValue the #value slot receives the raw
// string value, not the option object, so we resolve the label manually.
// They also tolerate being passed the full option object (defensive).
export const getComprobanteTypeLabel = (val) => {
    if (val && typeof val === 'object') return val.label || '';
    return comprobanteTypeOptions.find(o => o.value === val)?.label || val || '';
};

// ── Exportación (c_Exportacion) ──
export const exportacionOptions = [
    { value: '01', label: '01 - No aplica', description: '' },
    { value: '02', label: '02 - Definitiva con clave de pedimento A1', description: '' },
    { value: '03', label: '03 - Temporal', description: '' },
    { value: '04', label: '04 - Definitiva con clave de pedimento distinta a A1 o no requerida', description: '' },
];

export const getExportacionLabel = (val) => {
    if (val && typeof val === 'object') return val.label || '';
    return exportacionOptions.find(o => o.value === val)?.label || val || '';
};

// ── Tipo de relación (c_TipoRelacion) — solo aplica a notas de crédito (Tipo E) ──
export const tipoRelacionOptions = [
    { label: '01 - Nota de crédito de los documentos relacionados', value: '01' },
    { label: '02 - Débito de los documentos relacionados', value: '02' },
    { label: '03 - Sustitución de los CFDI previos', value: '03' },
    { label: '04 - Sustitución de los CFDI por un timbre fiscal digital', value: '04' },
    { label: '05 - Sustitución de los CFDI por un CFDI de pagos', value: '05' },
    { label: '06 - Factura de traslado', value: '06' },
    { label: '07 - CFDI por aplicación de anticipo', value: '07' },
    { label: '08 - Factura generada por pagos en parcialidades', value: '08' },
    { label: '09 - Factura generada por pagos diferidos', value: '09' },
];

// ── Moneda del pago (Complemento de pago 2.0) ──
export const pagoMonedaOptions = [
    { label: 'MXN - Peso mexicano', value: 'MXN' },
    { label: 'USD - Dólar estadounidense', value: 'USD' },
];
