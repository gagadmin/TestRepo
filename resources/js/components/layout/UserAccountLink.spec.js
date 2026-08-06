import { flushPromises, mount } from '@vue/test-utils';
import { createMemoryHistory, createRouter } from 'vue-router';
import { describe, expect, it } from 'vitest';
import UserAccountLink from './UserAccountLink.vue';

describe('UserAccountLink', () => {
    it('shows a clear Profile link and navigates to account security', async () => {
        const router = createRouter({
            history: createMemoryHistory(),
            routes: [
                { path: '/', name: 'overview', component: { template: '<div />' } },
                { path: '/account', name: 'profile', component: { template: '<div />' } },
            ],
        });

        await router.push('/');
        await router.isReady();

        const wrapper = mount(UserAccountLink, {
            props: { user: { name: 'Normal User' } },
            global: { plugins: [router] },
        });

        const link = wrapper.get('a');
        expect(link.text()).toContain('Profile & security');
        expect(link.attributes('aria-label')).toContain('Normal User');

        await link.trigger('click');
        await flushPromises();

        expect(router.currentRoute.value.name).toBe('profile');
    });
});
