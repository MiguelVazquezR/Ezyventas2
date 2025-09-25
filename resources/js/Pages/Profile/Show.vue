<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import LogoutOtherBrowserSessionsForm from '@/Pages/Profile/Partials/LogoutOtherBrowserSessionsForm.vue';
import UpdatePasswordForm from '@/Pages/Profile/Partials/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from '@/Pages/Profile/Partials/UpdateProfileInformationForm.vue';

defineProps({
    confirmsTwoFactorAuthentication: Boolean,
    sessions: Array,
});
</script>

<template>
    <AppLayout title="Perfil de Usuario">
        <div class="p-4 md:p-6 lg:p-8">
            <header class="mb-6">
                <h1 class="text-3xl font-bold text-gray-800 dark:text-gray-200">Mi Perfil</h1>
                <p class="text-gray-500 dark:text-gray-400 mt-1">Gestiona la información de tu cuenta y tus preferencias
                    de seguridad.</p>
            </header>

            <div class="max-w-4xl mx-auto bg-white">
                <Tabs value="0">
                    <TabList>
                        <Tab value="0">Información personal</Tab>
                        <Tab value="1">Seguridad</Tab>
                        <Tab value="2">Sesiones activas</Tab>
                    </TabList>
                    <TabPanel value="0">
                        <div class="p-4">
                            <div v-if="$page.props.jetstream.canUpdateProfileInformation">
                                <UpdateProfileInformationForm :user="$page.props.auth.user" />
                            </div>
                        </div>
                    </TabPanel>
                    <TabPanel value="1">
                        <div class="p-4 space-y-6">
                            <div v-if="$page.props.jetstream.canUpdatePassword">
                                <UpdatePasswordForm />
                            </div>
                            <!-- Aquí podrías añadir TwoFactorAuthenticationForm si lo necesitas -->
                        </div>
                    </TabPanel>
                    <TabPanel value="2">
                        <div class="p-4">
                            <LogoutOtherBrowserSessionsForm :sessions="sessions" />
                        </div>
                    </TabPanel>
                </Tabs>
            </div>
        </div>
    </AppLayout>
</template>