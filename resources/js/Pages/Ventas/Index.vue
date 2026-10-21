<script setup>
import { computed } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { Receipt, Download, Lock, ShoppingCart, ChevronRight } from 'lucide-vue-next';
import Encabezado from '@/Components/Encabezado.vue';
import Boton from '@/Components/Boton.vue';
import FiltroFechas from '@/Components/FiltroFechas.vue';
import Insignia from '@/Components/Insignia.vue';
import Vacio from '@/Components/Vacio.vue';
import { plata, fecha, hora, dia } from '@/lib/formato';

const props = defineProps({ ventas: Array, filtro: Object, cajaCerrada: Boolean, totales: Object, puedeExportar: Boolean });
const page = usePage();
const urlExport = (f) => '/ventas/exportar?' + new URLSearchParams({ formato: f, ...(props.filtro ?? {}) }).toString();
const abiertas = computed(() => props.ventas.filter((v) => !v.completada));
// fiado total: la forma de pago ya dice FIADO, se muestra solo la etiqueta roja
const soloFiado = (v) => v.fiado > 0 && /fiad|cuenta/i.test(v.forma_pago ?? '');
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
        <div v-if="totales" class="mb-4 flex flex-wrap items-baseline gap-x-6 gap-y-1">
            <p><span class="cifra text-3xl">{{ plata(totales.total) }}</span> <span class="text-sm text-petroleo-500">en {{ totales.cantidad }} ventas cobradas</span></p>
            <p v-if="abiertas.length && !filtro" class="text-sm text-sale">{{ abiertas.length }} sin cobrar</p>
        </div>

        <section class="panel overflow-hidden">
            <table v-if="ventas.length" class="tabla hidden md:table">
                <thead><tr><th>#</th><th>Cliente</th><th>Atendió</th><th>Pago</th><th>Fecha</th><th class="text-right">Total</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="v in ventas" :key="v.id" class="cursor-pointer" :class="{ 'bg-cartel-300/15': !v.completada }" @click="router.visit(`/ventas/${v.id}`)">
                        <td class="text-petroleo-400">{{ v.id }}</td>
                        <td>{{ v.cliente }}</td>
                        <td>{{ v.encargado }}</td>
                        <td>
                            <div class="flex flex-wrap items-center gap-1">
                                <Insignia v-if="!v.completada" color="amarillo">Sin cobrar</Insignia>
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
                    <Link :href="`/ventas/${v.id}`" class="flex items-center gap-3 px-4 py-3" :class="{ 'bg-cartel-300/15': !v.completada }">
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-medium">#{{ v.id }} · {{ v.cliente }}</p>
                            <p class="text-xs text-petroleo-500">{{ hora(v.created_at) }} · {{ !v.completada ? 'Sin cobrar' : soloFiado(v) ? 'Fiado' : v.forma_pago + (v.fiado > 0 ? ' + fiado' : '') }}</p>
                        </div>
                        <span class="cifra text-xl">{{ plata(v.monto - v.descuento) }}</span>
                    </Link>
                </li>
            </ul>
            <Vacio v-if="!ventas.length" :icono="Receipt" :titulo="filtro ? 'No hay ventas en esas fechas' : 'Todavía no hay ventas en este turno'" />
        </section>
    </template>
</template>
