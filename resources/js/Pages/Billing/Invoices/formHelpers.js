// Shared helpers for the CFDI 4.0 invoice builder.
// Centralizes the small utilities used across the type-specific form partials.

// Normalize a collection (array, object, or null) to a plain array.
export const toArray = (collection) => {
    if (!collection) return [];
    if (Array.isArray(collection)) return collection;
    if (typeof collection === 'object') return Object.values(collection);
    return [];
};

// Pull the fiscal regime + postal code out of a customer record.
export const extractFiscalData = (customer) => {
    if (!customer) return { tax_regime: '', postal_code: '' };

    const fa = customer.fiscal_address;
    if (fa && typeof fa === 'object' && !Array.isArray(fa)) {
        return {
            tax_regime: fa.tax_regime || fa.regimen_fiscal || customer.tax_regime || '',
            postal_code: fa.zip_code || fa.postal_code || fa.cp || '',
        };
    }

    const addr = customer.address;
    const addrObj = (addr && typeof addr === 'object' && !Array.isArray(addr)) ? addr : {};
    return {
        tax_regime: customer.tax_regime || '',
        postal_code: addrObj.zip_code || addrObj.postal_code || '',
    };
};

// Map persisted invoice items back into the editable form shape (edit mode).
export const mapInvoiceItems = (invoice) => {
    if (!invoice?.items) return [];
    return invoice.items.map(item => ({
        description: item.description || '',
        quantity: parseFloat(item.quantity) || 1,
        unit_price: parseFloat(item.unit_price) || 0,
        sat_product_code: item.sat_product_code || '',
        sat_unit_code: item.sat_unit_code || '',
        no_identificacion: item.no_identificacion || '',
        objeto_imp: item.objeto_imp || '02',
        tax_type: item.tax_type || '002',
        tax_rate: parseFloat(item.tax_rate) || 0.16,
        discount_amount: parseFloat(item.discount_amount) || 0,
        retained_tax_type: item.retained_tax_type || null,
        retained_tax_rate: item.retained_tax_rate ? parseFloat(item.retained_tax_rate) : null,
        retained_tax_amount: parseFloat(item.retained_tax_amount) || 0,
    }));
};

// Map persisted pago_documentos back into the editable form shape (edit mode).
// Loaded documents are never locked, so is_default is always false.
export const mapPagoDocuments = (invoice) => {
    const docs = Array.isArray(invoice?.pago_documentos) ? invoice.pago_documentos : [];
    return docs.map(doc => ({
        uuid: doc.uuid || '',
        folio: doc.folio || '',
        invoice_id: doc.invoice_id || null,
        is_default: false,
        num_parcialidad: doc.num_parcialidad ?? null,
        imp_saldo_ant: doc.imp_saldo_ant ?? null,
        imp_pagado: doc.imp_pagado ?? null,
        imp_saldo_insoluto: doc.imp_saldo_insoluto ?? null,
    }));
};

// Blank editable concept row.
export const blankItem = () => ({
    description: '', quantity: 1, unit_price: null,
    sat_product_code: '', sat_unit_code: '',
    no_identificacion: '', objeto_imp: '02',
    tax_type: '002', tax_rate: 0.16, discount_amount: null,
    retained_tax_type: null, retained_tax_rate: null, retained_tax_amount: 0,
});

// Automated concept required by a CFDI de Pago (Tipo P):
// ClaveProdServ 84111506 + ClaveUnidad ACT, sin impuestos (ObjetoImp 01).
export const pagoConceptItem = () => ({
    description: 'Pago',
    quantity: 1,
    unit_price: 0,
    sat_product_code: '84111506',
    sat_unit_code: 'ACT',
    no_identificacion: '',
    objeto_imp: '01',
    tax_type: '002',
    tax_rate: 0,
    discount_amount: 0,
    retained_tax_type: null,
    retained_tax_rate: null,
    retained_tax_amount: 0,
});

// Blank related-payment document row.
export const blankPagoDocument = (isDefault = false) => ({
    uuid: '',
    folio: '',
    invoice_id: null,
    is_default: isDefault,
    num_parcialidad: null,
    imp_saldo_ant: null,
    imp_pagado: null,
    imp_saldo_insoluto: null,
});

// Short display label for a related invoice (serie-folio).
export const pagoInvoiceLabel = (inv) =>
    (inv?.series ? `${inv.series}-${inv.folio}` : String(inv?.folio ?? ''));

// Compact es-MX date formatter.
export const formatDateShort = (value) => {
    if (!value) return '';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '';
    return date.toLocaleDateString('es-MX', { day: '2-digit', month: '2-digit', year: 'numeric' });
};

// MXN currency formatter used across the form sections.
export const formatCurrency = (value) =>
    new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value || 0);
