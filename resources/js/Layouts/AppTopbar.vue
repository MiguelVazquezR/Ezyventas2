<script setup>
import { ref, computed } from 'vue';
import { useLayout } from '@/Layouts/composables/layout';
import { Link, router, usePage } from '@inertiajs/vue3';
import Menu from 'primevue/menu';
import Drawer from 'primevue/drawer';
import Divider from 'primevue/divider';

const { toggleMenu: toggleSidebar, toggleDarkMode, isDarkTheme } = useLayout();
const page = usePage();

// SOLUCIÓN: Usar 'computed' para asegurar la reactividad en cambios de página.
const user = computed(() => page.props.auth.user);
const isOwner = computed(() => page.props.auth.is_subscription_owner);
const subscription = computed(() => page.props.auth.subscription);
const currentBranch = computed(() => page.props.auth.current_branch);
const availableBranches = computed(() => page.props.auth.available_branches);

const userMenu = ref();
const branchMenu = ref();

const userMenuItems = computed(() => {
    const items = [
        { label: 'Perfil', icon: 'pi pi-user', command: () => router.get(route('profile.show')) },
    ];
    if (isOwner.value) {
        items.push({ label: 'Suscripción', icon: 'pi pi-star', command: () => router.get(route('subscription.show')) });
    }
    items.push({ label: 'Cerrar sesión', icon: 'pi pi-sign-out', command: () => router.post(route('logout')) });
    return items;
});

const branchMenuItems = computed(() => {
    return availableBranches.value
        .filter(branch => branch.id !== currentBranch.value.id)
        .map(branch => ({
            label: branch.name,
            icon: 'pi pi-building',
            command: () => {
                router.put(route('branch.switch', branch.id));
            }
        }));
});

const toggleUserMenu = (event) => userMenu.value.toggle(event);
const toggleBranchMenu = (event) => branchMenu.value.toggle(event);
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

        <div class="layout-topbar-actions flex items-center">
            <div v-if="availableBranches && availableBranches.length > 1" class="layout-topbar-menu hidden lg:block">
                <div class="layout-topbar-menu-content">
                    <button @click="toggleBranchMenu"
                        class="flex items-center gap-2 p-2 rounded-md hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                        <i class="pi pi-building text-xl"></i>
                        <div class="text-left">
                            <p class="text-sm font-bold m-0">{{ subscription.commercial_name }}</p>
                            <p class="text-xs text-gray-500 m-0">{{ currentBranch.name }}</p>
                        </div>
                        <i class="pi pi-chevron-down text-xs ml-2"></i>
                    </button>
                    <Menu ref="branchMenu" :model="branchMenuItems" :popup="true" />
                </div>
            </div>
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

    <Drawer v-model:visible="mobileUserMenuVisible" position="right" class="w-full sm:w-80">
        <div class="p-4">
            <div class="flex flex-col items-center">
                <img class="size-24 rounded-full object-cover mb-4" :src="user.profile_photo_url" :alt="user.name">
                <h2 class="text-xl font-bold text-gray-800 dark:text-gray-100">{{ user.name }}</h2>
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ user.email }}</p>
            </div>
            <Divider />

            <!-- NUEVO: Selector de sucursal para móvil -->
            <div v-if="availableBranches && availableBranches.length > 1" class="mb-2">
                <div class="px-3 py-2">
                    <p class="text-sm font-bold">{{ subscription.commercial_name }}</p>
                    <p class="text-xs text-gray-500">{{ currentBranch.name }}</p>
                </div>
                <ul class="flex flex-col gap-2">
                    <li v-for="item in branchMenuItems" :key="item.label">
                        <button @click="item.command(); mobileUserMenuVisible = false;"
                            class="w-full flex items-center p-3 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors text-left">
                            <i :class="item.icon" class="text-xl mr-3 text-gray-500 dark:text-gray-400"></i>
                            <span class="font-medium text-gray-800 dark:text-gray-200">{{ item.label }}</span>
                        </button>
                    </li>
                </ul>
            </div>
            <Divider v-if="availableBranches && availableBranches.length > 1" />

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
