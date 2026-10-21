// Venta sin conexion: catalogo y ventas pendientes guardados en el navegador (IndexedDB).
import { reactive } from 'vue';
import http from '@/lib/http';

const DB = 'stokpos-offline';
let dbPromesa = null;

function abrir() {
    if (dbPromesa) return dbPromesa;
    dbPromesa = new Promise((ok, mal) => {
        if (!('indexedDB' in window)) return mal(new Error('Este navegador no permite guardar datos.'));
        const r = indexedDB.open(DB, 1);
        r.onupgradeneeded = () => {
            const db = r.result;
            if (!db.objectStoreNames.contains('kv')) db.createObjectStore('kv');
            if (!db.objectStoreNames.contains('ventas')) db.createObjectStore('ventas', { keyPath: 'uuid' });
        };
        r.onsuccess = () => ok(r.result);
        r.onerror = () => mal(r.error);
    });
    return dbPromesa;
}

async function tx(store, modo, fn) {
    const db = await abrir();
    return new Promise((ok, mal) => {
        const t = db.transaction(store, modo);
        const s = t.objectStore(store);
        let res;
        Promise.resolve(fn(s)).then((r) => (res = r));
        t.oncomplete = () => ok(res);
        t.onerror = () => mal(t.error);
    });
}
const pedir = (req) => new Promise((ok, mal) => { req.onsuccess = () => ok(req.result); req.onerror = () => mal(req.error); });

export const estado = reactive({
    conectado: navigator.onLine,
    pendientes: 0,
    enviando: false,
    catalogoFecha: null,
    catalogoCant: 0,
    ultimoError: null,
});

export async function leer(clave) {
    try { return await tx('kv', 'readonly', (s) => pedir(s.get(clave))); } catch { return null; }
}
async function escribir(clave, valor) {
    return tx('kv', 'readwrite', (s) => s.put(valor, clave));
}

const claveCatalogo = (sid) => `catalogo:${sid ?? 1}`;

export async function catalogo(sid) {
    return (await leer(claveCatalogo(sid))) ?? null;
}

/** baja el catalogo si tiene mas de `minutos` (o si se fuerza) */
export async function actualizarCatalogo(sid, { minutos = 10, forzar = false } = {}) {
    const actual = await catalogo(sid);
    if (!forzar && actual && Date.now() - new Date(actual.generado).getTime() < minutos * 60000) {
        estado.catalogoFecha = actual.generado;
        estado.catalogoCant = actual.productos.length;
        return actual;
    }
    const nuevo = (await http.get('/offline/catalogo', { timeout: 30000 })).data;
    await escribir(claveCatalogo(nuevo.sucursal), nuevo);
    estado.catalogoFecha = nuevo.generado;
    estado.catalogoCant = nuevo.productos.length;
    return nuevo;
}

/** descuenta en el catalogo local lo vendido sin conexion (para ver el stock que queda) */
async function descontar(sid, items) {
    const c = await catalogo(sid);
    if (!c) return;
    const porId = new Map(c.productos.map((p) => [p.id, p]));
    items.forEach((i) => { const p = porId.get(i.id); if (p) p.q = Math.max(0, Math.round((p.q - i.cantidad) * 1000) / 1000); });
    await escribir(claveCatalogo(sid), c);
}

function uuid() {
    if (crypto?.randomUUID) return crypto.randomUUID();
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
        const r = (Math.random() * 16) | 0;
        return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
    });
}

export async function guardarVenta(v) {
    const venta = { ...v, uuid: uuid(), fecha: new Date().toISOString(), estado: 'pendiente', mensaje: null, numero: Number((await leer('numero')) ?? 0) + 1 };
    await escribir('numero', venta.numero);
    await tx('ventas', 'readwrite', (s) => s.put(venta));
    await descontar(v.id_sucursal, v.items);
    await contar();
    return venta;
}

export async function ventas() {
    try { return await tx('ventas', 'readonly', (s) => pedir(s.getAll())); } catch { return []; }
}

export async function descartar(id) {
    await tx('ventas', 'readwrite', (s) => s.delete(id));
    await contar();
}

export async function contar() {
    estado.pendientes = (await ventas()).length;
    return estado.pendientes;
}

/**
 * Manda las ventas pendientes. Devuelve { registradas, avisos, errores }.
 * Las que el servidor registra (o ya tenia) se borran del navegador.
 */
export async function sincronizar() {
    if (estado.enviando) return null;
    const lista = await ventas();
    if (!lista.length) return { registradas: 0, avisos: [], errores: [] };
    estado.enviando = true;
    const out = { registradas: 0, avisos: [], errores: [], caja: null };
    try {
        for (let i = 0; i < lista.length; i += 50) {
            const parte = lista.slice(i, i + 50);
            const { data } = await http.post('/offline/ventas', {
                ventas: parte.map(({ uuid: u, fecha, id_sucursal, medio, descuento, total, items }) => ({ uuid: u, fecha, id_sucursal, medio, descuento, total, items: items.map(({ id, cantidad, monto }) => ({ id, cantidad, monto })) })),
            }, { timeout: 60000 });
            for (const v of parte) {
                const r = data.resultados[v.uuid];
                if (!r) continue;
                if (r.estado === 'ok' || r.estado === 'ya') {
                    await tx('ventas', 'readwrite', (s) => s.delete(v.uuid));
                    if (r.estado === 'ok') out.registradas++;
                    (r.avisos ?? []).forEach((a) => out.avisos.push(`Venta #${r.venta}: ${a}`));
                } else {
                    await tx('ventas', 'readwrite', (s) => s.put({ ...v, estado: r.estado, mensaje: r.mensaje }));
                    if (r.estado === 'caja') out.caja = r.mensaje;
                    else out.errores.push(`Venta sin conexión N° ${v.numero}: ${r.mensaje}`);
                }
            }
        }
        estado.ultimoError = null;
    } catch (e) {
        estado.ultimoError = e?.response ? (e.response.data?.message ?? 'Error del servidor') : 'Sin conexión';
        if (!e?.response) estado.conectado = false;
        throw e;
    } finally {
        estado.enviando = false;
        await contar();
    }
    return out;
}

/** el servidor responde? (navigator.onLine no alcanza: puede haber wifi sin servidor) */
export async function probar() {
    try {
        await http.get('/offline/ping', { timeout: 6000, params: { t: Date.now() } });
        estado.conectado = true;
    } catch (e) {
        estado.conectado = !!e?.response && e.response.status < 500;
    }
    return estado.conectado;
}
