<script setup>
import { computed } from 'vue';

const props = defineProps({
    visible: Boolean,
    role: Object, // Espera un objeto de rol con un array 'permissions'
});

const emit = defineEmits(['update:visible']);

const closeModal = () => {
    emit('update:visible', false);
};

// Agrupa los permisos por módulo para una mejor visualización
const permissionsByModule = computed(() => {
    if (!props.role || !props.role.permissions) {
        return {};
    }
    return props.role.permissions.reduce((acc, permission) => {
        const module = permission.module || 'General';
        if (!acc[module]) {
            acc[module] = [];
        }
        acc[module].push(permission);
        return acc;
    }, {});
});
</script>

<template>
    <Dialog :visible="visible" @update:visible="closeModal" modal :header="`Permisos del Rol: ${role?.name || ''}`" :style="{ width: '35rem' }">
        <div v-if="role && role.permissions.length > 0" class="p-2 max-h-[50vh] overflow-y-auto">
             <Accordion :multiple="true" :activeIndex="Array.from(Array(Object.keys(permissionsByModule).length).keys())">
                <AccordionTab v-for="(perms, moduleName) in permissionsByModule" :key="moduleName" :header="moduleName">
                    <ul class="space-y-2 pl-5">
                        <li v-for="permission in perms" :key="permission.id" class="text-sm">
                             <i class="pi pi-check text-green-600 !text-sm mr-3"></i>
                            <span class="font-semibold text-gray-800 dark:text-gray-200">{{ permission.description }}</span>
                        </li>
                    </ul>
                </AccordionTab>
            </Accordion>
        </div>
        <div v-else class="text-center p-8 text-gray-500">
            <i class="pi pi-ban text-4xl mb-3"></i>
            <p>Este rol no tiene permisos asignados.</p>
        </div>
    </Dialog>
</template>