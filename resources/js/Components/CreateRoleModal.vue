<script setup>
import { ref } from 'vue';
import axios from 'axios';
import InputLabel from './InputLabel.vue';
import InputError from './InputError.vue';

const props = defineProps({
    visible: Boolean,
    permissions: Object, // Recibe los permisos agrupados
});

const emit = defineEmits(['update:visible', 'created']);

const form = ref({
    name: '',
    permissions: [], // Para guardar los IDs de los permisos seleccionados
});
const processing = ref(false);
const error = ref('');

const closeModal = () => {
    emit('update:visible', false);
    form.value.name = '';
    form.value.permissions = [];
    error.value = '';
};

// Lógica para seleccionar/deseleccionar todos los permisos de un grupo
const groupPermissions = (group) => {
    return props.permissions[group].map(p => p.id);
};

const isGroupSelected = (group) => {
    const groupIds = groupPermissions(group);
    if (groupIds.length === 0) return false;
    return groupIds.every(id => form.value.permissions.includes(id));
};

const handleGroupSelection = (group) => {
    const groupIds = groupPermissions(group);
    if (isGroupSelected(group)) {
        // Si todos están seleccionados, deseleccionarlos
        form.value.permissions = form.value.permissions.filter(id => !groupIds.includes(id));
    } else {
        // Si no, seleccionar todos los que falten
        const permissionsToAdd = groupIds.filter(id => !form.value.permissions.includes(id));
        form.value.permissions.push(...permissionsToAdd);
    }
};

const submit = async () => {
    if (!form.value.name) return;
    processing.value = true;
    error.value = '';
    try {
        const response = await axios.post(route('quick-create.roles.store'), form.value);
        emit('created', response.data);
        closeModal();
    } catch (err) {
        if (err.response && err.response.status === 422) {
            error.value = err.response.data.errors.name[0];
        } else {
            console.error("Error creating role:", err);
            error.value = 'Ocurrió un error inesperado.';
        }
    } finally {
        processing.value = false;
    }
};
</script>

<template>
    <Dialog :visible="visible" @update:visible="closeModal" modal header="Crear nuevo rol" :style="{ width: '40rem' }">
        <form @submit.prevent="submit" class="p-2 space-y-4">
            <div>
                <InputLabel for="role-name" value="Nombre del Rol *" />
                <InputText id="role-name" v-model="form.name" class="w-full mt-1" :invalid="!!error" />
                <InputError v-if="error" :message="error" class="mt-1" />
            </div>

            <div>
                <InputLabel value="Permisos" class="mb-2" />
                <Accordion>
                    <AccordionPanel :value="groupName" v-for="(group, groupName) in permissions" :key="groupName">
                        <AccordionHeader>{{ groupName.charAt(0).toUpperCase() + groupName.slice(1) }}</AccordionHeader>
                        <AccordionContent>
                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                                <div v-for="permission in group" :key="permission.id" class="flex items-center">
                                    <Checkbox v-model="form.permissions" :inputId="`perm-${permission.id}`"
                                        :value="permission.id" />
                                    <label :for="`perm-${permission.id}`" class="ml-2 text-sm">{{ permission.description
                                        }}</label>
                                </div>
                            </div>
                        </AccordionContent>
                    </AccordionPanel>
                </Accordion>
            </div>

            <div class="flex justify-end gap-2 mt-4">
                <Button type="button" label="Cancelar" severity="secondary" @click="closeModal"></Button>
                <Button type="submit" label="Guardar" :loading="processing"></Button>
            </div>
        </form>
    </Dialog>
</template>