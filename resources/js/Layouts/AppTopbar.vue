<script setup>
import { ref, computed } from 'vue';
import { useLayout } from '@/Layouts/composables/layout';
import { Link, router, usePage } from '@inertiajs/vue3';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import { usePermissions } from '@/Composables';
import Popover from 'primevue/popover'; // <-- Importar Popover
import Badge from 'primevue/badge';     // <-- Importar Badge

const { toggleMenu: toggleSidebar, toggleDarkMode, isDarkTheme } = useLayout();
const page = usePage();

// composables
const { hasPermission } = usePermissions();

// SOLUCIÓN: Usar 'computed' para asegurar la reactividad en cambios de página.
const user = computed(() => page.props.auth.user);
const isOwner = computed(() => page.props.auth.is_subscription_owner);
const subscription = computed(() => page.props.auth.subscription);
const currentBranch = computed(() => page.props.auth.current_branch);
const availableBranches = computed(() => page.props.auth.available_branches);

// Notificaciones
const notifications = computed(() => page.props.notifications || { total: 0, expiring_layaways: 0, upcoming_deliveries: 0 });

const userMenu = ref();
const branchMenu = ref();
const notificationPopover = ref(); // <-- Ref para el popover

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
const toggleNotificationPopover = (event) => notificationPopover.value.toggle(event);

const mobileUserMenuVisible = ref(false);

</script>

<template>
    <div class="layout-topbar">
        <div class="layout-topbar-logo-container">
            <button class="layout-menu-button layout-topbar-action" @click="toggleSidebar">
                <i class="pi pi-bars"></i>
            </button>
            <Link href="/" class="layout-topbar-logo">
            <ApplicationLogo class="h-14" />
            </Link>
        </div>

        <div class="layout-topbar-actions flex items-center">
            <div v-if="availableBranches && availableBranches.length > 1 && hasPermission('system.branches.switch')"
                class="layout-topbar-menu hidden lg:block">
                <div class="layout-topbar-menu-content">
                    <button @click="toggleBranchMenu"
                        class="flex items-center gap-2 p-2 rounded-md hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                        <i class="pi pi-building !text-xl"></i>
                        <div class="text-left">
                            <p class="text-sm font-bold m-0">{{ subscription.commercial_name }}</p>
                            <p class="text-xs text-gray-500 m-0">{{ currentBranch.name }}</p>
                        </div>
                        <i class="pi pi-chevron-down text-xs ml-2"></i>
                    </button>
                    <Menu ref="branchMenu" :model="branchMenuItems" :popup="true" />
                </div>
            </div>
            <div v-else class="flex items-center gap-2 p-2 rounded-md">
                <i class="pi pi-building !text-xl"></i>
                <div class="text-left">
                    <p class="text-sm font-bold m-0">{{ subscription.commercial_name }}</p>
                    <p class="text-xs text-gray-500 m-0">{{ currentBranch.name }}</p>
                </div>
            </div>

            <!-- --- BOTÓN DE NOTIFICACIONES (Solo si hay alertas) --- -->
            <button v-if="notifications.total > 0" 
                type="button" 
                class="layout-topbar-action relative mr-2" 
                @click="toggleNotificationPopover"
            >
                <i class="pi pi-bell text-xl text-amber-500" :class="{'animate-swing': notifications.total > 0}"></i>
            </button>
            
            <Popover ref="notificationPopover">
                <div class="w-64">
                    <h4 class="font-bold text-gray-700 dark:text-gray-200 mb-2 px-2 text-sm">Pendientes de atención</h4>
                    <div class="flex flex-col gap-1">
                        <!-- Item: Apartados -->
                        <Link v-if="notifications.expiring_layaways > 0" 
                            :href="route('dashboard')" 
                            class="flex items-center justify-between p-2 rounded-lg hover:bg-purple-50 dark:hover:bg-purple-900/20 text-purple-700 dark:text-purple-300 transition-colors"
                        >
                            <div class="flex items-center gap-2">
                                <i class="pi pi-clock"></i>
                                <span class="text-sm font-medium">Apartados por vencer</span>
                            </div>
                            <Badge :value="notifications.expiring_layaways" class="!bg-purple-500" />
                        </Link>

                        <!-- Item: Entregas -->
                        <Link v-if="notifications.upcoming_deliveries > 0" 
                            :href="route('dashboard')" 
                            class="flex items-center justify-between p-2 rounded-lg hover:bg-blue-50 dark:hover:bg-blue-900/20 text-blue-700 dark:text-blue-300 transition-colors"
                        >
                            <div class="flex items-center gap-2">
                                <i class="pi pi-truck"></i>
                                <span class="text-sm font-medium">Próximas entregas</span>
                            </div>
                            <Badge :value="notifications.upcoming_deliveries" severity="info" />
                        </Link>
                    </div>
                </div>
            </Popover>
            <!-- ----------------------------------------------------- -->

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
                <h2 class="text-xl font-bold text-gray-800 dark:text-gray-100 m-0">{{ user.name }}</h2>
                <p class="text-sm text-gray-500 dark:text-gray-400 m-0">{{ user.email }}</p>
                <p class="text-sm font-bold m-0">{{ subscription.commercial_name }}</p>
            </div>
            <Divider />

            <!-- NUEVO: Selector de sucursal para móvil -->
            <div v-if="availableBranches && availableBranches.length > 1 && hasPermission('system.branches.switch')"
                class="mb-2">
                <div class="flex items-center gap-2 p-2 rounded-md">
                    <i class="pi pi-building !text-xl"></i>
                    <div class="text-left">
                        <p class="text-sm text-gray-700 m-0">{{ currentBranch.name }}</p>
                    </div>
                </div>
                <ul class="flex flex-col gap-2">
                    <h2 class="text-base pt-5 mb-0 text-center">Cambiar de sucursal</h2>
                    <li v-for="item in branchMenuItems" :key="item.label">
                        <button @click="item.command(); mobileUserMenuVisible = false;"
                            class="w-full flex items-center p-3 rounded-lg bg-gray-100 hover:bg-gray-200 dark:hover:bg-gray-700 transition-colors text-left">
                            <i :class="item.icon" class="!text-lg mr-3 text-gray-700 dark:text-gray-400"></i>
                            <span class="text-sm text-gray-700 dark:text-gray-200">{{ item.label }}</span>
                        </button>
                    </li>
                </ul>
            </div>
            <div v-else class="flex items-center gap-2 p-2 rounded-md">
                <i class="pi pi-building !text-xl"></i>
                <div class="text-left">
                    <p class="text-sm text-gray-700 m-0">{{ currentBranch.name }}</p>
                </div>
            </div>
            <Divider v-if="availableBranches && availableBranches.length > 1" />

            <ul class="flex flex-col gap-1">
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