import { ref } from 'vue';
import { defineStore } from 'pinia';

/**
 * Chrome-level UI state: the mobile sidebar and transient notices.
 *
 * Kept separate from the auth store so a layout re-render caused by opening
 * the sidebar cannot invalidate anything that depends on permissions.
 */
export const useUiStore = defineStore('ui', () => {
    const sidebarOpen = ref(false);
    const notice = ref('');

    function toggleSidebar() {
        sidebarOpen.value = !sidebarOpen.value;
    }

    function closeSidebar() {
        sidebarOpen.value = false;
    }

    /** Show a short-lived success message. */
    function flash(message) {
        notice.value = message;
    }

    function clearNotice() {
        notice.value = '';
    }

    return {
        sidebarOpen,
        notice,
        toggleSidebar,
        closeSidebar,
        flash,
        clearNotice,
    };
});

export default useUiStore;
