<script setup>
import { ref } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    tiers: Array,
    preview: Array,
});

// ──────────────────────────────────────
// Breadcrumb
// ──────────────────────────────────────
const home = ref({ icon: 'pi pi-home', url: route('admin.reports.index') });
const breadcrumbItems = ref([
    { label: 'Administración' },
    { label: 'Precios de timbres' },
]);

// ──────────────────────────────────────
// Create / Edit dialog state
// ──────────────────────────────────────
const showDialog = ref(false);
const editingTier = ref(null);
const isEditing = ref(false);

const form = useForm({
    min_quantity: 1,
    max_quantity: null,
    unit_price: 0.85,
    label: '',
    is_active: true,
    sort_order: 0,
});

function openCreate() {
    isEditing.value = false;
    editingTier.value = null;
    form.reset();
    form.min_quantity = 1;
    form.max_quantity = null;
    form.unit_price = 0.85;
    form.label = '';
    form.is_active = true;
    form.sort_order = 0;
    form.clearErrors();
    showDialog.value = true;
}

function openEdit(tier) {
    isEditing.value = true;
    editingTier.value = tier;
    form.min_quantity = tier.min_quantity;
    form.max_quantity = tier.max_quantity;
    form.unit_price = tier.unit_price;
    form.label = tier.label || '';
    form.is_active = tier.is_active;
    form.sort_order = tier.sort_order || 0;
    form.clearErrors();
    showDialog.value = true;
}

function submit() {
    if (isEditing.value) {
        form.put(route('admin.stamps.pricing.update', editingTier.value.id), {
            preserveScroll: true,
            onSuccess: () => { showDialog.value = false; },
        });
    } else {
        form.post(route('admin.stamps.pricing.store'), {
            preserveScroll: true,
            onSuccess: () => { showDialog.value = false; },
        });
    }
}

function confirmDelete(tier) {
    if (confirm(`¿Eliminar el tramo "${tier.label || tier.min_quantity + ' timbres'}"?`)) {
        router.delete(route('admin.stamps.pricing.destroy', tier.id), {
            preserveScroll: true,
        });
    }
}

// ──────────────────────────────────────
// Helpers
// ──────────────────────────────────────
function formatCurrency(amount) {
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(amount || 0);
}

// ──────────────────────────────────────
// Tesla UI
// ──────────────────────────────────────
const dialogPt = {
    root: { class: 'dark:!bg-[#232323] !border !border-gray-100 dark:!border-[#3a3a3a] !rounded-3xl !shadow-2xl !overflow-hidden' },
    header: { class: 'dark:!bg-[#232323] !border-b !border-gray-100 dark:!border-[#3a3a3a] !px-6 !py-5' },
    title: { class: '!text-lg !font-medium !text-gray-900 dark:!text-white !tracking-tight !m-0' },
    content: { class: 'dark:!bg-[#232323] !p-6 lg:!p-8' },
    mask: { class: '!bg-gray-900/60 dark:!bg-black/80' },
};
</script>

<template>
    <AppLayout :home="home" :breadcrumbItems="breadcrumbItems">
        <div class="max-w-5xl mx-auto space-y-6">

            <!-- ── Header ──────────────────────────────── -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-light tracking-tight text-gray-900 dark:text-white m-0">
                        Precios de timbres
                    </h1>
                    <p class="text-sm text-gray-500 mt-1 m-0">
                        Estos precios aplican a todos los suscriptores, no solo a uno.
                    </p>
                </div>
                <Button icon="pi pi-plus" label="Nuevo tramo" class="!rounded-full" @click="openCreate" />
            </div>

            <!-- ── Preview Table ────────────────────────── -->
            <div class="rounded-3xl bg-white dark:bg-[#232323] border border-gray-100 dark:border-[#3a3a3a] p-6">
                <h2 class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mb-4">Vista previa de precios</h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-[10px] uppercase tracking-widest font-bold text-gray-500">
                                <th class="pb-3 pr-4">Cantidad</th>
                                <th class="pb-3 pr-4">Precio unitario</th>
                                <th class="pb-3 pr-4">Total</th>
                                <th class="pb-3">Tramo aplicado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in preview" :key="row.quantity" class="border-t border-gray-100 dark:border-[#3a3a3a]">
                                <td class="py-3 pr-4 font-medium text-gray-900 dark:text-white">{{ row.quantity.toLocaleString() }}</td>
                                <td class="py-3 pr-4 text-gray-600 dark:text-gray-400">{{ formatCurrency(row.unit_price) }}</td>
                                <td class="py-3 pr-4 text-gray-900 dark:text-white font-light text-lg">{{ formatCurrency(row.total) }}</td>
                                <td class="py-3 text-gray-500">{{ row.tier_label }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ── Tiers List ────────────────────────────── -->
            <div class="rounded-3xl bg-white dark:bg-[#232323] border border-gray-100 dark:border-[#3a3a3a] p-6">
                <h2 class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mb-4">Tramos configurados</h2>

                <div v-if="tiers.length === 0" class="text-center py-8 text-sm text-gray-400">
                    No hay tramos de precio configurados. Crea el primero.
                </div>

                <div v-else class="space-y-3">
                    <div
                        v-for="tier in tiers"
                        :key="tier.id"
                        class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 p-4 rounded-2xl bg-gray-50 dark:bg-[#1a1a1a] border border-gray-100 dark:border-[#3a3a3a]"
                        :class="{ 'opacity-50': !tier.is_active }"
                    >
                        <div class="flex items-center gap-4">
                            <div class="w-10 h-10 rounded-full bg-primary-50 dark:bg-primary-900/20 flex items-center justify-center flex-shrink-0 border border-primary-100 dark:border-primary-900/30">
                                <i class="pi pi-tag !text-xs text-primary-500"></i>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-gray-900 dark:text-white m-0">
                                    {{ tier.label || 'Sin etiqueta' }}
                                    <Tag v-if="!tier.is_active" value="Inactivo" severity="secondary" class="!rounded-full ml-2" />
                                </p>
                                <p class="text-xs text-gray-500 m-0 mt-0.5">
                                    {{ tier.min_quantity.toLocaleString() }}
                                    <template v-if="tier.max_quantity">– {{ tier.max_quantity.toLocaleString() }}</template>
                                    <template v-else>+</template>
                                    timbres
                                    <span class="mx-1.5 text-gray-300 dark:text-gray-600">·</span>
                                    {{ formatCurrency(tier.unit_price) }} c/u
                                </p>
                            </div>
                        </div>
                        <div class="flex gap-2 shrink-0">
                            <Button icon="pi pi-pencil" severity="secondary" size="small" class="!rounded-full" @click="openEdit(tier)" v-tooltip.top="'Editar'" />
                            <Button icon="pi pi-trash" severity="danger" size="small" class="!rounded-full" @click="confirmDelete(tier)" v-tooltip.top="'Eliminar'" />
                        </div>
                    </div>
                </div>
            </div>

        </div>

        <!-- ── Create / Edit Dialog ─────────────────────── -->
        <Dialog
            v-model:visible="showDialog"
            :modal="true"
            class="w-full max-w-md"
            :pt="dialogPt"
        >
            <template #header>
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-full bg-primary-50 dark:bg-primary-900/20 flex items-center justify-center flex-shrink-0 border border-primary-100 dark:border-primary-900/30">
                        <i class="pi pi-tag !text-sm text-primary-500"></i>
                    </div>
                    <div>
                        <h2 class="text-lg font-light tracking-tight text-gray-900 dark:text-white m-0">
                            {{ isEditing ? 'Editar tramo' : 'Nuevo tramo' }}
                        </h2>
                        <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-1">Precio por volumen</p>
                    </div>
                </div>
            </template>

            <form @submit.prevent="submit" class="flex flex-col gap-4 pt-2">
                <div class="flex flex-col gap-1.5">
                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Cantidad mínima *</label>
                    <InputNumber v-model="form.min_quantity" :min="1" class="w-full"
                        :pt="{ input: { root: { class: 'w-full min-w-0 !rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !py-3' } } }" />
                    <Message v-if="form.errors.min_quantity" severity="error" variant="simple" size="small">{{ form.errors.min_quantity }}</Message>
                </div>

                <div class="flex flex-col gap-1.5">
                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Cantidad máxima (vacío = sin límite)</label>
                    <InputNumber v-model="form.max_quantity" :min="1" class="w-full"
                        :pt="{ input: { root: { class: 'w-full min-w-0 !rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !py-3' } } }" />
                    <Message v-if="form.errors.max_quantity" severity="error" variant="simple" size="small">{{ form.errors.max_quantity }}</Message>
                </div>

                <div class="flex flex-col gap-1.5">
                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Precio unitario (MXN) *</label>
                    <InputNumber v-model="form.unit_price" :minFractionDigits="2" :maxFractionDigits="4" mode="decimal" class="w-full"
                        :pt="{ input: { root: { class: 'w-full min-w-0 !rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !py-3 !text-2xl !font-light !text-gray-900 dark:!text-white' } } }" />
                    <Message v-if="form.errors.unit_price" severity="error" variant="simple" size="small">{{ form.errors.unit_price }}</Message>
                </div>

                <div class="flex flex-col gap-1.5">
                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Etiqueta</label>
                    <InputText v-model="form.label" placeholder="Ej: Volumen medio" class="w-full"
                        :pt="{ root: { class: '!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !py-3' } }" />
                </div>

                <div class="flex items-center gap-3">
                    <Checkbox v-model="form.is_active" inputId="isActive" :binary="true" />
                    <label for="isActive" class="text-sm text-gray-700 dark:text-gray-300 cursor-pointer">Activo</label>
                </div>
            </form>

            <template #footer>
                <Button label="Cancelar" severity="secondary" class="!rounded-full" @click="showDialog = false" />
                <Button
                    :label="isEditing ? 'Guardar cambios' : 'Crear tramo'"
                    :loading="form.processing"
                    class="!rounded-full"
                    @click="submit"
                />
            </template>
        </Dialog>
    </AppLayout>
</template>
