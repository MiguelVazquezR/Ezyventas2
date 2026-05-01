<script setup>
import { ref, computed } from 'vue';
import { useLayout } from '@/Layouts/composables/layout';
import { Link, router, usePage } from '@inertiajs/vue3';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import { usePermissions } from '@/Composables';

// Importamos los nuevos submódulos
import TopbarReleaseNotes from './Partials/Topbar/TopbarReleaseNotes.vue';
import TopbarNotifications from './Partials/Topbar/TopbarNotifications.vue';

const { toggleMenu: toggleSidebar, toggleDarkMode, isDarkTheme } = useLayout();
const page = usePage();
const { hasPermission } = usePermissions();

const user = computed(() => page.props.auth.user);
const isOwner = computed(() => page.props.auth.is_subscription_owner);
const subscription = computed(() => page.props.auth.subscription);
const currentBranch = computed(() => page.props.auth.current_branch);
const availableBranches = computed(() => page.props.auth.available_branches);

const userMenu = ref();
const branchMenu = ref();
const mobileUserMenuVisible = ref(false);

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
    if (user.value.id === 1) {
        return availableBranches.value.map(group => ({
            label: group.subscription_name,
            items: group.branches.map(branch => ({
                label: branch.name,
                icon: branch.id === currentBranch.value.id ? 'pi pi-check-circle text-green-500' : 'pi pi-building',
                command: () => {
                    if (branch.id !== currentBranch.value.id) router.put(route('branch.switch', branch.id));
                }
            }))
        }));
    }

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
            <!-- Selector de Sucursales (Escritorio) -->
            <div v-if="availableBranches && (availableBranches.length > 1 || user.id === 1) && hasPermission('system.branches.switch')"
                class="layout-topbar-menu hidden lg:block">
                <div class="layout-topbar-menu-content">
                    <button @click="toggleBranchMenu"
                        class="flex items-center gap-2 p-2 rounded-md hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors">
                        <i v-if="user.id === 1" class="pi pi-shield text-blue-500 !text-xl" v-tooltip.bottom="'Modo Soporte'"></i>
                        <i v-else class="pi pi-building !text-xl"></i>
                        
                        <div class="text-left">
                            <p class="text-sm font-bold m-0">{{ subscription.commercial_name }}</p>
                            <p class="text-xs text-surface-500 m-0">{{ currentBranch.name }}</p>
                        </div>
                        <i class="pi pi-chevron-down text-xs ml-2"></i>
                    </button>
                    <Menu ref="branchMenu" :model="branchMenuItems" :popup="true" class="max-h-96 overflow-y-auto" />
                </div>
            </div>
            
            <div v-else class="flex items-center gap-2 p-2 rounded-md">
                <i class="pi pi-building !text-xl"></i>
                <div class="text-left">
                    <p class="text-sm font-bold m-0">{{ subscription.commercial_name }}</p>
                    <p class="text-xs text-surface-500 m-0">{{ currentBranch.name }}</p>
                </div>
            </div>

            <!-- COMPONENTES MODULARES AISLADOS -->
            <TopbarReleaseNotes />
            <TopbarNotifications />

            <!-- Menú Usuario (Mobile) -->
            <button type="button" class="layout-topbar-action lg:!hidden" @click="mobileUserMenuVisible = true">
                <i class="pi pi-user text-xl"></i>
            </button>

            <!-- Menú Usuario (Desktop) -->
            <div class="layout-topbar-menu hidden lg:block">
                <div class="layout-topbar-menu-content">
                    <button @click="toggleUserMenu"
                        class="flex text-sm border-2 border-transparent rounded-full focus:outline-none focus:border-surface-300 transition">
                        <img class="size-9 rounded-full object-cover" :src="user.profile_photo_url" :alt="user.name">
                    </button>
                    <Menu ref="userMenu" :model="userMenuItems" :popup="true" />
                </div>
            </div>
        </div>
    </div>

    <!-- Menú Lateral Móvil (Sin cambios) -->
    <Drawer v-model:visible="mobileUserMenuVisible" position="right" class="w-full sm:w-80">
        <div class="p-4">
            <div class="flex flex-col items-center">
                <img class="size-24 rounded-full object-cover mb-4" :src="user.profile_photo_url" :alt="user.name">
                <h2 class="text-xl font-bold text-surface-800 dark:text-surface-100 m-0">{{ user.name }}</h2>
                <p class="text-sm text-surface-500 dark:text-surface-400 m-0">{{ user.email }}</p>
                <p v-if="user.id === 1" class="text-xs font-bold text-blue-500 mt-1 uppercase tracking-wide">Super Admin</p>
                <p v-else class="text-sm font-bold m-0">{{ subscription.commercial_name }}</p>
            </div>
            <Divider />

            <div v-if="availableBranches && (availableBranches.length > 1 || user.id === 1) && hasPermission('system.branches.switch')" class="mb-2">
                <div class="flex items-center gap-2 p-2 rounded-md">
                    <i class="pi pi-building !text-xl"></i>
                    <div class="text-left">
                        <p class="text-sm text-surface-700 m-0">{{ currentBranch.name }}</p>
                    </div>
                </div>
                
                <div class="flex flex-col gap-2 max-h-[400px] overflow-y-auto">
                    <h2 class="text-base pt-5 mb-0 text-center">Cambiar de sucursal</h2>
                    
                    <template v-for="(item, index) in branchMenuItems" :key="index">
                        <div v-if="item.items" class="mb-2">
                            <h3 class="text-xs font-bold text-surface-400 uppercase px-3 py-1 bg-surface-50 dark:bg-surface-800 rounded mb-1 sticky top-0">
                                {{ item.label }}
                            </h3>
                            <ul>
                                <li v-for="subItem in item.items" :key="subItem.label">
                                    <button @click="subItem.command(); mobileUserMenuVisible = false;"
                                        class="w-full flex items-center p-3 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors text-left"
                                        :class="{'bg-blue-50 dark:bg-blue-900/20': subItem.label === currentBranch.name}">
                                        <i :class="subItem.icon" class="!text-lg mr-3 text-surface-700 dark:text-surface-400"></i>
                                        <span class="text-sm text-surface-700 dark:text-surface-200">{{ subItem.label }}</span>
                                    </button>
                                </li>
                            </ul>
                        </div>
                        <div v-else>
                            <button @click="item.command(); mobileUserMenuVisible = false;"
                                class="w-full flex items-center p-3 rounded-lg bg-surface-100 hover:bg-surface-200 dark:hover:bg-surface-700 transition-colors text-left">
                                <i :class="item.icon" class="!text-lg mr-3 text-surface-700 dark:text-surface-400"></i>
                                <span class="text-sm text-surface-700 dark:text-surface-200">{{ item.label }}</span>
                            </button>
                        </div>
                    </template>
                </div>
            </div>

            <div v-else class="flex items-center gap-2 p-2 rounded-md">
                <i class="pi pi-building !text-xl"></i>
                <div class="text-left">
                    <p class="text-sm text-surface-700 m-0">{{ currentBranch.name }}</p>
                </div>
            </div>
            
            <Divider v-if="availableBranches && availableBranches.length > 1" />

            <ul class="flex flex-col gap-1">
                <li v-for="item in userMenuItems" :key="item.label">
                    <button @click="item.command(); mobileUserMenuVisible = false;"
                        class="w-full flex items-center p-3 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors text-left">
                        <i :class="item.icon" class="text-xl mr-3 text-surface-500 dark:text-surface-400"></i>
                        <span class="font-medium text-surface-800 dark:text-surface-200">{{ item.label }}</span>
                    </button>
                </li>
            </ul>
        </div>
    </Drawer>
</template>