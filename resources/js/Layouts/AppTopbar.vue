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
            Logo
            </Link>
        </div>

        <div class="layout-topbar-actions">
            <div class="layout-config-menu">
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
