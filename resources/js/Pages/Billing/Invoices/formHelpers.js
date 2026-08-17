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
// In included-IVA mode the stored unit_price is the SAT base, so it is
// re-inflated to the gross charged price for editing.
export const mapInvoiceItems = (invoice) => {
    if (!invoice?.items) return [];
    const pricesIncludeIva = !!invoice?.prices_include_iva;

    return invoice.items.map((item) => {
        const storedPrice = parseFloat(item.unit_price) || 0;
        const storedRate = parseFloat(item.tax_rate) || 0;
        const unitPrice = (pricesIncludeIva && item.objeto_imp === '02' && storedRate > 0)
            ? Math.round(storedPrice * (1 + storedRate) * 100) / 100
            : storedPrice;

        return {
            description: item.description || '',
            quantity: parseFloat(item.quantity) || 1,
            unit_price: unitPrice,
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
        };
    });
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

/**
 * Auto-completa un documento del CFDI de Pago con los datos de una factura
 * PPD del sistema: UUID/folio, siguiente parcialidad, saldo anterior (lo que
 * resta por pagar) e importe pagado = monto del pago; el saldo insoluto se
 * calcula. Todo queda editable.
 */
export const applyPpdToPagoDocument = (doc, ppd, pagoMonto) => {
    const saldoAnterior = parseFloat(ppd?.remaining ?? ppd?.total ?? 0) || 0;
    const importePagado = parseFloat(pagoMonto ?? 0) || 0;

    doc.invoice_id = ppd?.id ?? null;
    doc.folio = pagoInvoiceLabel(ppd);
    doc.uuid = ppd?.uuid || '';
    doc.num_parcialidad = ppd?.num_parcialidad ?? 1;
    doc.imp_saldo_ant = saldoAnterior;
    doc.imp_pagado = importePagado;
    doc.imp_saldo_insoluto = Math.max(0, saldoAnterior - importePagado);
};

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

// ──────────────────────────────────────────────────────────────
// Linked POS sale → invoice form mapping
// ──────────────────────────────────────────────────────────────

// POS payment method → SAT FormaPago code (only mappable methods).
export const paymentMethodSatMap = {
    efectivo: { code: '01', label: '01 - Efectivo' },
    transferencia: { code: '03', label: '03 - Transferencia electrónica de fondos' },
    tarjeta: { code: '04', label: '04 - Tarjeta de crédito' },
};

// Map sale line items into the editable concept shape. Tax rate defaults to
// 16 % (same default the manual builder applies). Line discounts are carried
// over so the invoice totals match the charged amounts.
export const mapSaleItems = (sale) => {
    if (!sale?.items || !Array.isArray(sale.items)) return [];

    return sale.items.map((item) => ({
        description: item.description || '',
        quantity: parseFloat(item.quantity) || 1,
        unit_price: parseFloat(item.unit_price) || 0,
        sat_product_code: item.sat_product_code || '',
        sat_unit_code: item.sat_unit_code || 'H87',
        no_identificacion: item.sku || item.no_identificacion || '',
        itemable_id: item.catalog_id ?? null,
        itemable_type: item.catalog_type ?? null,
        objeto_imp: '02',
        tax_type: '002',
        tax_rate: 0.16,
        discount_amount: parseFloat(item.discount_amount) || 0,
        retained_tax_type: null,
        retained_tax_rate: null,
        retained_tax_amount: 0,
    }));
};

/**
 * Pick the SAT FormaPago from the sale payments:
 *  - the highest-amount payment with a mappable method wins
 *  - returns { code, label, multipleMethods } for the UI note.
 */
export const mapSalePaymentForm = (payments = []) => {
    const list = Array.isArray(payments) ? payments : [];
    const mapped = list
        .filter((p) => paymentMethodSatMap[p.method])
        .sort((a, b) => parseFloat(b.amount) - parseFloat(a.amount));

    if (mapped.length === 0) {
        return { code: null, label: null, multipleMethods: false };
    }

    const distinctMethods = new Set(list.map((p) => p.method)).size;

    return {
        code: paymentMethodSatMap[mapped[0].method].code,
        label: paymentMethodSatMap[mapped[0].method].label,
        multipleMethods: distinctMethods > 1,
    };
};

/**
 * Apply a selected POS sale to the invoice form: receiver, concepts and
 * payment form. Every field remains editable afterwards.
 *
 * The POS does not compute taxes (total_tax=0), so "precios con IVA incluido"
 * is turned on by default — the invoice total then matches the sale amount
 * and the user can review that the IVA is already included.
 *
 * Returns an object with the payment-form note for the UI
 * ({ multiplePaymentMethods, selectedFormaLabel, creditSale, remainingDue }).
 */
export const applySaleToForm = (form, sale) => {
    form.transaction_id = sale.id ?? null;
    form.prices_include_iva = true;

    const customer = sale.customer || null;
    if (customer) {
        form.customer_id = customer.id ?? null;
        form.receiver_rfc = customer.tax_id || '';
        form.receiver_legal_name = (customer.company_name || customer.name || '').toUpperCase();
        const fiscal = extractFiscalData(customer);
        form.receiver_tax_regime = fiscal.tax_regime || '';
        form.receiver_postal_code = fiscal.postal_code || '';
    }

    form.items = mapSaleItems(sale);

    const payment = mapSalePaymentForm(sale.payments || []);
    // Venta a crédito no liquidada → MétodoPago PPD + FormaPago "Por definir".
    // Ambos campos quedan editables; el usuario puede cambiarlos si ya se pagó.
    const isCreditSale = sale.status === 'pendiente';
    if (isCreditSale) {
        form.payment_method = 'PPD';
        form.payment_form = '99';
    } else if (payment.code) {
        form.payment_form = payment.code;
        // Fully paid sale → single payment (PUE) is the correct method.
        if (!form.payment_method) form.payment_method = 'PUE';
    }

    return {
        multiplePaymentMethods: payment.multipleMethods,
        selectedFormaLabel: payment.label,
        creditSale: isCreditSale,
        remainingDue: parseFloat(sale.remaining_due ?? 0),
    };
};

// ──────────────────────────────────────────────────────────────
// Fuzzy search helpers (búsqueda por coincidencias)
// ──────────────────────────────────────────────────────────────

// Lowercase + accent-insensitive normalization.
export const normalizeSearchText = (value) =>
    String(value ?? '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');

/**
 * Score how well `query` matches `text`:
 *  - substring match → highest score (2+)
 *  - letter-subsequence match (letters in order) → lower score (0.5+)
 *  - no match → 0
 */
export const fuzzyScore = (query, text) => {
    const q = normalizeSearchText(query).trim();
    const t = normalizeSearchText(text);
    if (!q || !t) return 0;

    if (t.includes(q)) {
        return 2 + q.length / Math.max(t.length, 1);
    }

    let qi = 0;
    let consec = 0;
    let score = 0;
    for (let ti = 0; ti < t.length && qi < q.length; ti += 1) {
        if (t[ti] === q[qi]) {
            qi += 1;
            consec += 1;
            score += 1 + consec * 0.1;
        } else {
            consec = 0;
        }
    }

    return qi === q.length ? 0.5 + score / Math.max(t.length, 1) : 0;
};

// Best fuzzy score across several text fields of an item.
export const fuzzyMatchItem = (query, fields = []) => {
    if (!query || !String(query).trim()) return 0;
    let best = 0;
    for (const field of fields) {
        best = Math.max(best, fuzzyScore(query, field));
    }
    return best;
};

/**
 * Filter + sort a collection by fuzzy match, capped at `limit` items.
 * An empty query returns the first `limit` items as-is.
 */
export const fuzzySearchCollection = (collection, query, getFields, limit = 200) => {
    const q = String(query ?? '').trim();
    const items = Array.isArray(collection) ? collection : [];
    if (!q) return items.slice(0, limit);

    const scored = [];
    for (const item of items) {
        const score = fuzzyMatchItem(q, getFields(item));
        if (score > 0) scored.push({ item, score });
    }

    scored.sort((a, b) => b.score - a.score);
    return scored.slice(0, limit).map((entry) => entry.item);
};
