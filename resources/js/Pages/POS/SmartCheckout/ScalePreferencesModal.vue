<script setup>
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import { useToast } from 'primevue/usetoast';

const props = defineProps({
    visible: { type: Boolean, default: false },
    scale: { type: Object, default: () => ({ enabled: false, can_use: false, can_configure: false, preferences: null }) },
});

const emit = defineEmits(['update:visible', 'saved', 'configure']);

const toast = useToast();
const saving = ref(false);

const isOpen = computed({
    get: () => props.visible,
    set: (value) => emit('update:visible', value),
});

const scaleEnabled = ref(false);
const smartCheckoutEnabled = ref(false);

watch(
    () => props.visible,
    (open) => {
        if (!open) return;
        scaleEnabled.value = Boolean(props.scale?.preferences?.scale ?? props.scale?.enabled);
        smartCheckoutEnabled.value = Boolean(props.scale?.preferences?.smart_checkout);
    },
);

async function save(options = {}) {
    saving.value = true;
    try {
        await axios.put(route('pos.smart-checkout.preferences.update'), {
            scale_enabled: scaleEnabled.value,
            smart_checkout_enabled: smartCheckoutEnabled.value,
        });
        toast.add({
            severity: 'success',
            summary: 'Preferencias guardadas',
            detail: scaleEnabled.value
                ? 'La báscula ya está disponible en el punto de venta.'
                : 'La báscula se desactivó. El punto de venta sigue funcionando como siempre.',
            life: 3500,
        });
        isOpen.value = false;
        emit('saved', {
            scale: scaleEnabled.value,
            smart_checkout: smartCheckoutEnabled.value,
            configure: Boolean(options.configure) && scaleEnabled.value,
        });
    } catch (error) {
        const message =
            error?.response?.status === 403
                ? 'No tienes permiso para cambiar las preferencias de caja.'
                : error?.response?.data?.message || 'No se pudieron guardar las preferencias.';
        toast.add({ severity: 'error', summary: 'No se guardó', detail: message, life: 4500 });
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <Dialog
        v-model:visible="isOpen"
        modal
        :style="{ width: '480px', maxWidth: '94vw' }"
        :pt="{
            root: { class: '!rounded-3xl !border !border-gray-100 dark:!border-[#3a3a3a] !bg-white dark:!bg-[#232323] !shadow-2xl' },
            header: { class: '!px-6 !pt-6 !pb-2 !border-none' },
            content: { class: '!px-6 !pt-2 !pb-6' },
            closeButton: { class: '!text-gray-400 hover:!text-gray-600 dark:hover:!text-gray-200' },
        }"
        :draggable="false"
    >
        <template #header>
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-2xl flex items-center justify-center bg-primary-500/10 text-primary-500">
                    <i class="pi pi-sliders-h text-lg" aria-hidden="true"></i>
                </div>
                <div>
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-400 m-0">Punto de venta</p>
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-white m-0">Preferencias de caja</h3>
                </div>
            </div>
        </template>

        <div class="space-y-4">
            <div class="flex items-start gap-3 rounded-2xl border border-gray-100 dark:border-[#3a3a3a] bg-gray-50 dark:bg-[#1a1a1a] p-4">
                <div class="mt-0.5">
                    <ToggleSwitch v-model="scaleEnabled" inputId="pref-scale" aria-label="Báscula para productos a granel" />
                </div>
                <label for="pref-scale" class="flex-1 cursor-pointer select-none">
                    <span class="block text-sm font-semibold text-gray-800 dark:text-white">Báscula para productos a granel</span>
                    <span class="block text-[11px] text-gray-500 dark:text-gray-400 mt-0.5">
                        Abre el panel de pesado al tocar un producto marcado como granel con unidad Kg o Grs.
                        Funciona con o sin el Modo Caja Inteligente.
                    </span>
                </label>
            </div>

            <div class="flex items-start gap-3 rounded-2xl border border-gray-100 dark:border-[#3a3a3a] bg-gray-50 dark:bg-[#1a1a1a] p-4">
                <div class="mt-0.5">
                    <ToggleSwitch v-model="smartCheckoutEnabled" inputId="pref-smart-checkout" aria-label="Modo Caja Inteligente" />
                </div>
                <label for="pref-smart-checkout" class="flex-1 cursor-pointer select-none">
                    <span class="block text-sm font-semibold text-gray-800 dark:text-white">Modo Caja Inteligente</span>
                    <span class="block text-[11px] text-gray-500 dark:text-gray-400 mt-0.5">
                        Muestra el botón del modo en la barra del punto de venta: reconocimiento de productos con
                        semáforo de confianza, selector de candidatos y barra de indicadores.
                    </span>
                </label>
            </div>

            <div v-if="smartCheckoutEnabled" class="rounded-2xl border border-dashed border-primary-200 dark:border-primary-800/50 bg-primary-50/40 dark:bg-primary-900/10 p-3">
                <p class="text-[11px] text-gray-600 dark:text-gray-300 m-0">
                    Actívalo y pulsa el botón <i class="pi pi-microchip-ai" aria-hidden="true"></i> de la barra del punto de
                    venta. Los umbrales del semáforo se ajustan dentro del panel del modo.
                </p>
            </div>

            <div v-if="scaleEnabled" class="flex items-center justify-between rounded-2xl border border-gray-100 dark:border-[#3a3a3a] p-4">
                <div>
                    <span class="block text-sm font-semibold text-gray-800 dark:text-white">Tipos de báscula y perfiles</span>
                    <span class="block text-[11px] text-gray-500 dark:text-gray-400 mt-0.5">
                        Simulador, captura manual o cable USB (Web Serial), con perfiles y modo diagnóstico.
                    </span>
                </div>
                <Button
                    label="Configurar"
                    icon="pi pi-cog"
                    size="small"
                    outlined
                    class="!rounded-xl !text-[11px] !font-bold"
                    :loading="saving"
                    @click="save({ configure: true })"
                />
            </div>

            <p class="text-[11px] text-gray-400 m-0">
                Los ajustes se guardan por negocio. Cada cajero conserva su tipo de conexión en esta terminal.
            </p>
        </div>

        <template #footer>
            <div class="flex items-center justify-end gap-2">
                <Button label="Cancelar" severity="secondary" outlined class="!rounded-xl !font-bold" @click="isOpen = false" />
                <Button label="Guardar" icon="pi pi-check" class="!rounded-xl !font-bold" :loading="saving" @click="save" />
            </div>
        </template>
    </Dialog>
</template>
