<script setup>
import { ref, watch, computed } from 'vue';
import { useLayout } from '@/Layouts/composables/layout';
import { Link, router, usePage, useForm } from '@inertiajs/vue3';
import { useToast } from 'primevue/usetoast';
import { format } from 'date-fns';
import es from 'date-fns/locale/es';

const { toggleMenu: toggleSidebar, toggleDarkMode, isDarkTheme } = useLayout();
const user = usePage().props.auth.user;
const page = usePage(); // Crear una referencia a usePage()

// --- Lógica para el Menú de Usuario ---
const toast = useToast();
const userMenu = ref();
const userMenuItems = ref([
    { label: 'Perfil', icon: 'pi pi-user', command: () => router.get(route('profile.show')) },
    { label: 'Cerrar sesión', icon: 'pi pi-sign-out', command: () => router.post(route('logout')) }
]);
const toggleUserMenu = (event) => { userMenu.value.toggle(event); };

const mobileUserMenuVisible = ref(false);

</script>

<template>
    <div class="layout-topbar">
        <div class="layout-topbar-logo-container">
            <button class="layout-menu-button layout-topbar-action" @click="toggleSidebar">
                <i class="pi pi-bars"></i>
            </button>
            <Link href="/" class="layout-topbar-logo">
            <svg width="32" height="32" viewBox="0 0 39 39" fill="none" xmlns="http://www.w3.org/2000/svg">
                <rect x="0.287744" y="0.287744" width="38.4245" height="38.4245" rx="2.87442"
                    stroke="var(--primary-color)" stroke-width="0.575488" />
                <path
                    d="M10.0134 12.5604C13.6146 11.155 16.0354 11.5445 19.5876 12.5604C19.1694 13.5219 19.0248 14.1989 18.9734 15.7234H18.446V14.4051C18.4458 13.6147 17.4793 12.8497 16.2498 12.5604C15.0201 12.2711 13.439 12.22 11.8582 12.7361C7.5541 14.1415 5.88518 22.5748 11.8582 25.4734C13.7906 26.4111 17.2159 26.3512 19.324 24.8582C19.4118 24.796 19.6148 24.968 19.5876 25.034C19.3896 25.5163 19.319 25.912 19.1482 25.9998C16.0739 27.5808 12.4728 27.518 10.0134 26.3514C3.16213 23.1014 5.35803 14.3771 10.0134 12.5604ZM28.3718 11.9461C32.9391 11.9463 33.1148 17.4798 28.3718 18.4461C35.3106 18.7098 32.6757 26.3514 28.3718 26.7029C26.0002 26.8965 24.303 26.8101 21.696 26.8787V26.3514C22.1352 26.3513 22.5545 26.0875 22.574 25.824C22.7496 23.452 22.8375 14.7568 22.574 13.4393C22.4878 13.0085 22.3987 12.6484 21.9597 12.6483H20.905V11.9461H28.3718ZM26.9666 18.9734C26.1432 18.9734 25.6691 18.9481 24.7703 19.0604V25.824C25.7365 25.9997 26.1615 26.0658 26.9666 25.9998C31.6215 25.618 31.6215 18.9738 26.9666 18.9734ZM26.9666 12.824C26.0604 12.7855 25.5735 12.7727 24.7703 12.9998V18.1824C25.6612 18.2117 26.1479 18.2166 26.9666 18.1824C30.2162 18.0463 31.2701 13.0074 26.9666 12.824Z"
                    fill="var(--primary-color)" />
            </svg>
            </Link>
        </div>

        <div class="layout-topbar-actions">
            <div class="layout-config-menu">
                <button type="button" class="layout-topbar-action" @click="toggleAttendancePopover">
                    <i class="pi pi-clock"></i>
                </button>
                <button type="button" class="layout-topbar-action" @click="toggleDarkMode">
                    <i :class="['pi', { 'pi-moon': isDarkTheme, 'pi-sun': !isDarkTheme }]"></i>
                </button>
            </div>

            <button type="button" class="layout-topbar-action lg:!hidden" @click="mobileUserMenuVisible = true">
                <i class="pi pi-user text-xl"></i>
            </button>

            <div class="layout-topbar-menu hidden lg:block">
                <div class="layout-topbar-menu-content">
                    <button @click="toggleUserMenu"
                        class="flex text-sm border-2 border-transparent rounded-full focus:outline-none focus:border-gray-300 transition">
                        <img class="size-9 rounded-full object-cover" :src="user.profile_photo_url" :alt="user.name">
                    </button>
                    <Menu ref="userMenu" :model="userMenuItems" :popup="true" />
                </div>
            </div>
        </div>
    </div>

    <!-- menú lateral (Sidebar) para la vista móvil. -->
    <Drawer v-model:visible="mobileUserMenuVisible" position="right" class="w-full sm:w-80">
        <div class="p-4">
            <div class="flex flex-col items-center">
                <img class="size-24 rounded-full object-cover mb-4" :src="user.profile_photo_url" :alt="user.name">
                <h2 class="text-xl font-bold text-gray-800 dark:text-gray-100">{{ user.name }}</h2>
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ user.email }}</p>
            </div>
            <Divider />
            <ul class="flex flex-col gap-2">
                <li v-for="item in userMenuItems" :key="item.label">
                    <button @click="item.command(); mobileUserMenuVisible = false;"
                        class="w-full flex items-center p-3 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors text-left">
                        <i :class="item.icon" class="text-xl mr-3 text-gray-500 dark:text-gray-400"></i>
                        <span class="font-medium text-gray-800 dark:text-gray-200">{{ item.label }}</span>
                    </button>
                </li>
            </ul>
        </div>
    </Drawer>
</template>
