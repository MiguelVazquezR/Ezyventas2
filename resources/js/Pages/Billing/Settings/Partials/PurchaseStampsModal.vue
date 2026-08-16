<script setup>
import { ref, computed } from 'vue';
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    fiscalProfileId: {
        type: Number,
        required: true,
    },
    ourBankAccounts: {
        type: Array,
        default: () => [],
    },
});

const emit = defineEmits(['success']);

// ──────────────────────────────────────
// State
// ──────────────────────────────────────
const visible = ref(false);
const quantity = ref(1);
const quoteLoading = ref(false);
const quotedPrice = ref(null);
const pricingTiers = ref([]);
const paymentMethod = ref('bank_transfer');

// Friendly pricing rows: "100 a 499: $1.50 c/u"
const pricingRows = computed(() => {
    if (!pricingTiers.value.length) return [];
    return pricingTiers.value.map(tier => ({
        ...tier,
        rangeLabel: tier.max_quantity
            ? `${formatNumber(tier.min_quantity)} a ${formatNumber(tier.max_quantity)}`
            : `${formatNumber(tier.min_quantity)}+`,
    }));
});

const purchaseForm = useForm({
    fiscal_profile_id: props.fiscalProfileId,
    stamp_quantity: 1,
    payment_method: 'bank_transfer',
    proof_file: null,
});

// ──────────────────────────────────────
// Quote
// ──────────────────────────────────────
async function fetchQuote() {
    if (!quantity.value || quantity.value < 1) return;
    quoteLoading.value = true;
    quotedPrice.value = null;

    try {
        const response = await fetch(
            route('billing.fiscal-profiles.stamps.quote', props.fiscalProfileId),
            {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ quantity: quantity.value }),
            },
        );
        if (response.ok) {
            const data = await response.json();
            quotedPrice.value = data;
            if (data.tiers) pricingTiers.value = data.tiers;
        }
    } catch {
        // fail silently
    } finally {
        quoteLoading.value = false;
    }
}

let debounceQuote = null;
function onQuantityChange() {
    clearTimeout(debounceQuote);
    debounceQuote = setTimeout(fetchQuote, 400);
}

function onFileSelect(event) {
    purchaseForm.proof_file = event.files[0];
}

function removeFile() {
    purchaseForm.proof_file = null;
}

function submitPurchase() {
    purchaseForm.stamp_quantity = quantity.value;
    purchaseForm.payment_method = paymentMethod.value;

    purchaseForm.post(
        route('billing.fiscal-profiles.stamps.store', props.fiscalProfileId),
        {
            preserveScroll: true,
            onSuccess: () => {
                visible.value = false;
                emit('success');
            },
        },
    );
}

// ──────────────────────────────────────
// Public API
// ──────────────────────────────────────
function open() {
    quantity.value = 1;
    quotedPrice.value = null;
    pricingTiers.value = [];
    paymentMethod.value = 'bank_transfer';
    purchaseForm.reset();
    purchaseForm.proof_file = null;
    purchaseForm.clearErrors();
    visible.value = true;
    fetchQuote();
}

defineExpose({ open });

// ──────────────────────────────────────
// Helpers
// ──────────────────────────────────────
function getCsrfToken() {
    // 1. Try meta tag (set in app.blade.php)
    const meta = document.querySelector('meta[name="csrf-token"]');
    if (meta?.content) return meta.content;

    // 2. Fallback: read from XSRF-TOKEN cookie (Laravel sets this automatically)
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);
    if (match) return decodeURIComponent(match[1]);

    return '';
}

function formatCurrency(amount) {
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(amount);
}

function formatNumber(value) {
    return new Intl.NumberFormat('es-MX').format(value || 0);
}

const volumeSavings = computed(() => {
    if (!quotedPrice.value?.savings_percentage || quotedPrice.value.savings_percentage <= 0) return null;
    return {
        perUnit: (quotedPrice.value.savings_amount / quotedPrice.value.quantity).toFixed(4),
        total: quotedPrice.value.savings_amount,
        pct: quotedPrice.value.savings_percentage,
    };
});

// Dynamically detect if a cheaper tier is available above current selection
const activeTier = computed(() => {
    if (!quotedPrice.value?.pricing_tier_id || !pricingTiers.value.length) return null;
    return pricingTiers.value.find(t => t.id === quotedPrice.value.pricing_tier_id) || null;
});

const nextTier = computed(() => {
    if (!activeTier.value || !pricingTiers.value.length) return null;
    const currentIndex = pricingTiers.value.findIndex(t => t.id === activeTier.value.id);
    if (currentIndex === -1 || currentIndex >= pricingTiers.value.length - 1) return null;
    return pricingTiers.value[currentIndex + 1];
});

const stampsToNextTier = computed(() => {
    if (!nextTier.value) return null;
    return nextTier.value.min_quantity - quantity.value;
});

const showVolumeBanner = computed(() => {
    return nextTier.value !== null;
});

async function copyClabe(clabe) {
    try {
        await navigator.clipboard.writeText(clabe);
    } catch {
        // fallback silently
    }
}

// ──────────────────────────────────────
// Tesla UI — Drawer + Input PT
// ──────────────────────────────────────
const drawerPt = {
    root: { class: '!shadow-2xl' },
    mask: { class: '!bg-gray-900/60 dark:!bg-black/80' },
    header: { class: '!bg-white dark:!bg-[#121212] !border-b !border-gray-100 dark:!border-[#2e2e2e] !px-6 !py-5 !shrink-0' },
    title: { class: '!text-lg !font-light !tracking-tight !text-gray-900 dark:!text-white !m-0' },
    content: { class: '!bg-white dark:!bg-[#121212] !p-0 !overflow-y-auto !flex-1' },
    closeButton: { class: '!hover:bg-gray-100 dark:!hover:bg-[#232323] !transition-colors !rounded-full !w-9 !h-9 !flex !items-center !justify-center' },
    closeButtonIcon: { class: 'dark:!text-gray-400 !text-sm' },
};

const isButtonDisabled = computed(() => {
    return quoteLoading.value || !quantity.value || quantity.value < 1
        || (paymentMethod.value === 'bank_transfer' && !purchaseForm.proof_file);
});
</script>

<template>
    <Drawer
        v-model:visible="visible"
        position="right"
        class="!w-full md:!w-[520px]"
        :modal="true"
        :dismissable="true"
        :showCloseIcon="true"
        :pt="drawerPt"
    >
        <!-- ═══════════════════════════ HEADER ═══════════════════════════ -->
        <template #header>
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-full bg-primary-50 dark:bg-primary-900/20 flex items-center justify-center shrink-0 border border-primary-100 dark:border-primary-900/30">
                    <i class="pi pi-ticket !text-sm text-primary-500"></i>
                </div>
                <div>
                    <h2 class="text-lg font-light tracking-tight text-gray-900 dark:text-white m-0">
                        Comprar timbres fiscales
                    </h2>
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-400 m-0 mt-0.5">
                        Se aplica al emisor fiscal seleccionado.
                    </p>
                </div>
            </div>
        </template>

        <!-- ═══════════════════════════ SCROLLABLE BODY ═══════════════════════════ -->
        <div class="px-6 py-5 space-y-6">

            <!-- ── Subtítulo explicativo ────────────────── -->
            <p class="text-xs text-gray-500 dark:text-gray-400 m-0 leading-relaxed">
                Los timbres son los créditos que necesitas para timbrar y emitir tus facturas ante el SAT.
            </p>

            <!-- ── Sección 1: Cantidad + Montos por volumen (grid 2 cols) ── -->
            <div class="grid grid-cols-2 gap-4">
                <!-- Columna izquierda: Ingreso de cantidad -->
                <div>
                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mb-2.5 block">
                        Cantidad
                    </label>
                    <InputNumber
                        v-model="quantity"
                        :min="1"
                        :max="999999"
                        class="w-full"
                        @update:modelValue="onQuantityChange"
                        :pt="{
                            input: {
                                root: {
                                    class: 'w-full min-w-0 !rounded-2xl !bg-gray-50 dark:!bg-[#0d0d0d] !border-gray-100 dark:!border-[#2e2e2e] focus:dark:!border-primary-500 !transition-colors !py-3.5 !text-2xl !font-light !tracking-tight !text-gray-900 dark:!text-white',
                                },
                            },
                        }"
                    />
                </div>

                <!-- Columna derecha: Montos por volumen -->
                <div v-if="pricingRows.length" class="rounded-2xl bg-gray-50 dark:bg-[#0d0d0d] border border-gray-100 dark:border-[#2e2e2e] p-3.5">
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mb-2.5">
                        Montos por volumen
                    </p>
                    <div class="space-y-1.5">
                        <div
                            v-for="row in pricingRows"
                            :key="row.id"
                            class="flex items-center justify-between text-[11px] rounded-xl px-2 py-1.5 transition-colors"
                            :class="activeTier?.id === row.id
                                ? 'bg-primary-50 dark:bg-primary-900/20 text-primary-700 dark:text-primary-300 font-semibold'
                                : 'text-gray-600 dark:text-gray-400'"
                        >
                            <span>{{ row.rangeLabel }}</span>
                            <span>{{ formatCurrency(row.unit_price) }} c/u</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Upsell amistoso ───────────────────────── -->
            <div
                v-if="showVolumeBanner && nextTier"
                class="flex items-center gap-2 px-3 py-2.5 rounded-2xl bg-emerald-50/70 dark:bg-emerald-900/15 border border-emerald-100 dark:border-emerald-900/20"
            >
                <i class="pi pi-tags !text-xs text-emerald-500 shrink-0"></i>
                <p class="text-[11px] text-emerald-700 dark:text-emerald-300 m-0 leading-snug">
                    ¡Agrega <strong>{{ formatNumber(stampsToNextTier) }}</strong> timbres más para pagar menos por cada uno!
                </p>
            </div>

            <!-- ── Sección 4: Método de pago ──────────────── -->
            <div>
                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mb-2.5 block">
                    Método de pago
                </label>
                <div class="flex rounded-2xl bg-gray-50 dark:bg-[#0d0d0d] border border-gray-100 dark:border-[#2e2e2e] p-1 gap-1">
                    <button
                        type="button"
                        @click="paymentMethod = 'bank_transfer'"
                        class="flex-1 flex items-center justify-center gap-2 px-3 py-2.5 rounded-xl text-xs font-medium transition-all duration-200"
                        :class="paymentMethod === 'bank_transfer'
                            ? 'bg-white dark:bg-[#1a1a1a] text-gray-900 dark:text-white shadow-sm'
                            : 'text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300'"
                    >
                        <i class="pi pi-building-columns !text-xs" />
                        Transferencia
                    </button>
                    <!-- Mercado Pago: próximamente (deshabilitado) -->
                    <span v-tooltip.top="'Próximamente'" class="flex-1">
                        <button
                            type="button"
                            disabled
                            class="w-full flex items-center justify-center gap-2 px-3 py-2.5 rounded-xl text-xs font-medium transition-all duration-200 text-gray-400 dark:text-gray-600 opacity-45 cursor-not-allowed select-none"
                        >
                            <img src="/images/Mercado_Pago_logo.webp" alt="MP" class="w-4 h-4 object-contain grayscale opacity-99" />
                            Mercado Pago
                            <span class="text-[8px] uppercase tracking-widest font-bold bg-gray-200 dark:bg-[#2e2e2e] text-gray-700 dark:text-gray-400 px-1.5 py-0.5 rounded-full">Próximamente</span>
                        </button>
                    </span>
                </div>
            </div>

            <!-- ── Sección 5a: Transferencia bancaria ─────── -->
            <div v-if="paymentMethod === 'bank_transfer'" class="space-y-4">
                <div>
                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mb-2.5 block">
                        Cuentas bancarias
                    </label>
                    <div class="space-y-2">
                        <div
                            v-for="account in ourBankAccounts"
                            :key="account.id"
                            class="rounded-2xl bg-gray-50 dark:bg-[#0d0d0d] border border-gray-100 dark:border-[#2e2e2e] p-3.5"
                        >
                            <div class="flex justify-between items-start mb-2">
                                <span class="text-sm font-semibold text-gray-900 dark:text-white">
                                    {{ account.bank_name }}
                                </span>
                                <button
                                    v-if="account.clabe"
                                    type="button"
                                    @click="copyClabe(account.clabe)"
                                    class="flex items-center gap-1 text-[10px] uppercase tracking-widest font-bold text-gray-400 hover:text-primary-500 dark:hover:text-primary-400 transition-colors"
                                    v-tooltip.left="'Copiar CLABE'"
                                >
                                    <i class="pi pi-copy !text-[10px]" />
                                    Copiar
                                </button>
                            </div>
                            <div class="space-y-0.5 text-[11px] text-gray-500 dark:text-gray-400">
                                <p class="m-0">
                                    Titular:
                                    <span class="text-gray-700 dark:text-gray-300">{{ account.owner_name }}</span>
                                </p>
                                <p v-if="account.clabe" class="m-0 tracking-wide">
                                    CLABE:
                                    <span class="text-gray-700 dark:text-gray-300">{{ account.clabe }}</span>
                                </p>
                                <p v-if="account.account_number" class="m-0">
                                    Cuenta:
                                    <span class="text-gray-700 dark:text-gray-300">{{ account.account_number }}</span>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <div>
                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mb-2.5 block">
                        Comprobante de pago *
                    </label>

                    <div v-if="!purchaseForm.proof_file">
                        <FileUpload
                            mode="basic"
                            name="proof_file"
                            accept="image/*,.pdf"
                            :maxFileSize="10485760"
                            @select="onFileSelect"
                            chooseLabel="Seleccionar archivo"
                            class="w-full"
                            :pt="{
                                chooseButton: {
                                    class: '!w-full !py-6 !rounded-2xl !border-2 !border-dashed !border-gray-200 dark:!border-[#2e2e2e] !bg-gray-50/50 dark:!bg-[#0d0d0d] hover:!bg-gray-100 dark:hover:!bg-[#1a1a1a] hover:!border-primary-300 dark:hover:!border-primary-700 !transition-all !duration-200 !text-gray-400 dark:!text-gray-500 !text-xs',
                                },
                            }"
                        >
                            <template #empty>
                                <div class="flex flex-col items-center gap-1.5 pointer-events-none">
                                    <i class="pi pi-cloud-upload !text-lg text-gray-300 dark:text-gray-600" />
                                    <span class="text-xs text-gray-400 dark:text-gray-500">Arrastra tu comprobante o haz clic aquí</span>
                                    <span class="text-[10px] text-gray-300 dark:text-gray-600">PDF, JPG o PNG — máx. 10 MB</span>
                                </div>
                            </template>
                        </FileUpload>
                    </div>

                    <div
                        v-else
                        class="flex items-center justify-between rounded-2xl bg-blue-50/70 dark:bg-blue-900/15 border border-blue-100 dark:border-blue-900/20 px-3.5 py-2.5"
                    >
                        <div class="flex items-center gap-2.5 min-w-0">
                            <div class="w-8 h-8 rounded-full bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center shrink-0">
                                <i class="pi pi-check-circle !text-xs text-blue-600 dark:text-blue-400" />
                            </div>
                            <span class="text-xs text-blue-700 dark:text-blue-300 truncate">
                                {{ purchaseForm.proof_file.name }}
                            </span>
                        </div>
                        <button
                            type="button"
                            @click="removeFile"
                            class="w-7 h-7 rounded-full flex items-center justify-center text-gray-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors shrink-0"
                            v-tooltip.top="'Quitar archivo'"
                        >
                            <i class="pi pi-times !text-[11px]" />
                        </button>
                    </div>

                    <Message
                        v-if="purchaseForm.errors?.proof_file"
                        severity="error"
                        variant="simple"
                        size="small"
                        class="mt-2"
                    >
                        {{ purchaseForm.errors.proof_file }}
                    </Message>
                </div>

                <div class="flex items-start gap-2 px-3 py-2.5 rounded-2xl bg-amber-50/70 dark:bg-amber-900/15 border border-amber-100 dark:border-amber-900/20">
                    <i class="pi pi-clock !text-[11px] text-amber-500 mt-px" />
                    <p class="text-[12px] text-amber-600 dark:text-amber-400 m-0 leading-snug">
                        Tu pedido quedará pendiente hasta que el administrador apruebe tu comprobante.
                    </p>
                </div>
            </div>

            <!-- ── Sección 5b: Mercado Pago ──────────────── -->
            <div
                v-if="paymentMethod === 'mercadopago'"
                class="rounded-2xl bg-blue-50/70 dark:bg-blue-900/15 border border-blue-100 dark:border-blue-900/20 p-4"
            >
                <div class="flex items-center gap-3 mb-3">
                    <div class="w-10 h-10 rounded-xl bg-white dark:bg-[#1a1a1a] border border-gray-100 dark:border-[#2e2e2e] flex items-center justify-center overflow-hidden shrink-0">
                        <img src="/images/Mercado_Pago_logo.webp" alt="Mercado Pago" class="w-6 h-6 object-contain" />
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-blue-800 dark:text-blue-200 m-0">Mercado Pago</p>
                        <p class="text-[11px] text-blue-600 dark:text-blue-400 m-0">Acreditación instantánea</p>
                    </div>
                </div>
                <p class="text-[11px] text-blue-700 dark:text-blue-300 m-0 leading-relaxed">
                    Serás redirigido a Mercado Pago para completar tu pago de forma segura. Los timbres se acreditarán automáticamente al confirmarse el pago.
                </p>
            </div>

            <Message
                v-if="purchaseForm.errors?.stamp_quantity"
                severity="error"
                variant="simple"
                size="small"
            >
                {{ purchaseForm.errors.stamp_quantity }}
            </Message>

            <!-- ── Sección 6: Recibo / Desglose ──────────── -->
            <div class="rounded-2xl bg-gray-50 dark:bg-[#0d0d0d] border border-gray-100 dark:border-[#2e2e2e] overflow-hidden">
                <!-- Header row always visible -->
                <div class="px-4 py-2.5 border-b border-gray-100 dark:border-[#2e2e2e]">
                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-400">Resumen de pago</span>
                </div>

                <!-- Loading -->
                <div
                    v-if="quoteLoading"
                    class="flex items-center justify-center gap-2.5 py-8"
                >
                    <i class="pi pi-spin pi-spinner !text-base text-gray-400" />
                    <span class="text-xs text-gray-400">Calculando precio...</span>
                </div>

                <!-- Receipt content -->
                <div v-else-if="quotedPrice" class="divide-y divide-gray-100 dark:divide-[#2e2e2e]">
                    <div class="flex justify-between items-center px-4 py-3">
                        <span class="text-xs text-gray-500 dark:text-gray-400">
                            {{ formatNumber(quantity) }} timbres × {{ formatCurrency(quotedPrice.unit_price) }}
                        </span>
                        <span class="text-sm font-medium text-gray-900 dark:text-white">
                            {{ formatCurrency(quotedPrice.amount_total + (volumeSavings?.total || 0)) }}
                        </span>
                    </div>

                    <div
                        v-if="volumeSavings"
                        class="flex justify-between items-center px-4 py-2.5 bg-emerald-50/50 dark:bg-emerald-900/10"
                    >
                        <div class="flex items-center gap-1.5">
                            <i class="pi pi-arrow-down !text-[10px] text-emerald-500" />
                            <span class="text-xs text-emerald-700 dark:text-emerald-300 font-medium">
                                Ahorro por volumen
                            </span>
                            <span class="text-[10px] uppercase tracking-widest font-bold text-emerald-500 dark:text-emerald-400 bg-emerald-100 dark:bg-emerald-900/30 px-1.5 py-0.5 rounded-full">
                                −{{ volumeSavings.pct }}%
                            </span>
                        </div>
                        <span class="text-xs font-bold text-emerald-700 dark:text-emerald-300">
                            −{{ formatCurrency(volumeSavings.total) }}
                        </span>
                    </div>

                    <div class="flex justify-between items-center px-4 py-3.5">
                        <span class="text-xs font-semibold text-gray-700 dark:text-gray-300">Total</span>
                        <span class="text-xl font-light tracking-tight text-gray-900 dark:text-white">
                            {{ formatCurrency(quotedPrice.amount_total) }}
                        </span>
                    </div>
                </div>

                <!-- Empty / waiting for input -->
                <div v-else class="flex items-center justify-center py-8">
                    <span class="text-xs text-gray-400">Ingresa una cantidad para ver el precio</span>
                </div>
            </div>
        </div>

        <!-- ═══════════════════════════ FOOTER ═══════════════════════════ -->
        <template #footer>
            <div
                class="flex items-center justify-between gap-4 bg-white dark:bg-[#121212] border-t border-gray-100 dark:border-[#2e2e2e] px-6 py-4"
            >
                <div class="flex flex-col min-w-0">
                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-400 m-0">Total a pagar</span>
                    <span class="text-2xl font-light tracking-tight text-gray-900 dark:text-white truncate">
                        {{ quotedPrice ? formatCurrency(quotedPrice.amount_total) : '—' }}
                    </span>
                </div>
                <Button
                    :label="paymentMethod === 'mercadopago' ? 'Pagar con MP' : 'Confirmar compra'"
                    :icon="paymentMethod === 'mercadopago' ? 'pi pi-arrow-up-right' : 'pi pi-check'"
                    :loading="purchaseForm.processing"
                    :disabled="isButtonDisabled"
                    @click="submitPurchase"
                    class="!rounded-full !px-6 !text-xs !uppercase !tracking-widest !font-bold shrink-0"
                    severity="primary"
                />
            </div>
        </template>
    </Drawer>
</template>
