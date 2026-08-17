import { computed, unref } from 'vue';

/**
 * CFDI 4.0 Tax & Retention Calculator — SAT SW Smarter Web
 *
 * Reglas de retención (ISR + IVA):
 *   Solo aplican cuando Emisor es Persona Física (RFC 13 dígitos)
 *   Y Receptor es Persona Moral (RFC 12 dígitos).
 *
 * ┌──────────────────────────┬────────────┬──────────────────┐
 * │ Escenario                │ ISR        │ IVA retenido     │
 * ├──────────────────────────┼────────────┼──────────────────┤
 * │ RESICO (626) + servicio  │ 1.25 %     │ 2/3 IVA (10.67%)│
 * │ RESICO (626) + general   │ 1.25 %     │ —                │
 * │ Honorarios / Arrendamiento│ 10 %      │ 2/3 IVA (10.67%)│
 * │ Flete / Autotransporte   │ —          │ 4 %              │
 * │ Cualquier otro caso      │ —          │ —                │
 * └──────────────────────────┴────────────┴──────────────────┘
 *
 * @param {import('@inertiajs/vue3').InertiaForm} form — Reactive Inertia form
 * @param {import('vue').Ref|import('vue').ComputedRef|Array} fiscalProfiles — Emitter profiles
 * @param {import('vue').Ref|import('vue').ComputedRef|Array} customers — Receiver customers
 */
export function useInvoiceTaxes(form, fiscalProfiles, customers) {
    // ──────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────
    const round = (n) => Math.round(n * 100) / 100;

    /**
     * Unwrap and normalize any Vue-reactive or plain collection to a plain array.
     *
     * Handles:
     *  - Vue Ref / ComputedRef  → unref() first
     *  - Plain Array             → returned as-is
     *  - Associative object      → Object.values()
     *  - null / undefined        → []
     */
    const toArray = (collection) => {
        const raw = unref(collection);
        if (!raw) return [];
        if (Array.isArray(raw)) return raw;
        if (typeof raw === 'object') return Object.values(raw);
        return [];
    };

    const normalizeRfc = (rfc) => (rfc || '').replace(/[\s-]/g, '').toUpperCase();

    const isPersonaMoral = (rfc) => normalizeRfc(rfc).length === 12;

    const isPersonaFisica = (rfc) => normalizeRfc(rfc).length === 13;

    // ──────────────────────────────────────
    // Selected entities
    // ──────────────────────────────────────
    const selectedProfile = computed(() => {
        const arr = toArray(fiscalProfiles);
        return arr.find(p => p.id === form.fiscal_profile_id) || null;
    });

    const selectedCustomer = computed(() => {
        const arr = toArray(customers);
        return arr.find(c => c.id === form.customer_id) || null;
    });

    // ──────────────────────────────────────
    // Retention regime detection
    //
    // Retentions apply when:
    //  1. Emitter is Persona Física (RFC 13 chars)
    //  2. Receiver is Persona Moral (RFC 12 chars)
    //
    // The receiver check uses form.receiver_rfc directly (not just
    // the selected customer) because the customer field is optional.
    // ──────────────────────────────────────
    const retentionApplies = computed(() => {
        const emitter = selectedProfile.value;
        if (!emitter?.rfc || !isPersonaFisica(emitter.rfc)) return false;

        // Check receiver RFC: try customer first, fall back to manual RFC entry
        const receiverRfc = selectedCustomer.value?.tax_id || form.receiver_rfc;
        if (!receiverRfc) return false;

        return isPersonaMoral(receiverRfc);
    });

    const isResico = computed(() =>
        selectedProfile.value?.regimen_fiscal === '626',
    );

    /**
     * Human-readable retention explanation for the UI.
     * Returns null when no retentions apply.
     */
    const retentionMessage = computed(() => {
        if (!retentionApplies.value) return null;

        const regime = selectedProfile.value?.regimen_fiscal;

        if (regime === '626') {
            return 'Por disposición del SAT, cuando una persona física del Régimen Simplificado de Confianza (RESICO) factura a una persona moral, esta última debe retener el 1.25 % de ISR sobre el monto del concepto.';
        }

        if (regime === '606') {
            return 'Por disposición del SAT, cuando una persona física en régimen de Arrendamiento factura a una persona moral, esta última debe retener el 10 % de ISR y las dos terceras partes del IVA trasladado.';
        }

        // Honorarios / Servicios Profesionales
        if (regime === '612') {
            return 'Por disposición del SAT, cuando una persona física por servicios profesionales (honorarios) factura a una persona moral, esta última debe retener el 10 % de ISR y las dos terceras partes del IVA trasladado.';
        }

        return 'Aplican retenciones — Emisor Persona Física → Receptor Persona Moral.';
    });

    // ──────────────────────────────────────
    // Per-item rate resolver
    //
    // Retention rates are driven by the emitter's SAT fiscal regime.
    // concepto_tipo on the item acts as an optional override for
    // edge cases (flete, autotransporte) and backward compatibility.
    // ──────────────────────────────────────
    function getRetentionRates(item) {
        if (!retentionApplies.value) {
            return { isrRate: 0, ivaRetentionRate: 0 };
        }

        const regime = selectedProfile.value?.regimen_fiscal;
        const tipo = item.concepto_tipo;

        // ── RESICO (626) → 1.25 % ISR always, no IVA retention ──
        if (regime === '626') {
            return {
                isrRate: 0.0125,
                ivaRetentionRate: tipo === 'servicio' ? 0.106667 : 0,
            };
        }

        // ── Arrendamiento (606) → 10 % ISR + 2/3 IVA ──
        if (regime === '606') {
            return { isrRate: 0.10, ivaRetentionRate: 0.106667 };
        }

        // ── Honorarios / Servicios Profesionales → 10 % ISR + 2/3 IVA ──
        // Regime 612 = Personas Físicas con Actividades Empresariales y Profesionales
        if (regime === '612') {
            return { isrRate: 0.10, ivaRetentionRate: 0.106667 };
        }

        // ── Fallback: concepto_tipo for backward compat ──
        if (tipo === 'honorarios' || tipo === 'arrendamiento') {
            return { isrRate: 0.10, ivaRetentionRate: 0.106667 };
        }

        // Flete / Autotransporte → 0 % ISR + 4 % IVA
        if (tipo === 'flete' || tipo === 'autotransporte') {
            return { isrRate: 0, ivaRetentionRate: 0.04 };
        }

        // Default: no retentions
        return { isrRate: 0, ivaRetentionRate: 0 };
    }

    // ──────────────────────────────────────
    // Per-item breakdown (guarded)
    // ──────────────────────────────────────
    const breakdown = computed(() => {
        const items = form.items;
        if (!items || !Array.isArray(items) || items.length === 0) return [];

        return items.map((item) => {
            const qty = parseFloat(item.quantity) || 0;
            const price = parseFloat(item.unit_price) || 0;
            const discount = parseFloat(item.discount_amount) || 0;
            const taxRate = item.tax_rate === 'Exento' ? 0 : (parseFloat(item.tax_rate) || 0.16);
            const hasTax = item.objeto_imp === '02';
            const includesIva = !!form.prices_include_iva;

            const lineSubtotal = qty * price; // gross charged amount
            const lineDiscount = Math.min(discount, lineSubtotal);
            const taxable = lineSubtotal - lineDiscount;

            const { isrRate, ivaRetentionRate } = getRetentionRates(item);

            let base;
            let ivaTransfer;

            if (includesIva && hasTax && taxRate > 0) {
                // "Precios con IVA incluido": se deriva la base SAT del monto
                // cobrado. El IVA se calcula igual que el backend (base × tasa)
                // para que el total mostrado coincida con el que se guarda; por
                // el redondeo por producto puede diferir en centavos del cobrado.
                base = round(taxable / (1 + taxRate));
                ivaTransfer = round(base * taxRate);
            } else {
                base = round(taxable);
                ivaTransfer = hasTax ? round(base * taxRate) : 0;
            }

            const isrRetention = hasTax ? round(base * isrRate) : 0;
            const ivaRetention = hasTax ? round(base * ivaRetentionRate) : 0;

            return {
                subtotal: round(lineSubtotal),
                discount: round(lineDiscount),
                base: round(base),
                ivaTransfer,
                isrRetention,
                ivaRetention,
                lineTotal: round(base + ivaTransfer - isrRetention - ivaRetention),
                rates: { taxRate, isrRate, ivaRetentionRate },
                hasTax,
                includesIva,
                concepto_tipo: item.concepto_tipo || null,
            };
        });
    });

    // ──────────────────────────────────────
    // Aggregated totals (safe on empty breakdown)
    // ──────────────────────────────────────
    const subtotal = computed(() =>
        round(breakdown.value.reduce((s, i) => s + i.base, 0)),
    );

    const ivaTrasladado = computed(() =>
        round(breakdown.value.reduce((s, i) => s + i.ivaTransfer, 0)),
    );

    const isrRetenido = computed(() =>
        round(breakdown.value.reduce((s, i) => s + i.isrRetention, 0)),
    );

    const ivaRetenido = computed(() =>
        round(breakdown.value.reduce((s, i) => s + i.ivaRetention, 0)),
    );

    const granTotal = computed(() =>
        round(subtotal.value + ivaTrasladado.value - isrRetenido.value - ivaRetenido.value),
    );

    // ──────────────────────────────────────
    // Currency formatter
    // ──────────────────────────────────────
    function formatCurrency(value) {
        return new Intl.NumberFormat('es-MX', {
            style: 'currency',
            currency: 'MXN',
        }).format(value || 0);
    }

    return {
        // Entities
        selectedProfile,
        selectedCustomer,
        // Regime flags
        retentionApplies,
        isResico,
        retentionMessage,
        // Per-item
        breakdown,
        // Aggregated
        subtotal,
        ivaTrasladado,
        isrRetenido,
        ivaRetenido,
        granTotal,
        // Utility
        formatCurrency,
    };
}
