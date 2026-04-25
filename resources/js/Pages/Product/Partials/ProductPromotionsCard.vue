<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { useConfirm } from "primevue/useconfirm";

const props = defineProps({
    promotions: Array,
    hasManagePromosPermission: Boolean
});

const confirm = useConfirm();
const localPromotions = ref([...props.promotions]);
const promoMenus = ref({});

const formatCurrency = (value) => {
    if (value === null || value === undefined) return 'N/A';
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);
};

const formatDateOnly = (dateString) => {
    if (!dateString) return 'N/A';
    try {
        return new Date(dateString).toLocaleDateString('es-MX', { dateStyle: 'medium' });
    } catch (e) {
        return dateString;
    }
};

const togglePromotionStatus = (promo) => {
    router.patch(route('promotions.update', promo.id), {}, {
        preserveScroll: true,
        onSuccess: () => {
            const updatedPromo = localPromotions.value.find(p => p.id === promo.id);
            if (updatedPromo) {
                updatedPromo.is_active = !updatedPromo.is_active;
            }
        }
    });
};

const deletePromotion = (promo) => {
    confirm.require({
        message: `¿Estás seguro de que quieres eliminar la promoción "${promo.name}"? Esta acción no se puede deshacer.`,
        header: 'Confirmar eliminación',
        icon: 'pi pi-exclamation-triangle',
        acceptClass: 'p-button-danger',
        acceptLabel: 'Sí, eliminar',
        rejectLabel: 'Cancelar',
        accept: () => {
            router.delete(route('promotions.destroy', promo.id), {
                preserveScroll: true,
                onSuccess: () => {
                    localPromotions.value = localPromotions.value.filter(p => p.id !== promo.id);
                }
            });
        }
    });
};

const getPromotionSummary = (promo) => {
    const effect = promo.effects?.[0];
    const rule = promo.rules?.[0];

    switch (promo.type) {
        case 'ITEM_DISCOUNT': {
            if (!effect) return 'Descuento especial aplicado.';
            if (effect.type === 'PERCENTAGE_DISCOUNT') return `Aplica un ${effect.value}% de descuento.`;
            if (effect.type === 'FIXED_DISCOUNT') return `Aplica un descuento de ${formatCurrency(effect.value)}.`;
            return 'Descuento especial aplicado.';
        }
        case 'BOGO': {
            if (!rule || !effect) return 'Promoción especial de regalo.';
            const buyItem = rule.itemable?.name || 'producto';
            const freeItem = effect.itemable?.name || 'producto';
            return `Compra ${rule.value} de "${buyItem}" y llévate ${effect.value} de "${freeItem}" gratis.`;
        }
        case 'BUNDLE_PRICE': {
            if (!effect || !promo.rules) return 'Paquete con precio especial.';
            const productDetails = promo.rules.map(r => `${r.value} x ${r.itemable?.name || 'producto'}`).join(' + ');
            return `Paquete (${productDetails}) por ${formatCurrency(effect.value)}.`;
        }
        default:
            return promo.description || 'Promoción especial.';
    }
};
</script>

<template>
    <div class="bg-white dark:bg-gray-800 p-6 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-700/60">
        <h3 class="text-sm font-bold text-gray-800 dark:text-gray-200 mb-4 flex items-center gap-2">
            <i class="pi pi-percentage text-yellow-500"></i> Promociones vinculadas
        </h3>
        
        <div v-if="localPromotions && localPromotions.length > 0" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <div v-for="promo in localPromotions" :key="promo.id" 
                class="relative bg-white dark:bg-gray-900/30 rounded-xl border shadow-sm transition-all flex flex-col justify-between"
                :class="promo.is_active ? 'border-l-4 border-l-yellow-400 border-gray-200 dark:border-gray-700' : 'border-l-4 border-l-gray-300 border-gray-200 dark:border-gray-700 opacity-70'">
                
                <div class="p-4">
                    <div class="flex justify-between items-start mb-2">
                        <h5 class="font-bold text-gray-800 dark:text-gray-100 line-clamp-1 pr-2" :title="promo.name">{{ promo.name }}</h5>
                        <!-- Menú de opciones (3 puntos) -->
                        <Button v-if="hasManagePromosPermission" icon="pi pi-ellipsis-v" text rounded size="small" class="!w-6 !h-6 !text-gray-400" @click="promoMenus[promo.id].toggle($event)" />
                        <Menu :ref="el => { if (el) promoMenus[promo.id] = el }" :model="[
                            { label: promo.is_active ? 'Inactivar' : 'Reactivar', icon: promo.is_active ? 'pi pi-power-off' : 'pi pi-check', command: () => togglePromotionStatus(promo) },
                            { label: 'Eliminar', icon: 'pi pi-trash', class: 'text-red-500', command: () => deletePromotion(promo) }
                        ]" :popup="true" />
                    </div>
                    
                    <p class="text-sm text-gray-600 dark:text-gray-400 leading-snug mb-4 h-10 line-clamp-2" :title="getPromotionSummary(promo)">
                        {{ getPromotionSummary(promo) }}
                    </p>
                    
                    <div class="flex justify-between items-end mt-auto">
                        <div class="text-[10px] text-gray-500 uppercase font-semibold">
                            <div v-if="promo.start_date || promo.end_date">
                                Vence: <span class="text-gray-700 dark:text-gray-300">{{ formatDateOnly(promo.end_date) || 'Sin fecha' }}</span>
                            </div>
                        </div>
                        <Tag :value="promo.is_active ? 'Activa' : 'Inactiva'" :severity="promo.is_active ? 'success' : 'secondary'" class="!text-[10px]"></Tag>
                    </div>
                </div>
            </div>
        </div>
        <div v-else class="text-center text-gray-400 dark:text-gray-500 py-6 border border-dashed border-gray-200 dark:border-gray-700 rounded-xl">
            <span class="text-sm">No hay promociones activas para este producto.</span>
        </div>
    </div>
</template>