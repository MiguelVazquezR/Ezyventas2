<script setup>
import { useForm, Link } from '@inertiajs/vue3';
import { watch, computed } from 'vue';
import InputLabel from './InputLabel.vue';
import InputError from './InputError.vue';

const props = defineProps({
    visible: Boolean,
    branch: {
        type: Object,
        default: null
    },
    // --- AÑADIDO: Props para manejar los límites ---
    limit: {
        type: Number,
        default: -1 // -1 significa ilimitado
    },
    usage: {
        type: Number,
        default: 0
    }
});

const emit = defineEmits(['update:visible']);

const isEditMode = computed(() => !!props.branch);

// --- AÑADIDO: Lógica para verificar si se alcanzó el límite ---
const limitReached = computed(() => {
    if (props.limit === -1) return false; // Si es ilimitado, nunca se alcanza
    return props.usage >= props.limit;
});


const form = useForm({
    name: '',
    contact_email: '',
    contact_phone: '',
});

watch(() => props.visible, (newVal) => {
    if (newVal) {
        form.clearErrors();
        if (isEditMode.value) {
            form.name = props.branch.name;
            form.contact_email = props.branch.contact_email;
            form.contact_phone = props.branch.contact_phone;
        } else {
            form.reset();
        }
    }
});

const closeModal = () => {
    emit('update:visible', false);
};

const submit = () => {
    if (isEditMode.value) {
        form.put(route('branches.update', props.branch.id), {
            onSuccess: () => closeModal(),
        });
    } else {
        form.post(route('branches.store'), {
            onSuccess: () => closeModal(),
        });
    }
};
</script>

<template>
    <Dialog :visible="visible" @update:visible="closeModal" modal :header="isEditMode ? 'Editar Sucursal' : 'Nueva Sucursal'" :style="{ width: '30rem' }">
        <!-- AÑADIDO: Mensaje cuando se alcanza el límite -->
        <div v-if="limitReached && !isEditMode" class="p-4 text-center">
             <i class="pi pi-exclamation-triangle !text-5xl text-amber-500 mb-4"></i>
             <h3 class="text-lg font-bold mb-2">Límite de Sucursales Alcanzado</h3>
             <p class="text-gray-600 dark:text-gray-300 mb-6">
                 Has alcanzado el límite de <strong>{{ limit }} sucursales</strong> permitido por tu plan actual.
             </p>
             <a :href="route('subscription.upgrade.show')" target="_blank" rel="noopener noreferrer" class="w-full">
                  <Button label="Mejorar Mi Plan" icon="pi pi-arrow-up" class="w-full" />
             </a>
        </div>
        
        <!-- Formulario original -->
        <form v-else @submit.prevent="submit" class="p-2 space-y-4">
            <div>
                <InputLabel for="branch_name" value="Nombre de la Sucursal *" />
                <InputText id="branch_name" v-model="form.name" class="w-full mt-1" />
                <InputError :message="form.errors.name" class="mt-1" />
            </div>
             <div>
                <InputLabel for="branch_email" value="Email de Contacto *" />
                <InputText id="branch_email" v-model="form.contact_email" class="w-full mt-1" />
                <InputError :message="form.errors.contact_email" class="mt-1" />
            </div>
             <div>
                <InputLabel for="branch_phone" value="Teléfono de Contacto" />
                <InputText id="branch_phone" v-model="form.contact_phone" class="w-full mt-1" />
                <InputError :message="form.errors.contact_phone" class="mt-1" />
            </div>
            <div class="flex justify-end gap-2 mt-4">
                <Button type="button" label="Cancelar" severity="secondary" @click="closeModal" text></Button>
                <Button type="submit" :label="isEditMode ? 'Guardar Cambios' : 'Crear Sucursal'" :loading="form.processing"></Button>
            </div>
        </form>
    </Dialog>
</template>