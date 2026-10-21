<script setup>
import { computed, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { Croissant, Search, Plus, Minus, Lock, CalendarDays, Flame, Trash2, ClipboardCheck } from 'lucide-vue-next';
import Encabezado from '@/Components/Encabezado.vue';
import Boton from '@/Components/Boton.vue';
import Dato from '@/Components/Dato.vue';
import Insignia from '@/Components/Insignia.vue';
import Vacio from '@/Components/Vacio.vue';
import http, { mensajeError } from '@/lib/http';
import { toastError } from '@/lib/toast';
import { confirmar } from '@/lib/confirmar';
import { plata, cantidad, unidad, numero, dia, hoyISO } from '@/lib/formato';

const props = defineProps({ fecha: String, esHoy: Boolean, produccion: Object, items: Array, historial: Array });
const cerrada = computed(() => !!props.produccion?.cerrada);
const abierta = computed(() => props.esHoy && !cerrada.value);
const verDia = (f) => router.get('/produccion', f && f !== hoyISO() ? { fecha: f } : {}, { preserveScroll: true });

// ------------------------------------------------ salio del horno
const q = ref('');
const resultados = ref([]);
const elegido = ref(null);
const restar = ref(false);
const horno = useForm({ id_producto: null, cantidad: '' });
const cantRef = ref(null);
let t;
watch(q, (v) => {
    clearTimeout(t);
    if (!v.trim() || elegido.value?.nombre === v) return (resultados.value = []);
    t = setTimeout(async () => {
        try { resultados.value = (await http.get('/produccion/productos', { params: { q: v } })).data; } catch (e) { toastError(mensajeError(e)); }
    }, 200);
});
function elegir(p, menos = false) {
    elegido.value = p;
    restar.value = menos;
    q.value = p.nombre;
    resultados.value = [];
    horno.cantidad = '';
    setTimeout(() => cantRef.value?.focus(), 30);
}
function enter() {
    const r = resultados.value;
    if (r.length === 1 || (r[0] && r[0].codigo === q.value.trim())) elegir(r[0]);
}
function hornear() {
    const c = numero(horno.cantidad);
    if (!elegido.value) return toastError('Elegí el producto.');
    if (!c || c <= 0) return toastError('Escribí cuánto salió del horno.');
    horno.transform(() => ({ id_producto: elegido.value.id, cantidad: restar.value ? -c : c })).post('/produccion/hornear', {
        preserveScroll: true,
        onSuccess: () => { elegido.value = null; q.value = ''; horno.reset(); restar.value = false; },
    });
}
const corregir = (i) => elegir({ id: i.id_producto, nombre: i.nombre, codigo: i.codigo, unidad: i.unidad, fraccionable: i.fraccionable }, true);

// ------------------------------------------------ cierre del dia
const cerrando = ref(false);
const cierre = ref({});
function empezarCierre() {
    cierre.value = Object.fromEntries(props.items.map((i) => [i.id_producto, { sobrante: String(Math.max(0, i.stock)).replace('.', ','), descartar: true }]));
    cerrando.value = true;
}
const enviando = ref(false);
async function cerrar() {
    const faltan = props.items.filter((i) => numero(cierre.value[i.id_producto]?.sobrante) === null);
    if (faltan.length) return toastError(`Anotá lo que sobró de ${faltan[0].nombre} (0 si no sobró nada).`);
    const descarta = props.items.filter((i) => cierre.value[i.id_producto].descartar && numero(cierre.value[i.id_producto].sobrante) > 0);
    if (!(await confirmar({ titulo: 'Cerrar el día', boton: 'Cerrar el día',
        texto: descarta.length ? `Lo que marcaste para descartar (${descarta.length} productos) sale del stock. Lo demás queda para mañana. Después no se puede cambiar.` : 'No se descarta nada: lo que sobró queda en stock para mañana. Después no se puede cambiar.' }))) return;
    router.post('/produccion/cerrar', {
        fecha: props.fecha,
        items: props.items.map((i) => ({ id_producto: i.id_producto, sobrante: numero(cierre.value[i.id_producto].sobrante), descartar: !!cierre.value[i.id_producto].descartar })),
    }, { preserveScroll: true, onStart: () => (enviando.value = true), onFinish: () => (enviando.value = false), onSuccess: () => (cerrando.value = false) });
}

// ------------------------------------------------ totales (en plata, para sumar panes con facturas)
const tot = computed(() => {
    const s = { horneado: 0, vendido: 0, descartado: 0, perdida: 0 };
    props.items.forEach((i) => {
        s.horneado += i.horneado * i.monto;
        s.vendido += Math.min(i.horneado, i.vendido) * i.monto;
        s.descartado += i.descartado * i.monto;
        s.perdida += i.descartado * i.costo;
    });
    s.desperdicio = s.horneado > 0 ? Math.round((s.descartado / s.horneado) * 1000) / 10 : 0;
    return s;
});
const u = (i, v) => unidad(i.unidad, v);
const pct = (v) => `${String(v).replace('.', ',')}%`;
</script>

<template>
    <Encabezado titulo="Producción del día" :subtitulo="esHoy ? `Hoy, ${dia(fecha)}` : `Día ${dia(fecha)}`">
        <label class="flex items-center gap-2 text-sm"><CalendarDays class="size-4 text-petroleo-400" />
            <input type="date" :value="fecha" :max="hoyISO()" class="campo w-40 py-2" aria-label="Ver otro día" @change="verDia($event.target.value)" /></label>
        <Boton v-if="!esHoy" variante="suave" @click="verDia(null)">Hoy</Boton>
    </Encabezado>

    <form v-if="abierta" class="panel mb-5 p-4" autocomplete="off" @submit.prevent="hornear">
        <h2 class="mb-3 flex items-center gap-2 font-semibold"><Flame class="size-5 text-cartel-600" />{{ restar ? 'Corregir (restar del stock)' : 'Salió del horno' }}</h2>
        <div class="flex flex-wrap items-end gap-2">
            <div class="relative min-w-0 flex-1 basis-64">
                <span class="etiqueta">Producto</span>
                <Search class="pointer-events-none absolute bottom-3.5 left-3 size-4 text-petroleo-300" />
                <input v-model="q" class="campo pl-9" placeholder="Buscá el producto (pan, medialunas, facturas...)" @keydown.enter.prevent="enter" @input="elegido = null" />
                <ul v-if="resultados.length" class="absolute inset-x-0 top-full z-20 mt-1 max-h-72 divide-y divide-petroleo-50 overflow-y-auto rounded-xl border border-petroleo-100 bg-white shadow-xl dark:divide-petroleo-800 dark:border-petroleo-700 dark:bg-petroleo-900">
                    <li v-for="p in resultados" :key="p.id">
                        <button type="button" class="flex w-full items-center gap-3 px-4 py-2.5 text-left hover:bg-petroleo-50 dark:hover:bg-petroleo-800" @click="elegir(p)">
                            <span class="min-w-0 flex-1"><span class="block truncate font-medium">{{ p.nombre }}</span><span class="text-xs text-petroleo-400">{{ p.codigo }}</span></span>
                            <span class="text-xs text-petroleo-500">hay {{ cantidad(p.quedan) }} {{ unidad(p.unidad, p.quedan) }}</span>
                        </button>
                    </li>
                </ul>
            </div>
            <label class="w-36"><span class="etiqueta">Cantidad{{ elegido?.unidad ? ` (${elegido.unidad})` : '' }}</span>
                <input ref="cantRef" v-model="horno.cantidad" inputmode="decimal" class="campo text-center text-lg font-semibold" :placeholder="elegido?.fraccionable ? '12,5' : '48'" /></label>
            <Boton tipo="submit" :variante="restar ? 'peligro' : 'oscuro'" :icono="restar ? Minus : Plus" :cargando="horno.processing" :disabled="!elegido">{{ restar ? 'Restar del stock' : 'Sumar al stock' }}</Boton>
            <Boton v-if="restar" variante="fantasma" @click="restar = false">Cancelar corrección</Boton>
        </div>
        <p v-if="horno.errors.cantidad" class="mt-1 text-sm text-sale">{{ horno.errors.cantidad }}</p>
        <p class="mt-2 text-xs text-petroleo-500">Cada tanda se suma al stock en el momento. Podés cargar varias tandas del mismo producto.</p>
    </form>
    <div v-else-if="esHoy && cerrada" class="panel mb-5 flex items-center gap-3 p-4 text-sm"><Lock class="size-5 text-petroleo-400" />El día ya está cerrado. Lo que se hornee ahora, cargalo con "Entra mercadería" en el producto.</div>

    <div v-if="items.length" class="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <Dato titulo="Salió del horno" :valor="plata(tot.horneado)" :icono="Flame" color="ambar" :nota="`${items.length} productos (a precio de venta)`" />
        <Dato titulo="Vendido" :valor="plata(tot.vendido)" tono="entra" :icono="ClipboardCheck" color="verde" :nota="tot.horneado ? `${pct(Math.round(tot.vendido / tot.horneado * 1000) / 10)} de lo horneado` : ''" />
        <Dato titulo="Descartado" :valor="cerrada ? plata(tot.descartado) : '—'" :tono="tot.descartado ? 'sale' : 'normal'" :icono="Trash2" color="petroleo" :nota="cerrada ? `Pérdida a costo: ${plata(tot.perdida)}` : 'Se ve al cerrar el día'" />
        <Dato titulo="Desperdicio" :valor="cerrada ? pct(tot.desperdicio) : '—'" :icono="Croissant" :nota="cerrada ? 'de lo horneado (en plata)' : 'Se ve al cerrar el día'" />
    </div>

    <section class="panel overflow-hidden">
        <div v-if="items.length" class="overflow-x-auto">
            <table class="tabla">
                <thead><tr>
                    <th>Producto</th><th class="text-right">Salió del horno</th><th class="text-right">Vendido</th>
                    <th v-if="!cerrada" class="text-right">En stock</th>
                    <template v-if="cerrando"><th class="w-32 text-right">Sobró</th><th class="w-24 text-center">Descartar</th></template>
                    <template v-else-if="cerrada"><th class="text-right">Sobró</th><th class="text-right">Descartado</th></template>
                    <th v-if="abierta && !cerrando" class="w-10"></th>
                </tr></thead>
                <tbody>
                    <tr v-for="i in items" :key="i.id_producto">
                        <td><span class="font-medium">{{ i.nombre }}</span>
                            <span class="block text-xs text-petroleo-400">{{ i.tandas.map((x) => `${x.hora} ${x.cantidad > 0 ? '+' : ''}${cantidad(x.cantidad)}`).join(' · ') }}</span></td>
                        <td class="text-right whitespace-nowrap">{{ cantidad(i.horneado) }} {{ u(i, i.horneado) }}</td>
                        <td class="text-right whitespace-nowrap">{{ cantidad(i.vendido) }} {{ u(i, i.vendido) }}</td>
                        <td v-if="!cerrada" class="text-right whitespace-nowrap" :class="{ 'text-sale': i.stock <= 0 }">{{ cantidad(i.stock) }} {{ u(i, i.stock) }}</td>
                        <template v-if="cerrando">
                            <td><input v-model="cierre[i.id_producto].sobrante" inputmode="decimal" class="campo py-1.5 text-right" :aria-label="`Sobró de ${i.nombre}`" /></td>
                            <td class="text-center"><input v-model="cierre[i.id_producto].descartar" type="checkbox" class="size-4 accent-petroleo-700" :aria-label="`Descartar lo que sobró de ${i.nombre}`" /></td>
                        </template>
                        <template v-else-if="cerrada">
                            <td class="text-right whitespace-nowrap">{{ i.sobrante !== null ? `${cantidad(i.sobrante)} ${u(i, i.sobrante)}` : '—' }}</td>
                            <td class="text-right whitespace-nowrap" :class="{ 'font-semibold text-sale': i.descartado > 0 }">{{ i.descartado > 0 ? `${cantidad(i.descartado)} ${u(i, i.descartado)}` : '—' }}</td>
                        </template>
                        <td v-if="abierta && !cerrando"><Boton tam="sm" variante="fantasma" :icono="Minus" :aria-label="`Corregir ${i.nombre}`" title="Corregir (restar)" @click="corregir(i)" /></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <Vacio v-else :icono="Croissant" :titulo="esHoy ? 'Todavía no cargaste la producción de hoy' : 'Ese día no tiene producción cargada'" :texto="esHoy ? 'Buscá el producto arriba y cargá lo que salió del horno: entra al stock al instante.' : ''" />

        <div v-if="items.length && !cerrada && produccion" class="flex flex-wrap items-center justify-end gap-2 border-t border-petroleo-100 p-4 dark:border-petroleo-800">
            <template v-if="cerrando">
                <p class="mr-auto text-xs text-petroleo-500">Contá lo que sobró. Lo marcado en "Descartar" sale del stock (se tira o se dona); lo demás queda para mañana.</p>
                <Boton variante="fantasma" @click="cerrando = false">Cancelar</Boton>
                <Boton variante="oscuro" :icono="Lock" :cargando="enviando" @click="cerrar">Confirmar cierre</Boton>
            </template>
            <Boton v-else variante="oscuro" :icono="Lock" @click="empezarCierre">Cerrar el día</Boton>
        </div>
    </section>

    <section v-if="historial.length" class="panel mt-5 overflow-hidden">
        <h2 class="border-b border-petroleo-100 px-5 py-3.5 font-semibold dark:border-petroleo-800">Últimos días</h2>
        <div class="overflow-x-auto">
            <table class="tabla">
                <thead><tr><th>Día</th><th class="text-right">Salió del horno</th><th class="text-right">Vendido</th><th class="text-right">Descartado</th><th class="text-right">Pérdida a costo</th><th class="text-right">Desperdicio</th></tr></thead>
                <tbody>
                    <tr v-for="h in historial" :key="h.fecha" class="cursor-pointer hover:bg-petroleo-50/60 dark:hover:bg-petroleo-800/40" :class="{ 'bg-petroleo-50/60 dark:bg-petroleo-800/40': h.fecha === fecha }" @click="verDia(h.fecha)">
                        <td class="font-medium">{{ dia(h.fecha) }} <Insignia v-if="!h.cerrada" color="amarillo" class="ml-1">Abierto</Insignia></td>
                        <td class="text-right">{{ plata(h.horneado) }}</td>
                        <td class="text-right text-entra">{{ plata(h.vendido) }}</td>
                        <td class="text-right">{{ h.cerrada ? plata(h.descartado) : '—' }}</td>
                        <td class="text-right text-sale">{{ h.cerrada ? plata(h.perdida) : '—' }}</td>
                        <td class="text-right font-semibold" :class="{ 'text-sale': h.desperdicio >= 10 }">{{ h.cerrada ? pct(h.desperdicio) : '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p class="px-5 py-3 text-xs text-petroleo-500">Todo en plata (precio de venta) para poder sumar productos por kilo y por unidad. Tocá un día para ver el detalle.</p>
    </section>
</template>
