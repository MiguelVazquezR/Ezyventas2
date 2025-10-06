<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { useConfirm } from 'primevue/useconfirm';
import AppLayout from '@/Layouts/AppLayout.vue';
import { usePermissions } from '@/Composables';
import { computed } from 'vue';

const props = defineProps({
    templates: Array,
    // --- AÑADIDO: Props para manejar los límites ---
    templateLimit: Number,
    templateUsage: Number,
});

// --- AÑADIDO: Lógica para verificar si se alcanzó el límite ---
const limitReached = computed(() => {
    if (props.templateLimit === -1) return false;
    return props.templateUsage >= props.templateLimit;
});

const confirm = useConfirm();
const { hasPermission } = usePermissions();

const confirmDelete = (template) => {
    confirm.require({
        message: `¿Estás seguro de que quieres eliminar la plantilla "${template.name}"?`,
        header: 'Confirmar Eliminación',
        icon: 'pi pi-exclamation-triangle',
        accept: () => {
            router.delete(route('print-templates.destroy', template.id), { preserveScroll: true });
        }
    });
};

const newTemplateOptions = [
    {
        label: 'Nuevo Ticket de Venta',
        icon: 'pi pi-receipt',
        command: () => router.get(route('print-templates.create', { type: 'ticket_venta' }))
    },
    {
        label: 'Nueva Etiqueta',
        icon: 'pi pi-tags',
        command: () => router.get(route('print-templates.create', { type: 'etiqueta' }))
    }
];
</script>

<template>
    <Head title="Plantillas Personalizadas" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8">
            <header class="mb-6 flex justify-between items-center">
                <div>
                    <h1 class="text-3xl font-bold">Plantillas personalizadas</h1>
                    <p class="text-gray-500 mt-1">Gestiona las plantillas para tickets, etiquetas y más.</p>
                </div>
                <!-- MODIFICADO: Se envuelve en un div para el tooltip y se deshabilita el botón -->
                <div v-tooltip.bottom="limitReached ? `Límite de ${templateLimit} plantillas alcanzado` : 'Crear nueva plantilla'">
                    <SplitButton v-if="hasPermission('settings.templates.create')" label="Nueva plantilla" icon="pi pi-plus"
                        :model="newTemplateOptions" @click="newTemplateOptions[0].command" :disabled="limitReached"/>
                </div>
            </header>

            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-4 md:p-6">
                <DataTable :value="templates" responsiveLayout="scroll">
                    <Column field="name" header="Nombre"></Column>
                    <Column field="type" header="Tipo">
                        <template #body="{ data }">
                            <span class="capitalize">{{ data.type.replace('_', ' ') }}</span>
                        </template>
                    </Column>
                    <Column header="Sucursales asignadas">
                        <template #body="{ data }">
                            <div class="flex flex-wrap gap-1">
                                <Tag v-for="branch in data.branches" :key="branch.id" :value="branch.name" />
                                <Tag v-if="data.branches.length === 0" value="Ninguna" severity="warning" />
                            </div>
                        </template>
                    </Column>
                    <Column>
                        <template #body="{ data }">
                            <div class="flex justify-end gap-2">
                                <Link :href="route('print-templates.edit', data.id)">
                                <Button v-if="hasPermission('settings.templates.edit')" icon="pi pi-pencil" text
                                    rounded />
                                </Link>
                                <Button v-if="hasPermission('settings.templates.delete')" @click="confirmDelete(data)"
                                    icon="pi pi-trash" text rounded severity="danger" />
                            </div>
                        </template>
                    </Column>
                    <template #empty>
                        <div class="text-center text-gray-500 py-4">
                            No has registrado ninguna plantilla.
                        </div>
                    </template>
                </DataTable>
            </div>
        </div>
    </AppLayout>
</template>