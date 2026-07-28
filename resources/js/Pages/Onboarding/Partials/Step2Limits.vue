<script setup>
import InputError from '@/Components/InputError.vue';

const props = defineProps({
    form: Object,
    saving: Boolean,
    availableModules: Array,
});

const emit = defineEmits(['save-step', 'go-back']);

// --- Tesla UI PT ---
const inputNumberPt = {
    input: {
        root: {
            class: 'w-full !rounded-xl !bg-white dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !py-2.5 !text-sm !text-gray-900 dark:!text-white',
        },
    },
};

// --- Module helpers ---
const FREE_MODULE_KEYS = ['module_transactions', 'module_products', 'module_expenses', 'module_cash_registers', 'module_settings'];
const AI_MODULE_KEY = 'module_ai_agent';

/**
 * Módulos que siempre están activos (no se pueden desactivar):
 * - Módulos con precio $0 excepto AI Agent
 */
const isAlwaysActiveModule = (module) => {
    return FREE_MODULE_KEYS.includes(module.key);
};

const isModuleActive = (key) => {
    return props.form.modules.includes(key);
};

const toggleModule = (key) => {
    const module = props.availableModules.find(m => m.key === key);
    if (!module) return;

    // Módulos $0 bloqueados (excepto AI)
    if (isAlwaysActiveModule(module)) return;

    const idx = props.form.modules.indexOf(key);
    if (idx > -1) {
        props.form.modules.splice(idx, 1);
    } else {
        props.form.modules.push(key);
    }
};
</script>

<template>
    <div class="p-5 lg:p-6 space-y-6">

        <!-- Info message -->
        <Message severity="info" :closable="false" class="!rounded-xl !text-xs" :pt="{ content: { class: '!text-xs' } }">
            Establece los límites totales para tu suscripción. Éstos se compartirán entre todas tus sucursales.
        </Message>

        <!-- Grid de límites -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

            <!-- Usuarios -->
            <div class="bg-gray-50 dark:bg-[#1a1a1a] p-5 rounded-2xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col gap-3">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-primary-500/10 flex items-center justify-center flex-shrink-0">
                        <i class="pi pi-users text-primary-500 !text-lg"></i>
                    </div>
                    <div>
                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Usuarios</label>
                        <p class="text-[10px] text-gray-400 m-0 mt-0.5">Cuentas que podrán acceder al sistema</p>
                    </div>
                </div>
                <InputNumber v-model="form.limits.limit_users" :min="1" showButtons fluid :pt="inputNumberPt" />
                <InputError :message="form.errors['limits.limit_users']" />
            </div>

            <!-- Productos -->
            <div class="bg-gray-50 dark:bg-[#1a1a1a] p-5 rounded-2xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col gap-3">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-primary-500/10 flex items-center justify-center flex-shrink-0">
                        <i class="pi pi-barcode text-primary-500 !text-lg"></i>
                    </div>
                    <div>
                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Productos</label>
                        <p class="text-[10px] text-gray-400 m-0 mt-0.5">Capacidad para registrar tu inventario</p>
                    </div>
                </div>
                <InputNumber v-model="form.limits.limit_products" :min="1" showButtons fluid :pt="inputNumberPt" />
                <InputError :message="form.errors['limits.limit_products']" />
            </div>

            <!-- Cajas registradoras -->
            <div class="bg-gray-50 dark:bg-[#1a1a1a] p-5 rounded-2xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col gap-3">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-primary-500/10 flex items-center justify-center flex-shrink-0">
                        <i class="pi pi-inbox text-primary-500 !text-lg"></i>
                    </div>
                    <div>
                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Cajas registradoras</label>
                        <p class="text-[10px] text-gray-400 m-0 mt-0.5">Cajas operando simultáneamente</p>
                    </div>
                </div>
                <InputNumber v-model="form.limits.limit_cash_registers" :min="1" showButtons fluid :pt="inputNumberPt" />
                <InputError :message="form.errors['limits.limit_cash_registers']" />
            </div>

            <!-- Plantillas de impresión -->
            <div class="bg-gray-50 dark:bg-[#1a1a1a] p-5 rounded-2xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col gap-3">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-primary-500/10 flex items-center justify-center flex-shrink-0">
                        <i class="pi pi-palette text-primary-500 !text-lg"></i>
                    </div>
                    <div>
                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Plantillas de impresión</label>
                        <p class="text-[10px] text-gray-400 m-0 mt-0.5">Diseños de tickets o etiquetas</p>
                    </div>
                </div>
                <InputNumber v-model="form.limits.limit_print_templates" :min="1" showButtons fluid :pt="inputNumberPt" />
                <InputError :message="form.errors['limits.limit_print_templates']" />
            </div>

        </div>

        <!-- Sección de Módulos -->
        <div class="space-y-4">
            <div class="flex items-center gap-2">
                <i class="pi pi-puzzle-piece text-primary-500 !text-sm"></i>
                <h3 class="text-xs uppercase tracking-widest font-bold text-gray-500 m-0">Módulos</h3>
            </div>

            <p class="text-[10px] text-gray-400 m-0">
                Selecciona los módulos que deseas activar para tu suscripción. Los módulos incluidos no pueden desactivarse.
            </p>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <template v-for="module in availableModules" :key="module.key">
                    <div
                        class="bg-gray-50 dark:bg-[#1a1a1a] p-4 rounded-2xl border border-gray-100 dark:border-[#3a3a3a] flex items-center justify-between gap-3"
                        :class="{ 'opacity-60': isAlwaysActiveModule(module) }"
                    >
                        <div class="flex items-center gap-3 min-w-0">
                            <div class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0"
                                :class="isAlwaysActiveModule(module) ? 'bg-green-500/10' : (module.key === AI_MODULE_KEY ? 'bg-blue-500/10' : 'bg-primary-500/10')"
                            >
                                <i :class="[module.meta?.icon || 'pi pi-box', '!text-lg', isAlwaysActiveModule(module) ? 'text-green-500' : (module.key === AI_MODULE_KEY ? 'text-blue-500' : 'text-primary-500')]"></i>
                            </div>
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-gray-900 dark:text-white m-0 truncate">{{ module.name }}</p>
                                <div class="flex items-center gap-2 mt-1">
                                    <span class="text-[10px] text-gray-400 truncate" v-if="module.description">{{ module.description }}</span>
                                    <span v-if="isAlwaysActiveModule(module)"
                                        class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-full text-[9px] font-bold uppercase tracking-wider bg-green-500/10 text-green-600 dark:bg-green-500/20 dark:text-green-400 flex-shrink-0">
                                        <i class="pi pi-check-circle !text-[10px]"></i>
                                        Incluido
                                    </span>
                                    <span v-else-if="module.key === AI_MODULE_KEY"
                                        class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-full text-[9px] font-bold uppercase tracking-wider bg-blue-500/10 text-blue-600 dark:bg-blue-500/20 dark:text-blue-400 flex-shrink-0">
                                        <i class="pi pi-clock !text-[10px]"></i>
                                        Gratis por tiempo limitado
                                    </span>
                                    <span v-else
                                        class="text-[10px] font-bold text-gray-600 dark:text-gray-400 flex-shrink-0">
                                        +US${{ module.monthly_price }}/mes
                                    </span>
                                </div>
                            </div>
                        </div>

                        <ToggleSwitch
                            :modelValue="true"
                            :disabled="true"
                            v-if="isAlwaysActiveModule(module)"
                            :pt="{
                                root: { class: 'flex-shrink-0' },
                                slider: { class: '!bg-green-500' },
                            }"
                        />
                        <ToggleSwitch
                            :modelValue="true"
                            :disabled="true"
                            v-else-if="module.key === AI_MODULE_KEY"
                            :pt="{
                                root: { class: 'flex-shrink-0' },
                                slider: { class: '!bg-blue-500' },
                            }"
                        />
                        <ToggleSwitch
                            v-else
                            :modelValue="isModuleActive(module.key)"
                            @update:modelValue="toggleModule(module.key)"
                            :pt="{
                                root: { class: 'flex-shrink-0' },
                            }"
                        />
                    </div>
                </template>
            </div>

            <InputError :message="form.errors['modules']" />
        </div>

        <!-- Navegación -->
        <div class="flex justify-between pt-2">
            <Button label="Anterior" icon="pi pi-arrow-left" severity="secondary" outlined
                @click="emit('go-back')" class="!rounded-full !text-xs !uppercase !tracking-wider" />
            <Button label="Siguiente" icon="pi pi-arrow-right" iconPos="right"
                @click="emit('save-step', 1)" :loading="saving || form.processing"
                class="!rounded-full !text-xs !uppercase !tracking-wider" />
        </div>
    </div>
</template>