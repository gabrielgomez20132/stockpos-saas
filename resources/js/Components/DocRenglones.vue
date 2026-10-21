<script setup>
import { ref, watch } from 'vue';
import { Search, Trash2, Minus, Plus, PenLine } from 'lucide-vue-next';
import Boton from '@/Components/Boton.vue';
import http, { mensajeError } from '@/lib/http';
import { toastError } from '@/lib/toast';
import { plata, cantidad, unidad, numero } from '@/lib/formato';

/**
 * Renglones de un presupuesto o remito.
 * Cada renglon: { key, id_producto, codigo, descripcion, cantidad, precio, unidad, fraccionable, max, maxTexto }
 * - max: tope de cantidad (stock en un remito suelto, lo que falta entregar en uno de venta)
 */
const lineas = defineModel({ type: Array, required: true });
const props = defineProps({
    url: String, // busqueda de productos (sin url no hay buscador)
    conStock: Boolean, // el tope es el stock de la sucursal
    libre: { type: Boolean, default: true }, // renglones escritos a mano
    vacio: { type: String, default: 'Agregá productos del catálogo o renglones a mano.' },
});

let n = 0;
const clave = () => `l${Date.now()}${n++}`;
const q = ref('');
const resultados = ref([]);
let t;
watch(q, (v) => {
    clearTimeout(t);
    if (!v.trim()) return (resultados.value = []);
    t = setTimeout(async () => {
        try { resultados.value = (await http.get(props.url, { params: { q: v } })).data; } catch (e) { toastError(mensajeError(e)); }
    }, 200);
});

function agregar(p) {
    const ya = lineas.value.find((l) => l.id_producto === p.id);
    if (ya) {
        const nueva = Math.round(((numero(ya.cantidad) || 0) + 1) * 1000) / 1000;
        if (ya.max !== null && ya.max !== undefined && nueva > ya.max + 1e-9) toastError(`De ${p.nombre} solo quedan ${cantidad(ya.max)} ${unidad(p.unidad, ya.max)} en stock.`);
        else ya.cantidad = nueva;
    } else if (props.conStock && p.quedan <= 0) {
        toastError(`${p.nombre} no tiene stock.`);
    } else {
        const inicial = props.conStock && p.quedan < 1 ? p.quedan : 1;
        lineas.value.push({ key: clave(), id_producto: p.id, codigo: p.codigo, descripcion: p.nombre, cantidad: inicial, precio: p.monto,
            unidad: p.unidad, fraccionable: p.fraccionable, max: props.conStock ? p.quedan : null, maxTexto: props.conStock ? 'en stock' : '' });
    }
    q.value = '';
    resultados.value = [];
}
function enter() {
    const r = resultados.value;
    if (r.length === 1 || (r[0] && r[0].codigo === q.value.trim())) agregar(r[0]);
}
function libreNuevo() {
    lineas.value.push({ key: clave(), id_producto: null, codigo: '', descripcion: '', cantidad: 1, precio: 0, unidad: null, fraccionable: true, max: null, maxTexto: '' });
}
function mover(l, d) {
    const paso = l.fraccionable && l.id_producto ? 0.1 : 1;
    let v = Math.max(0, Math.round(((numero(l.cantidad) || 0) + d * paso) * 1000) / 1000);
    if (l.max !== null && l.max !== undefined) v = Math.min(v, l.max);
    l.cantidad = v;
}
const excede = (l) => l.max !== null && l.max !== undefined && (numero(l.cantidad) || 0) > l.max + 1e-9;
const subtotal = (l) => Math.round((numero(l.cantidad) || 0) * (numero(l.precio) || 0) * 100) / 100;
defineExpose({ excede });
</script>

<template>
    <section class="panel overflow-visible">
        <div v-if="url || libre" class="flex flex-wrap gap-2 border-b border-petroleo-100 p-4 dark:border-petroleo-800">
            <div v-if="url" class="relative min-w-0 flex-1 basis-64">
                <Search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-petroleo-300" />
                <input v-model="q" class="campo pl-9" placeholder="Escaneá o buscá un producto para agregarlo" @keydown.enter.prevent="enter" />
                <ul v-if="resultados.length" class="absolute inset-x-0 top-full z-20 mt-1 max-h-72 divide-y divide-petroleo-50 overflow-y-auto rounded-xl border border-petroleo-100 bg-white shadow-xl dark:divide-petroleo-800 dark:border-petroleo-700 dark:bg-petroleo-900">
                    <li v-for="p in resultados" :key="p.id">
                        <button type="button" class="flex w-full items-center gap-3 px-4 py-2.5 text-left hover:bg-petroleo-50 disabled:opacity-50 dark:hover:bg-petroleo-800" :disabled="conStock && p.quedan <= 0" @click="agregar(p)">
                            <span class="min-w-0 flex-1"><span class="block truncate font-medium">{{ p.nombre }}</span><span class="text-xs text-petroleo-400">{{ p.codigo }}</span></span>
                            <span class="text-right text-sm">
                                <span class="block font-semibold">{{ plata(p.monto) }}</span>
                                <span :class="p.quedan > 0 ? 'text-petroleo-500' : 'text-sale'" class="text-xs">{{ p.quedan > 0 ? `hay ${cantidad(p.quedan)} ${unidad(p.unidad, p.quedan)}` : 'sin stock' }}</span>
                            </span>
                        </button>
                    </li>
                </ul>
            </div>
            <Boton v-if="libre" :icono="PenLine" @click="libreNuevo">Renglón a mano</Boton>
        </div>

        <ul v-if="lineas.length" class="divide-y divide-petroleo-50 dark:divide-petroleo-800">
            <li v-for="(l, i) in lineas" :key="l.key" class="grid grid-cols-[1fr_auto] items-center gap-x-3 gap-y-2 px-4 py-3 sm:grid-cols-[auto_9rem_1fr_auto]">
                <div class="col-span-2 min-w-0 sm:col-span-4">
                    <input v-model="l.descripcion" class="campo w-full py-1.5 font-medium" maxlength="150" :placeholder="l.id_producto ? 'Descripción' : 'Escribí el concepto (ej: Flete, Mano de obra)'" :aria-label="`Descripción del renglón ${i + 1}`" />
                    <p class="mt-1 text-xs" :class="excede(l) ? 'font-semibold text-sale' : 'text-petroleo-500'">
                        <template v-if="l.id_producto">{{ l.codigo }}</template><template v-else>Renglón a mano</template>
                        <template v-if="l.max !== null && l.max !== undefined"> · {{ excede(l) ? 'solo ' : '' }}{{ cantidad(l.max) }} {{ unidad(l.unidad, l.max) }} {{ l.maxTexto }}</template>
                    </p>
                </div>
                <div class="flex items-center rounded-xl border border-petroleo-200 dark:border-petroleo-700" :class="{ 'border-sale': excede(l) }">
                    <button type="button" class="grid size-9 place-items-center text-petroleo-600 hover:bg-petroleo-50 dark:hover:bg-petroleo-800" aria-label="Menos" @click="mover(l, -1)"><Minus class="size-4" /></button>
                    <input v-model="l.cantidad" inputmode="decimal" class="w-14 bg-transparent text-center font-semibold outline-none" :aria-label="`Cantidad del renglón ${i + 1}`" />
                    <button type="button" class="grid size-9 place-items-center text-petroleo-600 hover:bg-petroleo-50 dark:hover:bg-petroleo-800" aria-label="Más" @click="mover(l, 1)"><Plus class="size-4" /></button>
                </div>
                <div class="relative">
                    <span class="pointer-events-none absolute inset-y-0 left-2.5 flex items-center text-sm text-petroleo-400">$</span>
                    <input v-model="l.precio" inputmode="decimal" class="campo py-1.5 pl-6 text-right" :aria-label="`Precio unitario del renglón ${i + 1}`" />
                </div>
                <p class="text-right font-semibold tabular-nums sm:text-base">{{ plata(subtotal(l)) }}</p>
                <Boton tam="sm" variante="fantasma" :icono="Trash2" aria-label="Quitar renglón" class="justify-self-end" @click="lineas.splice(i, 1)" />
            </li>
        </ul>
        <p v-else class="px-5 py-12 text-center text-sm text-petroleo-500">{{ vacio }}</p>
    </section>
</template>
