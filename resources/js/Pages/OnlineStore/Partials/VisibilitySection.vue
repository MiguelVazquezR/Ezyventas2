<script setup>
defineProps({
    form: { type: Object, required: true },
    storeUrl: { type: String, default: null },
});
</script>

<template>
    <div id="visibility" class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] space-y-4">
        <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-300 m-0 pb-2 border-b border-gray-100 dark:border-[#3a3a3a]">Visibilidad</h2>
        <div class="flex flex-col sm:flex-row sm:items-center gap-4 p-4 bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl">
            <div class="flex-1">
                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Estado de la tienda</label>
                <p class="text-xs text-gray-400 m-0 mt-1">Activa para que tu tienda sea visible al público.</p>
            </div>
            <ToggleSwitch v-model="form.is_active" />
        </div>
        <div v-if="storeUrl" class="p-4 bg-green-50 dark:bg-green-900/10 rounded-2xl border border-green-100 dark:border-green-900/30 flex items-center gap-3">
            <i class="pi pi-link text-green-600" />
            <span class="text-sm text-green-800 dark:text-green-300 break-all flex-1">{{ storeUrl }}</span>
            <Button as="a" :href="storeUrl" severity="success" target="_blank" label="Ver mi tienda" icon="pi pi-external-link" size="small" class="!rounded-full !shrink-0" />
        </div>
    </div>
</template>
