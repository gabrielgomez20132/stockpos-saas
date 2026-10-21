<script setup>
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Search, Undo2, ArrowLeft, Minus, Plus, CheckCheck } from 'lucide-vue-next';
import Encabezado from '@/Components/Encabezado.vue';
import Boton from '@/Components/Boton.vue';
import Campo from '@/Components/Campo.vue';
import Vacio from '@/Components/Vacio.vue';
import http, { mensajeError } from '@/lib/http';
import { toastError } from '@/lib/toast';
import { plata, cantidad, unidad, fecha, numero } from '@/lib/formato';

const props = defineProps({ ventas: Array });
const buscar = ref('');
const venta = ref(null);
const cants = ref({});
const cargando = ref(false);

// busca por Nº de venta, cliente o fecha (20/10, 20/10/26, 20/10/2026 o 2026-10-20)
function textoFecha(f) {
    const d = new Date(f);
    if (isNaN(d)) return '';
    const dd = String(d.getDate()).padStart(2, '0'), mm = String(d.getMonth() + 1).padStart(2, '0'), yyyy = String(d.getFullYear());
    return [`${dd}/${mm}/${yyyy}`, `${dd}/${mm}/${yyyy.slice(2)}`, `${d.getDate()}/${d.getMonth() + 1}/${yyyy}`, `${yyyy}-${mm}-${dd}`, `${dd}-${mm}-${yyyy}`].join(' ');
}
const filtradas = computed(() => {
    const q = buscar.value.trim().toLowerCase();
    if (!q) return props.ventas.slice(0, 40);
    const num = q.replace(/^#|^n[ºo°]?\s*/i, '');
    if (/^\d+$/.test(num)) return props.ventas.filter((v) => String(v.id).startsWith(num)).slice(0, 40);
    return props.ventas.filter((v) => v.cliente.toLowerCase().includes(q) || textoFecha(v.fecha).includes(q)).slice(0, 40);
});

async function elegir(id) {
    cargando.value = true;
    try {
        venta.value = (await http.get(`/devoluciones/venta/${id}`)).data;
        cants.value = Object.fromEntries(venta.value.productos.map((p) => [p.id_producto, '']));
    } catch (e) { toastError(mensajeError(e)); } finally { cargando.value = false; }
}

const total = computed(() => venta.value ? venta.value.productos.reduce((a, p) => a + (numero(cants.value[p.id_producto]) || 0) * p.precio_devolucion, 0) : 0);
function paso(p) { return p.fraccionable ? 0.1 : 1; }
function mover(p, d) {
    const v = Math.round(((numero(cants.value[p.id_producto]) || 0) + d * paso(p)) * 1000) / 1000;
    cants.value[p.id_producto] = Math.min(p.disponible, Math.max(0, v)) || '';
}
function todo(p) { cants.value[p.id_producto] = p.disponible; }
function todoTodo() { venta.value.productos.forEach((p) => p.disponible > 0 && todo(p)); }
const elegidos = computed(() => venta.value ? venta.value.productos.filter((p) => (numero(cants.value[p.id_producto]) || 0) > 0).length : 0);
const form = useForm({ id_order: null, productos: [], observaciones: '' });
function guardar() {
    form.transform((d) => ({
        ...d, id_order: venta.value.id,
        productos: venta.value.productos.map((p) => ({ id_producto: p.id_producto, cantidad: numero(cants.value[p.id_producto]) || 0 })).filter((p) => p.cantidad > 0),
    })).post('/devoluciones');
}
</script>

<template>
    <Encabezado titulo="Nueva devolución" :subtitulo="venta ? `Venta #${venta.id} · ${venta.cliente} · ${fecha(venta.fecha)}` : 'Elegí la venta de la que vuelven los productos (últimos 60 días).'">
        <Boton v-if="venta" variante="fantasma" :icono="ArrowLeft" @click="venta = null">Otra venta</Boton>
    </Encabezado>

    <section v-if="!venta" class="panel overflow-hidden">
        <div class="border-b border-petroleo-100 p-4 dark:border-petroleo-800">
            <div class="relative max-w-md">
                <Search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-petroleo-300" />
                <input v-model="buscar" class="campo pl-9" placeholder="Buscar por Nº de venta, cliente o fecha (ej: 20/10)" autofocus />
            </div>
        </div>
        <ul v-if="filtradas.length" class="divide-y divide-petroleo-50 dark:divide-petroleo-800">
            <li v-for="v in filtradas" :key="v.id">
                <button class="flex w-full items-center gap-4 px-5 py-3 text-left hover:bg-petroleo-50/60 disabled:opacity-50 dark:hover:bg-petroleo-800/40" :disabled="cargando" @click="elegir(v.id)">
                    <span class="w-14 text-petroleo-400">#{{ v.id }}</span>
                    <span class="flex-1">{{ v.cliente }}</span>
                    <span class="text-sm text-petroleo-500">{{ fecha(v.fecha, true) }}</span>
                    <span class="cifra-suave w-24 text-right">{{ plata(v.total) }}</span>
                </button>
            </li>
        </ul>
        <Vacio v-else :icono="Search" titulo="No hay ventas que coincidan" />
    </section>

    <form v-else class="grid gap-5 lg:grid-cols-[1fr_22rem]" @submit.prevent="guardar">
        <section class="panel overflow-hidden">
            <p v-if="venta.descuento > 0" class="border-b border-petroleo-100 px-5 py-3 text-sm text-petroleo-600 dark:border-petroleo-800">La venta tuvo un descuento de {{ plata(venta.descuento) }}: se devuelve el precio realmente pagado.</p>
            <div class="flex items-center justify-between gap-3 border-b border-petroleo-100 px-5 py-3 dark:border-petroleo-800">
                <p class="text-sm text-petroleo-600 dark:text-petroleo-300">Marcá cuántos de cada producto vuelven. El stock se repone y se genera un vale para el cliente.</p>
                <Boton tam="sm" class="shrink-0 whitespace-nowrap" :icono="CheckCheck" @click="todoTodo">Devolver todo</Boton>
            </div>
            <ul class="divide-y divide-petroleo-50 dark:divide-petroleo-800">
                <li v-for="p in venta.productos" :key="p.id_producto" class="flex flex-wrap items-center gap-3 px-5 py-3" :class="{ 'bg-petroleo-50 dark:bg-petroleo-800/40': numero(cants[p.id_producto]) > 0, 'opacity-50': p.disponible <= 0 }">
                    <div class="min-w-0 flex-1 basis-48">
                        <p class="font-medium">{{ p.nombre }}</p>
                        <p class="text-xs text-petroleo-500">Vendido {{ cantidad(p.vendido) }} {{ unidad(p.unidad, p.vendido) }} · ya devuelto {{ cantidad(p.devuelto) }} · {{ plata(p.precio_devolucion, { centavos: true }) }} c/u</p>
                        <p v-if="p.disponible <= 0" class="text-xs font-semibold text-sale">Ya se devolvió todo</p>
                    </div>
                    <div v-if="p.disponible > 0" class="flex items-center gap-2">
                        <div class="flex items-center rounded-xl border border-petroleo-200 dark:border-petroleo-700">
                            <button type="button" class="grid size-10 place-items-center text-petroleo-600 hover:bg-petroleo-50 dark:hover:bg-petroleo-800" aria-label="Menos" @click="mover(p, -1)"><Minus class="size-4" /></button>
                            <input v-model="cants[p.id_producto]" inputmode="decimal" placeholder="0" class="w-14 bg-transparent text-center font-semibold outline-none" :aria-label="`Cantidad a devolver de ${p.nombre}`" />
                            <button type="button" class="grid size-10 place-items-center text-petroleo-600 hover:bg-petroleo-50 dark:hover:bg-petroleo-800" aria-label="Más" @click="mover(p, 1)"><Plus class="size-4" /></button>
                        </div>
                        <Boton tam="sm" variante="fantasma" @click="todo(p)">Todo ({{ cantidad(p.disponible) }})</Boton>
                    </div>
                </li>
            </ul>
        </section>
        <aside class="panel h-fit space-y-4 p-5">
            <div class="flex items-end justify-between"><span class="text-sm text-petroleo-500">Vale por<br><span class="text-xs">{{ elegidos ? `${elegidos} producto${elegidos > 1 ? 's' : ''} · vence en 30 días` : 'Elegí qué se devuelve' }}</span></span><span class="cifra text-4xl">{{ plata(total) }}</span></div>
            <Campo v-model="form.observaciones" label="Motivo (opcional)" tipo="textarea" maxlength="500" />
            <p v-if="form.hasErrors" class="text-sm text-sale">{{ Object.values(form.errors)[0] }}</p>
            <Boton tipo="submit" variante="oscuro" class="w-full" :icono="Undo2" :disabled="total <= 0" :cargando="form.processing">Registrar y generar vale</Boton>
        </aside>
    </form>
</template>
