<script setup>
import { ref, computed, watch } from 'vue';
import InputError from '@/Components/InputError.vue';
import SelectVariantModal from '@/Components/SelectVariantModal.vue';
import { useConfirm } from "primevue/useconfirm";

const props = defineProps({
    form: Object,
    products: Array,
    services: Array,
});

const confirm = useConfirm();

const manualSubtotalMode = ref(props.form.id ? false : true); // Edit viene con info, Create por defecto true
const itemTypeOptions = ref([
    { label: 'Refacción', value: 'App\\Models\\Product' },
    { label: 'Servicio', value: 'App\\Models\\Service' },
]);
const discountTypeOptions = ref([
    { label: 'Fijo ($)', value: 'fixed' }, 
    { label: 'Porcentaje (%)', value: 'percentage' }
]);

const recalculateSubtotal = () => {
    let subtotal = 0;
    props.form.items.forEach(item => {
        const lineTotal = (item.quantity || 0) * (item.unit_price || 0);
        item.line_total = lineTotal;
        subtotal += lineTotal;
    });
    props.form.subtotal = subtotal;
};

const toggleSubtotalMode = () => {
    manualSubtotalMode.value = !manualSubtotalMode.value;
    if (!manualSubtotalMode.value) {
        recalculateSubtotal();
    }
};

watch(() => props.form.items, () => {
    if (!manualSubtotalMode.value) recalculateSubtotal();
}, { deep: true });

watch([() => props.form.subtotal, () => props.form.discount_type, () => props.form.discount_value], ([subtotal, discountType, discountValue]) => {
    const sub = subtotal || 0;
    const val = discountValue || 0;
    let discountAmount = 0;

    if (discountType === 'percentage') discountAmount = (sub * val) / 100;
    else discountAmount = val;

    if (discountAmount > sub) {
        discountAmount = sub;
        if (discountType === 'fixed') props.form.discount_value = sub;
        else if (discountType === 'percentage') props.form.discount_value = 100;
    }

    props.form.discount_amount = discountAmount;
    props.form.final_total = sub - discountAmount;
}, { immediate: true });

const showVariantModal = ref(false);
const showServiceVariantModal = ref(false);
const productForVariantSelection = ref(null);
const serviceForVariantSelection = ref(null);
const itemIndexForVariantSelection = ref(null);

const openVariantSelector = (index) => {
    const item = props.form.items[index];
    let product = null;
    let service = null;

    if (item.itemable_type === 'App\\Models\\Product') {
        product = props.products.find(p => p.id === item.itemable_id);
    } else if (item.itemable_type === 'App\\Models\\ProductAttribute') {
        product = props.products.find(p => p.product_attributes?.some(attr => attr.id === item.itemable_id));
    } else if (item.itemable_type === 'App\\Models\\Service') {
        service = props.services.find(s => s.id === item.itemable_id);
    } else if (item.itemable_type === 'App\\Models\\ServiceVariant') {
        service = props.services.find(s => s.variants?.some(v => v.id === item.itemable_id));
    }

    if (product) {
        productForVariantSelection.value = product;
        itemIndexForVariantSelection.value = index;
        showVariantModal.value = true;
    } else if (service) {
        serviceForVariantSelection.value = service;
        itemIndexForVariantSelection.value = index;
        showServiceVariantModal.value = true;
    }
};

const handleVariantSelected = (variant) => {
    if (itemIndexForVariantSelection.value === null || !props.form.items[itemIndexForVariantSelection.value]) return;
    const item = props.form.items[itemIndexForVariantSelection.value];
    const product = productForVariantSelection.value;
    
    item.itemable_id = variant.id;
    item.itemable_type = 'App\\Models\\ProductAttribute';
    item.variant_details = variant.attributes;
    item.description = `${product.name} (${Object.values(variant.attributes).join(', ')})`;
    item.unit_price = (parseFloat(product.selling_price) || 0) + (parseFloat(variant.selling_price_modifier) || 0);

    if (!manualSubtotalMode.value) recalculateSubtotal();
};

const handleServiceVariantSelected = (variant) => {
    if (itemIndexForVariantSelection.value === null || !props.form.items[itemIndexForVariantSelection.value]) return;
    const item = props.form.items[itemIndexForVariantSelection.value];
    const service = serviceForVariantSelection.value;
    
    item.itemable_id = variant.id;
    item.itemable_type = 'App\\Models\\ServiceVariant';
    item.description = `${service.name} - ${variant.name}`;
    item.unit_price = parseFloat(variant.price) || 0;

    if (!manualSubtotalMode.value) recalculateSubtotal();
};

const canSelectVariant = (item) => {
    if (!item.itemable_id) return false;
    if (item.itemable_type === 'App\\Models\\ProductAttribute' || item.itemable_type === 'App\\Models\\ServiceVariant') return true;
    if (item.itemable_type === 'App\\Models\\Product') {
         const p = props.products.find(p => p.id === item.itemable_id);
         return p && p.product_attributes && p.product_attributes.length > 0;
    }
    if (item.itemable_type === 'App\\Models\\Service') {
         const s = props.services.find(s => s.id === item.itemable_id);
         return s && s.variants && s.variants.length > 0;
    }
    return false;
};

const availableItems = computed(() => [
    ...props.products.map(p => ({ ...p, type: 'Producto', price: p.selling_price, itemable_type: 'App\\Models\\Product' })),
    ...props.services.map(s => ({ ...s, type: 'Servicio', price: s.base_price, itemable_type: 'App\\Models\\Service' }))
]);
const selectedItem = ref(null);
const filteredItems = ref([]);

const searchItems = (event) => {
    if (!event.query.trim().length) filteredItems.value = [...availableItems.value];
    else filteredItems.value = availableItems.value.filter((item) => item.name.toLowerCase().includes(event.query.toLowerCase()));
};

const addItem = () => {
    let itemToAdd = {
        itemable_id: null,
        itemable_type: 'App\\Models\\Service',
        description: '',
        quantity: 1,
        unit_price: 0,
        line_total: 0,
    };

    let triggerProductModal = false;
    let triggerServiceModal = false;
    let productForModal = null;
    let serviceForModal = null;

    if (typeof selectedItem.value === 'object' && selectedItem.value !== null) {
        const selected = selectedItem.value;
        itemToAdd = {
            ...itemToAdd,
            itemable_id: selected.id,
            itemable_type: selected.itemable_type,
            description: selected.name,
            unit_price: selected.price
        };

        if (selected.itemable_type === 'App\\Models\\Product' && selected.product_attributes && selected.product_attributes.length > 0) {
            triggerProductModal = true;
            productForModal = selected;
        } else if (selected.itemable_type === 'App\\Models\\Service' && selected.variants && selected.variants.length > 0) {
            triggerServiceModal = true;
            serviceForModal = selected;
        }
    } else if (typeof selectedItem.value === 'string') {
        itemToAdd = { ...itemToAdd, itemable_id: 0, description: selectedItem.value };
    } else { return; }

    props.form.items.push(itemToAdd);
    
    if (triggerProductModal) {
        productForVariantSelection.value = productForModal;
        itemIndexForVariantSelection.value = props.form.items.length - 1;
        showVariantModal.value = true;
    } else if (triggerServiceModal) {
        serviceForVariantSelection.value = serviceForModal;
        itemIndexForVariantSelection.value = props.form.items.length - 1;
        showServiceVariantModal.value = true;
    }

    selectedItem.value = null;
};

const removeItem = (index) => props.form.items.splice(index, 1);
const confirmRemoveItem = (event, index) => {
    confirm.require({
        target: event.currentTarget,
        message: '¿Estás seguro de que quieres eliminar este elemento?',
        group: 'concept-delete',
        icon: 'pi pi-exclamation-triangle',
        acceptLabel: 'Sí',
        rejectLabel: 'No',
        accept: () => removeItem(index)
    });
};

const checkUnitPrice = (index) => {
    setTimeout(() => {
        if (props.form.items[index].unit_price === null || props.form.items[index].unit_price === undefined) {
            props.form.items[index].unit_price = 0;
        }
    }, 0);
};
</script>

<template>
    <div class="bg-white dark:bg-gray-800 p-6 rounded-lg shadow-md">
        <h2 class="text-lg font-semibold border-b pb-3 mb-4">Refacciones y mano de obra</h2>
        
        <div class="flex gap-2 mb-4">
            <AutoComplete v-model="selectedItem" :suggestions="filteredItems" @complete="searchItems"
                field="name" optionLabel="name" placeholder="Busca o escribe un concepto..." class="w-full"
                dropdown>
                <template #option="slotProps">
                    <div>
                        {{ slotProps.option.name }}
                        <Tag :value="slotProps.option.type" :severity="slotProps.option.type === 'Servicio' ? 'success' : 'info'" />
                    </div>
                </template>
            </AutoComplete>
            <Button @click="addItem" icon="pi pi-plus" label="Agregar" :disabled="!selectedItem" />
        </div>

        <DataTable :value="form.items" class="p-datatable-sm">
            <template #empty>
                <div class="text-center p-4">No se han agregado refacciones o servicios.</div>
            </template>
            <Column header="Tipo" style="width: 15rem">
                <template #body="{ data, index }">
                    <SelectButton 
                        :model-value="['App\\Models\\Product', 'App\\Models\\ProductAttribute'].includes(form.items[index].itemable_type) ? 'App\\Models\\Product' : (['App\\Models\\Service', 'App\\Models\\ServiceVariant'].includes(form.items[index].itemable_type) ? 'App\\Models\\Service' : form.items[index].itemable_type)"
                        @update:model-value="(val) => form.items[index].itemable_type = val"
                        :options="itemTypeOptions"
                        optionLabel="label" optionValue="value" :allowEmpty="false"
                        :disabled="data.itemable_id !== 0 && data.itemable_id !== null" class="w-full" />
                    <div v-if="['App\\Models\\Product', 'App\\Models\\ProductAttribute'].includes(form.items[index].itemable_type) && form.items[index].itemable_id && form.items[index].itemable_id !== 0"
                        class="text-xs text-gray-500 dark:text-gray-400 italic mt-1 pl-1">
                        (Se descontarán {{ form.items[index].quantity || 0 }} unidad(es) del stock)
                    </div>
                </template>
            </Column>
            <Column field="description" header="Descripción">
                <template #body="{ data, index }">
                    <InputText v-model="form.items[index].description" fluid class="w-full" />
                    <div v-if="canSelectVariant(data)" class="text-xs text-gray-500 mt-1">
                        <Button 
                            @click="openVariantSelector(index)" 
                            :label="['App\\Models\\ProductAttribute', 'App\\Models\\ServiceVariant'].includes(data.itemable_type) ? 'Cambiar variante' : 'Seleccionar variante'" 
                            text size="small" class="!p-0" 
                        />
                    </div>
                </template>
            </Column>
            <Column field="quantity" header="Cantidad" style="width: 9.5rem"><template #body="{ index }">
                    <InputNumber v-model="form.items[index].quantity" fluid class="w-full" showButtons
                        buttonLayout="horizontal" :step="1" :min="1" />
                </template>
            </Column>
            <Column field="unit_price" header="Precio unit." style="width: 9.5rem"><template #body="{ index }">
                    <InputNumber v-model="form.items[index].unit_price" @blur="checkUnitPrice(index)"
                        mode="currency" currency="MXN" locale="es-MX" fluid class="w-full" />
                </template>
            </Column>
            <Column field="line_total" header="Total">
                <template #body="{ data }">
                    {{ new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(data.line_total) }}
                </template>
            </Column>
            <Column style="width: 4rem">
                <template #body="{ index, event }">
                    <Button @click="confirmRemoveItem($event, index)" icon="pi pi-trash" text rounded size="small" severity="danger" />
                </template>
            </Column>
        </DataTable>
        <InputError :message="form.errors.items" class="mt-2" />

        <!-- Totales -->
        <div class="flex justify-end mt-6">
            <div class="w-full max-w-xl bg-gray-50 dark:bg-gray-700/20 p-4 rounded-lg space-y-3">
                <div class="flex justify-between items-center">
                    <div class="flex items-center gap-2">
                        <Button :icon="manualSubtotalMode ? 'pi pi-lock' : 'pi pi-lock-open'"
                            @click="toggleSubtotalMode" :severity="manualSubtotalMode ? 'secondary' : 'success'"
                            text rounded size="small"
                            v-tooltip.left="manualSubtotalMode ? 'Cambiar a cálculo automático' : 'Cambiar a subtotal manual'" />
                        <label class="font-semibold text-gray-700 dark:text-gray-300">Subtotal</label>
                    </div>
                    <InputNumber v-model="form.subtotal" mode="currency" currency="MXN" locale="es-MX"
                        :disabled="!manualSubtotalMode" inputClass="font-semibold text-right !w-[120px]" />
                </div>
                <div class="flex justify-between items-center">
                    <label class="font-semibold text-gray-700 dark:text-gray-300 pl-10">Descuento</label>
                    <div class="flex items-center gap-2">
                        <SelectButton v-model="form.discount_type" :options="discountTypeOptions"
                            optionLabel="label" optionValue="value" />
                        <InputNumber fluid v-model="form.discount_value" class="max-w-[120px]" :min="0"
                            :max="form.discount_type === 'percentage' ? 100 : form.subtotal"
                            :prefix="form.discount_type === 'fixed' ? '$' : null"
                            :suffix="form.discount_type === 'percentage' ? '%' : null" />
                    </div>
                </div>
                <div v-if="form.discount_amount > 0"
                    class="flex justify-end items-center text-sm text-red-600 dark:text-red-400 pr-1">
                    <span>- {{ new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(form.discount_amount) }}</span>
                </div>
                <Divider class="!my-2" />
                <div class="flex justify-between items-center text-xl font-bold">
                    <span class="text-gray-800 dark:text-gray-200">TOTAL:</span>
                    <span>{{ new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(form.final_total) }}</span>
                </div>
            </div>
        </div>

        <SelectVariantModal 
            v-model:visible="showVariantModal" 
            :item="productForVariantSelection"
            type="product"
            @variant-selected="handleVariantSelected" 
        />
        <SelectVariantModal 
            v-model:visible="showServiceVariantModal" 
            :item="serviceForVariantSelection"
            type="service"
            @variant-selected="handleServiceVariantSelected" 
        />
        <ConfirmPopup group="concept-delete" />
    </div>
</template>