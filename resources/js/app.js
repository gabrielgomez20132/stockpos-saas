import '../css/app.css';
import '@fontsource-variable/archivo/wdth.css';

import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { ZiggyVue } from 'ziggy-js';
import AppLayout from '@/Layouts/AppLayout.vue';

const appName = document.title || 'StokPOS';

createInertiaApp({
    title: (t) => (t ? `${t} · ${appName}` : appName),
    resolve: async (name) => {
        const pages = import.meta.glob('./Pages/**/*.vue');
        const cargar = pages[`./Pages/${name}.vue`];
        if (!cargar) throw new Error(`Página no encontrada: ${name}`);
        const page = await cargar();
        // layout por defecto: el del panel (salvo login e impresiones)
        if (page.default.layout === undefined && !name.startsWith('Auth/') && !name.startsWith('Print/')) {
            page.default.layout = AppLayout;
        }
        return page;
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },
    progress: { color: '#F2B705', delay: 150 },
});

// foto de producto que ya no existe -> imagen generica
document.addEventListener('error', (e) => {
    const img = e.target;
    if (img?.tagName === 'IMG' && img.src.includes('/uploads/') && !img.dataset.sinFoto) {
        img.dataset.sinFoto = '1';
        img.src = '/uploads/sinImagen.png';
    }
}, true);

// guarda la aplicacion en el navegador para poder vender sin conexion (necesita https o localhost)
if ('serviceWorker' in navigator && window.isSecureContext) {
    window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js').catch(() => { /* certificado no confiable u otro problema: sigue sin modo offline al recargar */ }));
}
