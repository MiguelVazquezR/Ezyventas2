/**
 * SAT catalogs shared across billing views.
 * Add other SAT catalog helpers here as needed (e.g., UsoCFDI, MetodoPago).
 */

const TAX_REGIME_MAP = {
    '601': 'General de Ley Personas Morales',
    '603': 'Personas Morales con Fines no Lucrativos',
    '605': 'Sueldos y Salarios',
    '606': 'Arrendamiento',
    '608': 'Demás ingresos',
    '612': 'Personas Físicas con Actividades Empresariales',
    '614': 'Ingresos por intereses',
    '616': 'Sin obligaciones fiscales',
    '620': 'Sociedades Cooperativas',
    '621': 'Incorporación Fiscal',
    '622': 'Actividades Agrícolas, Ganaderas, Silvícolas y Pesqueras',
    '626': 'Régimen Simplificado de Confianza',
};

/**
 * Returns the human-readable label for a SAT tax regime code.
 * Falls back to the raw code if not found.
 */
export function taxRegimeLabel(code) {
    return TAX_REGIME_MAP[code] || code || '—';
}

/**
 * Full options list for Select components.
 */
export const taxRegimeOptions = Object.entries(TAX_REGIME_MAP).map(([value, label]) => ({
    label: `${value} - ${label}`,
    value,
}));

export function useSatCatalogs() {
    return {
        taxRegimeLabel,
        taxRegimeOptions,
    };
}
