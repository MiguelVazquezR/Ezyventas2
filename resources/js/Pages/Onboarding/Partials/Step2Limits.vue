<script setup>
import { computed, ref } from 'vue';
import InputError from '@/Components/InputError.vue';
import { AI_MODULE_KEY, FREE_MODULE_KEYS, MODULE_SHORT_DESCRIPTIONS } from '@/constants/modules';

const props = defineProps({
    form: Object,
    saving: Boolean,
    availableModules: Array,
    availableLimits: Array,
});

const emit = defineEmits(['finish', 'go-back']);

// --- Local UI state ---
const includedPanel = ref(null); // null = "Incluido en tu plan esencial" collapsed
const advancedPanel = ref(null); // null = "Ajustes avanzados" collapsed

// --- Tesla UI PT ---
const inputNumberPt = {
    input: {
        root: {
            class: 'w-full !rounded-xl !bg-white dark:!bg-[#232323] !border-gray-200 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !py-2.5 !text-sm !text-gray-900 dark:!text-white text-right',
        },
    },
};

const accordionPt = {
    panel: { class: 'border border-dashed border-gray-200 dark:border-[#3a3a3a] rounded-2xl bg-transparent overflow-hidden' },
    headerAction: { class: '!p-4 hover:bg-gray-50 dark:hover:bg-[#2a2a2a] transition-colors flex items-center justify-between gap-3 w-full outline-none focus:ring-0 bg-transparent' },
    content: { class: '!p-4 !pt-0 bg-transparent' },
};

const includedAccordionPt = {
    panel: { class: 'border border-green-500/20 rounded-2xl bg-green-500/5 overflow-hidden' },
    headerAction: { class: '!p-4 hover:bg-green-500/10 transition-colors flex items-center justify-between gap-3 w-full outline-none focus:ring-0 bg-transparent' },
    content: { class: '!p-4 !pt-0 bg-transparent' },
};

// Short descriptions live in @/constants/modules so the welcome screen and
// this wizard show the same copy.
const moduleDescription = (module) => {
    return MODULE_SHORT_DESCRIPTIONS[module.key] || module.description || null;
};

const isAlwaysActiveModule = (module) => {
    return FREE_MODULE_KEYS.includes(module.key);
};

const isModuleActive = (key) => {
    return props.form.modules.includes(key);
};

const toggleModule = (key) => {
    const module = props.availableModules.find(m => m.key === key);
    if (!module) return;
    if (isAlwaysActiveModule(module)) return;

    const idx = props.form.modules.indexOf(key);
    if (idx > -1) {
        props.form.modules.splice(idx, 1);
    } else {
        props.form.modules.push(key);
    }
};

// --- Sorted modules: included first, then AI, then paid extras ---
const sortedModules = computed(() => {
    const modules = [...(props.availableModules || [])];
    const included = [];
    const ai = [];
    const paid = [];

    modules.forEach(m => {
        if (FREE_MODULE_KEYS.includes(m.key)) {
            included.push(m);
        } else if (m.key === AI_MODULE_KEY) {
            ai.push(m);
        } else {
            paid.push(m);
        }
    });

    return [...included, ...ai, ...paid];
});

// --- The AI agent is part of the essentials only while its plan item is free ---
const aiModuleItem = computed(() => {
    return (props.availableModules || []).find(m => m.key === AI_MODULE_KEY) || null;
});

const isAiModuleFree = computed(() => {
    const price = parseFloat(aiModuleItem.value?.monthly_price) || 0;
    return !!aiModuleItem.value && price <= 0;
});

// --- Split modules: always-on (essential) vs toggleable (additional) ---
const includedModules = computed(() => {
    return sortedModules.value.filter(m => {
        if (isAlwaysActiveModule(m)) return true;
        if (m.key === AI_MODULE_KEY) return isAiModuleFree.value;
        return false;
    });
});

const toggleableModules = computed(() => {
    return sortedModules.value.filter(m => {
        if (isAlwaysActiveModule(m)) return false;
        if (m.key === AI_MODULE_KEY) return !isAiModuleFree.value;
        return true;
    });
});

// --- Bulk actions for additional modules ---
const allAdditionalModulesActive = computed(() => {
    return toggleableModules.value.length > 0 && toggleableModules.value.every(m => props.form.modules.includes(m.key));
});

const activateAllAdditionalModules = () => {
    toggleableModules.value.forEach(m => {
        if (!props.form.modules.includes(m.key)) {
            props.form.modules.push(m.key);
        }
    });
};

const deactivateAllAdditionalModules = () => {
    toggleableModules.value.forEach(m => {
        const idx = props.form.modules.indexOf(m.key);
        if (idx > -1) {
            props.form.modules.splice(idx, 1);
        }
    });
};

// --- Servicios module active? ---
const isServicesModuleActive = computed(() => {
    return props.form.modules.includes('module_services');
});

// --- Find limit item from availableLimits ---
const getLimitItem = (key) => {
    return props.availableLimits?.find(l => l.key === key) || null;
};

// --- Limit rows shown inside "Ajustes avanzados" ---
const limitRows = computed(() => {
    const rows = [
        { key: 'limit_users', label: 'Usuarios', icon: 'pi pi-users', fallback: 'Cuentas que podrán acceder al sistema' },
        { key: 'limit_products', label: 'Productos', icon: 'pi pi-barcode', fallback: 'Capacidad para registrar tu inventario' },
        { key: 'limit_cash_registers', label: 'Cajas registradoras', icon: 'pi pi-inbox', fallback: 'Cajas operando simultáneamente' },
        { key: 'limit_print_templates', label: 'Plantillas de impresión', icon: 'pi pi-palette', fallback: 'Diseños de tickets o etiquetas' },
    ];

    if (isServicesModuleActive.value) {
        rows.splice(2, 0, { key: 'limit_services', label: 'Servicios', icon: 'pi pi-wrench', fallback: 'Servicios que puedes registrar en tu catálogo' });
    }

    return rows;
});
</script>

<template>
    <div class="p-5 lg:p-6 space-y-6">

        <!-- Intro -->
        <p class="text-[11px] text-gray-500 dark:text-gray-400 m-0">
            Tu plan esencial ya está activo. Puedes probar cualquier módulo adicional sin compromiso durante tus 30 días de prueba.
        </p>

        <!-- Plan esencial (incluido) — colapsable -->
        <Accordion v-model:value="includedPanel" :pt="includedAccordionPt">
            <AccordionPanel value="included">
                <AccordionHeader>
                    <div class="flex items-center gap-3 flex-1 min-w-0 text-left">
                        <i class="pi pi-check-circle text-green-500 !text-sm flex-shrink-0"></i>
                        <div class="flex flex-col min-w-0">
                            <span class="text-[11px] font-bold uppercase tracking-widest text-green-600 dark:text-green-400">
                                Incluido en tu plan esencial
                            </span>
                            <span class="text-[10px] text-green-700/60 dark:text-green-400/60">
                                {{ includedModules.length }} módulos ya activos en tu cuenta
                            </span>
                        </div>
                    </div>
                </AccordionHeader>

                <AccordionContent>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                        <div v-for="module in includedModules" :key="module.key"
                            class="flex items-center gap-3 bg-gray-50 dark:bg-[#1a1a1a] border border-gray-100 dark:border-[#3a3a3a] rounded-xl px-3.5 py-2.5 transition-colors hover:border-primary-500/50">
                            <div class="w-8 h-8 rounded-lg bg-primary-500/10 flex items-center justify-center flex-shrink-0">
                                <i :class="[module.meta?.icon || 'pi pi-box', '!text-sm text-primary-500']"></i>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-[12px] font-semibold text-gray-900 dark:text-white m-0 leading-tight">{{ module.name }}</p>
                                <p class="text-[11px] leading-snug text-gray-500 dark:text-gray-400 m-0 mt-0.5">
                                    {{ moduleDescription(module) || 'Siempre activo en tu cuenta' }}
                                </p>
                                <span v-if="module.key === AI_MODULE_KEY"
                                    class="inline-flex items-center gap-1 mt-1 px-1.5 py-0.5 rounded-full text-[8.5px] font-bold uppercase tracking-widest bg-amber-500/10 text-amber-600 dark:text-amber-400">
                                    <i class="pi pi-clock !text-[8px]"></i>
                                    Gratis por tiempo limitado
                                </span>
                            </div>
                            <i class="pi pi-check-circle !text-[12px] text-green-500 flex-shrink-0"></i>
                        </div>
                    </div>
                </AccordionContent>
            </AccordionPanel>
        </Accordion>

        <!-- Módulos adicionales -->
        <div v-if="toggleableModules.length > 0" class="space-y-3">
            <div class="flex items-center justify-between gap-3">
                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[9px] font-bold uppercase tracking-widest bg-gray-100 dark:bg-[#232323] text-gray-500 dark:text-gray-400 border border-gray-100 dark:border-[#3a3a3a]">
                    <i class="pi pi-plus-circle !text-[10px]"></i>
                    Módulos adicionales
                </span>

                <Button v-if="!allAdditionalModulesActive" label="Probar todos" icon="pi pi-bolt" outlined size="small"
                    @click="activateAllAdditionalModules"
                    class="!rounded-full !text-[10px] !uppercase !tracking-wider" />
                <Button v-else label="Desactivar todos" icon="pi pi-undo" text size="small"
                    @click="deactivateAllAdditionalModules"
                    class="!rounded-full !text-[10px] !uppercase !tracking-wider !text-gray-400 hover:!bg-gray-100 dark:hover:!bg-[#2a2a2a]" />
            </div>

            <p class="text-[11px] text-gray-500 dark:text-gray-400 m-0">
                Actívalos cuando quieras; puedes desactivarlos con un clic.
            </p>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                <div v-for="module in toggleableModules" :key="module.key"
                    class="flex items-center gap-3 bg-gray-50 dark:bg-[#1a1a1a] border border-gray-100 dark:border-[#3a3a3a] rounded-xl px-3.5 py-2.5 transition-colors hover:border-primary-500/50">
                    <div class="w-8 h-8 rounded-lg bg-primary-500/10 flex items-center justify-center flex-shrink-0">
                        <i :class="[module.meta?.icon || 'pi pi-box', '!text-sm text-primary-500']"></i>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-[12px] font-semibold text-gray-900 dark:text-white m-0 leading-tight">{{ module.name }}</p>
                        <p v-if="moduleDescription(module)" class="text-[11px] leading-snug text-gray-500 dark:text-gray-400 m-0 mt-0.5">
                            {{ moduleDescription(module) }}
                        </p>
                    </div>
                    <ToggleSwitch
                        :modelValue="isModuleActive(module.key)"
                        @update:modelValue="toggleModule(module.key)"
                        :pt="{ root: { class: 'flex-shrink-0' } }"
                    />
                </div>
            </div>

            <InputError :message="form.errors['modules']" />
        </div>

        <!-- Separador: los ajustes avanzados viven en su propia sección -->
        <div class="flex items-center gap-3 pt-2">
            <span class="text-[9px] uppercase tracking-widest font-bold text-gray-400 dark:text-gray-500 whitespace-nowrap">
                Solo si lo necesitas
            </span>
            <div class="flex-1 border-t border-dashed border-gray-200 dark:border-[#3a3a3a]"></div>
        </div>

        <!-- Ajustes avanzados: límites de recursos -->
        <Accordion v-model:value="advancedPanel" :pt="accordionPt">
            <AccordionPanel value="advanced">
                <AccordionHeader>
                    <div class="flex items-center gap-3 flex-1 min-w-0 text-left">
                        <i class="pi pi-sliders-h text-gray-400 !text-sm flex-shrink-0"></i>
                        <div class="flex flex-col min-w-0">
                            <span class="text-sm font-medium text-gray-900 dark:text-gray-100">Ajustes avanzados</span>
                            <span class="text-[10px] text-gray-500 dark:text-gray-400">
                                Límites de recursos — los valores predeterminados funcionan bien para empezar
                            </span>
                        </div>
                    </div>
                </AccordionHeader>

                <AccordionContent>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div v-for="row in limitRows" :key="row.key"
                            class="bg-gray-50 dark:bg-[#1a1a1a] border border-gray-100 dark:border-[#3a3a3a] rounded-2xl p-4 flex flex-col gap-3 transition-colors hover:border-primary-500/30">
                            <div class="flex items-start gap-3">
                                <div class="w-9 h-9 rounded-xl bg-primary-500/10 flex items-center justify-center flex-shrink-0">
                                    <i :class="[row.icon, '!text-sm text-primary-500']"></i>
                                </div>
                                <div class="min-w-0">
                                    <p class="text-[12px] font-semibold text-gray-900 dark:text-white m-0 leading-tight">{{ row.label }}</p>
                                    <p class="text-[10px] leading-snug text-gray-500 dark:text-gray-400 m-0 mt-1">
                                        {{ getLimitItem(row.key)?.description || row.fallback }}
                                    </p>
                                </div>
                            </div>
                            <div>
                                <InputNumber v-model="form.limits[row.key]" :min="1" locale="es-MX" fluid :pt="inputNumberPt" />
                                <InputError :message="form.errors[`limits.${row.key}`]" class="mt-1" />
                            </div>
                        </div>

                        <!-- Sucursales (se gestionan en el paso 1) -->
                        <div class="bg-gray-50 dark:bg-[#1a1a1a] border border-gray-100 dark:border-[#3a3a3a] rounded-2xl p-4 flex flex-col gap-3">
                            <div class="flex items-start gap-3">
                                <div class="w-9 h-9 rounded-xl bg-primary-500/10 flex items-center justify-center flex-shrink-0">
                                    <i class="pi pi-building !text-sm text-primary-500"></i>
                                </div>
                                <div class="min-w-0">
                                    <p class="text-[12px] font-semibold text-gray-900 dark:text-white m-0 leading-tight">Sucursales</p>
                                    <p class="text-[10px] leading-snug text-gray-500 dark:text-gray-400 m-0 mt-1">
                                        Se gestionan en el paso anterior
                                    </p>
                                </div>
                            </div>
                            <div class="flex items-center justify-between rounded-xl bg-white dark:bg-[#232323] border border-gray-200 dark:border-[#3a3a3a] px-3.5 py-2.5">
                                <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500">Registradas</span>
                                <span class="text-base font-light text-gray-900 dark:text-white">{{ form.branches?.length || 0 }}</span>
                            </div>
                        </div>
                    </div>
                </AccordionContent>
            </AccordionPanel>
        </Accordion>

        <!-- Navegación -->
        <div class="flex flex-col-reverse sm:flex-row sm:items-center sm:justify-between gap-3 pt-1">
            <Button label="Anterior" icon="pi pi-angle-left" severity="secondary" outlined
                @click="emit('go-back')" class="!rounded-full !px-5 !text-sm" />
            <Button label="Comenzar ahora" icon="pi pi-rocket"
                @click="emit('finish')" :loading="saving || form.processing"
                class="ez-glow w-full sm:w-auto !rounded-full !border-0 !bg-gradient-to-r !from-[#f68c0f] !via-[#ffa31a] !to-[#ffc24d] !text-[#1A1A1A] font-bold !py-3 !px-6 !text-sm hover:!brightness-110 transition-all duration-200" />
        </div>
    </div>
</template>