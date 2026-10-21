import { reactive } from 'vue';

export const toasts = reactive([]);
let id = 0;

export function toast(texto, tipo = 'ok', ms = 3500) {
    if (!texto) return;
    const t = { id: ++id, texto, tipo };
    toasts.push(t);
    setTimeout(() => cerrarToast(t.id), ms);
}
export const toastError = (t) => toast(t, 'error', 6000);

export function cerrarToast(i) {
    const k = toasts.findIndex((t) => t.id === i);
    if (k >= 0) toasts.splice(k, 1);
}
