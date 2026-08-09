<script setup>
import { ref, computed } from 'vue';
import { useLayout } from '@/Layouts/composables/layout';
import { Link, router, usePage } from '@inertiajs/vue3';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import { usePermissions } from '@/Composables';

// Importamos los nuevos submódulos
import TopbarReleaseNotes from './Partials/Topbar/TopbarReleaseNotes.vue';
import TopbarNotifications from './Partials/Topbar/TopbarNotifications.vue';
import TopbarReferrals from './Partials/Topbar/TopbarReferrals.vue';
import SupportModal from './Partials/Topbar/SupportModal.vue';

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
const supportModalVisible = ref(false);

const userMenuItems = computed(() => {
    const items = [
        { label: 'Perfil', icon: 'pi pi-user', command: () => router.get(route('profile.show')) },
    ];
    if (isOwner.value) {
        items.push({ label: 'Suscripción', icon: 'pi pi-star', command: () => router.get(route('subscription.show')) });
    }
    items.push(
        { label: 'Soporte', icon: 'pi pi-headphones', command: () => { supportModalVisible.value = true; } },
        { label: 'Cerrar sesión', icon: 'pi pi-sign-out', command: () => router.post(route('logout')) },
    );
    return items;
});

const branchMenuItems = computed(() => {
    if (user.value.id === 1) {
        return availableBranches.value.map(group => ({
            label: group.subscription_name,
            items: group.branches.map(branch => ({
                label: branch.name,
                icon: branch.id === currentBranch.value.id ? 'pi pi-check-circle !text-green-500' : 'pi pi-building',
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

// --- TESLA UI PASS-THROUGH (PT) ---
const menuPt = {
    root: { class: 'dark:!bg-[#232323] !border-gray-200 dark:!border-[#3a3a3a] !rounded-3xl !p-2 !shadow-2xl mt-2' },
    content: { class: 'dark:hover:!bg-[#1a1a1a] !rounded-2xl !transition-colors' },
    label: { class: 'text-sm font-medium text-gray-900 dark:!text-gray-200' },
    icon: { class: 'dark:!text-gray-400 !text-sm mr-3' },
    submenuHeader: { class: 'text-[10px] uppercase tracking-widest font-bold text-gray-500 bg-transparent m-0 px-3 py-2' }
};

const drawerPt = {
    root: { class: 'dark:!bg-[#232323] !border-l-gray-100 dark:!border-l-[#3a3a3a]' },
    header: { class: 'dark:bg-[#232323] border-b border-gray-100 dark:border-[#3a3a3a] px-6 py-5' },
    title: { class: 'text-lg font-medium text-gray-900 dark:text-white tracking-tight m-0' },
    content: { class: 'dark:bg-[#232323] p-0 custom-scrollbar' },
    closeButton: { class: 'hover:bg-gray-100 dark:hover:bg-[#1a1a1a] transition-colors rounded-full w-8 h-8 flex items-center justify-center' },
    closeButtonIcon: { class: 'dark:text-gray-400 !text-sm' },
    mask: { class: 'backdrop-blur-sm bg-gray-900/40 dark:bg-black/60' }
};
</script>

<template>
    <div class="layout-topbar">
        <div class="layout-topbar-logo-container">
            <button class="layout-menu-button layout-topbar-action !w-10 !h-10 !rounded-full hover:!bg-gray-100 dark:hover:!bg-[#1a1a1a] !transition-colors" @click="toggleSidebar">
                <i class="pi pi-bars !text-lg dark:!text-gray-300"></i>
            </button>
            <Link href="/" class="layout-topbar-logo">
                <ApplicationLogo class="h-14" />
            </Link>
        </div>

        <div class="layout-topbar-actions flex items-center gap-2">
            <!-- Selector de Sucursales (Escritorio) -->
            <div v-if="availableBranches && (availableBranches.length > 1 || user.id === 1) && hasPermission('system.branches.switch')"
                class="hidden lg:block">
                <button @click="toggleBranchMenu"
                    class="flex items-center gap-3 p-1.5 pr-4 rounded-full bg-white dark:bg-[#1a1a1a] border border-gray-100 dark:border-[#3a3a3a] hover:border-gray-300 dark:hover:border-gray-600 transition-all group">
                    <div class="w-8 h-8 rounded-full bg-blue-50 dark:bg-blue-900/20 flex items-center justify-center flex-shrink-0 border border-blue-100 dark:border-blue-900/30 group-hover:bg-blue-100 dark:group-hover:bg-blue-900/40 transition-colors">
                        <i v-if="user.id === 1" class="pi pi-shield text-blue-500 !text-xs" v-tooltip.bottom="'Modo soporte'"></i>
                        <i v-else class="pi pi-building text-blue-500 !text-xs"></i>
                    </div>
                    <div class="text-left hidden xl:block">
                        <p class="text-xs font-medium text-gray-900 dark:text-white m-0 tracking-tight leading-tight">{{ subscription.commercial_name }}</p>
                        <p class="text-[9px] uppercase tracking-widest font-bold text-gray-500 m-0">{{ currentBranch.name }}</p>
                    </div>
                    <i class="pi pi-chevron-down !text-[10px] text-gray-400 ml-2 hidden xl:block"></i>
                </button>
                <Menu ref="branchMenu" :model="branchMenuItems" :popup="true" class="max-h-96 overflow-y-auto custom-scrollbar" :pt="menuPt" />
            </div>
            
            <div v-else class="hidden lg:flex items-center gap-3 p-1.5 pr-4 rounded-full bg-white dark:bg-[#1a1a1a] border border-gray-100 dark:border-[#3a3a3a]">
                <div class="w-8 h-8 rounded-full bg-gray-50 dark:bg-[#232323] flex items-center justify-center flex-shrink-0 border border-gray-100 dark:border-[#3a3a3a]">
                    <i class="pi pi-building text-gray-500 !text-xs"></i>
                </div>
                <div class="text-left hidden xl:block">
                    <p class="text-xs font-medium text-gray-900 dark:text-white m-0 tracking-tight leading-tight">{{ subscription.commercial_name }}</p>
                    <p class="text-[9px] uppercase tracking-widest font-bold text-gray-500 m-0">{{ currentBranch.name }}</p>
                </div>
            </div>

            <!-- Divisor vertical sutil -->
            <div class="hidden lg:block w-px h-6 bg-gray-200 dark:bg-[#3a3a3a] mx-1"></div>

            <!-- COMPONENTES MODULARES AISLADOS -->
            <TopbarReleaseNotes />
            <TopbarNotifications />
            <TopbarReferrals v-if="isOwner" />

            <!-- Menú Usuario (Mobile) -->
            <button type="button" class="relative flex items-center justify-center w-10 h-10 rounded-full hover:bg-gray-100 dark:hover:bg-[#1a1a1a] transition-colors lg:hidden" @click="mobileUserMenuVisible = true">
                <i class="pi pi-user !text-lg text-gray-400"></i>
            </button>

            <!-- Menú Usuario (Desktop) -->
            <div class="hidden lg:block ml-1">
                <button @click="toggleUserMenu"
                    class="flex items-center justify-center w-10 h-10 rounded-full overflow-hidden ring-1 ring-gray-200 dark:ring-[#3a3a3a] hover:ring-primary-500 dark:hover:ring-primary-500 transition-all focus:outline-none focus:ring-2 focus:ring-primary-500/50"
                    v-tooltip.bottom="user.name">
                    <img class="w-full h-full object-cover" :src="user.profile_photo_url" :alt="user.name">
                </button>
                <Menu ref="userMenu" :model="userMenuItems" :popup="true" :pt="menuPt">
                    <template #start>
                        <div class="flex items-center gap-3 px-3 py-3 mb-1 border-b border-gray-100 dark:border-[#3a3a3a]">
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-gray-900 dark:text-white m-0 tracking-tight truncate">{{ user.name }}</p>
                                <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 truncate">{{ user.email }}</p>
                            </div>
                        </div>
                    </template>
                </Menu>
            </div>
        </div>
    </div>

    <!-- Menú Lateral Móvil (Drawer) Estilo Tesla UI -->
    <Drawer v-model:visible="mobileUserMenuVisible" position="right" class="w-full sm:w-80" :pt="drawerPt">
        
        <template #header>
            <h2 class="text-lg font-medium text-gray-900 dark:text-white tracking-tight m-0">Navegación</h2>
        </template>

        <!-- Perfil Usuario -->
        <div class="p-6 flex flex-col items-center border-b border-gray-100 dark:border-[#3a3a3a] bg-gray-50/50 dark:bg-[#1a1a1a]/50">
            <img class="w-20 h-20 rounded-full object-cover mb-4 border-2 border-white dark:border-[#232323] shadow-md" :src="user.profile_photo_url" :alt="user.name">
            <h2 class="text-lg font-medium text-gray-900 dark:text-white m-0 tracking-tight">{{ user.name }}</h2>
            <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-1">{{ user.email }}</p>
            <div v-if="user.id === 1" class="mt-3 bg-blue-50 dark:bg-blue-900/20 px-3 py-1 rounded-full border border-blue-100 dark:border-blue-900/30">
                <p class="text-[9px] font-bold text-blue-500 uppercase tracking-widest m-0">Super admin</p>
            </div>
        </div>

        <!-- Sucursales Mobile -->
        <div v-if="availableBranches && (availableBranches.length > 1 || user.id === 1) && hasPermission('system.branches.switch')" class="p-6 border-b border-gray-100 dark:border-[#3a3a3a]">
            <h3 class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mb-4">Sucursal activa</h3>
            
            <div class="flex items-center gap-3 p-4 bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl border border-gray-100 dark:border-[#3a3a3a] mb-6">
                <div class="w-8 h-8 rounded-full bg-blue-50 dark:bg-blue-900/20 flex items-center justify-center flex-shrink-0 border border-blue-100 dark:border-blue-900/30">
                    <i class="pi pi-building !text-sm text-blue-500"></i>
                </div>
                <div>
                    <p class="text-sm font-medium text-gray-900 dark:text-white m-0 tracking-tight">{{ currentBranch.name }}</p>
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-0.5">{{ subscription.commercial_name }}</p>
                </div>
            </div>

            <h3 class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mb-3">Cambiar sucursal</h3>
            <div class="flex flex-col gap-2 max-h-[300px] overflow-y-auto custom-scrollbar pr-2">
                <template v-for="(item, index) in branchMenuItems" :key="index">
                    <div v-if="item.items" class="mb-3">
                        <h4 class="text-[9px] font-bold text-gray-400 dark:text-gray-600 uppercase tracking-widest mb-2 px-2">{{ item.label }}</h4>
                        <div class="flex flex-col gap-1">
                            <button v-for="subItem in item.items" :key="subItem.label"
                                @click="subItem.command(); mobileUserMenuVisible = false;"
                                class="w-full flex items-center p-3 rounded-2xl hover:bg-gray-50 dark:hover:bg-[#1a1a1a] transition-colors text-left group"
                                :class="{'bg-blue-50/50 dark:bg-blue-900/10': subItem.label === currentBranch.name}">
                                <i class="!text-sm mr-3" :class="[item.icon, subItem.label === currentBranch.name ? 'text-green-500' : 'text-gray-400 group-hover:text-gray-600 dark:group-hover:text-gray-300']"></i>
                                <span class="text-sm font-medium" :class="subItem.label === currentBranch.name ? 'text-blue-700 dark:text-blue-400' : 'text-gray-700 dark:text-gray-300'">{{ subItem.label }}</span>
                            </button>
                        </div>
                    </div>
                    <div v-else>
                        <button @click="item.command(); mobileUserMenuVisible = false;"
                            class="w-full flex items-center p-3 rounded-2xl hover:bg-gray-50 dark:hover:bg-[#1a1a1a] transition-colors text-left group">
                            <i :class="item.icon" class="!text-sm mr-3 text-gray-400 group-hover:text-gray-600 dark:group-hover:text-gray-300"></i>
                            <span class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ item.label }}</span>
                        </button>
                    </div>
                </template>
            </div>
        </div>

        <div v-else class="p-6 border-b border-gray-100 dark:border-[#3a3a3a]">
            <h3 class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mb-4">Sucursal activa</h3>
            <div class="flex items-center gap-3 p-4 bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl border border-gray-100 dark:border-[#3a3a3a]">
                <div class="w-8 h-8 rounded-full bg-blue-50 dark:bg-blue-900/20 flex items-center justify-center flex-shrink-0 border border-blue-100 dark:border-blue-900/30">
                    <i class="pi pi-building !text-sm text-blue-500"></i>
                </div>
                <div>
                    <p class="text-sm font-medium text-gray-900 dark:text-white m-0 tracking-tight">{{ currentBranch.name }}</p>
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-0.5">{{ subscription.commercial_name }}</p>
                </div>
            </div>
        </div>

        <!-- Acciones Usuario Mobile -->
        <div class="p-6">
            <h3 class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mb-3">Mi cuenta</h3>
            <div class="flex flex-col gap-1">
                <button v-for="item in userMenuItems" :key="item.label"
                    @click="item.command(); mobileUserMenuVisible = false;"
                    class="w-full flex items-center p-4 rounded-2xl hover:bg-gray-50 dark:hover:bg-[#1a1a1a] transition-colors text-left group border border-transparent hover:border-gray-100 dark:hover:border-[#3a3a3a]">
                    <div class="w-8 h-8 rounded-full bg-gray-100 dark:bg-[#232323] flex items-center justify-center mr-3 group-hover:bg-white dark:group-hover:bg-[#1a1a1a] transition-colors border border-transparent group-hover:border-gray-200 dark:group-hover:border-[#3a3a3a]">
                        <i :class="item.icon" class="!text-sm text-gray-500 dark:text-gray-400 group-hover:text-gray-700 dark:group-hover:text-gray-200"></i>
                    </div>
                    <span class="font-medium text-sm text-gray-700 dark:text-gray-300 group-hover:text-gray-900 dark:group-hover:text-white">{{ item.label }}</span>
                </button>
            </div>
        </div>

    </Drawer>

    <!-- Modal de Soporte -->
    <SupportModal v-model:visible="supportModalVisible" />
</template>