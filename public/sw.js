/* StokPOS: guarda la aplicacion para poder abrirla y vender sin conexion. */
const ASSETS = 'stokpos-assets-v1';
const PAGINAS = 'stokpos-paginas-v1';

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (e) => {
    e.waitUntil((async () => {
        const nombres = await caches.keys();
        await Promise.all(nombres.filter((n) => ![ASSETS, PAGINAS].includes(n)).map((n) => caches.delete(n)));
        await self.clients.claim();
    })());
});

self.addEventListener('message', (e) => {
    if (e.data === 'limpiar') caches.delete(PAGINAS); // al salir del sistema
});

const esAsset = (url) => url.pathname.startsWith('/build/') || /\.(woff2?|svg|png|ico|webmanifest)$/.test(url.pathname);

self.addEventListener('fetch', (e) => {
    const req = e.request;
    if (req.method !== 'GET') return;
    const url = new URL(req.url);
    if (url.origin !== self.location.origin || url.pathname.startsWith('/uploads/')) return;

    // archivos de la aplicacion (con hash en el nombre): primero la copia guardada
    if (esAsset(url)) {
        e.respondWith(caches.open(ASSETS).then(async (c) => {
            const guardado = await c.match(req);
            if (guardado) return guardado;
            const r = await fetch(req);
            if (r.ok) c.put(req, r.clone());
            return r;
        }));
        return;
    }

    // paginas: primero la red; si no hay, la ultima pantalla guardada
    if (req.mode === 'navigate') {
        e.respondWith((async () => {
            try {
                const r = await fetch(req);
                const tipo = r.headers.get('content-type') || '';
                if (r.ok && tipo.includes('text/html') && !url.pathname.startsWith('/login') && !url.pathname.includes('/ticket') && !url.pathname.includes('/print')) {
                    const c = await caches.open(PAGINAS);
                    c.put('/__ultima', r.clone());
                }
                return r;
            } catch {
                const c = await caches.open(PAGINAS);
                return (await c.match('/__ultima')) || new Response(
                    '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Sin conexión</title>'
                    + '<body style="font-family:sans-serif;padding:2rem;text-align:center;color:#0e3b43"><h1>Sin conexión</h1>'
                    + '<p>Este equipo todavía no tiene guardado el sistema. Cuando vuelva la conexión, entrá una vez y después vas a poder vender sin conexión.</p></body>',
                    { headers: { 'Content-Type': 'text/html; charset=utf-8' } });
            }
        })());
    }
});
