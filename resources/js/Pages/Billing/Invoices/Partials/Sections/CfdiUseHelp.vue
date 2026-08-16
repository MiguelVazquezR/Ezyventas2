<script setup>
import { ref, computed } from 'vue';
import { cfdiUseCatalog, getRegimeShortName, splitRegimesByPersonType } from '../../cfdiUseCatalog';

const props = defineProps({
    // Uso de CFDI actualmente seleccionado (ej. "G03") — se resalta en el modal.
    modelValue: { type: String, default: '' },
});

// ── Modal state ─────────────────────────────────────────────
const visible = ref(false);
// Clave del uso actualmente desplegado (accordion de una sola tarjeta abierta).
const expandedKey = ref('');

const isSelected = (clave) => clave === props.modelValue;

// Regímenes agrupados por tipo de persona (física/moral) con sus textos de la
// vista tipo tabla, precalculados por clave para mantener el template limpio.
const groupsByClave = computed(() => {
    const map = {};
    for (const item of cfdiUseCatalog) {
        const compatibles = splitRegimesByPersonType(item.regimenesPermitidos);
        const incompatibles = splitRegimesByPersonType(item.incompatibles);
        // Los compatibles muestran solo el nombre corto; los no compatibles
        // muestran "Nombre (clave)" para identificarlos sin ambigüedad.
        const toText = (codes, showCode) =>
            codes.map((c) => (showCode ? `${getRegimeShortName(c)} (${c})` : getRegimeShortName(c))).join(' · ');
        map[item.clave] = {
            compatiblesFisicaText: toText(compatibles.fisica, false),
            compatiblesMoralText: toText(compatibles.moral, false),
            incompatiblesFisicaText: toText(incompatibles.fisica, true),
            incompatiblesMoralText: toText(incompatibles.moral, true),
        };
    }
    return map;
});

const groups = (clave) => groupsByClave.value[clave] || {};

function toggle(clave) {
    expandedKey.value = expandedKey.value === clave ? '' : clave;
}

function open() {
    // Al abrir, el uso actual queda desplegado para que el usuario lo revise.
    expandedKey.value = props.modelValue || '';
    visible.value = true;
}

// ── Dialog PT (Tesla UI) ────────────────────────────────────
const dialogPt = {
    root: { class: 'dark:!bg-[#232323] !border !border-gray-100 dark:!border-[#3a3a3a] !rounded-3xl !shadow-2xl !overflow-hidden' },
    header: { class: 'dark:!bg-[#232323] !border-b !border-gray-100 dark:!border-[#3a3a3a] !px-6 !py-5' },
    content: { class: 'dark:!bg-[#232323] !p-0' },
    mask: { class: '!bg-gray-900/60 dark:!bg-black/80' },
};
</script>

<template>
    <!-- Discreet info trigger, right after the "Uso de CFDI" title -->
    <button
        type="button"
        v-tooltip.top="'Ver compatibilidad'"
        aria-label="Ver compatibilidad de uso de CFDI"
        class="inline-flex items-center justify-center w-6 h-6 rounded-full text-gray-400 dark:text-gray-500 hover:text-primary-500 hover:bg-primary-50 dark:hover:bg-primary-900/20 transition-colors shrink-0"
        @click="open"
    >
        <i class="pi pi-question-circle !text-sm"></i>
    </button>

    <Dialog
        v-model:visible="visible"
        :modal="true"
        :dismissableMask="true"
        :draggable="false"
        :pt="dialogPt"
        :style="{ width: 'min(92vw, 660px)' }"
    >
        <template #header>
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-full bg-primary-50 dark:bg-primary-900/20 flex items-center justify-center shrink-0 border border-primary-100 dark:border-primary-900/30">
                    <i class="pi pi-book !text-sm text-primary-500"></i>
                </div>
                <div>
                    <h2 class="text-lg font-light tracking-tight text-gray-900 dark:text-white m-0">Catálogo y Compatibilidad de Uso de CFDI (SAT 4.0)</h2>
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-1">Despliega cada uso para ver sus regímenes y en qué casos aplica</p>
                </div>
            </div>
        </template>

        <!-- Single-column accordion (read-only, expand/collapse only) -->
        <div class="max-h-[66vh] overflow-y-auto p-5">
            <div class="flex flex-col gap-2.5">
                <div
                    v-for="item in cfdiUseCatalog"
                    :key="item.clave"
                    :class="['rounded-2xl border transition-all duration-200 overflow-hidden',
                             isSelected(item.clave)
                                 ? 'border-emerald-200 dark:border-emerald-700/60 bg-emerald-50/40 dark:bg-emerald-950/10'
                                 : 'bg-white dark:bg-[#1a1a1a] border-gray-100 dark:border-[#3a3a3a]']"
                >
                    <!-- Accordion header -->
                    <button
                        type="button"
                        class="w-full flex items-center gap-3 px-4 py-3.5 text-left cursor-pointer transition-colors hover:bg-gray-50 dark:hover:bg-white/5"
                        @click="toggle(item.clave)"
                        :aria-expanded="expandedKey === item.clave"
                    >
                        <span :class="['px-2.5 py-1 rounded-lg text-xs font-bold tracking-wider shrink-0',
                                       isSelected(item.clave)
                                           ? 'bg-emerald-500 text-white'
                                           : 'bg-gray-900 dark:bg-white text-white dark:text-gray-900']">
                            {{ item.clave }}
                        </span>
                        <span class="flex-1 min-w-0">
                            <span class="block text-sm font-medium text-gray-800 dark:text-gray-200 leading-snug">{{ item.descripcion }}</span>
                            <span v-if="isSelected(item.clave)" class="inline-flex items-center gap-1 mt-0.5 text-[10px] font-bold uppercase tracking-widest text-emerald-600 dark:text-emerald-400">
                                <i class="pi pi-check-circle !text-[10px]"></i> Uso actual
                            </span>
                        </span>
                        <i :class="['pi pi-chevron-down !text-xs text-gray-400 transition-transform duration-200 shrink-0', expandedKey === item.clave ? 'rotate-180' : '']"></i>
                    </button>

                    <!-- Expanded body -->
                    <div v-if="expandedKey === item.clave" class="px-4 pb-4 pt-1">
                        <!-- ¿Cuándo se utiliza? -->
                        <div class="rounded-xl bg-gray-50 dark:bg-white/5 border border-gray-100 dark:border-gray-800 px-3.5 py-3 flex flex-col gap-1">
                            <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 flex items-center gap-1.5">
                                <i class="pi pi-question-circle !text-[10px]"></i> ¿Cuándo se utiliza?
                            </p>
                            <p v-if="item.nota" class="text-[12px] text-gray-600 dark:text-gray-400 leading-relaxed m-0 flex items-start gap-1.5">
                                <i class="!text-[11px] text-gray-400 mt-px shrink-0"></i>
                                <span>{{ item.nota }}</span>
                            </p>
                        </div>

                        <!-- Tabla: Regímenes compatibles -->
                        <div class="mt-3 flex flex-col gap-1.5">
                            <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Regímenes compatibles</p>
                            <div class="flex items-start gap-2.5 rounded-xl border border-gray-100 dark:border-gray-800 px-3 py-2.5">
                                <span class="shrink-0 inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-sky-50 dark:bg-sky-950/40 border border-sky-100 dark:border-sky-900/40 text-[10px] font-bold text-sky-600 dark:text-sky-300 mt-px">
                                    <i class="pi pi-user !text-[9px]"></i> Persona Física
                                </span>
                                <p class="text-[11px] text-gray-600 dark:text-gray-300 leading-relaxed m-0">{{ groups(item.clave).compatiblesFisicaText }}</p>
                            </div>
                            <div class="flex items-start gap-2.5 rounded-xl border border-gray-100 dark:border-gray-800 px-3 py-2.5">
                                <span class="shrink-0 inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-indigo-50 dark:bg-indigo-950/40 border border-indigo-100 dark:border-indigo-900/40 text-[10px] font-bold text-indigo-600 dark:text-indigo-300 mt-px">
                                    <i class="pi pi-building !text-[9px]"></i> Persona Moral
                                </span>
                                <p class="text-[11px] text-gray-600 dark:text-gray-300 leading-relaxed m-0">{{ groups(item.clave).compatiblesMoralText }}</p>
                            </div>
                        </div>

                        <!-- Tabla: Regímenes NO compatibles -->
                        <div v-if="groups(item.clave).incompatiblesFisicaText || groups(item.clave).incompatiblesMoralText" class="mt-3 flex flex-col gap-1.5">
                            <p class="text-[10px] uppercase tracking-widest font-bold text-rose-500 m-0">Regímenes NO compatibles</p>
                            <div v-if="groups(item.clave).incompatiblesFisicaText" class="flex items-start gap-2.5 rounded-xl bg-rose-50/60 dark:bg-rose-950/20 border border-rose-100 dark:border-rose-900/40 px-3 py-2.5">
                                <span class="shrink-0 inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-sky-50 dark:bg-sky-950/40 border border-sky-100 dark:border-sky-900/40 text-[10px] font-bold text-sky-600 dark:text-sky-300 mt-px">
                                    <i class="pi pi-user !text-[9px]"></i> Persona Física
                                </span>
                                <p class="text-[11px] text-rose-600 dark:text-rose-300 leading-relaxed m-0">{{ groups(item.clave).incompatiblesFisicaText }}</p>
                            </div>
                            <div v-if="groups(item.clave).incompatiblesMoralText" class="flex items-start gap-2.5 rounded-xl bg-rose-50/60 dark:bg-rose-950/20 border border-rose-100 dark:border-rose-900/40 px-3 py-2.5">
                                <span class="shrink-0 inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-indigo-50 dark:bg-indigo-950/40 border border-indigo-100 dark:border-indigo-900/40 text-[10px] font-bold text-indigo-600 dark:text-indigo-300 mt-px">
                                    <i class="pi pi-building !text-[9px]"></i> Persona Moral
                                </span>
                                <p class="text-[11px] text-rose-600 dark:text-rose-300 leading-relaxed m-0">{{ groups(item.clave).incompatiblesMoralText }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </Dialog>
</template>
