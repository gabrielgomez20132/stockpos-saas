<script setup>
import { computed, onMounted, ref } from 'vue';
import VueApexCharts from 'vue3-apexcharts';
import { ChartColumn, DollarSign, TrendingUp, Wallet, ShoppingBag, CreditCard, Banknote, QrCode, Landmark, ScrollText, TicketPercent, BookUser, Receipt } from 'lucide-vue-next';
import Encabezado from '@/Components/Encabezado.vue';
import Boton from '@/Components/Boton.vue';
import Dato from '@/Components/Dato.vue';
import Vacio from '@/Components/Vacio.vue';
import http, { mensajeError } from '@/lib/http';
import { toastError } from '@/lib/toast';
import { plata, cantidad, unidad, dia, hoyISO } from '@/lib/formato';

const props = defineProps({ maxDias: Number });
const periodo = ref('semana');
const inicio = ref(hoyISO(-14));
const fin = ref(hoyISO());
const datos = ref(null);
const sucursal = ref(null); // null = la actual; 'todas' o un id
const cargando = ref(false);
const oscuro = () => document.documentElement.classList.contains('dark');

async function cargar(p = periodo.value) {
    periodo.value = p;
    cargando.value = true;
    try {
        const params = p === 'personalizado' ? { periodo: p, inicio: inicio.value, fin: fin.value } : { periodo: p };
        if (sucursal.value) params.sucursal = sucursal.value;
        datos.value = (await http.get('/reportes/datos', { params })).data;
        if (!sucursal.value && datos.value.sucursal) sucursal.value = datos.value.sucursal;
    } catch (e) { toastError(mensajeError(e)); } finally { cargando.value = false; }
}
onMounted(() => cargar());

const base = () => ({
    chart: { fontFamily: 'Archivo Variable, sans-serif', toolbar: { show: false }, foreColor: oscuro() ? '#a9cfd2' : '#3f5a60', background: 'transparent' },
    theme: { mode: oscuro() ? 'dark' : 'light' },
    grid: { borderColor: oscuro() ? '#12484f' : '#e3eceb' },
    dataLabels: { enabled: false },
    tooltip: { y: { formatter: (v) => plata(v) } },
});
const serieDia = computed(() => [
    { name: 'Vendido', data: datos.value?.porDia.map((d) => d.ingreso) ?? [] },
    { name: 'Ganancia', data: datos.value?.porDia.map((d) => d.ganancia) ?? [] },
]);
const opcDia = computed(() => ({
    ...base(), colors: ['#175960', '#f2b705'],
    plotOptions: { bar: { borderRadius: 4, columnWidth: '60%' } },
    xaxis: { categories: datos.value?.porDia.map((d) => dia(d.fecha).slice(0, 5)) ?? [] },
    yaxis: { labels: { formatter: (v) => plata(v) } },
    legend: { position: 'top', horizontalAlign: 'left' },
}));
const opcSuc = computed(() => ({
    ...base(), colors: ['#175960', '#f2b705'],
    plotOptions: { bar: { borderRadius: 4, horizontal: true, barHeight: '60%' } },
    xaxis: { categories: datos.value?.sucursales?.map((s) => s.nombre) ?? [], labels: { formatter: (v) => plata(v) } },
    legend: { position: 'top', horizontalAlign: 'left' },
}));
const serieSuc = computed(() => [
    { name: 'Vendido', data: datos.value?.sucursales?.map((s) => s.ingreso) ?? [] },
    { name: 'Ganancia', data: datos.value?.sucursales?.map((s) => s.ganancia) ?? [] },
]);
const opcMedios = computed(() => ({
    ...base(), labels: datos.value?.medios.map((m) => m.medio) ?? [],
    colors: (datos.value?.pagos ?? []).filter((p) => p.total > 0).map((p) => colores[p.medio]),
    legend: { position: 'bottom' }, stroke: { width: 0 },
    plotOptions: { pie: { donut: { size: '62%' } } },
}));

// desglose de pagos
const iconos = { efectivo: Banknote, debito: CreditCard, credito: CreditCard, qr: QrCode, transferencia: Landmark, cheque: ScrollText, dolares: DollarSign, vale: TicketPercent, fiado: BookUser };
const colores = { efectivo: '#1f7a4a', debito: '#236f77', credito: '#0e3b43', qr: '#73afb4', transferencia: '#f2b705', cheque: '#a87c00', dolares: '#6b7f84', vale: '#a9cfd2', fiado: '#b83a26' };
const pctEfectivo = computed(() => datos.value?.pagos?.find((p) => p.medio === 'efectivo')?.porcentaje ?? 0);
const pct = (v) => `${String(v).replace('.', ',')}%`;
</script>

<template>
    <Encabezado titulo="Reportes" :subtitulo="datos ? `Del ${dia(datos.desde)} al ${dia(datos.hasta)} · ventas cobradas` : ''" />

    <div class="mb-5 flex flex-wrap items-end gap-2">
        <div class="flex rounded-xl bg-petroleo-100/70 p-1 dark:bg-petroleo-900">
            <button v-for="p in [['semana', 'Última semana'], ['mes', 'Último mes']]" :key="p[0]" class="rounded-lg px-3.5 py-2 text-sm"
                :class="periodo === p[0] ? 'bg-white font-semibold shadow-sm dark:bg-petroleo-700' : 'text-petroleo-600 dark:text-petroleo-300'" @click="cargar(p[0])">{{ p[1] }}</button>
        </div>
        <form class="flex flex-wrap items-end gap-2" @submit.prevent="cargar('personalizado')">
            <label><span class="etiqueta">Desde</span><input v-model="inicio" type="date" class="campo w-40" required /></label>
            <label><span class="etiqueta">Hasta</span><input v-model="fin" type="date" :min="inicio" class="campo w-40" required /></label>
            <Boton tipo="submit" :variante="periodo === 'personalizado' ? 'oscuro' : 'suave'" :cargando="cargando">Ver rango</Boton>
        </form>
        <label v-if="datos?.sucursales" class="sm:ml-auto"><span class="etiqueta">Sucursal</span>
            <select v-model="sucursal" class="campo w-48" @change="cargar()">
                <option value="todas">Todas las sucursales</option>
                <option v-for="s in datos.sucursales" :key="s.id" :value="String(s.id)">{{ s.nombre }}</option>
            </select></label>
        <p class="w-full text-xs text-petroleo-500">El rango personalizado puede ser de hasta {{ maxDias }} días.</p>
    </div>

    <template v-if="datos">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <Dato titulo="Total vendido" :valor="plata(datos.totales.ingreso)" :icono="DollarSign" color="verde"
                :nota="datos.totales.descuentos > 0 ? `Descuentos: ${plata(datos.totales.descuentos)}` : 'Sin descuentos'" />
            <Dato titulo="Ganancia bruta" :valor="plata(datos.totales.ganancia)" tono="entra" :icono="TrendingUp" color="verde"
                :nota="`Margen: ${pct(datos.totales.margen)} · costo ${plata(datos.totales.costo)}`" />
            <Dato titulo="Ventas en efectivo" :valor="pct(pctEfectivo)" :icono="Wallet" color="ambar"
                :nota="`${pct(Math.round((100 - pctEfectivo) * 10) / 10)} con otros medios`" />
            <Dato titulo="Total de ventas" :valor="datos.totales.ventas" :icono="ShoppingBag" :nota="`Ticket prom.: ${plata(datos.totales.ticket)}`" />
        </div>

        <div class="mt-5 grid gap-5 lg:grid-cols-[1fr_22rem]">
            <section class="panel p-5">
                <h2 class="mb-4 flex items-center gap-2 font-semibold"><CreditCard class="size-5 text-petroleo-400" />Desglose de pagos</h2>
                <ul class="space-y-3.5">
                    <li v-for="p in datos.pagos" :key="p.medio" :class="{ 'opacity-45': !p.total }">
                        <div class="flex items-center gap-2 text-sm">
                            <component :is="iconos[p.medio]" class="size-4 shrink-0" :style="{ color: colores[p.medio] }" />
                            <span class="min-w-0 flex-1 truncate">{{ p.nombre }}</span>
                            <span class="hidden text-xs text-petroleo-500 sm:inline">{{ p.ventas }} {{ p.ventas === 1 ? 'venta' : 'ventas' }}</span>
                            <span class="w-28 text-right font-semibold tabular-nums">{{ plata(p.total) }}</span>
                        </div>
                        <div class="mt-1.5 flex items-center gap-3">
                            <div class="h-2 flex-1 overflow-hidden rounded-full bg-petroleo-100 dark:bg-petroleo-800" role="progressbar" :aria-valuenow="p.porcentaje" aria-valuemin="0" aria-valuemax="100" :aria-label="p.nombre">
                                <div class="h-full rounded-full transition-[width] duration-500" :style="{ width: p.porcentaje + '%', background: colores[p.medio] }" />
                            </div>
                            <span class="w-12 text-right text-xs text-petroleo-500 tabular-nums">{{ pct(p.porcentaje) }}</span>
                        </div>
                    </li>
                </ul>
                <dl class="mt-5 space-y-2 border-t border-petroleo-100 pt-4 text-sm dark:border-petroleo-800">
                    <div class="flex justify-between gap-2"><dt class="flex items-center gap-2 text-petroleo-500"><Receipt class="size-4" />Total cobrado</dt><dd class="font-bold tabular-nums">{{ plata(datos.totales.cobrado) }}</dd></div>
                    <div class="flex justify-between gap-2"><dt class="flex items-center gap-2 text-petroleo-500"><ShoppingBag class="size-4" />Ticket promedio</dt><dd class="font-semibold tabular-nums">{{ plata(datos.totales.ticket) }}</dd></div>
                </dl>
                <p class="mt-2 text-xs text-petroleo-500">Una venta pagada con dos medios cuenta en los dos. Cuenta corriente es lo que quedó fiado.</p>
            </section>
            <section class="panel p-4"><h2 class="mb-2 font-semibold">Cómo pagaron</h2>
                <VueApexCharts v-if="datos.medios.length" type="donut" height="320" :options="opcMedios" :series="datos.medios.map((m) => m.total)" />
                <p v-else class="py-20 text-center text-sm text-petroleo-500">Sin cobros en el período.</p>
            </section>
        </div>

        <section v-if="datos.porDia.length" class="panel mt-5 p-4"><h2 class="mb-2 font-semibold">Por día</h2><VueApexCharts type="bar" height="320" :options="opcDia" :series="serieDia" /></section>

        <section v-if="datos.sucursales?.length > 1" class="panel mt-5 overflow-hidden">
            <h2 class="border-b border-petroleo-100 px-5 py-3.5 font-semibold dark:border-petroleo-800">Comparación de sucursales</h2>
            <div class="grid gap-4 p-4 lg:grid-cols-[1fr_1.1fr]">
                <VueApexCharts type="bar" :height="Math.max(180, datos.sucursales.length * 70)" :options="opcSuc" :series="serieSuc" />
                <div class="overflow-x-auto">
                    <table class="tabla">
                        <thead><tr><th>Sucursal</th><th class="text-right">Ventas</th><th class="text-right">Vendido</th><th class="text-right">Ticket prom.</th><th class="text-right">Ganancia</th></tr></thead>
                        <tbody>
                            <tr v-for="s in datos.sucursales" :key="s.id">
                                <td class="font-medium">{{ s.nombre }}</td><td class="text-right">{{ s.ventas }}</td><td class="text-right">{{ plata(s.ingreso) }}</td>
                                <td class="text-right text-petroleo-500">{{ plata(s.ticket) }}</td><td class="cifra-suave text-right text-entra">{{ plata(s.ganancia) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <section v-if="datos.top.length" class="panel mt-5 overflow-hidden">
            <h2 class="border-b border-petroleo-100 px-5 py-3.5 font-semibold dark:border-petroleo-800">Los 15 más vendidos</h2>
            <table class="tabla">
                <thead><tr><th class="w-10">#</th><th>Producto</th><th class="text-right">Cantidad</th><th class="hidden text-right sm:table-cell">Vendido</th><th class="text-right">Ganancia</th></tr></thead>
                <tbody>
                    <tr v-for="(t, k) in datos.top" :key="k">
                        <td class="text-petroleo-400">{{ k + 1 }}</td>
                        <td>{{ t.nombre }}</td>
                        <td class="text-right whitespace-nowrap">{{ cantidad(t.cantidad) }} {{ unidad(t.unidad, t.cantidad) }}</td>
                        <td class="hidden text-right sm:table-cell">{{ plata(t.ingreso) }}</td>
                        <td class="cifra-suave text-right text-entra">{{ plata(t.ganancia) }}</td>
                    </tr>
                </tbody>
            </table>
        </section>
        <div v-if="!datos.porDia.length" class="panel mt-5"><Vacio :icono="ChartColumn" titulo="No hay ventas en este período" /></div>
    </template>
</template>
