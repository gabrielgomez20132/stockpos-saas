<script setup>
import { computed } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { Receipt, Download, Lock, ShoppingCart, ChevronRight, DollarSign, TrendingUp, ShoppingBag, Clock, Banknote, CreditCard, QrCode, Landmark, ScrollText, TicketPercent, BookUser, FileText, Percent, Trophy, UserRound } from 'lucide-vue-next';
import Dato from '@/Components/Dato.vue';
import Encabezado from '@/Components/Encabezado.vue';
import Boton from '@/Components/Boton.vue';
import FiltroFechas from '@/Components/FiltroFechas.vue';
import Insignia from '@/Components/Insignia.vue';
import Vacio from '@/Components/Vacio.vue';
import { plata, fecha, hora, dia, cantidad, unidad } from '@/lib/formato';

const props = defineProps({ ventas: Array, filtro: Object, cajaCerrada: Boolean, totales: Object, resumen: Object, puedeExportar: Boolean });
const page = usePage();
const urlExport = (f) => '/ventas/exportar?' + new URLSearchParams({ formato: f, ...(props.filtro ?? {}) }).toString();
const vacia = (v) => !v.completada && Number(v.vacia) === 1;
const abiertas = computed(() => props.ventas.filter((v) => !v.completada && !vacia(v)));
// fiado total: la forma de pago ya dice FIADO, se muestra solo la etiqueta roja
const soloFiado = (v) => v.fiado > 0 && /fiad|cuenta/i.test(v.forma_pago ?? '');

const iconos = { efectivo: Banknote, debito: CreditCard, credito: CreditCard, qr: QrCode, transferencia: Landmark, cheque: ScrollText, dolares: DollarSign, vale: TicketPercent, fiado: BookUser };
const colores = { efectivo: '#1f7a4a', debito: '#236f77', credito: '#0e3b43', qr: '#73afb4', transferencia: '#f2b705', cheque: '#a87c00', dolares: '#6b7f84', vale: '#a9cfd2', fiado: '#b83a26' };
const totalMedios = computed(() => (props.resumen?.medios ?? []).reduce((a, m) => a + m.total, 0));
const pctMedio = (m) => (totalMedios.value ? Math.round((m.total / totalMedios.value) * 1000) / 10 : 0);
const pct = (v) => `${String(v).replace('.', ',')}%`;
const hh = (h) => `${String(h).padStart(2, '0')}:00 a ${String((h + 1) % 24).padStart(2, '0')}:00`;
</script>

<template>
    <Encabezado titulo="Ventas" :subtitulo="filtro ? `Del ${dia(filtro.desde)} al ${dia(filtro.hasta)}` : 'Ventas del turno abierto.'">
        <template v-if="puedeExportar && ventas.length">
            <Boton :href="urlExport('xlsx')" externo :icono="Download">Excel</Boton>
            <Boton :href="urlExport('csv')" externo :icono="Download">CSV</Boton>
        </template>
        <Boton v-if="!cajaCerrada" variante="principal" :icono="ShoppingCart" @click="router.post('/ventas')">Nueva venta</Boton>
    </Encabezado>

    <div class="mb-5"><FiltroFechas :filtro="filtro" url="/ventas" /></div>

    <div v-if="cajaCerrada && !filtro" class="panel">
        <Vacio :icono="Lock" titulo="La caja está cerrada" texto="Abrí la caja para empezar a vender, o buscá ventas viejas con las fechas de arriba.">
            <Boton href="/caja" variante="oscuro">Abrir caja</Boton>
        </Vacio>
    </div>

    <template v-else>
        <template v-if="resumen">
            <div class="mb-3 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <Dato titulo="Total vendido" :valor="plata(resumen.neto)" :icono="DollarSign" color="verde"
                    :nota="`${resumen.ventas} ${resumen.ventas === 1 ? 'venta cobrada' : 'ventas cobradas'}${resumen.descuentos > 0 ? ` · descuentos ${plata(resumen.descuentos)}` : ''}`" />
                <Dato v-if="resumen.ganancia !== null" titulo="Ganancia estimada" :valor="plata(resumen.ganancia)" tono="entra" :icono="TrendingUp" color="verde"
                    :nota="resumen.margen !== null ? `Margen ${pct(resumen.margen)} · vendido − costo − descuentos` : 'vendido − costo − descuentos'" />
                <Dato v-else titulo="Cobrado" :valor="plata(resumen.cobrado)" :icono="Banknote" color="verde" nota="Sin lo que quedó fiado" />
                <Dato titulo="Ticket promedio" :valor="plata(resumen.ticket)" :icono="ShoppingBag" :nota="`${resumen.productos} productos distintos vendidos`" />
                <Dato titulo="Sin cobrar" :valor="resumen.sinCobrar.ventas" :tono="resumen.sinCobrar.ventas ? 'sale' : 'normal'" :icono="Clock" color="ambar"
                    :nota="resumen.sinCobrar.ventas ? `${plata(resumen.sinCobrar.total)} abiertas, esperando cobro` : 'Todo cobrado'" />
            </div>

            <div v-if="resumen.ventas" class="mb-5 grid gap-3 lg:grid-cols-3">
                <section class="panel p-4">
                    <h2 class="mb-3 text-sm font-semibold">Por medio de pago</h2>
                    <ul class="space-y-2.5">
                        <li v-for="m in resumen.medios" :key="m.medio">
                            <div class="flex items-center gap-2 text-sm">
                                <component :is="iconos[m.medio]" class="size-4 shrink-0" :style="{ color: colores[m.medio] }" />
                                <span class="min-w-0 flex-1 truncate">{{ m.nombre }} <span class="text-xs text-petroleo-400">· {{ m.ventas }}</span></span>
                                <span class="font-semibold tabular-nums">{{ plata(m.total) }}</span>
                            </div>
                            <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-petroleo-100 dark:bg-petroleo-800"><div class="h-full rounded-full" :style="{ width: pctMedio(m) + '%', background: colores[m.medio] }" /></div>
                        </li>
                    </ul>
                    <p v-if="resumen.fiado > 0" class="mt-3 text-xs text-petroleo-500">Cobrado en el momento: <b>{{ plata(resumen.cobrado) }}</b> · quedó fiado {{ plata(resumen.fiado) }}</p>
                </section>

                <section class="panel p-4">
                    <h2 class="mb-3 flex items-center gap-1.5 text-sm font-semibold"><Trophy class="size-4 text-cartel-600" />Lo más vendido</h2>
                    <ol class="space-y-2 text-sm">
                        <li v-for="(t, k) in resumen.top" :key="k" class="flex items-baseline gap-2">
                            <span class="w-4 text-xs text-petroleo-400">{{ k + 1 }}</span>
                            <span class="min-w-0 flex-1 truncate">{{ t.nombre }} <span class="text-xs text-petroleo-500">· {{ cantidad(t.cantidad) }} {{ unidad(t.unidad, t.cantidad) }}</span></span>
                            <span class="font-semibold tabular-nums">{{ plata(t.total) }}</span>
                        </li>
                    </ol>
                </section>

                <section class="panel space-y-3 p-4 text-sm">
                    <div>
                        <h2 class="mb-2 flex items-center gap-1.5 font-semibold"><UserRound class="size-4 text-petroleo-400" />Por encargado</h2>
                        <ul class="space-y-1.5">
                            <li v-for="e in resumen.encargados" :key="e.nombre" class="flex items-baseline gap-2">
                                <span class="min-w-0 flex-1 truncate">{{ e.nombre }} <span class="text-xs text-petroleo-500">· {{ e.ventas }} {{ e.ventas === 1 ? 'venta' : 'ventas' }}</span></span>
                                <span class="font-semibold tabular-nums">{{ plata(e.total) }}</span>
                            </li>
                        </ul>
                    </div>
                    <dl class="space-y-1.5 border-t border-petroleo-100 pt-3 dark:border-petroleo-800">
                        <div v-if="resumen.pico" class="flex justify-between gap-2"><dt class="flex items-center gap-1.5 text-petroleo-500"><Clock class="size-4" />Hora con más ventas</dt><dd class="font-semibold">{{ hh(resumen.pico.hora) }} <span class="text-xs font-normal text-petroleo-500">({{ resumen.pico.ventas }})</span></dd></div>
                        <div class="flex justify-between gap-2"><dt class="text-petroleo-500">Primera / última venta</dt><dd>{{ filtro ? fecha(resumen.primera) + ' ' : '' }}{{ hora(resumen.primera) }} / {{ filtro ? fecha(resumen.ultima) + ' ' : '' }}{{ hora(resumen.ultima) }}</dd></div>
                        <div v-if="resumen.descuentos > 0" class="flex justify-between gap-2"><dt class="flex items-center gap-1.5 text-petroleo-500"><Percent class="size-4" />Descuentos</dt><dd class="text-sale">− {{ plata(resumen.descuentos) }}</dd></div>
                        <div v-if="resumen.facturadas.ventas" class="flex justify-between gap-2"><dt class="flex items-center gap-1.5 text-petroleo-500"><FileText class="size-4" />Facturadas AFIP</dt><dd>{{ resumen.facturadas.ventas }} · {{ plata(resumen.facturadas.total) }}</dd></div>
                    </dl>
                </section>
            </div>
        </template>

        <section class="panel overflow-hidden">
            <table v-if="ventas.length" class="tabla hidden md:table">
                <thead><tr><th>#</th><th>Cliente</th><th>Atendió</th><th>Pago</th><th>Fecha</th><th class="text-right">Total</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="v in ventas" :key="v.id" class="cursor-pointer" :class="{ 'bg-cartel-300/15': !v.completada && !vacia(v) }" @click="router.visit(`/ventas/${v.id}`)">
                        <td class="text-petroleo-400">{{ v.id }}</td>
                        <td>{{ v.cliente }}</td>
                        <td>{{ v.encargado }}</td>
                        <td>
                            <div class="flex flex-wrap items-center gap-1">
                                <Insignia v-if="vacia(v)" color="gris">Vacía</Insignia>
                                <Insignia v-else-if="!v.completada" color="amarillo">Sin cobrar</Insignia>
                                <Insignia v-else-if="!soloFiado(v)" color="gris">{{ v.forma_pago }}</Insignia>
                                <Insignia v-if="v.fiado > 0" color="rojo">Fiado</Insignia>
                                <Insignia v-if="v.idAfipNdc" color="rojo">NC</Insignia>
                                <Insignia v-else-if="v.idAfipFct" color="azul">Facturada</Insignia>
                            </div>
                        </td>
                        <td class="whitespace-nowrap text-petroleo-500">{{ fecha(v.created_at) }} {{ hora(v.created_at) }}</td>
                        <td class="cifra-suave text-right">{{ plata(v.monto - v.descuento) }}</td>
                        <td class="w-8 text-petroleo-300"><ChevronRight class="size-4" /></td>
                    </tr>
                </tbody>
            </table>
            <ul v-if="ventas.length" class="divide-y divide-petroleo-50 md:hidden dark:divide-petroleo-800">
                <li v-for="v in ventas" :key="v.id">
                    <Link :href="`/ventas/${v.id}`" class="flex items-center gap-3 px-4 py-3" :class="{ 'bg-cartel-300/15': !v.completada && !vacia(v) }">
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-medium">#{{ v.id }} · {{ v.cliente }}</p>
                            <p class="text-xs text-petroleo-500">{{ hora(v.created_at) }} · {{ !v.completada ? (vacia(v) ? 'Vacía' : 'Sin cobrar') : soloFiado(v) ? 'Fiado' : v.forma_pago + (v.fiado > 0 ? ' + fiado' : '') }}</p>
                        </div>
                        <span class="cifra text-xl">{{ plata(v.monto - v.descuento) }}</span>
                    </Link>
                </li>
            </ul>
            <Vacio v-if="!ventas.length" :icono="Receipt" :titulo="filtro ? 'No hay ventas en esas fechas' : 'Todavía no hay ventas en este turno'" />
        </section>
    </template>
</template>
