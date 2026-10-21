import { reactive } from 'vue';

export const dialogo = reactive({ abierto: false, titulo: '', texto: '', boton: 'Confirmar', peligro: false, resolver: null });

/** await confirmar({ titulo, texto, boton, peligro }) -> true/false */
export function confirmar(op) {
    return new Promise((resolve) => {
        Object.assign(dialogo, { titulo: 'Confirmar', texto: '', boton: 'Confirmar', peligro: false }, op, { abierto: true, resolver: resolve });
    });
}

export function responder(valor) {
    dialogo.abierto = false;
    dialogo.resolver?.(valor);
    dialogo.resolver = null;
}
