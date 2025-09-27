import { usePage } from '@inertiajs/vue3';

export function usePermissions() {
    const permissions = usePage().props.auth.permissions || [];

    const hasPermission = (permission) => {
        return permissions.includes(permission);
    };

    return { hasPermission };
}