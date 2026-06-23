<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import { useConfirm } from 'primevue/useconfirm';

const props = defineProps({
    form: Object,
});

const confirm = useConfirm();

const hasOnlineStore = computed(() => usePage().props.auth.active_modules?.includes('module_online_store'));

// --- LÓGICA DE UTILIDAD (PROFIT MARGIN) ---
const profitData = computed(() => {
    const cost = Number(props.form.cost_price);
    const sell = Number(props.form.selling_price);

    if (cost > 0 && sell > 0) {
        const profitAmount = sell - cost;
        // Calculamos el margen de rendimiento sobre el costo (Markup)
        const marginPercentage = (profitAmount / cost) * 100;
        const isLoss = profitAmount < 0;

        return {
            amount: profitAmount,
            percentage: marginPercentage,
            isLoss
        };
    }
    return null;
});

// Función para calcular la utilidad de los precios de mayoreo
const getTierProfit = (tierPrice) => {
    const cost = Number(props.form.cost_price);
    const price = Number(tierPrice);
    if (cost > 0 && price > 0) {
        const profit = price - cost;
        const percentage = (profit / cost) * 100;
        return { percentage: percentage.toFixed(1), isLoss: profit < 0 };
    }
    return null;
};

// --- LÓGICA DE PRECIOS DE MAYOREO ---
const addPriceTier = () => {
    if (!props.form.price_tiers) {
        props.form.price_tiers = [];
    }
    props.form.price_tiers.push({ min_quantity: 2, price: null });
};

const confirmRemovePriceTier = (event, index) => {
    confirm.require({
        target: event.currentTarget,
        message: '¿Estás seguro de que quieres eliminar este nivel de precio?',
        group: 'price-tiers-delete',
        icon: 'pi pi-exclamation-triangle',
        acceptClass: 'p-button-danger',
        acceptLabel: 'Sí, eliminar',
        rejectLabel: 'No',
        accept: () => {
            props.form.price_tiers.splice(index, 1);
        }
    });
};
</script>

<template>
    <div id="pricing" class="bg-white dark:bg-[#232323] p-6 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] scroll-mt-24">
        <h2 class="text-lg font-semibold mb-6 text-gray-900 dark:text-white m-0">
            Precios y visibilidad
        </h2>

        <!-- POS visibility toggle -->
        <div class="mb-6 bg-blue-50 dark:bg-blue-900/10 p-4 rounded-2xl border border-blue-100 dark:border-blue-900/30 flex items-center justify-between">
            <div>
                <h3 class="font-bold text-blue-800 dark:text-blue-300 m-0 text-sm">¿Mostrar en punto de venta?</h3>
                <p class="text-xs text-blue-600 dark:text-blue-400 mt-1 mb-0">
                    Si desactivas esta opción, este artículo será tratado como un <strong>insumo interno</strong>.
                    Podrás controlar su stock, pero no aparecerá en la pantalla de caja para venderse.
                </p>
            </div>
            <div class="ml-4 flex-shrink-0 flex items-center">
                <ToggleSwitch v-model="form.show_in_pos" />
            </div>
        </div>

        <!-- Online store section (only when subscription has module_online_store) -->
        <template v-if="hasOnlineStore">
            <div class="mb-6 bg-emerald-50 dark:bg-emerald-900/10 p-4 rounded-2xl border border-emerald-100 dark:border-emerald-900/30 flex items-center justify-between">
                <div>
                    <h3 class="font-bold text-emerald-800 dark:text-emerald-300 m-0 text-sm flex items-center gap-2">
                        <i class="pi pi-globe !text-sm" />
                        ¿Mostrar en tienda en línea?
                    </h3>
                    <p class="text-xs text-emerald-600 dark:text-emerald-400 mt-1 mb-0">
                        Activa esta opción para que el producto aparezca en tu tienda en línea. Puedes establecer un precio diferente al de venta en POS.
                    </p>
                </div>
                <div class="ml-4 flex-shrink-0 flex items-center">
                    <ToggleSwitch v-model="form.show_online" />
                </div>
            </div>

            <!-- Online price and featured (only when show_online is enabled) -->
            <div v-if="form.show_online" class="mb-6 bg-emerald-50/50 dark:bg-emerald-900/5 p-4 rounded-2xl border border-emerald-100 dark:border-emerald-900/30">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="flex flex-col gap-1.5">
                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Precio en línea</label>
                        <InputNumber v-model="form.online_price" mode="currency" currency="MXN" locale="es-MX" class="w-full" placeholder="Igual que precio de venta" :pt="{ input: { root: { class: 'w-full !rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a] !text-sm' } } }" />
                        <p class="text-[11px] text-gray-400 m-0">Deja en blanco para usar el precio de venta normal.</p>
                        <InputError :message="form.errors.online_price" class="mt-1" />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Destacar en tienda</label>
                        <div class="flex items-center gap-3 mt-2">
                            <ToggleSwitch v-model="form.is_featured" />
                            <span class="text-xs text-gray-500 dark:text-gray-400">Aparecerá en la sección de destacados de tu tienda.</span>
                        </div>
                    </div>
                </div>
            </div>
        </template>

        <!-- GUÍA CONTEXTUAL PARA VENTA A GRANEL -->
        <div v-if="form.product_type === 'bulk'"
            class="mb-6 bg-orange-50 dark:bg-orange-900/10 p-4 rounded-2xl border border-orange-100 dark:border-orange-900/30">
            <h3 class="font-bold text-orange-800 dark:text-orange-300 m-0 text-sm flex items-center gap-2">
                <i class="pi pi-info-circle"></i> Guía para precio a granel
            </h3>
            <p class="text-xs text-orange-700 dark:text-orange-300 mt-2 mb-0 leading-relaxed">
                Ingresa el costo y el precio de venta que equivalga a <strong>1 {{ form.measure_unit || 'unidad entera'
                }} completa</strong>. <br>
                El sistema calculará automáticamente el cobro correcto cuando vendas fracciones (Ej. Si vendes 0.250 {{
                    form.measure_unit || '' }}, cobrará una cuarta parte del precio que ingreses aquí).
            </p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-4">
            <!-- Precios Base -->
            <div>
                <InputLabel for="cost_price" value="Precio de costo (Opcional)" />
                <InputNumber v-model="form.cost_price" id="cost_price" mode="currency" currency="MXN" locale="es-MX"
                    class="w-full mt-1" placeholder="$0.00" />
                <InputError :message="form.errors.cost_price" class="mt-2" />
            </div>

            <div>
                <InputLabel for="selling_price" value="Precio de venta *" />
                <InputNumber v-model="form.selling_price" id="selling_price" mode="currency" currency="MXN"
                    locale="es-MX" class="w-full mt-1" placeholder="$0.00" />
                <InputError :message="form.errors.selling_price" class="mt-2" />
            </div>

            <!-- INDICADOR DE UTILIDAD DINÁMICO -->
            <div v-if="profitData" class="col-span-full">
                <div :class="[
                    'p-3 rounded-xl border flex items-center gap-3 transition-colors',
                    profitData.isLoss
                        ? 'bg-red-50 border-red-200 text-red-700 dark:bg-red-900/20 dark:border-red-800/50 dark:text-red-300'
                        : 'bg-green-50 border-green-200 text-green-700 dark:bg-green-900/20 dark:border-green-800/50 dark:text-green-300'
                ]">
                    <div
                        :class="['flex items-center justify-center w-8 h-8 rounded-full shrink-0', profitData.isLoss ? 'bg-red-100 dark:bg-red-900/50' : 'bg-green-100 dark:bg-green-900/50']">
                        <i
                            :class="[profitData.isLoss ? 'pi pi-arrow-down' : 'pi pi-arrow-up', 'text-sm font-bold']"></i>
                    </div>
                    <div class="flex flex-col">
                        <span class="text-sm font-semibold">{{ profitData.isLoss ? 'Pérdida' : 'Utilidad' }} estimada
                            por unidad: {{ new Intl.NumberFormat('es-MX', {
                                style: 'currency', currency: 'MXN'
                            }).format(profitData.amount) }}</span>
                        <span class="text-xs opacity-85 mt-0.5">Rendimiento del <strong>{{
                            profitData.percentage.toFixed(2) }}%</strong> sobre tu costo de inversión.</span>
                    </div>
                </div>
            </div>

            <!-- Precios de Mayoreo (Price Tiers) -->
            <div class="col-span-full mt-2 pt-6 border-t border-gray-100 dark:border-[#3a3a3a]">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between mb-4 gap-2">
                    <div>
                        <InputLabel value="Precios de mayoreo (Opcional)"
                            class="!font-bold text-gray-900 dark:text-white" />
                        <p class="text-sm text-gray-500 mt-1">
                            Ejemplo: Si compran 5 o más, el precio baja a $90. Si compran 10 o más, baja a $80.
                        </p>
                    </div>
                    <Button @click="addPriceTier" label="Añadir nivel" icon="pi pi-plus" size="small" outlined class="!rounded-xl" />
                </div>

                <div v-if="!form.price_tiers || form.price_tiers.length === 0"
                    class="text-sm text-gray-500 italic bg-gray-50 dark:bg-[#1a1a1a] p-4 rounded-2xl text-center border border-dashed border-gray-200 dark:border-[#3a3a3a]">
                    No has configurado precios especiales por volumen para este producto.
                </div>

                <div v-else class="space-y-3">
                    <div v-for="(tier, index) in form.price_tiers" :key="index"
                        class="flex flex-col sm:flex-row items-start sm:items-center gap-4 bg-gray-50 dark:bg-[#1a1a1a] p-3 rounded-2xl border border-gray-100 dark:border-[#3a3a3a] transition-all hover:border-gray-300 dark:hover:border-gray-500">
                        <div class="flex-1 w-full">
                            <!-- Dinámico si es a granel -->
                            <InputLabel
                                :value="form.product_type === 'bulk' ? 'A partir de (' + (form.measure_unit || 'cantidad') + ')' : 'A partir de (cantidad)'"
                                class="text-xs mb-1 font-semibold" />
                            <InputNumber v-model="tier.min_quantity" :min="form.product_type === 'bulk' ? 0.01 : 2"
                                :maxFractionDigits="form.product_type === 'bulk' ? 3 : 0" class="w-full" showButtons />
                            <InputError :message="form.errors[`price_tiers.${index}.min_quantity`]" class="mt-1" />
                        </div>
                        <div class="flex-1 w-full relative">
                            <InputLabel :value="`Precio unitario`" class="text-xs mb-1 font-semibold" />
                            <InputNumber v-model="tier.price" mode="currency" currency="MXN" locale="es-MX"
                                class="w-full" />
                            <InputError :message="form.errors[`price_tiers.${index}.price`]" class="mt-1" />
                        </div>
                        <!-- Indicador de utilidad individual por nivel -->
                        <div v-if="getTierProfit(tier.price)"
                            :class="['text-[11px] mt-1.5 font-medium flex justify-end items-center gap-1', getTierProfit(tier.price).isLoss ? 'text-red-500' : 'text-green-600 dark:text-green-400']">
                            <i
                                :class="getTierProfit(tier.price).isLoss ? 'pi pi-arrow-down !text-[9px]' : 'pi pi-arrow-up !text-[9px]'"></i>
                            Margen: {{ getTierProfit(tier.price).percentage }}%
                        </div>
                        <div class="pt-5 flex justify-end w-full sm:w-auto">
                            <Button icon="pi pi-trash" severity="danger" text rounded
                                @click="confirmRemovePriceTier($event, index)" v-tooltip.top="'Eliminar nivel'" />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>