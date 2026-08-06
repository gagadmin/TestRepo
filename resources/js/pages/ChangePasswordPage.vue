<script setup>
/**
 * Forced password change.
 *
 * Reached when the server reports `must_change_password`. The form itself is
 * shared with the profile page; this page only adds the explanation and the
 * post-change redirect.
 */
import { computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import Message from 'primevue/message';
import PageHeader from '@/components/ui/PageHeader.vue';
import PasswordChangeForm from '@/components/account/PasswordChangeForm.vue';
import { identityService } from '@/services/identityService';
import { useAsyncResource } from '@/composables/useAsyncResource';
import { useAuthStore } from '@/stores/authStore';

const auth = useAuthStore();
const router = useRouter();

const policy = useAsyncResource(() => identityService.passwordPolicy());

onMounted(policy.execute);

const forced = computed(() => policy.data.value?.must_change ?? auth.mustChangePassword);

async function onChanged() {
    // Re-read the session so the guard stops diverting here.
    await auth.bootstrap();
    await router.push({ name: 'overview' });
}
</script>

<template>
    <PageHeader
        :eyebrow="forced ? 'Required before you continue' : 'Account security'"
        title="Change your password"
        :description="forced
            ? 'Your password must be changed before you can use the workspace.'
            : 'Choose a new password for your account.'"
    />

    <Message v-if="forced" severity="warn" :closable="false">
        An administrator has required a password change on this account. This is standard for a
        newly created account, and is also used when a possible compromise is identified —
        if you did not expect it, contact IT.
    </Message>

    <article class="panel change-password-panel">
        <PasswordChangeForm :policy="policy.data.value" @changed="onChanged" />
    </article>
</template>

<style scoped>
.change-password-panel { max-width: 560px; }
</style>
