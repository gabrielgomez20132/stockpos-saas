<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { ArrowLeft, Check, Link2, Search, Settings2, Trash2, TrendingDown, TrendingUp, Unlink, X } from 'lucide-vue-next';
import Encabezado from '@/Components/Encabezado.vue';
import Boton from '@/Components/Boton.vue';
import Insignia from '@/Components/Insignia.vue';
import Modal from '@/Components/Modal.vue';
import http, { mensajeError } from '@/lib/http';
import { toastError } from '@/lib/toast';
import { confirmar } from '@/lib/confirmar';
import { plata, fecha } from '@/lib/formato';

const props = defineProps({ lista: Object, items: Array });
const filas = ref(props.items.map((i) => ({ ...i })));
watch(() => props.items, (v) => (filas.value = v.map((i) => ({ ...i }))));
const editable = computed(() => props.lista.estado === 'pendiente');

const varCosto = (i) => (i.costo_anterior > 0 ? ((i.costo_nuevo - i.costo_anterior) / i.costo_anterior) * 100 : null);
const tipo = (i) => {
    if (!i.producto) return 'sin';
    const d = i.costo_nuevo - i.costo_anterior;
    return Math.abs(d) < 0.01 && Math.abs((i.precio_nuevo ?? 0) - (i.precio_anterior ?? 0)) < 0.01 ? 'igual' : d > 0 ? 'sube' : d < 0 ? 'baja' : 'precio';
};
const cuenta = computed(() => {
    const c = { todos: filas.value.length, sube: 0, baja: 0, igual: 0, sin: 0, precio: 0 };
    filas.value.forEach((i) => c[tipo(i)]++);
    return c;
});
const filtros = computed(() => [
    ['todos', 'Todos'], ['sube', 'Suben'], ['baja', 'Bajan'], ['igual', 'Sin cambio'], ['sin', 'Sin vincular'],
].map(([id, n]) => ({ id, n, c: cuenta.value[id] })));
const filtro = ref(cuenta.value.sube + cuenta.value.baja ? 'todos' : 'todos');
const q = ref('');
const visibles = computed(() => {
    const t = q.value.trim().toLowerCase();
    return filas.value.filter((i) => (filtro.value === 'todos' || tipo(i) === filtro.value || (filtro.value === 'sube' && tipo(i) === 'precio'))
        && (!t || i.codigo.toLowerCase().includes(t) || (i.descripcion ?? '').toLowerCase().includes(t) || (i.producto?.nombre ?? '').toLowerCase().includes(t)));
});
const limite = ref(200);
watch([filtro, q], () => (limite.value = 200));

const elegidos = computed(() => filas.value.filter((i) => i.aplicar && i.producto));
const todosVisibles = computed(() => visibles.value.filter((i) => i.producto).every((i) => i.aplicar));
function marcarVisibles(v) { visibles.value.forEach((i) => i.producto && (i.aplicar = v)); }

const variacionElegidos = computed(() => {
    const l = elegidos.value.filter((i) => i.costo_anterior > 0);
    return l.length ? l.reduce((a, i) => a + varCosto(i), 0) / l.length : null;
});
const pct = (v) => (v === null || v === undefined ? '' : `${v > 0 ? '+' : ''}${v.toFixed(1).replace('.', ',')}%`);

// ---- vincular a mano
const vinc = ref(null);
const buscar = ref('');
const resultados = ref([]);
let t;
watch(buscar, (v) => {
    clearTimeout(t);
    t = setTimeout(async () => {
        if (!v.trim()) return (resultados.value = []);
        try { resultados.value = (await http.get('/listas-precios/productos', { params: { q: v } })).data; } catch (e) { toastError(mensajeError(e)); }
    }, 250);
});
function abrirVincular(i) { vinc.value = i; buscar.value = i.descripcion?.split(' ').slice(0, 2).join(' ') ?? ''; }
async function vincular(i, idProducto) {
    try {
        const r = (await http.put(`/listas-precios/${props.lista.id}/renglones/${i.id}`, { id_producto: idProducto })).data;
        Object.assign(filas.value.find((f) => f.id === i.id), r);
        vinc.value = null;
    } catch (e) { toastError(mensajeError(e)); }
}

const aplicando = ref(false);
async function aplicar() {
    const n = elegidos.value.length;
    if (!(await confirmar({ titulo: 'Aplicar la lista', texto: `Se van a actualizar el costo${props.lista.opciones.politica !== 'no' ? ' y el precio de venta' : ''} de ${n} productos. ¿Seguir?`, boton: 'Aplicar' }))) return;
    router.post(`/listas-precios/${props.lista.id}/aplicar`, { items: elegidos.value.map((i) => i.id) }, {
        onStart: () => (aplicando.value = true), onFinish: () => (aplicando.value = false),
    });
}
async function descartar() {
    if (await confirmar({ titulo: 'Descartar la lista', texto: 'No se cambia ningún precio y el archivo se borra.', boton: 'Descartar', peligro: true })) router.post(`/listas-precios/${props.lista.id}/descartar`);
}
const politica = computed(() => ({ margen: 'mantiene el margen', fijo: `margen fijo ${props.lista.opciones.margen}%`, no: 'no cambia el precio de venta' }[props.lista.opciones.politica]));
</script>

<template>
    <Encabezado :titulo="`Lista de ${lista.proveedor}`" :subtitulo="`${lista.archivo} · subida ${fecha(lista.created_at, true)}${lista.usuario ? ' por ' + lista.usuario : ''}`">
        <Boton href="/listas-precios" variante="fantasma" :icono="ArrowLeft">Listas</Boton>
        <Boton v-if="editable" :href="`/listas-precios/${lista.id}/columnas`" :icono="Settings2">Cambiar columnas</Boton>
    </Encabezado>

    <div v-if="lista.estado === 'aplicada'" class="mb-4 rounded-xl bg-green-50 px-4 py-3 text-sm text-entra dark:bg-green-950/40">Aplicada el {{ fecha(lista.aplicada_at, true) }}: se actualizaron {{ lista.aplicados }} productos.</div>
    <div v-else-if="lista.estado === 'descartada'" class="mb-4 rounded-xl bg-petroleo-100 px-4 py-3 text-sm dark:bg-petroleo-800">Esta lista se descartó: no se cambió ningún precio.</div>

    <div class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
        <div class="panel p-4"><p class="text-xs text-petroleo-500">Renglones</p><p class="cifra text-2xl">{{ cuenta.todos }}</p></div>
        <div class="panel p-4"><p class="flex items-center gap-1 text-xs text-sale"><TrendingUp class="size-3.5" />Suben</p><p class="cifra text-2xl">{{ cuenta.sube + cuenta.precio }}</p></div>
        <div class="panel p-4"><p class="flex items-center gap-1 text-xs text-entra"><TrendingDown class="size-3.5" />Bajan</p><p class="cifra text-2xl">{{ cuenta.baja }}</p></div>
        <div class="panel p-4"><p class="text-xs text-petroleo-500">Sin vincular</p><p class="cifra text-2xl" :class="{ 'text-cartel-700': cuenta.sin }">{{ cuenta.sin }}</p></div>
    </div>

    <section class="panel overflow-hidden">
        <div class="flex flex-wrap items-center gap-2 border-b border-petroleo-100 p-3 dark:border-petroleo-800">
            <button v-for="f in filtros" :key="f.id" type="button" class="rounded-full px-3 py-1.5 text-sm font-semibold transition-colors"
                :class="filtro === f.id ? 'bg-petroleo-800 text-white dark:bg-petroleo-600' : 'bg-petroleo-50 text-petroleo-700 hover:bg-petroleo-100 dark:bg-petroleo-800 dark:text-petroleo-200'"
                @click="filtro = f.id">{{ f.n }} <span class="opacity-70">{{ f.c }}</span></button>
            <div class="relative ml-auto w-full sm:w-64">
                <Search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-petroleo-300" />
                <input v-model="q" class="campo pl-9" placeholder="Buscar código o nombre" />
            </div>
        </div>
        <p class="px-4 pt-3 text-xs text-petroleo-500">Bonificación {{ lista.opciones.descuento || 0 }}% · IVA {{ lista.opciones.iva ? '+' + lista.opciones.iva + '%' : 'incluido' }} · precio de venta: {{ politica }}<template v-if="lista.opciones.politica !== 'no'">, redondeado a $ {{ lista.opciones.redondeo }}</template>.</p>

        <div class="overflow-x-auto">
            <table class="tabla">
                <thead>
                    <tr>
                        <th class="w-10"><input v-if="editable" type="checkbox" class="size-4 accent-petroleo-700" :checked="todosVisibles" aria-label="Marcar todos" @change="marcarVisibles($event.target.checked)" /></th>
                        <th>En la lista del proveedor</th><th>Producto</th><th class="text-right">Costo</th><th class="text-right">Precio de venta</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="i in visibles.slice(0, limite)" :key="i.id" :class="{ 'opacity-60': editable && i.producto && !i.aplicar }">
                        <td>
                            <input v-if="editable && i.producto" v-model="i.aplicar" type="checkbox" class="size-4 accent-petroleo-700" :aria-label="'Aplicar ' + i.codigo" />
                            <Check v-else-if="i.aplicado" class="size-4 text-entra" />
                        </td>
                        <td class="max-w-72"><p class="truncate">{{ i.descripcion || '—' }}</p><p class="text-xs text-petroleo-400">{{ i.codigo }} · fila {{ i.fila }}</p></td>
                        <td class="max-w-64">
                            <template v-if="i.producto">
                                <p class="truncate font-medium">{{ i.producto.nombre }} <Insignia v-if="i.producto.variantes" color="azul">con variantes</Insignia><Insignia v-if="!i.producto.activo">De baja</Insignia></p>
                                <p class="flex items-center gap-2 text-xs text-petroleo-400">{{ i.producto.codigo }}
                                    <button v-if="editable" type="button" class="inline-flex items-center gap-0.5 hover:text-sale" @click="vincular(i, null)"><Unlink class="size-3" />desvincular</button>
                                    <button v-if="editable" type="button" class="inline-flex items-center gap-0.5 hover:underline" @click="abrirVincular(i)"><Link2 class="size-3" />cambiar</button></p>
                            </template>
                            <Boton v-else-if="editable" tam="sm" :icono="Link2" @click="abrirVincular(i)">Vincular</Boton>
                            <span v-else class="text-sm text-petroleo-400">Sin vincular</span>
                        </td>
                        <td class="text-right whitespace-nowrap">
                            <template v-if="i.producto"><span class="text-petroleo-400">{{ plata(i.costo_anterior) }} →</span> <b>{{ plata(i.costo_nuevo) }}</b>
                                <span class="block text-xs font-semibold" :class="varCosto(i) > 0 ? 'text-sale' : varCosto(i) < 0 ? 'text-entra' : 'text-petroleo-400'">{{ pct(varCosto(i)) || '=' }}</span></template>
                            <b v-else>{{ plata(i.costo_nuevo) }}</b>
                        </td>
                        <td class="text-right whitespace-nowrap">
                            <template v-if="i.producto"><span class="text-petroleo-400">{{ plata(i.precio_anterior) }} →</span> <b class="cifra-suave">{{ plata(i.precio_nuevo) }}</b>
                                <span class="block text-xs text-petroleo-400">margen {{ i.costo_nuevo > 0 ? Math.round((i.precio_nuevo / i.costo_nuevo - 1) * 100) + '%' : '—' }}</span></template>
                            <span v-else class="text-petroleo-300">—</span>
                        </td>
                    </tr>
                    <tr v-if="!visibles.length"><td colspan="5" class="py-10 text-center text-petroleo-500">No hay renglones con ese filtro.</td></tr>
                </tbody>
            </table>
        </div>
        <button v-if="visibles.length > limite" type="button" class="w-full border-t border-petroleo-100 py-3 text-sm font-semibold text-petroleo-600 hover:bg-petroleo-50 dark:border-petroleo-800 dark:hover:bg-petroleo-800" @click="limite += 300">
            Mostrar más ({{ visibles.length - limite }} restantes)
        </button>
    </section>

    <div v-if="editable" class="sticky bottom-20 z-10 mt-4 flex flex-wrap items-center justify-end gap-2 rounded-2xl bg-white/95 p-3 shadow-lg backdrop-blur lg:bottom-4 dark:bg-petroleo-900/95">
        <span class="mr-auto text-sm"><b>{{ elegidos.length }}</b> productos para actualizar<template v-if="variacionElegidos !== null"> · costo promedio <b :class="variacionElegidos > 0 ? 'text-sale' : 'text-entra'">{{ pct(variacionElegidos) }}</b></template></span>
        <Boton variante="fantasma" :icono="Trash2" @click="descartar">Descartar</Boton>
        <Boton variante="exito" :icono="Check" :disabled="!elegidos.length" :cargando="aplicando" @click="aplicar">Aplicar {{ elegidos.length }} cambios</Boton>
    </div>

    <Modal :model-value="!!vinc" titulo="Vincular con un producto" ancho="max-w-lg" @update:model-value="(v) => !v && (vinc = null)">
        <template v-if="vinc">
            <p class="mb-3 rounded-xl bg-papel p-3 text-sm dark:bg-petroleo-950"><b>{{ vinc.codigo }}</b> · {{ vinc.descripcion }} · {{ plata(vinc.costo_nuevo) }}</p>
            <div class="relative"><Search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-petroleo-300" />
                <input v-model="buscar" class="campo pl-9" placeholder="Buscar producto por nombre o código" autofocus /></div>
            <ul class="mt-2 max-h-72 divide-y divide-petroleo-50 overflow-y-auto dark:divide-petroleo-800">
                <li v-for="p in resultados" :key="p.id">
                    <button type="button" class="flex w-full items-center gap-3 px-2 py-2.5 text-left hover:bg-petroleo-50 dark:hover:bg-petroleo-800" @click="vincular(vinc, p.id)">
                        <span class="min-w-0 flex-1"><span class="block truncate font-medium">{{ p.nombre }}</span><span class="text-xs text-petroleo-400">{{ p.codigo }}{{ p.tiene_variantes ? ' · con variantes (se actualizan todas)' : '' }}</span></span>
                        <span class="text-right text-sm"><span class="block text-petroleo-500">costo {{ plata(p.costo) }}</span><span class="block font-semibold">{{ plata(p.monto) }}</span></span>
                    </button>
                </li>
                <li v-if="buscar && !resultados.length" class="py-6 text-center text-sm text-petroleo-500">Sin resultados</li>
            </ul>
            <p class="mt-3 text-xs text-petroleo-500">El vínculo se recuerda: en las próximas listas de este proveedor el código {{ vinc.codigo }} se reconoce solo.</p>
        </template>
        <template #pie><Boton :icono="X" @click="vinc = null">Cerrar</Boton></template>
    </Modal>
</template>
