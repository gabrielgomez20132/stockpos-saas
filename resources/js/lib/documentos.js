import { numero } from '@/lib/formato';

let n = 0;
export const clave = () => `d${Date.now()}${n++}`;

export const clienteVacio = () => ({ id: null, nombre: '', doc: '', direccion: '', telefono: '' });

export function clienteDe(c) {
    return c ? { id: c.id ?? null, nombre: c.nombre ?? '', doc: c.doc ?? '', direccion: c.direccion ?? '', telefono: c.telefono ?? '' } : clienteVacio();
}

/** renglones guardados -> renglones editables */
export const aLineas = (items = []) => items.map((i) => ({ key: clave(), id_producto: i.id_producto, codigo: i.codigo ?? '', descripcion: i.descripcion,
    cantidad: i.cantidad, precio: i.precio, unidad: i.unidad ?? null, fraccionable: i.fraccionable ?? true, max: i.max ?? null, maxTexto: i.maxTexto ?? '' }));

export const subtotal = (lineas) => Math.round(lineas.reduce((s, l) => s + Math.round((numero(l.cantidad) || 0) * (numero(l.precio) || 0) * 100) / 100, 0) * 100) / 100;

/** lo que se manda al servidor */
export function datos(c, lineas) {
    return {
        id_cliente: c.id, cliente_nombre: c.nombre, cliente_doc: c.doc, cliente_direccion: c.direccion, cliente_telefono: c.telefono,
        items: lineas.map((l) => ({ id_producto: l.id_producto, codigo: l.codigo, descripcion: (l.descripcion ?? '').trim(), cantidad: numero(l.cantidad), precio: numero(l.precio) ?? 0 })),
    };
}

/** primer error del formulario, legible */
export const primerError = (errors) => Object.values(errors ?? {})[0];

export const abrirImpresion = (url) => window.open(url, 'documento', 'width=900,height=1000');
