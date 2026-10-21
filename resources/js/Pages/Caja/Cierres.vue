<script setup>
import { ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { Archive, Search, ChevronRight } from 'lucide-vue-next';
import Encabezado from '@/Components/Encabezado.vue';
import CajaNav from '@/Components/CajaNav.vue';
import FiltroFechas from '@/Components/FiltroFechas.vue';
import Paginacion from '@/Components/Paginacion.vue';
import Vacio from '@/Components/Vacio.vue';
import Dato from '@/Components/Dato.vue';
import { plata, fecha, hora, dia } from '@/lib/formato';

const props = defineProps({ cierres: Object, filtro: Object, quien: String, totales: Object });
const quien = ref(props.quien ?? '');
let t;
watch(quien, (v) => {
    clearTimeout(t);
    t = setTimeout(() => router.get('/caja/cierres', { ...(props.filtro ?? {}), quien: v || undefined }, { preserveState: true, replace: true }), 350);
});

function duracion(a, c) {
    if (!a || !c) return '—';
    const min = Math.round((new Date(String(c).replace(' ', 'T')) - new Date(String(a).replace(' ', 'T'))) / 60000);
    if (min < 0) return '—';
    const d = Math.floor(min / 1440), h = Math.floor((min % 1440) / 60), m = min % 60;
    return d ? `${d} d ${h} h` : `${h} h ${String(m).padStart(2, '0')} min`;
}
</script>

<template>
    <Encabezado titulo="Cierres de caja" :subtitulo="filtro ? `Cerrados del ${dia(filtro.desde)} al ${dia(filtro.hasta)}` : 'Historial de todos los turnos cerrados.'" />
    <CajaNav />

    <div class="mb-4 flex flex-wrap items-end gap-3">
        <FiltroFechas :filtro="filtro" url="/caja/cierres" texto-limpiar="Ver todos" />
        <label class="w-full sm:w-64">
            <span class="etiqueta">Abrió o cerró</span>
            <div class="relative">
                <Search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-petroleo-300" />
                <input v-model="quien" class="campo pl-9" placeholder="Nombre del encargado" />
            </div>
        </label>
    </div>

    <div v-if="totales.cierres" class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <Dato titulo="Cierres" :valor="String(totales.cierres)" />
        <Dato titulo="Ventas cobradas" :valor="String(totales.ventas)" />
        <Dato titulo="Efectivo al cierre" :valor="plata(totales.efectivo)" nota="Suma de lo que había en el cajón" />
        <Dato titulo="Total de los turnos" :valor="plata(totales.total)" />
    </div>

    <section class="panel overflow-hidden">
        <table v-if="cierres.data.length" class="tabla hidden lg:table">
            <thead><tr><th>#</th><th>Apertura</th><th>Cierre</th><th>Duración</th><th class="text-right">Ventas</th><th class="text-right">Efectivo</th><th class="text-right">Total turno</th><th></th></tr></thead>
            <tbody>
                <tr v-for="c in cierres.data" :key="c.id" class="cursor-pointer" @click="router.visit(`/caja/cierres/${c.id}`)">
                    <td class="text-petroleo-400">{{ c.id }}</td>
                    <td><span class="whitespace-nowrap">{{ c.apertura ? fecha(c.apertura) + ' ' + hora(c.apertura) : '—' }}</span><br><span class="text-xs text-petroleo-500">{{ c.abierto_por || '—' }}</span></td>
                    <td><span class="whitespace-nowrap">{{ fecha(c.cierre) }} {{ hora(c.cierre) }}</span><br><span class="text-xs text-petroleo-500">{{ c.cerrado_por }}</span></td>
                    <td class="whitespace-nowrap text-petroleo-500">{{ duracion(c.apertura, c.cierre) }}</td>
                    <td class="text-right">{{ c.cant_ventas }}</td>
                    <td class="cifra-suave text-right">{{ plata(c.total_efectivo) }}</td>
                    <td class="cifra-suave text-right">{{ plata(c.total_turno) }}</td>
                    <td class="w-8 text-petroleo-300"><ChevronRight class="size-4" /></td>
                </tr>
            </tbody>
        </table>
        <ul v-if="cierres.data.length" class="divide-y divide-petroleo-50 lg:hidden dark:divide-petroleo-800">
            <li v-for="c in cierres.data" :key="c.id">
                <Link :href="`/caja/cierres/${c.id}`" class="flex items-center justify-between gap-3 px-4 py-3">
                    <span class="min-w-0 text-sm">
                        <span class="font-semibold">#{{ c.id }} · {{ c.cant_ventas }} ventas</span><br>
                        <span class="text-petroleo-500">Abrió {{ c.apertura ? fecha(c.apertura) + ' ' + hora(c.apertura) : '—' }} · {{ c.abierto_por || '—' }}</span><br>
                        <span class="text-petroleo-500">Cerró {{ fecha(c.cierre) }} {{ hora(c.cierre) }} · {{ c.cerrado_por }}</span>
                    </span>
                    <span class="cifra text-xl">{{ plata(c.total_turno) }}</span>
                </Link>
            </li>
        </ul>
        <Vacio v-if="!cierres.data.length" :icono="Archive" :titulo="filtro || quien ? 'No hay cierres con esos filtros' : 'Todavía no se cerró ninguna caja'" />
    </section>
    <Paginacion :datos="cierres" />
</template>
