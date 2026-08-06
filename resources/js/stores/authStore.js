import { computed, ref } from 'vue';
import { defineStore } from 'pinia';
import { authService } from '@/services/authService';
import { onUnauthenticated } from '@/services/http';

/**
 * Authentication and platform bootstrap state.
 *
 * This is the only place that knows whether someone is signed in and what
 * they may do. Previously `platform` was a ref in App.vue and every permission
 * check was an inline `platform.value?.user?.permissions?.includes(...)`
 * repeated across the template and script.
 */
export const useAuthStore = defineStore('auth', () => {
    const platform = ref(null);
    const loading = ref(true);
    const submitting = ref(false);
    const error = ref('');

    // Half-completed sign-in: the password was correct but the second factor
    // is outstanding. No authenticated session exists while this is true.
    const twoFactorPending = ref(false);
    const recoveryCodesAvailable = ref(false);
    const lockedForSeconds = ref(null);

    const user = computed(() => platform.value?.user ?? null);
    const isAuthenticated = computed(() => Boolean(user.value));
    const permissions = computed(() => user.value?.permissions ?? []);
    const roles = computed(() => user.value?.roles ?? []);
    const phases = computed(() => platform.value?.phases ?? []);

    /* ---- Identity gating, mirrored from the bootstrap payload ---- */

    const twoFactor = computed(() => platform.value?.security?.two_factor ?? {
        enabled: false,
        required: false,
        recovery_codes_remaining: 0,
    });

    const password = computed(() => platform.value?.security?.password ?? {
        must_change: false,
        age_days: null,
    });

    /**
     * The server enforces both of these; these flags only let the router send
     * the user somewhere useful instead of showing a bare 403.
     */
    const mustEnrolTwoFactor = computed(
        () => isAuthenticated.value && twoFactor.value.required && ! twoFactor.value.enabled,
    );

    const mustChangePassword = computed(
        () => isAuthenticated.value && password.value.must_change,
    );

    /** Single source of truth for permission checks. */
    function can(permission) {
        if (!permission) return true;

        return permissions.value.includes(permission);
    }

    function canAny(required = []) {
        if (required.length === 0) return true;

        return required.some((permission) => can(permission));
    }

    function hasRole(role) {
        return roles.value.some((value) => (value?.name ?? value) === role);
    }

    async function bootstrap() {
        loading.value = true;

        try {
            platform.value = await authService.bootstrap();
        } catch (caught) {
            error.value = caught.message;
        } finally {
            loading.value = false;
        }

        return platform.value;
    }

    async function login(credentials) {
        submitting.value = true;
        error.value = '';

        lockedForSeconds.value = null;

        try {
            const result = await authService.login(credentials);

            // An enrolled account gets a challenge and NO session.
            if (result?.two_factor_required) {
                twoFactorPending.value = true;
                recoveryCodesAvailable.value = Boolean(result.recovery_codes_available);

                return { ok: true, twoFactorRequired: true };
            }

            await bootstrap();

            return { ok: true, twoFactorRequired: false };
        } catch (caught) {
            error.value = caught.message;

            if (caught.locked) {
                lockedForSeconds.value = caught.retryAfterSeconds ?? null;
            }

            return { ok: false, error: caught.message, locked: Boolean(caught.locked) };
        } finally {
            submitting.value = false;
        }
    }

    /**
     * Step two of sign-in. A session exists only once this succeeds.
     */
    async function verifyTwoFactor(code) {
        submitting.value = true;
        error.value = '';

        try {
            await authService.verifyTwoFactor(code);
            twoFactorPending.value = false;
            await bootstrap();

            return { ok: true };
        } catch (caught) {
            error.value = caught.message;

            // Expired or locked: there is nothing left to retry against, so
            // send the user back to the password step.
            const restart = Boolean(caught.expired || caught.locked);

            if (restart) {
                twoFactorPending.value = false;
            }

            if (caught.locked) {
                lockedForSeconds.value = caught.retryAfterSeconds ?? null;
            }

            return { ok: false, error: caught.message, restart };
        } finally {
            submitting.value = false;
        }
    }

    async function cancelTwoFactor() {
        await authService.cancelTwoFactor();
        twoFactorPending.value = false;
        error.value = '';
    }

    async function logout() {
        try {
            await authService.logout();
        } finally {
            // Clear locally even if the request failed: the user asked to leave.
            platform.value = null;
        }
    }

    /** Called by the HTTP layer when the server reports the session is gone. */
    function handleSessionExpiry() {
        platform.value = null;
        error.value = 'Your session has ended. Please sign in again.';
    }

    onUnauthenticated(handleSessionExpiry);

    return {
        platform,
        loading,
        submitting,
        error,
        twoFactorPending,
        recoveryCodesAvailable,
        lockedForSeconds,
        user,
        isAuthenticated,
        permissions,
        roles,
        phases,
        twoFactor,
        password,
        mustEnrolTwoFactor,
        mustChangePassword,
        can,
        canAny,
        hasRole,
        bootstrap,
        login,
        verifyTwoFactor,
        cancelTwoFactor,
        logout,
        handleSessionExpiry,
        clearError: () => { error.value = ''; },
    };
});

export default useAuthStore;
