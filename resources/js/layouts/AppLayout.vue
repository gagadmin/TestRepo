<script setup>
/**
 * Authenticated application shell.
 *
 * Structure and class names match the original layout so the established
 * app.css rules apply: `.app-shell` > `.sidebar` + `.workspace` > `.topbar` +
 * `.content`. The `.content` wrapper is what supplies the page's max-width and
 * padding, and `.workspace` supplies the sidebar offset.
 */
import Message from 'primevue/message';
import AppSidebar from '@/components/layout/AppSidebar.vue';
import AppTopbar from '@/components/layout/AppTopbar.vue';
import { useUiStore } from '@/stores/uiStore';

const ui = useUiStore();
</script>

<template>
    <div class="app-shell">
        <AppSidebar />

        <button
            v-if="ui.sidebarOpen"
            class="sidebar-backdrop"
            aria-label="Close menu"
            @click="ui.closeSidebar"
        ></button>

        <section class="workspace">
            <AppTopbar />

            <div class="content">
                <Message
                    v-if="ui.notice"
                    severity="success"
                    closable
                    @close="ui.clearNotice"
                >
                    {{ ui.notice }}
                </Message>

                <RouterView v-slot="{ Component }">
                    <!--
                      Suspense covers the lazily imported page chunk so the shell
                      paints before the page code arrives.
                    -->
                    <Suspense>
                        <component :is="Component" />
                        <template #fallback>
                            <div class="source-empty panel" role="status" aria-live="polite">
                                <i class="pi pi-spin pi-spinner" aria-hidden="true"></i>
                                <span>Loading…</span>
                            </div>
                        </template>
                    </Suspense>
                </RouterView>
            </div>
        </section>
    </div>
</template>
