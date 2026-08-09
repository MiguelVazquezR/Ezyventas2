// SAT CFDI 4.0 — Catálogo de usos de CFDI (c_UsoCFDI) con su compatibilidad de
// regímenes fiscales (c_RegimenFiscal). Alimenta el asistente "Catálogo y
// Compatibilidad de Uso de CFDI" del formulario de facturación para evitar
// errores de timbrado (regla Anexo 20: el uso de CFDI debe ser válido para el
// régimen fiscal del receptor).

export const cfdiUseCatalog = [
    {
        clave: 'G01',
        descripcion: 'Adquisición de mercancías',
        aplicaFisica: true,
        aplicaMoral: true,
        regimenesPermitidos: ['601', '603', '606', '612', '620', '621', '622', '623', '624', '625', '626'],
        incompatibles: ['605', '607', '608', '610', '611', '614', '615', '616'],
        nota: 'Para compra de inventarios o mercancía destinada a la venta.',
    },
    {
        clave: 'G02',
        descripcion: 'Devoluciones, descuentos o bonificaciones',
        aplicaFisica: true,
        aplicaMoral: true,
        regimenesPermitidos: ['601', '603', '606', '612', '620', '621', '622', '623', '624', '625', '626'],
        incompatibles: ['605', '607', '608', '610', '611', '614', '615', '616'],
        nota: 'Utilizado principalmente en notas de crédito.',
    },
    {
        clave: 'G03',
        descripcion: 'Gastos en general',
        aplicaFisica: true,
        aplicaMoral: true,
        regimenesPermitidos: ['601', '603', '606', '612', '620', '621', '622', '623', '624', '625', '626'],
        incompatibles: ['605', '607', '608', '610', '611', '614', '615', '616'],
        nota: 'No aplica para empleados bajo régimen de Sueldos y Salarios (605).',
    },
    {
        clave: 'I01',
        descripcion: 'Construcciones',
        aplicaFisica: true,
        aplicaMoral: true,
        regimenesPermitidos: ['601', '603', '606', '612', '620', '621', '622', '623', '624', '625', '626'],
        incompatibles: ['605', '607', '608', '610', '611', '614', '615', '616'],
    },
    {
        clave: 'I02',
        descripcion: 'Mobiliario y equipo de oficina por inversiones',
        aplicaFisica: true,
        aplicaMoral: true,
        regimenesPermitidos: ['601', '603', '606', '612', '620', '621', '622', '623', '624', '625', '626'],
        incompatibles: ['605', '607', '608', '610', '611', '614', '615', '616'],
    },
    {
        clave: 'I03',
        descripcion: 'Equipo de transporte',
        aplicaFisica: true,
        aplicaMoral: true,
        regimenesPermitidos: ['601', '603', '606', '612', '620', '621', '622', '623', '624', '625', '626'],
        incompatibles: ['605', '607', '608', '610', '611', '614', '615', '616'],
    },
    {
        clave: 'I04',
        descripcion: 'Equipo de cómputo y accesorios',
        aplicaFisica: true,
        aplicaMoral: true,
        regimenesPermitidos: ['601', '603', '606', '612', '620', '621', '622', '623', '624', '625', '626'],
        incompatibles: ['605', '607', '608', '610', '611', '614', '615', '616'],
    },
    {
        clave: 'I08',
        descripcion: 'Otra maquinaria y equipo',
        aplicaFisica: true,
        aplicaMoral: true,
        regimenesPermitidos: ['601', '603', '606', '612', '620', '621', '622', '623', '624', '625', '626'],
        incompatibles: ['605', '607', '608', '610', '611', '614', '615', '616'],
    },
    {
        clave: 'D01',
        descripcion: 'Honorarios médicos, dentales y gastos hospitalarios',
        aplicaFisica: true,
        aplicaMoral: false,
        regimenesPermitidos: ['605', '606', '607', '608', '611', '612', '614', '615', '625'],
        incompatibles: ['601', '603', '620', '626'],
        nota: 'Deducción personal exclusiva para Personas Físicas. NO aplica para RESICO (626) ni Personas Morales.',
    },
    {
        clave: 'D02',
        descripcion: 'Gastos médicos por incapacidad o discapacidad',
        aplicaFisica: true,
        aplicaMoral: false,
        regimenesPermitidos: ['605', '606', '607', '608', '611', '612', '614', '615', '625'],
        incompatibles: ['601', '603', '620', '626'],
    },
    {
        clave: 'D04',
        descripcion: 'Donativos',
        aplicaFisica: true,
        aplicaMoral: false,
        regimenesPermitidos: ['605', '606', '607', '608', '611', '612', '614', '615', '625'],
        incompatibles: ['601', '603', '620', '626'],
    },
    {
        clave: 'D07',
        descripcion: 'Primas por seguros de gastos médicos',
        aplicaFisica: true,
        aplicaMoral: false,
        regimenesPermitidos: ['605', '606', '607', '608', '611', '612', '614', '615', '625'],
        incompatibles: ['601', '603', '620', '626'],
    },
    {
        clave: 'D10',
        descripcion: 'Pagos por servicios educativos (Colegiaturas)',
        aplicaFisica: true,
        aplicaMoral: false,
        regimenesPermitidos: ['605', '606', '607', '608', '611', '612', '614', '615', '625'],
        incompatibles: ['601', '603', '620', '626'],
    },
    {
        clave: 'S01',
        descripcion: 'Sin efectos fiscales',
        aplicaFisica: true,
        aplicaMoral: true,
        regimenesPermitidos: ['601', '603', '605', '606', '607', '608', '610', '611', '612', '614', '615', '616', '620', '621', '622', '623', '624', '625', '626'],
        incompatibles: [],
        nota: 'Universal. Compatible con todos los regímenes fiscales.',
    },
    {
        clave: 'CP01',
        descripcion: 'Pagos',
        aplicaFisica: true,
        aplicaMoral: true,
        regimenesPermitidos: ['601', '603', '605', '606', '607', '608', '610', '611', '612', '614', '615', '616', '620', '621', '622', '623', '624', '625', '626'],
        incompatibles: [],
        nota: 'Uso exclusivo para Recibos Electrónicos de Pago (Complemento de Pago).',
    },
    {
        clave: 'CN01',
        descripcion: 'Nómina',
        aplicaFisica: true,
        aplicaMoral: false,
        regimenesPermitidos: ['605'],
        incompatibles: ['601', '603', '606', '612', '620', '626'],
        nota: 'Exclusivo para recibos de nómina de empleados con régimen 605.',
    },
];

// Nombre corto y simplificado de cada régimen fiscal (c_RegimenFiscal) para la
// vista tipo tabla del asistente. Fuente: SAT Anexo 20.
const regimeShortNames = {
    '601': 'General de Ley (Personas Morales)',
    '603': 'Sin Fines de Lucro',
    '605': 'Sueldos y Salarios',
    '606': 'Arrendamiento',
    '607': 'Enajenación o Adquisición de Bienes',
    '608': 'Demás Ingresos',
    '610': 'Residentes en el Extranjero',
    '611': 'Dividendos (Socios y Accionistas)',
    '612': 'Servicios Profesionales (Honorarios)',
    '614': 'Ingresos por Intereses',
    '615': 'Premios',
    '616': 'Sin Obligaciones Fiscales',
    '620': 'Sociedades Cooperativas',
    '621': 'Incorporación Fiscal',
    '622': 'Actividades Agropecuarias',
    '623': 'Grupos de Sociedades',
    '624': 'Coordinados',
    '625': 'Plataformas Tecnológicas',
    '626': 'RESICO',
};

// Resuelve el nombre corto de un régimen fiscal por su clave (devuelve la clave
// si no existe en el mapa).
export const getRegimeShortName = (code) => regimeShortNames[code] || code;

// Tipo de persona al que pertenece cada régimen fiscal, para agruparlos en la
// tabla: 'fisica' | 'moral' | 'ambos' (aplica a ambos tipos de persona).
const regimePersonType = {
    '601': 'moral',
    '603': 'moral',
    '605': 'fisica',
    '606': 'fisica',
    '607': 'fisica',
    '608': 'fisica',
    '610': 'ambos',
    '611': 'fisica',
    '612': 'fisica',
    '614': 'fisica',
    '615': 'fisica',
    '616': 'ambos',
    '620': 'moral',
    '621': 'fisica',
    '622': 'ambos',
    '623': 'moral',
    '624': 'moral',
    '625': 'fisica',
    '626': 'ambos',
};

export const getRegimePersonType = (code) => regimePersonType[code] || 'ambos';

// Divide una lista de claves de régimen en { fisica, moral }. Los regímenes
// que aplican a ambos tipos ('ambos') se incluyen en las dos listas.
export const splitRegimesByPersonType = (codes) => {
    const fisica = [];
    const moral = [];
    for (const code of codes) {
        const type = getRegimePersonType(code);
        if (type === 'fisica' || type === 'ambos') fisica.push(code);
        if (type === 'moral' || type === 'ambos') moral.push(code);
    }
    return { fisica, moral };
};
