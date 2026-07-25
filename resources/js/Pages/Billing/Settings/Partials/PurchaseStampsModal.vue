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
const quantity = ref(100);
const quoteLoading = ref(false);
const quotedPrice = ref(null);
const suggestedQuantities = [100, 500, 1000, 2500, 5000];
const paymentMethod = ref('bank_transfer');

const purchaseForm = useForm({
    fiscal_profile_id: props.fiscalProfileId,
    stamp_quantity: 100,
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
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ quantity: quantity.value }),
            },
        );
        if (response.ok) quotedPrice.value = await response.json();
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
    quantity.value = 100;
    quotedPrice.value = null;
    paymentMethod.value = 'bank_transfer';
    purchaseForm.reset();
    purchaseForm.clearErrors();
    visible.value = true;
    fetchQuote();
}

defineExpose({ open });

// ──────────────────────────────────────
// Helpers
// ──────────────────────────────────────
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

const showVolumeBanner = computed(() => {
    return quantity.value > 0 && quantity.value < 500;
});

async function copyClabe(clabe) {
    try {
        await navigator.clipboard.writeText(clabe);
    } catch {
        // fallback silently
    }
}

// ──────────────────────────────────────
// Tesla UI PT
// ──────────────────────────────────────
const dialogPt = {
    root: { class: '!rounded-3xl !bg-white dark:!bg-[#1a1a1a] !border !border-gray-100 dark:!border-[#2e2e2e] !shadow-2xl !overflow-hidden' },
    header: { class: '!bg-transparent !px-8 !pt-8 !pb-0 !border-none' },
    title: { class: '!text-2xl !font-light !tracking-tight !text-gray-900 dark:!text-white !m-0' },
    content: { class: '!bg-transparent !px-8 !py-6' },
    closeButton: { class: '!hover:bg-gray-100 dark:!hover:bg-[#232323] !transition-colors !rounded-full !w-9 !h-9 !flex !items-center !justify-center !-mt-2 !-mr-2' },
    closeButtonIcon: { class: 'dark:!text-gray-400 !text-sm' },
    mask: { class: '!bg-gray-900/60 dark:!bg-black/80' },
};
</script>

<template>
    <Dialog
        v-model:visible="visible"
        header="Comprar timbres"
        :modal="true"
        :draggable="false"
        class="w-full max-w-2xl"
        :pt="dialogPt"
    >
        <!-- ════════════════════════════════════════
             Fiscal profile banner
             ════════════════════════════════════════ -->
        <div class="flex items-start gap-3 px-4 py-3 mb-3 rounded-2xl bg-blue-50/60 dark:bg-blue-900/10 border border-blue-100 dark:border-blue-900/20">
            <div class="w-7 h-7 rounded-full bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center shrink-0 mt-px">
                <i class="pi pi-info-circle !text-xs text-blue-600 dark:text-blue-400"></i>
            </div>
            <p class="text-[12px] text-blue-700 dark:text-blue-300 m-0 leading-relaxed">
                Los timbres adquiridos se acreditarán automáticamente al emisor fiscal activo actualmente seleccionado.
            </p>
        </div>

        <!-- ════════════════════════════════════════
             Quantity selection
             ════════════════════════════════════════ -->
        <div class="mb-2">
            <h3 class="text-[11px] uppercase tracking-wide font-bold text-gray-600 dark:text-gray-500 m-0 mb-4">
                Cantidad de timbres
            </h3>

            <div class="flex flex-col gap-4">
                <InputNumber
                    v-model="quantity"
                    :min="1"
                    :max="999999"
                    class="w-full"
                    @update:modelValue="onQuantityChange"
                    :pt="{
                        input: {
                            root: {
                                class: 'w-full min-w-0 !rounded-2xl !bg-gray-50 dark:!bg-[#121212] !border-gray-100 dark:!border-[#2e2e2e] focus:dark:!border-primary-500 !transition-colors !py-4 !text-3xl !font-light !tracking-tight !text-gray-900 dark:!text-white',
                            },
                        },
                    }"
                />

                <!-- Chip suggestions -->
                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="qty in suggestedQuantities"
                        :key="qty"
                        type="button"
                        @click="quantity = qty; onQuantityChange()"
                        class="px-4 py-2 text-xs font-medium rounded-full border transition-all duration-200"
                        :class="quantity === qty
                            ? 'bg-primary-500 text-white border-primary-500 shadow-[0_0_12px_rgba(99,102,241,0.4)]'
                            : 'bg-gray-50 dark:bg-[#121212] text-gray-600 dark:text-gray-400 border-gray-100 dark:border-[#2e2e2e] hover:border-primary-300 dark:hover:border-primary-600 hover:text-primary-600 dark:hover:text-primary-400'"
                    >
                        {{ formatNumber(qty) }}
                    </button>
                </div>
            </div>
        </div>

        <!-- ════════════════════════════════════════
             Volume discount promotional banner
             ════════════════════════════════════════ -->
        <div
            v-if="showVolumeBanner"
            class="flex items-start gap-3 px-4 py-3 mb-6 rounded-2xl bg-emerald-50/60 dark:bg-emerald-900/10 border border-emerald-100 dark:border-emerald-900/20"
        >
            <i class="pi pi-tags !text-sm text-emerald-600 dark:text-emerald-400 mt-px"></i>
            <p class="text-xs text-emerald-700 dark:text-emerald-300 m-0 leading-relaxed">
                ¡Adquiere más de 500 timbres para obtener descuentos por volumen!
            </p>
        </div>

        <!-- ════════════════════════════════════════
             Price breakdown card
             ════════════════════════════════════════ -->
        <div class="mb-8">
            <h3 class="text-[11px] uppercase tracking-wide font-bold text-gray-600 dark:text-gray-500 m-0 mb-4">
                Desglose de precio
            </h3>

            <!-- Loading skeleton -->
            <div
                v-if="quoteLoading"
                class="flex items-center justify-center gap-3 py-10 rounded-2xl bg-gray-50/80 dark:bg-[#121212] border border-gray-100 dark:border-[#2e2e2e]"
            >
                <i class="pi pi-spin pi-spinner !text-lg text-gray-400"></i>
                <span class="text-sm text-gray-400">Calculando precio...</span>
            </div>

            <!-- Price card -->
            <div
                v-else-if="quotedPrice"
                class="rounded-2xl bg-gray-50/80 dark:bg-[#121212] border border-gray-100 dark:border-[#2e2e2e] p-5 space-y-4"
            >
                <div class="flex justify-between items-center">
                    <span class="text-sm text-gray-500 dark:text-gray-400">Precio unitario</span>
                    <span class="text-sm font-medium text-gray-900 dark:text-white">
                        {{ formatCurrency(quotedPrice.unit_price) }}
                    </span>
                </div>

                <!-- Volume savings badge row -->
                <div
                    v-if="volumeSavings"
                    class="flex justify-between items-center rounded-xl bg-emerald-50 dark:bg-emerald-900/20 px-4 py-2.5 border border-emerald-100 dark:border-emerald-900/30"
                >
                    <div class="flex items-center gap-2">
                        <span class="w-5 h-5 rounded-full bg-emerald-200 dark:bg-emerald-800 flex items-center justify-center shrink-0">
                            <i class="pi pi-arrow-down !text-[10px] text-emerald-700 dark:text-emerald-300"></i>
                        </span>
                        <span class="text-sm text-emerald-700 dark:text-emerald-300 font-medium">
                            Ahorro por volumen
                        </span>
                        <span class="text-[10px] uppercase tracking-widest font-bold text-emerald-500 dark:text-emerald-400 bg-emerald-100 dark:bg-emerald-900/40 px-2 py-0.5 rounded-full">
                            −{{ volumeSavings.pct }}%
                        </span>
                    </div>
                    <span class="text-sm font-bold text-emerald-700 dark:text-emerald-300">
                        −{{ formatCurrency(volumeSavings.total) }}
                    </span>
                </div>

                <div class="border-t border-gray-200 dark:border-[#2e2e2e]"></div>

                <div class="flex justify-between items-center">
                    <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">Total a pagar</span>
                    <span class="text-3xl font-light tracking-tight text-gray-900 dark:text-white">
                        {{ formatCurrency(quotedPrice.amount_total) }}
                    </span>
                </div>
            </div>
        </div>

        <!-- ════════════════════════════════════════
             Payment method selector
             ════════════════════════════════════════ -->
        <div class="mb-6">
            <h3 class="text-[11px] uppercase tracking-wide font-bold text-gray-600 dark:text-gray-500 m-0 mb-4">
                Método de pago
            </h3>

            <div class="grid grid-cols-2 gap-3">
                <!-- Transferencia -->
                <button
                    type="button"
                    @click="paymentMethod = 'bank_transfer'"
                    class="flex flex-col items-center gap-3 p-5 rounded-2xl border transition-all duration-200"
                    :class="paymentMethod === 'bank_transfer'
                        ? 'bg-primary-50/50 dark:bg-primary-900/10 border-primary-300 dark:border-primary-700 shadow-[0_0_0_1px_rgba(99,102,241,0.3)]'
                        : 'bg-gray-50/80 dark:bg-[#121212] border-gray-100 dark:border-[#2e2e2e] hover:border-gray-200 dark:hover:border-[#3a3a3a]'"
                >   <div class="flex flex-col-2 items-center gap-3">
                        <div class="w-10 h-10 rounded-full bg-white dark:bg-[#1a1a1a] border border-gray-100 dark:border-[#2e2e2e] flex items-center justify-center">
                            <i
                                class="pi pi-building-columns !text-lg"
                                :class="paymentMethod === 'bank_transfer' ? 'text-primary-500' : 'text-gray-400 dark:text-gray-500'"
                            ></i>
                        </div>
                        <div class="text-center">
                            <p class="text-sm font-medium text-gray-900 dark:text-white m-0">Transferencia</p>
                            <p class="text-[11px] text-gray-600 dark:text-gray-500 m-0 mt-1">Bancaria</p>
                    </div>
                    </div>
                </button>

                <!-- Mercado Pago -->
                <button
                    type="button"
                    @click="paymentMethod = 'mercadopago'"
                    class="flex flex-col items-center gap-3 p-5 rounded-2xl border transition-all duration-200"
                    :class="paymentMethod === 'mercadopago'
                        ? 'bg-primary-50/50 dark:bg-primary-900/10 border-primary-300 dark:border-primary-700 shadow-[0_0_0_1px_rgba(99,102,241,0.3)]'
                        : 'bg-gray-50/80 dark:bg-[#121212] border-gray-100 dark:border-[#2e2e2e] hover:border-gray-200 dark:hover:border-[#3a3a3a]'"
                >   <div class="flex flex-col-2 items-center gap-3">
                        <div class="w-10 h-10 rounded-full bg-white dark:bg-[#1a1a1a] border border-gray-100 dark:border-[#2e2e2e] flex items-center justify-center overflow-hidden">
                            <img
                                src="/images/Mercado_Pago_logo.webp"
                                alt="Mercado Pago"
                                class="w-7 h-7 object-contain"
                            />
                        </div>
                        <div class="text-center">
                            <p class="text-sm font-medium text-gray-900 dark:text-white m-0">Mercado Pago</p>
                            <p class="text-[11px] text-gray-600 dark:text-gray-500 m-0 mt-1">Instantáneo</p>
                        </div>
                    </div>
                </button>
            </div>
        </div>

        <!-- ════════════════════════════════════════
             Bank Transfer section
             ════════════════════════════════════════ -->
        <div v-if="paymentMethod === 'bank_transfer'" class="space-y-5">
            <!-- Bank accounts -->
            <div>
                <h3 class="text-[11px] uppercase tracking-wide font-bold text-gray-600 dark:text-gray-500 m-0 mb-3">
                    Cuentas bancarias
                </h3>
                <div class="space-y-2">
                    <div
                        v-for="account in ourBankAccounts"
                        :key="account.id"
                        class="rounded-2xl bg-gray-50/80 dark:bg-[#121212] border border-gray-100 dark:border-[#2e2e2e] p-4"
                    >
                        <div class="flex justify-between items-start mb-2">
                            <span class="text-sm font-semibold text-gray-900 dark:text-white">
                                {{ account.bank_name }}
                            </span>
                            <button
                                v-if="account.clabe"
                                type="button"
                                @click="copyClabe(account.clabe)"
                                class="flex items-center gap-1.5 text-[10px] uppercase tracking-widest font-bold text-gray-400 hover:text-primary-500 dark:hover:text-primary-400 transition-colors"
                                v-tooltip.left="'Copiar CLABE'"
                            >
                                <i class="pi pi-copy !text-[10px]"></i>
                                Copiar
                            </button>
                        </div>
                        <div class="space-y-1 text-xs text-gray-500 dark:text-gray-400">
                            <p class="m-0">
                                Titular:
                                <span class="text-gray-700 dark:text-gray-300">{{ account.owner_name }}</span>
                            </p>
                            <p v-if="account.clabe" class="m-0 font-mono tracking-wide">
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

            <!-- File upload -->
            <div>
                <h3 class="text-[11px] uppercase tracking-wide font-bold text-gray-600 dark:text-gray-500 m-0 mb-3">
                    Comprobante de pago *
                </h3>

                <!-- Upload zone when no file -->
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
                                class: '!w-full !py-8 !rounded-2xl !border-2 !border-dashed !border-gray-200 dark:!border-[#2e2e2e] !bg-gray-50/50 dark:!bg-[#121212] hover:!bg-gray-100 dark:hover:!bg-[#1a1a1a] hover:!border-primary-300 dark:hover:!border-primary-700 !transition-all !duration-200 !text-gray-400 dark:!text-gray-500 !text-sm',
                            },
                        }"
                    >
                        <template #empty>
                            <div class="flex flex-col items-center gap-2 pointer-events-none">
                                <i class="pi pi-cloud-upload !text-2xl text-gray-300 dark:text-gray-600"></i>
                                <span class="text-sm text-gray-400 dark:text-gray-500">Arrastra tu comprobante o haz clic aquí</span>
                                <span class="text-[10px] text-gray-300 dark:text-gray-600">PDF, JPG o PNG — máx. 10 MB</span>
                            </div>
                        </template>
                    </FileUpload>
                </div>

                <!-- File selected card -->
                <div
                    v-else
                    class="flex items-center justify-between rounded-2xl bg-emerald-50/60 dark:bg-emerald-900/10 border border-emerald-100 dark:border-emerald-900/20 px-4 py-3"
                >
                    <div class="flex items-center gap-3 min-w-0">
                        <div class="w-9 h-9 rounded-full bg-emerald-100 dark:bg-emerald-900/30 flex items-center justify-center shrink-0">
                            <i class="pi pi-check-circle !text-sm text-emerald-600 dark:text-emerald-400"></i>
                        </div>
                        <span class="text-sm text-emerald-700 dark:text-emerald-300 truncate">
                            {{ purchaseForm.proof_file.name }}
                        </span>
                    </div>
                    <button
                        type="button"
                        @click="removeFile"
                        class="w-8 h-8 rounded-full flex items-center justify-center text-gray-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors shrink-0"
                        v-tooltip.top="'Quitar archivo'"
                    >
                        <i class="pi pi-times !text-xs"></i>
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

            <!-- Pending approval note -->
            <div class="flex items-start gap-2.5 px-4 py-3 rounded-2xl bg-amber-50/60 dark:bg-amber-900/10 border border-amber-100 dark:border-amber-900/20">
                <i class="pi pi-clock !text-xs text-amber-500 mt-0.5"></i>
                <p class="text-xs text-amber-600 dark:text-amber-400 m-0 leading-relaxed">
                    Tu pedido quedará en estado pendiente hasta que el administrador apruebe tu comprobante de pago.
                </p>
            </div>
        </div>

        <!-- ════════════════════════════════════════
             Mercado Pago section
             ════════════════════════════════════════ -->
        <div
            v-if="paymentMethod === 'mercadopago'"
            class="rounded-2xl bg-blue-50/60 dark:bg-blue-900/10 border border-blue-100 dark:border-blue-900/20 p-5"
        >
            <div class="flex items-center gap-4 mb-4">
                <div class="w-12 h-12 rounded-2xl bg-white dark:bg-[#1a1a1a] border border-gray-100 dark:border-[#2e2e2e] flex items-center justify-center overflow-hidden shrink-0">
                    <img
                        src="/images/Mercado_Pago_logo.webp"
                        alt="Mercado Pago"
                        class="w-8 h-8 object-contain"
                    />
                </div>
                <div>
                    <p class="text-sm font-semibold text-blue-800 dark:text-blue-200 m-0">
                        Pago con Mercado Pago
                    </p>
                    <p class="text-xs text-blue-600 dark:text-blue-400 m-0 mt-0.5">
                        Acreditación instantánea
                    </p>
                </div>
            </div>
            <p class="text-xs text-blue-700 dark:text-blue-300 m-0 leading-relaxed">
                Serás redirigido a Mercado Pago para completar tu pago de forma segura con tarjeta de crédito, débito o efectivo en tiendas de conveniencia. Los timbres se acreditarán automáticamente al confirmarse el pago.
            </p>
        </div>

        <!-- ════════════════════════════════════════
             Stamp quantity error
             ════════════════════════════════════════ -->
        <Message
            v-if="purchaseForm.errors?.stamp_quantity"
            severity="error"
            variant="simple"
            size="small"
            class="mt-4"
        >
            {{ purchaseForm.errors.stamp_quantity }}
        </Message>

        <!-- ════════════════════════════════════════
             Footer
             ════════════════════════════════════════ -->
        <template #footer>
            <div class="flex justify-end items-center gap-3 w-full pt-2">
                <Button
                    label="Cancelar"
                    severity="secondary"
                    text
                    @click="visible = false"
                    :disabled="purchaseForm.processing"
                    class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold"
                />
                <Button
                    :label="paymentMethod === 'mercadopago' ? 'Pagar con Mercado Pago' : 'Confirmar compra'"
                    :icon="paymentMethod === 'mercadopago' ? 'pi pi-arrow-up-right' : 'pi pi-check'"
                    :loading="purchaseForm.processing"
                    :disabled="quoteLoading || !quantity || quantity < 1 || (paymentMethod === 'bank_transfer' && !purchaseForm.proof_file)"
                    @click="submitPurchase"
                    class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold px-6 shadow-sm"
                    severity="primary"
                />
            </div>
        </template>
    </Dialog>
</template>
