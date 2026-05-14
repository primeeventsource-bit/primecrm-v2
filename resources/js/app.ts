import './bootstrap';

import { createApp, h, DefineComponent } from 'vue';
import { createInertiaApp, Link, Head } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createPinia } from 'pinia';
import { initEcho } from './echo';

const appName = import.meta.env.VITE_APP_NAME || 'Prime CRM';

createInertiaApp({
    title: (title) => (title ? `${title} · ${appName}` : appName),
    resolve: (name) =>
        resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob<DefineComponent>('./Pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        const pinia = createPinia();

        // Inertia's PageProps type is `Record<string, unknown>`-ish;
        // our concrete shape declares an `echo` key. A double-cast
        // (through unknown) avoids TS's "non-overlapping types"
        // complaint without weakening the runtime contract.
        const echoProps = props.initialPage.props as unknown as {
            echo: { host: string; key: string; cluster: string; port?: number; scheme?: string };
        };
        initEcho(echoProps);

        const app = createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(pinia)
            .component('Link', Link)
            .component('Head', Head);

        // setup() is typed as returning `void | App`; mount() itself
        // returns a ComponentPublicInstance, which is NOT assignable
        // to App. Mount as a side-effect and return the App so Inertia
        // can keep its handle for SSR-mode parity if it ever fires.
        app.mount(el);
        return app;
    },
    progress: {
        color: '#22c55e',
        showSpinner: false,
    },
});
