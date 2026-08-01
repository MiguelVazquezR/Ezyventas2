<script setup>
import { computed } from 'vue';
import InputError from '@/Components/InputError.vue';

const props = defineProps({
    form: Object,
    saving: Boolean,
    availableModules: Array,
    availableLimits: Array,
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
const FREE_MODULE_KEYS = ['module_pos', 'module_transactions', 'module_products', 'module_expenses', 'module_cash_registers', 'module_settings'];
const AI_MODULE_KEY = 'module_ai_agent';

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
    if (key === AI_MODULE_KEY) return; // AI module is always on

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

// --- Servicios module active? ---
const isServicesModuleActive = computed(() => {
    return props.form.modules.includes('module_services');
});

// --- Find limit item from availableLimits ---
const getLimitItem = (key) => {
    return props.availableLimits?.find(l => l.key === key) || null;
};

// --- Monthly cost calculation ---
const monthlyCost = computed(() => {
    let total = 0;

    // Sum modules (only those active)
    (props.availableModules || []).forEach(m => {
        if (props.form.modules.includes(m.key)) {
            total += parseFloat(m.monthly_price) || 0;
        }
    });

    // Sum limits: monthly_price can be per unit (e.g. $5/user/month) or per package
    // when meta.quantity is defined (e.g. $1.50 per 100 products)
    (props.availableLimits || []).forEach(limitItem => {
        const limitKey = limitItem.key;
        const currentQty = props.form.limits[limitKey];
        if (currentQty === undefined || currentQty === null) return;

        // Skip limit_services when the services module is not active
        if (limitKey === 'limit_services' && !props.form.modules.includes('module_services')) return;

        const monthlyPrice = parseFloat(limitItem.monthly_price) || 0;
        if (monthlyPrice <= 0) return;

        const packageSize = limitItem.meta?.quantity;
        if (packageSize && packageSize > 0) {
            // Price is per package: divide to get unit price, then multiply by current qty
            total += (monthlyPrice / packageSize) * currentQty;
        } else {
            // Price is per unit: multiply directly
            total += monthlyPrice * currentQty;
        }
    });

    // Sum branches (managed in Step 1, priced per unit)
    const branchCount = (props.form.branches || []).length;
    if (branchCount > 0) {
        const branchPlanItem = getLimitItem('limit_branches');
        const branchPrice = parseFloat(branchPlanItem?.monthly_price) || 0;
        if (branchPrice > 0) {
            total += branchPrice * branchCount;
        }
    }

    return total;
});

const formatMxn = (amount) => {
    return new Intl.NumberFormat('es-MX', {
        style: 'currency',
        currency: 'MXN',
        minimumFractionDigits: 2,
    }).format(amount);
};
</script>

<template>
    <div class="p-5 lg:p-6 space-y-6">

        <!-- Info message -->
        <Message severity="info" :closable="false" class="!rounded-xl !text-xs" :pt="{ content: { class: '!text-xs' } }">
            Configura los módulos y límites de tu suscripción. Tienes <strong>30 días gratis de prueba</strong> para usar todas las funciones.
        </Message>

        <!-- Sección de Módulos -->
        <div class="space-y-4">
            <div class="flex items-center gap-2">
                <i class="pi pi-puzzle-piece text-primary-500 !text-sm"></i>
                <h3 class="text-xs uppercase tracking-widest font-bold text-gray-500 m-0">Módulos</h3>
            </div>

            <p class="text-[10px] text-gray-600 dark:text-gray-300 m-0">
                Selecciona los módulos que deseas activar para tu suscripción. Los módulos incluidos no pueden desactivarse.
            </p>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <template v-for="module in sortedModules" :key="module.key">
                    <div
                        class="bg-gray-50 dark:bg-[#1a1a1a] p-4 rounded-2xl border border-gray-100 dark:border-[#3a3a3a] flex items-start justify-between gap-3"
                        :class="{ 'opacity-60': isAlwaysActiveModule(module) }"
                    >
                        <div class="flex items-start gap-3 min-w-0">
                            <div class="w-10 h-10 rounded-xl bg-primary-500/10 flex items-center justify-center flex-shrink-0">
                                <i :class="[module.meta?.icon || 'pi pi-box', '!text-lg text-primary-500']"></i>
                            </div>
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-gray-900 dark:text-white m-0 truncate">{{ module.name }}</p>
                                <p class="text-[10px] text-gray-600 dark:text-gray-300 m-0 mt-0.5 leading-relaxed" v-if="module.description">{{ module.description }}</p>
                                <div class="flex items-center gap-2 mt-1.5">
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
                                    <span v-else-if="parseFloat(module.monthly_price) > 0"
                                        class="text-[10px] font-bold text-gray-600 dark:text-gray-400 flex-shrink-0">
                                        +{{ formatMxn(module.monthly_price) }}/mes
                                    </span>
                                    <span v-else
                                        class="text-[10px] font-bold text-green-600 dark:text-green-400 flex-shrink-0">
                                        Incluido
                                    </span>
                                </div>
                            </div>
                        </div>

                        <ToggleSwitch
                            :modelValue="true"
                            :disabled="true"
                            v-if="isAlwaysActiveModule(module) || module.key === AI_MODULE_KEY"
                            :pt="{ root: { class: 'flex-shrink-0' } }"
                        />
                        <ToggleSwitch
                            v-else
                            :modelValue="isModuleActive(module.key)"
                            @update:modelValue="toggleModule(module.key)"
                            :pt="{ root: { class: 'flex-shrink-0' } }"
                        />
                    </div>
                </template>
            </div>

            <InputError :message="form.errors['modules']" />
        </div>

        <!-- Sección de Límites -->
        <div class="space-y-4">
            <div class="flex items-center gap-2">
                <i class="pi pi-sliders-h text-primary-500 !text-sm"></i>
                <h3 class="text-xs uppercase tracking-widest font-bold text-gray-500 m-0">Límites de recursos</h3>
            </div>

            <p class="text-[10px] text-gray-600 dark:text-gray-300 m-0">
                Establece los límites totales para tu suscripción. Éstos se compartirán entre todas tus sucursales.
            </p>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Usuarios -->
                <div class="bg-gray-50 dark:bg-[#1a1a1a] p-5 rounded-2xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col gap-3">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-primary-500/10 flex items-center justify-center flex-shrink-0">
                            <i class="pi pi-users text-primary-500 !text-lg"></i>
                        </div>
                        <div>
                            <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Usuarios</label>
                            <p class="text-[10px] text-gray-600 dark:text-gray-300 m-0 mt-0.5">{{ getLimitItem('limit_users')?.description || 'Cuentas que podrán acceder al sistema' }}</p>
                        </div>
                    </div>
                    <InputNumber v-model="form.limits.limit_users" :min="1" showButtons fluid :pt="inputNumberPt" />
                    <p class="text-[9px] text-gray-500 dark:text-gray-400 m-0" v-if="getLimitItem('limit_users')?.monthly_price > 0">
                        {{ formatMxn(getLimitItem('limit_users').monthly_price) }} por usuario adicional al mes
                    </p>
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
                            <p class="text-[10px] text-gray-600 dark:text-gray-300 m-0 mt-0.5">{{ getLimitItem('limit_products')?.description || 'Capacidad para registrar tu inventario' }}</p>
                        </div>
                    </div>
                    <InputNumber v-model="form.limits.limit_products" :min="1" showButtons fluid :pt="inputNumberPt" />
                    <p class="text-[9px] text-gray-500 dark:text-gray-400 m-0" v-if="getLimitItem('limit_products')?.monthly_price > 0">
                        {{ formatMxn(getLimitItem('limit_products').monthly_price) }} por cada {{ getLimitItem('limit_products')?.meta?.quantity || 100 }} productos adicionales
                    </p>
                    <InputError :message="form.errors['limits.limit_products']" />
                </div>

                <!-- Servicios (condicional) -->
                <div v-if="isServicesModuleActive"
                    class="bg-gray-50 dark:bg-[#1a1a1a] p-5 rounded-2xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col gap-3">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-primary-500/10 flex items-center justify-center flex-shrink-0">
                            <i class="pi pi-wrench text-primary-500 !text-lg"></i>
                        </div>
                        <div>
                            <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Servicios</label>
                            <p class="text-[10px] text-gray-600 dark:text-gray-300 m-0 mt-0.5">{{ getLimitItem('limit_services')?.description || 'Servicios que puedes registrar en tu catálogo' }}</p>
                        </div>
                    </div>
                    <InputNumber v-model="form.limits.limit_services" :min="1" showButtons fluid :pt="inputNumberPt" />
                    <p class="text-[9px] text-gray-500 dark:text-gray-400 m-0" v-if="getLimitItem('limit_services')?.monthly_price > 0">
                        {{ formatMxn(getLimitItem('limit_services').monthly_price) }} por cada {{ getLimitItem('limit_services')?.meta?.quantity || 100 }} servicios adicionales
                    </p>
                    <InputError :message="form.errors['limits.limit_services']" />
                </div>

                <!-- Cajas registradoras -->
                <div class="bg-gray-50 dark:bg-[#1a1a1a] p-5 rounded-2xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col gap-3">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-primary-500/10 flex items-center justify-center flex-shrink-0">
                            <i class="pi pi-inbox text-primary-500 !text-lg"></i>
                        </div>
                        <div>
                            <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Cajas registradoras</label>
                            <p class="text-[10px] text-gray-600 dark:text-gray-300 m-0 mt-0.5">{{ getLimitItem('limit_cash_registers')?.description || 'Cajas operando simultáneamente' }}</p>
                        </div>
                    </div>
                    <InputNumber v-model="form.limits.limit_cash_registers" :min="1" showButtons fluid :pt="inputNumberPt" />
                    <p class="text-[9px] text-gray-500 dark:text-gray-400 m-0" v-if="getLimitItem('limit_cash_registers')?.monthly_price > 0">
                        {{ formatMxn(getLimitItem('limit_cash_registers').monthly_price) }} por caja adicional al mes
                    </p>
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
                            <p class="text-[10px] text-gray-600 dark:text-gray-300 m-0 mt-0.5">{{ getLimitItem('limit_print_templates')?.description || 'Diseños de tickets o etiquetas' }}</p>
                        </div>
                    </div>
                    <InputNumber v-model="form.limits.limit_print_templates" :min="1" showButtons fluid :pt="inputNumberPt" />
                    <p class="text-[9px] text-gray-500 dark:text-gray-400 m-0" v-if="getLimitItem('limit_print_templates')?.monthly_price > 0">
                        {{ formatMxn(getLimitItem('limit_print_templates').monthly_price) }} por plantilla adicional al mes
                    </p>
                    <InputError :message="form.errors['limits.limit_print_templates']" />
                </div>

                <!-- Sucursales (solo lectura — se gestionan en el paso 1) -->
                <div class="bg-gray-50 dark:bg-[#1a1a1a] p-5 rounded-2xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col gap-3">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-primary-500/10 flex items-center justify-center flex-shrink-0">
                            <i class="pi pi-building text-primary-500 !text-lg"></i>
                        </div>
                        <div>
                            <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Sucursales</label>
                            <p class="text-[10px] text-gray-600 dark:text-gray-300 m-0 mt-0.5">{{ getLimitItem('limit_branches')?.description || 'Sucursales registradas para tu negocio' }}</p>
                        </div>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium text-gray-900 dark:text-white">{{ form.branches?.length || 0 }} {{ (form.branches?.length || 0) === 1 ? 'sucursal registrada' : 'sucursales registradas' }}</span>
                        <span class="text-[10px] text-gray-500 dark:text-gray-400">
                            Se gestionan en el paso 1
                        </span>
                    </div>
                    <p class="text-[9px] text-gray-500 dark:text-gray-400 m-0" v-if="getLimitItem('limit_branches')?.monthly_price > 0 && (form.branches?.length || 0) > 0">
                        {{ formatMxn(getLimitItem('limit_branches').monthly_price * (form.branches?.length || 0)) }} al mes ({{ formatMxn(getLimitItem('limit_branches').monthly_price) }} por sucursal)
                    </p>
                </div>
            </div>
        </div>

        <!-- Costo mensual estimado (siempre visible) -->
        <div class="bg-gray-50 dark:bg-[#1a1a1a] p-5 rounded-2xl border border-gray-100 dark:border-[#3a3a3a]">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Costo mensual estimado</p>
                    <p class="text-[10px] text-gray-600 dark:text-gray-300 m-0 mt-0.5">Después de los 30 días de prueba gratis</p>
                </div>
                <div class="text-right">
                    <p class="text-3xl font-light tracking-tight text-gray-900 dark:text-white m-0">{{ formatMxn(monthlyCost) }}</p>
                    <p class="text-[10px] text-gray-500 dark:text-gray-400 m-0">/mes</p>
                </div>
            </div>
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