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
    // ──────────────────────────────────────
    const retentionApplies = computed(() => {
        const emitter = selectedProfile.value;
        const receptor = selectedCustomer.value;
        if (!emitter?.rfc || !receptor?.tax_id) return false;
        return isPersonaFisica(emitter.rfc) && isPersonaMoral(receptor.tax_id);
    });

    const isResico = computed(() =>
        selectedProfile.value?.regimen_fiscal === '626',
    );

    // ──────────────────────────────────────
    // Per-item rate resolver
    // ──────────────────────────────────────
    function getRetentionRates(item) {
        if (!retentionApplies.value) {
            return { isrRate: 0, ivaRetentionRate: 0 };
        }

        const tipo = item.concepto_tipo;

        // RESICO (626) — always 1.25% ISR; IVA retention only if "servicio"
        if (isResico.value) {
            return {
                isrRate: 0.0125,
                ivaRetentionRate: tipo === 'servicio' ? 0.106667 : 0,
            };
        }

        // Honorarios / Arrendamiento → 10% ISR + 2/3 IVA
        if (tipo === 'honorarios' || tipo === 'arrendamiento') {
            return { isrRate: 0.10, ivaRetentionRate: 0.106667 };
        }

        // Flete / Autotransporte → 0% ISR + 4% IVA
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
            const taxRate = parseFloat(item.tax_rate) || 0.16;
            const hasTax = item.objeto_imp === '02';

            const lineSubtotal = qty * price;
            const lineDiscount = Math.min(discount, lineSubtotal);
            const base = lineSubtotal - lineDiscount;

            const { isrRate, ivaRetentionRate } = getRetentionRates(item);

            const ivaTransfer = hasTax ? round(base * taxRate) : 0;
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
