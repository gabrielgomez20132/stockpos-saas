<script setup>
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { ArrowLeft, ArrowRight, Search, Trash2, Send, Minus, Plus } from 'lucide-vue-next';
import Encabezado from '@/Components/Encabezado.vue';
import Boton from '@/Components/Boton.vue';
import Campo from '@/Components/Campo.vue';
import Vacio from '@/Components/Vacio.vue';
import http, { mensajeError } from '@/lib/http';
import { toastError } from '@/lib/toast';
import { cantidad, unidad, numero } from '@/lib/formato';

const props = defineProps({ origen: Object, destinos: Array });
const form = useForm({ destino: props.destinos[0]?.id ?? null, items: [], observaciones: '' });
const lineas = ref([]); // { producto, cantidad }

const q = ref('');
const resultados = ref([]);
let t;
watch(q, (v) => {
    clearTimeout(t);
    t = setTimeout(async () => {
        if (!v.trim()) return (resultados.value = []);
        try { resultados.value = (await http.get('/transferencias/productos', { params: { q: v } })).data; } catch (e) { toastError(mensajeError(e)); }
    }, 200);
});
function agregar(p) {
    const ya = lineas.value.find((l) => l.producto.id === p.id);
    if (ya) ya.cantidad = Math.min(p.quedan, (numero(ya.cantidad) || 0) + 1);
    else lineas.value.push({ producto: p, cantidad: p.quedan >= 1 ? 1 : p.quedan });
    q.value = '';
    resultados.value = [];
}
function enter() { if (resultados.value.length === 1 || (resultados.value[0] && resultados.value[0].codigo === q.value.trim())) agregar(resultados.value[0]); }
function mover(l, d) {
    const paso = l.producto.fraccionable ? 0.1 : 1;
    l.cantidad = Math.max(0, Math.min(l.producto.quedan, Math.round(((numero(l.cantidad) || 0) + d * paso) * 1000) / 1000));
}
const excede = (l) => (numero(l.cantidad) || 0) > l.producto.quedan + 1e-9;
const validas = computed(() => lineas.value.filter((l) => (numero(l.cantidad) || 0) > 0));
const destinoNombre = computed(() => props.destinos.find((d) => d.id === form.destino)?.nombre);

function enviar() {
    form.transform((d) => ({ ...d, items: validas.value.map((l) => ({ id_producto: l.producto.id, cantidad: numero(l.cantidad) })) })).post('/transferencias');
}
</script>

<template>
    <Encabezado titulo="Nueva transferencia" :subtitulo="`Sale de ${origen.nombre}`">
        <Boton href="/transferencias" variante="fantasma" :icono="ArrowLeft">Transferencias</Boton>
    </Encabezado>

    <Vacio v-if="!destinos.length" titulo="No hay otra sucursal activa" texto="Creá otra sucursal en Configuración para poder mandarle mercadería." />

    <form v-else class="grid gap-5 lg:grid-cols-[1fr_22rem]" @submit.prevent="enviar">
        <section class="panel overflow-visible">
            <div class="relative border-b border-petroleo-100 p-4 dark:border-petroleo-800">
                <Search class="pointer-events-none absolute top-1/2 left-7 size-4 -translate-y-1/2 text-petroleo-300" />
                <input v-model="q" class="campo pl-9" placeholder="Escaneá o buscá un producto para agregarlo" autofocus @keydown.enter.prevent="enter" />
                <ul v-if="resultados.length" class="absolute inset-x-4 top-full z-20 mt-1 max-h-72 divide-y divide-petroleo-50 overflow-y-auto rounded-xl border border-petroleo-100 bg-white shadow-xl dark:divide-petroleo-800 dark:border-petroleo-700 dark:bg-petroleo-900">
                    <li v-for="p in resultados" :key="p.id">
                        <button type="button" class="flex w-full items-center gap-3 px-4 py-2.5 text-left hover:bg-petroleo-50 disabled:opacity-50 dark:hover:bg-petroleo-800" :disabled="p.quedan <= 0" @click="agregar(p)">
                            <span class="min-w-0 flex-1"><span class="block truncate font-medium">{{ p.nombre }}</span><span class="text-xs text-petroleo-400">{{ p.codigo }}</span></span>
                            <span class="text-sm" :class="p.quedan > 0 ? 'text-petroleo-600' : 'text-sale'">{{ p.quedan > 0 ? `hay ${cantidad(p.quedan)} ${unidad(p.unidad, p.quedan)}` : 'sin stock' }}</span>
                        </button>
                    </li>
                </ul>
            </div>
            <ul v-if="lineas.length" class="divide-y divide-petroleo-50 dark:divide-petroleo-800">
                <li v-for="(l, i) in lineas" :key="l.producto.id" class="flex flex-wrap items-center gap-3 px-5 py-3">
                    <div class="min-w-0 flex-1 basis-48">
                        <p class="font-medium">{{ l.producto.nombre }}</p>
                        <p class="text-xs" :class="excede(l) ? 'font-semibold text-sale' : 'text-petroleo-500'">{{ l.producto.codigo }} · hay {{ cantidad(l.producto.quedan) }} {{ unidad(l.producto.unidad, l.producto.quedan) }} en {{ origen.nombre }}</p>
                    </div>
                    <div class="flex items-center rounded-xl border border-petroleo-200 dark:border-petroleo-700" :class="{ 'border-sale': excede(l) }">
                        <button type="button" class="grid size-10 place-items-center text-petroleo-600 hover:bg-petroleo-50 dark:hover:bg-petroleo-800" aria-label="Menos" @click="mover(l, -1)"><Minus class="size-4" /></button>
                        <input v-model="l.cantidad" inputmode="decimal" class="w-16 bg-transparent text-center font-semibold outline-none" :aria-label="'Cantidad de ' + l.producto.nombre" />
                        <button type="button" class="grid size-10 place-items-center text-petroleo-600 hover:bg-petroleo-50 dark:hover:bg-petroleo-800" aria-label="Más" @click="mover(l, 1)"><Plus class="size-4" /></button>
                    </div>
                    <Boton tam="sm" variante="fantasma" @click="l.cantidad = l.producto.quedan">Todo</Boton>
                    <Boton tam="sm" variante="fantasma" :icono="Trash2" aria-label="Quitar" @click="lineas.splice(i, 1)" />
                </li>
            </ul>
            <p v-else class="px-5 py-12 text-center text-sm text-petroleo-500">Agregá los productos que vas a mandar.</p>
        </section>

        <aside class="panel h-fit space-y-4 p-5">
            <div class="flex items-center gap-2 text-sm"><span class="font-semibold">{{ origen.nombre }}</span><ArrowRight class="size-4 text-petroleo-400" /><span class="font-semibold">{{ destinoNombre }}</span></div>
            <div><label class="etiqueta">Destino</label>
                <select v-model="form.destino" class="campo" required><option v-for="d in destinos" :key="d.id" :value="d.id">{{ d.nombre }}</option></select>
                <p v-if="form.errors.destino" class="mt-1 text-sm text-sale">{{ form.errors.destino }}</p></div>
            <Campo v-model="form.observaciones" label="Observaciones (opcional)" tipo="textarea" maxlength="300" placeholder="Ej: remito 0001-234, lo lleva Juan" />
            <p v-if="form.hasErrors" class="text-sm text-sale">{{ Object.values(form.errors)[0] }}</p>
            <Boton tipo="submit" variante="oscuro" class="w-full" :icono="Send" :disabled="!validas.length || lineas.some(excede)" :cargando="form.processing">Transferir {{ validas.length }} productos</Boton>
            <p class="text-xs text-petroleo-500">El stock se descuenta de {{ origen.nombre }} y se suma en {{ destinoNombre }} en el momento.</p>
        </aside>
    </form>
</template>
