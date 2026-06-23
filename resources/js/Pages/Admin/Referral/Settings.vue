<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    settings: Object,
});

const form = useForm({
    referred_discount_pct: props.settings?.referred_discount_pct || 15,
    referrer_reward_pct: props.settings?.referrer_reward_pct || 50,
    referrer_ongoing_discount_pct: props.settings?.referrer_ongoing_discount_pct || 10,
});

function save() {
    form.put(route('admin.referrals.settings.update'), {
        onSuccess: () => {},
    });
}
</script>

<template>
    <AppLayout title="Configuración de referidos">
        <Head title="Configuración de referidos" />

        <div class="max-w-xl mx-auto py-8 px-4 space-y-6">
            <div>
                <h2 class="text-2xl font-light text-gray-900 dark:text-white tracking-tight m-0">Configuración de referidos</h2>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1 m-0">Ajusta los porcentajes de premios y descuentos del sistema de referidos.</p>
            </div>

            <div class="bg-white dark:bg-[#232323] rounded-3xl border border-gray-100 dark:border-[#3a3a3a] p-6 space-y-5">
                <div class="flex flex-col gap-1.5">
                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Descuento al nuevo suscriptor (%)</label>
                    <InputNumber v-model="form.referred_discount_pct" :min="0" :max="100" suffix="%" class="w-full"
                        :pt="{ input: { root: { class: 'w-full !rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a]' } } }" />
                    <Message v-if="form.errors.referred_discount_pct" severity="error" variant="simple" size="small">{{ form.errors.referred_discount_pct }}</Message>
                </div>

                <div class="flex flex-col gap-1.5">
                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Premio al referidor (% de mensualidad)</label>
                    <InputNumber v-model="form.referrer_reward_pct" :min="0" :max="100" suffix="%" class="w-full"
                        :pt="{ input: { root: { class: 'w-full !rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a]' } } }" />
                    <Message v-if="form.errors.referrer_reward_pct" severity="error" variant="simple" size="small">{{ form.errors.referrer_reward_pct }}</Message>
                </div>

                <div class="flex flex-col gap-1.5">
                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Descuento continuo al referidor (%)</label>
                    <InputNumber v-model="form.referrer_ongoing_discount_pct" :min="0" :max="100" suffix="%" class="w-full"
                        :pt="{ input: { root: { class: 'w-full !rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a]' } } }" />
                    <Message v-if="form.errors.referrer_ongoing_discount_pct" severity="error" variant="simple" size="small">{{ form.errors.referrer_ongoing_discount_pct }}</Message>
                </div>

                <Button label="Guardar configuración" icon="pi pi-save" :loading="form.processing" @click="save" class="!rounded-full w-full" />
            </div>
        </div>
    </AppLayout>
</template>
