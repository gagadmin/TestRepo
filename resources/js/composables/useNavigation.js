import { computed } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '@/stores/authStore';

/**
 * Builds the sidebar from the route table.
 *
 * App.vue maintained two hand-written arrays (`navItems`, `adminItems`) whose
 * permission strings had to be kept in sync with the backend routes by hand.
 * Deriving them from `route.meta` means adding a route adds its nav entry, and
 * the permission is declared once.
 */
export function useNavigation() {
    const router = useRouter();
    const auth = useAuthStore();

    const buildGroup = (group) => computed(() => router
        .getRoutes()
        .filter((route) => route.meta?.nav === group)
        .sort((a, b) => (a.meta.order ?? 0) - (b.meta.order ?? 0))
        .map((route) => ({
            name: route.name,
            label: route.meta.title,
            icon: route.meta.icon,
            permission: route.meta.permission ?? null,
            available: auth.can(route.meta.permission),
        })));

    return {
        workspaceItems: buildGroup('workspace'),
        adminItems: buildGroup('admin'),
    };
}

export default useNavigation;
