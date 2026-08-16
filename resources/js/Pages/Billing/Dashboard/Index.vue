<script setup>
import { ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    fiscalProfiles: { type: Array, default: () => [] },
    draftInvoices: { type: Number, default: 0 },
    certifiedInvoices: { type: Number, default: 0 },
    cancelationPendingInvoices: { type: Number, default: 0 },
    canceledInvoices: { type: Number, default: 0 },
});

// --- HELPERS ---
const formatNumber = (value) =>
    new Intl.NumberFormat('es-MX').format(value || 0);
</script>

<template>
    <Head title="Facturación — Resumen" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8 max-w-[1600px] mx-auto space-y-6">

            <!-- ════════════════════════════════════════
                 Header
                 ════════════════════════════════════════ -->
            <div class="mb-6">
                <h1 class="text-4xl md:text-5xl font-light tracking-tight text-gray-900 dark:text-white m-0">
                    Facturación
                </h1>
                <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 mt-2 m-0 flex items-center gap-2">
                    <span class="w-1.5 h-1.5 rounded-full bg-primary-500 shadow-[0_0_8px_rgba(99,102,241,0.8)] animate-pulse"></span>
                    Resumen general de todos tus emisores fiscales
                </p>
            </div>

            <!-- KPI Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <!-- Pre-facturas -->
                <div class="bg-white dark:bg-[#232323] p-6 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col gap-3">
                    <div class="flex items-center gap-2">
                        <div class="w-9 h-9 rounded-full bg-purple-50 dark:bg-purple-900/20 flex items-center justify-center flex-shrink-0 border border-purple-100 dark:border-purple-900/30">
                            <i class="pi pi-file !text-sm text-purple-500"></i>
                        </div>
                        <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500">Pre-facturas</span>
                    </div>
                    <span class="text-4xl font-light tracking-tight text-gray-900 dark:text-white">{{ formatNumber(draftInvoices) }}</span>
                    <span class="text-xs text-gray-400">Pendientes de timbrar</span>
                </div>

                <!-- Facturas timbradas -->
                <div class="bg-white dark:bg-[#232323] p-6 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col gap-3">
                    <div class="flex items-center gap-2">
                        <div class="w-9 h-9 rounded-full bg-emerald-50 dark:bg-emerald-900/20 flex items-center justify-center flex-shrink-0 border border-emerald-100 dark:border-emerald-900/30">
                            <i class="pi pi-check-circle !text-sm text-emerald-500"></i>
                        </div>
                        <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500">Facturas timbradas</span>
                    </div>
                    <span class="text-4xl font-light tracking-tight text-gray-900 dark:text-white">{{ formatNumber(certifiedInvoices) }}</span>
                    <span class="text-xs text-gray-400">CFDI certificados</span>
                </div>

                <!-- Pendientes de cancelación -->
                <div class="bg-white dark:bg-[#232323] p-6 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col gap-3">
                    <div class="flex items-center gap-2">
                        <div class="w-9 h-9 rounded-full bg-amber-50 dark:bg-amber-900/20 flex items-center justify-center flex-shrink-0 border border-amber-100 dark:border-amber-900/30">
                            <i class="pi pi-clock !text-sm text-amber-500"></i>
                        </div>
                        <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500">Pendientes de cancelación</span>
                    </div>
                    <span class="text-4xl font-light tracking-tight text-gray-900 dark:text-white">{{ formatNumber(cancelationPendingInvoices) }}</span>
                    <span class="text-xs text-gray-400">En proceso de cancelación</span>
                </div>

                <!-- Facturas canceladas -->
                <div class="bg-white dark:bg-[#232323] p-6 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col gap-3">
                    <div class="flex items-center gap-2">
                        <div class="w-9 h-9 rounded-full bg-red-50 dark:bg-red-900/20 flex items-center justify-center flex-shrink-0 border border-red-100 dark:border-red-900/30">
                            <i class="pi pi-times-circle !text-sm text-red-500"></i>
                        </div>
                        <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500">Facturas canceladas</span>
                    </div>
                    <span class="text-4xl font-light tracking-tight text-gray-900 dark:text-white">{{ formatNumber(canceledInvoices) }}</span>
                    <span class="text-xs text-gray-400">CFDI cancelados</span>
                </div>
            </div>

            <!-- Per-Fiscal-Profile Cards -->
            <div v-if="fiscalProfiles.length > 0" class="space-y-6 pt-6 lg:pt-8">
                <h2 class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Emisores fiscales</h2>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                    <div
                        v-for="profile in fiscalProfiles"
                        :key="profile.id"
                        class="bg-white dark:bg-[#232323] p-6 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] space-y-4"
                    >
                        <!-- Profile header -->
                        <div class="flex items-start justify-between">
                            <div>
                                <Link
                                    :href="route('billing.fiscal-profiles.show', profile.id)"
                                    class="text-sm font-medium text-gray-900 dark:text-white hover:text-primary-500 transition-colors no-underline"
                                >
                                    {{ profile.razon_social }}
                                </Link>
                                <p class="text-xs text-gray-400 m-0">RFC: {{ profile.rfc }}</p>
                            </div>
                        </div>

                        <!-- Live stamp balance -->
                        <div v-if="profile.balanceError" class="text-xs text-red-500">
                            {{ profile.balanceError }}
                        </div>
                        <div v-else-if="profile.balance" class="grid grid-cols-3 gap-3 p-3 rounded-2xl bg-gray-50 dark:bg-[#1a1a1a]">
                            <div class="text-center">
                                <p class="text-[9px] uppercase tracking-wider text-gray-400 m-0">Timbres adquiridos</p>
                                <p class="text-xl font-light text-gray-900 dark:text-white m-0">{{ profile.balance.stampsAssigned ?? '—' }}</p>
                            </div>
                            <div class="text-center">
                                <p class="text-[9px] uppercase tracking-wider text-gray-400 m-0">Timbres usados</p>
                                <p class="text-xl font-light text-gray-900 dark:text-white m-0">{{ profile.balance.stampsUsed ?? '—' }}</p>
                            </div>
                            <div class="text-center">
                                <p class="text-[9px] uppercase tracking-wider text-gray-400 m-0">Timbres disponibles</p>
                                <p class="text-xl font-light text-gray-900 dark:text-white m-0">{{ profile.balance.stampsBalance ?? '—' }}</p>
                            </div>
                        </div>

                        <!-- Invoice KPIs -->
                        <div class="grid grid-cols-4 gap-3">
                            <div class="text-center">
                                <p class="text-[9px] uppercase tracking-wider text-gray-400 m-0">Pre-facturas</p>
                                <p class="text-lg font-light text-purple-600 dark:text-purple-400 m-0">{{ formatNumber(profile.draftCount) }}</p>
                            </div>
                            <div class="text-center">
                                <p class="text-[9px] uppercase tracking-wider text-gray-400 m-0">Timbradas</p>
                                <p class="text-lg font-light text-emerald-600 dark:text-emerald-400 m-0">{{ formatNumber(profile.certifiedCount) }}</p>
                            </div>
                            <div class="text-center">
                                <p class="text-[9px] uppercase tracking-wider text-gray-400 m-0">Pend. cancelar</p>
                                <p class="text-lg font-light text-amber-500 m-0">{{ formatNumber(profile.cancelationPendingCount) }}</p>
                            </div>
                            <div class="text-center">
                                <p class="text-[9px] uppercase tracking-wider text-gray-400 m-0">Canceladas</p>
                                <p class="text-lg font-light text-red-500 m-0">{{ formatNumber(profile.canceledCount) }}</p>
                            </div>
                        </div>

                        <!-- Action link -->
                        <Link
                            :href="route('billing.fiscal-profiles.show', profile.id)"
                            class="inline-flex items-center gap-1.5 text-[10px] uppercase tracking-widest font-bold text-gray-500 hover:text-primary-500 transition-colors no-underline"
                        >
                            <i class="pi pi-arrow-right !text-[10px]"></i>
                            Ver detalle
                        </Link>
                    </div>
                </div>
            </div>

        </div>
    </AppLayout>
</template>
