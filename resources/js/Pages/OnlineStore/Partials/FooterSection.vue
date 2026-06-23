<script setup>
defineProps({
    form: { type: Object, required: true },
    inputPt: { type: Object, required: true },
});
</script>

<template>
    <div id="footer" class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] space-y-4">
        <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-300 m-0 pb-2 border-b border-gray-100 dark:border-[#3a3a3a]">Pie de página</h2>
        <div class="flex flex-col gap-1.5">
            <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Nota de pie de página</label>
            <InputText v-model="form.footer_note" :pt="inputPt" class="w-full" placeholder="© 2026 Mi tienda. Todos los derechos reservados." />
        </div>
    </div>
</template>
