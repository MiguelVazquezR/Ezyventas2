<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

const page = usePage();

const referralNotifications = computed(() => page.props.referralNotifications);

const hasNoReferralCode = computed(() => {
    return referralNotifications.value && !referralNotifications.value.has_referral_code;
});

const referralBadgeCount = computed(() => {
    if (!referralNotifications.value) return 0;
    return (referralNotifications.value.pending_rewards_count ?? 0) +
           (referralNotifications.value.unseen_referrals_count ?? 0);
});
</script>

<template>
    <button type="button" class="relative flex items-center justify-center w-10 h-10 rounded-full hover:bg-gray-100 dark:hover:bg-[#1a1a1a] transition-colors" v-tooltip.bottom="'Mis referidos'" @click="$inertia.visit(route('referrals.index'))">
        <!-- Anillo de pulso cuando no tiene código o hay notificaciones -->
        <span v-if="hasNoReferralCode || referralBadgeCount > 0" class="absolute inset-0 rounded-full animate-ping z-0" :class="hasNoReferralCode ? 'bg-violet-500/30' : 'bg-amber-500/20'"></span>
        <i class="pi pi-users !text-xl relative z-10 transition-colors"
            :class="{
                '!text-violet-400': hasNoReferralCode,
                '!text-amber-500': referralBadgeCount > 0 && !hasNoReferralCode,
                'text-gray-400': !hasNoReferralCode && referralBadgeCount === 0
            }">
        </i>
    </button>
</template>
