<script setup>
import { ref, computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import axios from 'axios';

// ── Props & Emits ─────────────────────────────────────────
const props = defineProps({
    visible: Boolean,
    fiscalProfiles: { type: Array, default: () => [] },
    tiers: { type: Array, default: () => [] },
    preselectedProfileId: { type: [Number, null], default: null },
});
const emit = defineEmits(['update:visible']);

// ── Operation mode ─────────────────────────────────────────
const opMode = ref('manual'); // 'manual' | 'purchase'

// ── Form state ─────────────────────────────────────────────
const form = useForm({
    fiscal_profile_id: props.preselectedProfileId,
    stamp_quantity: 100,
    adjustment_type: 'add',
    admin_note: '',
    mode: 'manual',
    proof_file: null,
});

watch(() => props.visible, (open) => {
    if (!open) return;
    opMode.value = 'manual';
    form.fiscal_profile_id = props.preselectedProfileId;
    form.stamp_quantity = 100;
    form.adjustment_type = 'add';
    form.admin_note = '';
    form.mode = 'manual';
    form.proof_file = null;
    form.clearErrors();
    filePreviewUrl.value = null;
    if (props.preselectedProfileId) fetchBalance(props.preselectedProfileId);
});

watch(() => props.preselectedProfileId, (id) => {
    if (id && props.visible) { form.fiscal_profile_id = id; fetchBalance(id); }
});

watch(opMode, (m) => { form.mode = m; });

// ── Selected profile ───────────────────────────────────────
const selectedProfile = computed(() =>
    props.fiscalProfiles.find(p => p.id === form.fiscal_profile_id) ?? null
);

// ── Stamp balance ──────────────────────────────────────────
const stampBalance = ref(null);
const balanceLoading = ref(false);
const balanceError = ref(null);

async function fetchBalance(profileId) {
    if (!profileId) { stampBalance.value = null; balanceError.value = null; return; }
    balanceLoading.value = true;
    balanceError.value = null;
    stampBalance.value = null;
    try {
        const { data } = await axios.get(route('admin.stamps.balance', profileId));
        stampBalance.value = data.balance?.stampsBalance ?? null;
        if (stampBalance.value === null) balanceError.value = 'No se pudo interpretar el saldo.';
    } catch (err) {
        balanceError.value = err.response?.data?.error ?? 'No se pudo consultar el saldo.';
    } finally { balanceLoading.value = false; }
}
watch(() => form.fiscal_profile_id, (n) => { if (n) fetchBalance(n); });

// ── Pricing calculation (purchase mode) ────────────────────
const activeTiers = computed(() =>
    [...props.tiers].filter(t => t.is_active !== false).sort((a, b) => a.min_quantity - b.min_quantity)
);
function findTier(qty) {
    if (!qty || qty < 1) return null;
    return activeTiers.value.find(t =>
        t.min_quantity <= qty && (t.max_quantity == null || t.max_quantity >= qty)
    ) ?? null;
}
const currentTier = computed(() => findTier(form.stamp_quantity));
const unitPrice = computed(() => currentTier.value ? Number(currentTier.value.unit_price) : 0);
const amountTotal = computed(() => form.stamp_quantity * unitPrice.value);
const baseTier = computed(() => findTier(1));
const baseUnitPrice = computed(() => baseTier.value ? Number(baseTier.value.unit_price) : 0);
const savingsPU = computed(() => baseUnitPrice.value - unitPrice.value);
const savingsTotal = computed(() => savingsPU.value * form.stamp_quantity);
const savingsPct = computed(() => baseUnitPrice.value > 0 ? Math.round((savingsPU.value / baseUnitPrice.value) * 100) : 0);

// ── File handling ──────────────────────────────────────────
const filePreviewUrl = ref(null);
const fileName = ref('');

function onFileSelect(e) {
    const file = e.target?.files?.[0] ?? e.files?.[0];
    if (!file) return;
    form.proof_file = file;
    fileName.value = file.name;
    filePreviewUrl.value = URL.createObjectURL(file);
}
function removeFile() { form.proof_file = null; fileName.value = ''; filePreviewUrl.value = null; }

// ── Submit / Close ─────────────────────────────────────────
function submit() {
    form.post(route('admin.stamps.manual-adjustment'), {
        preserveScroll: true,
        forceFormData: opMode.value === 'purchase',
        onSuccess: () => emit('update:visible', false),
    });
}
function close() { emit('update:visible', false); }

// ── Helpers ────────────────────────────────────────────────
const adjLabel = (t) => t === 'add' ? 'Agregar timbres' : 'Retirar timbres';
const modeLabel = (m) => m === 'manual' ? 'Ajuste manual' : 'Compra de timbres';
const fmtCurr = (v) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(v || 0);
const fmtNum = (v) => new Intl.NumberFormat('es-MX').format(v || 0);

// ── Dialog PT ──────────────────────────────────────────────
const dpt = {
    root: { class: 'dark:!bg-[#232323] !border !border-gray-100 dark:!border-[#3a3a3a] !rounded-3xl !shadow-2xl !overflow-hidden' },
    header: { class: 'dark:!bg-[#232323] !border-b !border-gray-100 dark:!border-[#3a3a3a] !px-6 !py-5' },
    title: { class: '!text-lg !font-medium !text-gray-900 dark:!text-white !tracking-tight !m-0' },
    content: { class: 'dark:!bg-[#232323] !p-6 lg:!p-8' },
    mask: { class: '!bg-gray-900/60 dark:!bg-black/80' },
};
</script>

<template>
    <Dialog :visible="visible" :modal="true" :pt="dpt" @update:visible="close" class="w-full max-w-xl">
        <template #header>
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-full bg-primary-50 dark:bg-primary-900/20 flex items-center justify-center shrink-0 border border-primary-100 dark:border-primary-900/30">
                    <i class="pi pi-cog !text-sm text-primary-500" />
                </div>
                <div>
                    <h2 class="text-lg font-light tracking-tight text-gray-900 dark:text-white m-0">{{ modeLabel(opMode) }}</h2>
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-1">{{ selectedProfile ? selectedProfile.razon_social : 'Gesti&oacute;n de timbres' }}</p>
                </div>
            </div>
        </template>

        <form @submit.prevent="submit" class="flex flex-col gap-5">

            <!-- ── Mode Toggle ──────────────────────────── -->
            <div class="flex flex-col gap-1.5">
                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Modo de operaci&oacute;n</label>
                <div class="flex p-1 rounded-2xl bg-gray-100 dark:bg-[#1a1a1a] border border-gray-100 dark:border-[#3a3a3a]">
                    <button type="button" @click="opMode = 'manual'"
                        class="flex-1 px-4 py-2 text-xs font-semibold uppercase tracking-widest rounded-xl transition-all cursor-pointer"
                        :class="opMode === 'manual' ? 'bg-white dark:bg-[#232323] text-gray-900 dark:text-white shadow-sm' : 'text-gray-400 hover:text-gray-600 dark:hover:text-gray-300'">
                        <i class="pi pi-sliders-h !text-xs mr-1.5" />Ajuste manual
                    </button>
                    <button type="button" @click="opMode = 'purchase'"
                        class="flex-1 px-4 py-2 text-xs font-semibold uppercase tracking-widest rounded-xl transition-all cursor-pointer"
                        :class="opMode === 'purchase' ? 'bg-white dark:bg-[#232323] text-gray-900 dark:text-white shadow-sm' : 'text-gray-400 hover:text-gray-600 dark:hover:text-gray-300'">
                        <i class="pi pi-shopping-cart !text-xs mr-1.5" />Compra de timbres
                    </button>
                </div>
            </div>

            <!-- ── Fiscal Profile Select ────────────────── -->
            <div class="flex flex-col gap-1.5">
                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Perfil fiscal *</label>
                <Select v-model="form.fiscal_profile_id" :options="fiscalProfiles" optionLabel="razon_social" optionValue="id"
                    placeholder="Selecciona un perfil fiscal" class="w-full" :disabled="!!preselectedProfileId"
                    :pt="{ root: { class: '!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors' } }">
                    <template #value>
                        <div v-if="selectedProfile" class="flex flex-col">
                            <span class="text-sm font-medium text-gray-900 dark:text-white">{{ selectedProfile.razon_social }}</span>
                            <span class="text-[11px] text-gray-400">{{ selectedProfile.rfc }} &middot; {{ selectedProfile.subscription_name }}</span>
                        </div>
                        <span v-else class="text-gray-400">Selecciona un perfil fiscal</span>
                    </template>
                    <template #option="{ option }">
                        <div class="flex flex-col py-0.5">
                            <span class="text-sm font-medium text-gray-900 dark:text-white">{{ option.razon_social }}</span>
                            <span class="text-[11px] text-gray-400">{{ option.rfc }} &middot; {{ option.subscription_name }}</span>
                        </div>
                    </template>
                </Select>
                <Message v-if="form.errors.fiscal_profile_id" severity="error" variant="simple" size="small">{{ form.errors.fiscal_profile_id }}</Message>
            </div>

            <!-- ── Subscriber Info Card ─────────────────── -->
            <div v-if="selectedProfile" class="rounded-2xl bg-gray-50 dark:bg-[#1a1a1a] border border-gray-100 dark:border-[#3a3a3a] p-4 space-y-2.5">
                <div class="flex items-center justify-between">
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Informaci&oacute;n del suscriptor</p>
                    <i v-if="balanceLoading" class="pi pi-spin pi-spinner !text-xs text-gray-400" />
                    <span v-else-if="stampBalance !== null"
                        class="inline-flex items-center gap-1 rounded-full bg-emerald-50 dark:bg-emerald-900/20 px-2.5 py-0.5 text-[10px] font-semibold text-emerald-700 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800">
                        <i class="pi pi-ticket !text-[10px]" />{{ fmtNum(stampBalance) }} disponibles
                    </span>
                    <span v-else-if="balanceError" class="text-[10px] text-red-500">{{ balanceError }}</span>
                </div>
                <div class="grid grid-cols-2 gap-x-4 gap-y-2 text-xs">
                    <div><span class="text-gray-400">Suscriptor</span><p class="text-gray-900 dark:text-white font-medium m-0">{{ selectedProfile.subscription_name || '&mdash;' }}</p></div>
                    <div><span class="text-gray-400">Contacto</span><p class="text-gray-900 dark:text-white font-medium m-0 truncate">{{ selectedProfile.subscription_email || '&mdash;' }}</p></div>
                    <div><span class="text-gray-400">RFC</span><p class="text-gray-900 dark:text-white font-medium m-0">{{ selectedProfile.rfc }}</p></div>
                    <div><span class="text-gray-400">Correo del perfil</span><p class="text-gray-900 dark:text-white font-medium m-0 truncate">{{ selectedProfile.email || '&mdash;' }}</p></div>
                </div>
            </div>

            <!-- ── Quantity ─────────────────────────────── -->
            <div class="flex flex-col gap-1.5">
                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Cantidad de timbres *</label>
                <InputNumber v-model="form.stamp_quantity" :min="1" :max="999999" class="w-full"
                    :pt="{ input: { root: { class: 'w-full min-w-0 !rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !py-3 !text-2xl !font-light !text-gray-900 dark:!text-white' } } }" />
                <Message v-if="form.errors.stamp_quantity" severity="error" variant="simple" size="small">{{ form.errors.stamp_quantity }}</Message>
            </div>

            <!-- ── Pricing Breakdown (purchase) ─────────── -->
            <div v-if="opMode === 'purchase'" class="rounded-2xl bg-gray-50 dark:bg-[#1a1a1a] border border-gray-100 dark:border-[#3a3a3a] p-4 space-y-3">
                <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Desglose de precios</p>
                <div class="space-y-1.5 text-sm">
                    <div class="flex justify-between">
                        <span class="text-gray-400">Tramo aplicado</span>
                        <span class="font-medium text-gray-900 dark:text-white">
                            {{ currentTier?.label || '&mdash;' }}
                            <span v-if="currentTier" class="text-gray-400 font-normal">
                                ({{ fmtNum(currentTier.min_quantity) }}{{ currentTier.max_quantity ? '&ndash;' + fmtNum(currentTier.max_quantity) : '+' }} timbres)
                            </span>
                        </span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-400">Precio unitario</span>
                        <span class="font-medium text-gray-900 dark:text-white">{{ fmtCurr(unitPrice) }}</span>
                    </div>
                    <div class="flex justify-between border-t border-gray-200 dark:border-[#3a3a3a] pt-1.5 mt-1.5">
                        <span class="font-semibold text-gray-700 dark:text-gray-300">Monto total a pagar</span>
                        <span class="text-2xl font-light tracking-tight text-gray-900 dark:text-white">{{ fmtCurr(amountTotal) }}</span>
                    </div>
                </div>
                <div v-if="savingsTotal > 0" class="flex items-center gap-2 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800 px-3 py-2">
                    <i class="pi pi-verified !text-xs text-emerald-500" />
                    <span class="text-xs text-emerald-700 dark:text-emerald-300 font-medium">Ahorras {{ fmtCurr(savingsTotal) }} ({{ savingsPct }}%) vs. precio base</span>
                </div>
            </div>

            <!-- ── Manual: adjustment type ──────────────── -->
            <div v-if="opMode === 'manual'" class="flex flex-col gap-1.5">
                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Tipo de ajuste *</label>
                <div class="flex gap-4">
                    <div class="flex items-center gap-2">
                        <RadioButton v-model="form.adjustment_type" value="add" inputId="adj_add" />
                        <label for="adj_add" class="text-sm text-gray-700 dark:text-gray-300 cursor-pointer">Agregar</label>
                    </div>
                    <div class="flex items-center gap-2">
                        <RadioButton v-model="form.adjustment_type" value="remove" inputId="adj_remove" />
                        <label for="adj_remove" class="text-sm text-gray-700 dark:text-gray-300 cursor-pointer">Retirar</label>
                    </div>
                </div>
                <Message v-if="form.errors.adjustment_type" severity="error" variant="simple" size="small">{{ form.errors.adjustment_type }}</Message>
            </div>

            <!-- ── Manual: note ─────────────────────────── -->
            <div v-if="opMode === 'manual'" class="flex flex-col gap-1.5">
                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Nota administrativa *</label>
                <Textarea v-model="form.admin_note" placeholder="Raz&oacute;n del ajuste..." rows="3" class="w-full"
                    :pt="{ root: { class: '!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !py-3' } }" />
                <Message v-if="form.errors.admin_note" severity="error" variant="simple" size="small">{{ form.errors.admin_note }}</Message>
            </div>

            <!-- ── Purchase: file upload ────────────────── -->
            <div v-if="opMode === 'purchase'" class="flex flex-col gap-1.5">
                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Comprobante de pago *</label>
                <div @click="$refs.fileInput.click()" @dragover.prevent @drop.prevent="onFileSelect($event.dataTransfer)"
                    class="relative flex flex-col items-center justify-center gap-2 p-6 rounded-2xl border-2 border-dashed border-gray-200 dark:border-[#3a3a3a] bg-gray-50 dark:bg-[#1a1a1a] transition-colors cursor-pointer hover:border-primary-400 dark:hover:border-primary-500">
                    <template v-if="!form.proof_file">
                        <i class="pi pi-cloud-upload !text-2xl text-gray-300 dark:text-gray-500" />
                        <p class="text-sm text-gray-400 m-0">Arrastra el comprobante aqu&iacute; o <span class="text-primary-500 font-medium">selecciona un archivo</span></p>
                        <p class="text-[10px] text-gray-400 m-0">PDF, JPG o PNG &middot; M&aacute;x. 5 MB</p>
                    </template>
                    <template v-else>
                        <div class="flex items-center gap-3 w-full">
                            <i class="pi pi-file !text-xl text-primary-500 shrink-0" />
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-gray-900 dark:text-white m-0 truncate">{{ fileName }}</p>
                                <p class="text-[10px] text-gray-400 m-0">Archivo listo para enviar</p>
                            </div>
                            <Button icon="pi pi-times" severity="secondary" size="small" class="!rounded-full shrink-0" @click.stop="removeFile" />
                        </div>
                    </template>
                    <input ref="fileInput" type="file" accept=".pdf,.jpg,.jpeg,.png" class="hidden" @change="onFileSelect" />
                </div>
                <Message v-if="form.errors.proof_file" severity="error" variant="simple" size="small">{{ form.errors.proof_file }}</Message>
            </div>

            <!-- ── Info alerts ──────────────────────────── -->
            <div v-if="opMode === 'manual'" class="flex items-start gap-3 p-4 rounded-2xl bg-blue-50 dark:bg-blue-900/20 border border-blue-100 dark:border-blue-800">
                <i class="pi pi-info-circle !text-sm text-blue-500 mt-0.5" />
                <div>
                    <p class="text-xs text-blue-700 dark:text-blue-300 m-0 leading-relaxed">
                        <template v-if="form.adjustment_type === 'add'"><strong>Agregar timbres:</strong> descuenta de la cuenta maestra y acredita al perfil fiscal.</template>
                        <template v-else><strong>Retirar timbres:</strong> devuelve timbres del perfil fiscal a la cuenta maestra.</template>
                    </p>
                    <p class="text-xs text-blue-600 dark:text-blue-400 m-0 mt-2">La cuenta maestra debe tener saldo suficiente para agregar timbres. Los cambios se aplican de inmediato y se reflejan en el PAC en breve.</p>
                </div>
            </div>
            <div v-if="opMode === 'purchase'" class="flex items-start gap-3 p-4 rounded-2xl bg-amber-50 dark:bg-amber-900/20 border border-amber-100 dark:border-amber-800">
                <i class="pi pi-info-circle !text-sm text-amber-500 mt-0.5" />
                <div>
                    <p class="text-xs text-amber-700 dark:text-amber-300 m-0 leading-relaxed"><strong>Compra de timbres:</strong> se cobrar&aacute; el monto total calculado seg&uacute;n el tramo de precio aplicado. El comprobante de pago quedar&aacute; almacenado como respaldo.</p>
                    <p class="text-xs text-amber-600 dark:text-amber-400 m-0 mt-2">Los timbres se acreditar&aacute;n al perfil fiscal inmediatamente despu&eacute;s de confirmar la operaci&oacute;n.</p>
                </div>
            </div>
        </form>

        <template #footer>
            <Button label="Cancelar" severity="secondary" class="!rounded-full" @click="close" />
            <Button v-if="opMode === 'manual'" :label="adjLabel(form.adjustment_type)" icon="pi pi-check" :loading="form.processing"
                class="!rounded-full" :severity="form.adjustment_type === 'remove' ? 'danger' : 'primary'" @click="submit" />
            <Button v-if="opMode === 'purchase'" label="Procesar compra" icon="pi pi-shopping-cart" :loading="form.processing"
                class="!rounded-full" severity="success" @click="submit" />
        </template>
    </Dialog>
</template>
