<script setup>
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    usages: Object,
});

const rewardLabel = (status) => {
    const map = { pending: 'Pendiente', paid: 'Pagado', cancelled: 'Cancelado' };
    return map[status] || status;
};

const rewardSeverity = (status) => {
    const map = { pending: 'warn', paid: 'success', cancelled: 'danger' };
    return map[status] || 'info';
};

function markPaid(usage) {
    if (confirm('¿Confirmar que este premio ha sido pagado?')) {
        useForm({}).post(route('admin.referrals.pay', usage.id));
    }
}
</script>

<template>
    <AppLayout title="Referidos">
        <div class="max-w-6xl mx-auto py-8 px-4 space-y-6">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-2xl font-light text-gray-900 dark:text-white tracking-tight m-0">Gestión de referidos</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1 m-0">Premios pendientes e historial de referidos.</p>
                </div>
                <Button
                    label="Configuración"
                    icon="pi pi-cog"
                    severity="secondary"
                    class="!rounded-full"
                    @click="$inertia.visit(route('admin.referrals.settings'))"
                />
            </div>

            <DataTable :value="usages.data" paginator :rows="20" :totalRecords="usages.total" stripedRows class="!rounded-2xl overflow-hidden"
                :pt="{
                    root: { class: '!border-gray-100 dark:!border-[#3a3a3a]' },
                    headerCell: { class: '!bg-gray-50 dark:!bg-[#1a1a1a] !text-[10px] !uppercase !tracking-widest !font-bold !text-gray-500 !py-3 !px-4' },
                    bodyCell: { class: '!py-3 !px-4 !text-sm' }
                }">
                <Column header="Referidor">
                    <template #body="{ data }">
                        {{ data.referral_code?.user?.name || '—' }}
                    </template>
                </Column>
                <Column header="Referido">
                    <template #body="{ data }">
                        {{ data.referred_subscription?.commercial_name || '—' }}
                    </template>
                </Column>
                <Column header="Fecha">
                    <template #body="{ data }">
                        {{ new Date(data.created_at).toLocaleDateString('es-MX') }}
                    </template>
                </Column>
                <Column header="Monto pagado">
                    <template #body="{ data }">
                        ${{ parseFloat(data.payment?.amount || 0).toFixed(2) }}
                    </template>
                </Column>
                <Column header="Premio">
                    <template #body="{ data }">
                        ${{ parseFloat(data.reward_amount).toFixed(2) }}
                    </template>
                </Column>
                <Column header="Estado">
                    <template #body="{ data }">
                        <Tag :value="rewardLabel(data.reward_status)" :severity="rewardSeverity(data.reward_status)" rounded />
                    </template>
                </Column>
                <Column header="Acción">
                    <template #body="{ data }">
                        <Button
                            v-if="data.reward_status === 'pending'"
                            icon="pi pi-check"
                            label="Marcar pagado"
                            size="small"
                            severity="success"
                            class="!rounded-full"
                            @click="markPaid(data)"
                        />
                        <span v-else class="text-xs text-gray-400">
                            {{ data.reward_paid_at ? new Date(data.reward_paid_at).toLocaleDateString('es-MX') : '—' }}
                        </span>
                    </template>
                </Column>
            </DataTable>
        </div>
    </AppLayout>
</template>
