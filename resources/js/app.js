import './bootstrap';
import { createApp, defineAsyncComponent } from 'vue';
import { createPinia } from 'pinia';
import PrimeVue from 'primevue/config';
import Aura from '@primeuix/themes/aura';
import App from './App.vue';
import router from './router';
import 'primeicons/primeicons.css';

const app = createApp(App);

// The chart engine is the largest dependency and most pages never use it.
app.component('apexchart', defineAsyncComponent(
    () => import('vue3-apexcharts').then((module) => module.default)
));

app.use(createPinia());
app.use(router);
app.use(PrimeVue, {
    theme: {
        preset: Aura,
        options: {
            darkModeSelector: '.app-dark',
            cssLayer: false,
        },
    },
});

// Mount only once the initial route has resolved, so the guard's session
// bootstrap completes before anything renders.
router.isReady().then(() => app.mount('#app'));
