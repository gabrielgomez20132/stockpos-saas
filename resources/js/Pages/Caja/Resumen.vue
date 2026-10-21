<script setup>
import { Printer, ArrowLeft, ChevronLeft, ChevronRight, LockOpen, LockKeyhole, Clock } from 'lucide-vue-next';
import Encabezado from '@/Components/Encabezado.vue';
import Boton from '@/Components/Boton.vue';
import TablaTurno from '@/Components/TablaTurno.vue';
import { plata, fecha, hora, cantidad, unidad } from '@/lib/formato';

const props = defineProps({ cierre: Object, verGanancia: Boolean, anterior: Number, siguiente: Number });
function duracion(a, c) {
    if (!a || !c) return '—';
    const min = Math.round((new Date(String(c).replace(' ', 'T')) - new Date(String(a).replace(' ', 'T'))) / 60000);
    const d = Math.floor(min / 1440), h = Math.floor((min % 1440) / 60), m = min % 60;
    return d ? `${d} d ${h} h ${m} min` : `${h} h ${String(m).padStart(2, '0')} min`;
}
const imprimir = () => window.print();
</script>

<template>
    <Encabezado :titulo="`Cierre de caja #${cierre.id}`">
        <div class="flex flex-wrap gap-2 print:hidden">
            <Boton href="/caja/cierres" variante="fantasma" :icono="ArrowLeft">Cierres</Boton>
            <Boton v-if="anterior" :href="`/caja/cierres/${anterior}`" :icono="ChevronLeft" aria-label="Cierre anterior">Anterior</Boton>
            <Boton v-if="siguiente" :href="`/caja/cierres/${siguiente}`" :icono="ChevronRight" aria-label="Cierre siguiente">Siguiente</Boton>
            <Boton variante="oscuro" :icono="Printer" @click="imprimir">Imprimir</Boton>
        </div>
    </Encabezado>

    <section class="panel mb-4 grid gap-4 p-5 sm:grid-cols-3 print:border-petroleo-300">
        <div class="flex gap-3">
            <LockOpen class="mt-0.5 size-5 shrink-0 text-entra" />
            <div><p class="text-sm text-petroleo-500">Apertura</p>
                <p class="font-semibold">{{ cierre.apertura ? fecha(cierre.apertura) + ' · ' + hora(cierre.apertura) + ' hs' : 'Sin dato' }}</p>
                <p class="text-sm">{{ cierre.abierto_por || 'Sin dato' }}</p></div>
        </div>
        <div class="flex gap-3">
            <LockKeyhole class="mt-0.5 size-5 shrink-0 text-sale" />
            <div><p class="text-sm text-petroleo-500">Cierre</p>
                <p class="font-semibold">{{ fecha(cierre.cierre) }} · {{ hora(cierre.cierre) }} hs</p>
                <p class="text-sm">{{ cierre.cerrado_por }}</p></div>
        </div>
        <div class="flex gap-3">
            <Clock class="mt-0.5 size-5 shrink-0 text-petroleo-400" />
            <div><p class="text-sm text-petroleo-500">Duración del turno</p>
                <p class="font-semibold">{{ duracion(cierre.apertura, cierre.cierre) }}</p>
                <p class="text-sm">{{ cierre.cant_ventas }} ventas cobradas</p></div>
        </div>
    </section>

    <div v-if="cierre.pendientes_cant" class="mb-4 rounded-xl border border-cartel-500 bg-cartel-300/20 px-4 py-3 text-sm">
        Quedaron {{ cierre.pendientes_cant }} ventas sin cobrar por {{ plata(cierre.pendientes_monto) }}. No entran en este resumen y pasaron al turno siguiente.
    </div>

    <TablaTurno :d="cierre" :ver-ganancia="verGanancia" />

    <section v-if="cierre.productos?.length" class="panel mt-4 overflow-hidden print:break-before-page">
        <h3 class="border-b border-petroleo-100 px-5 py-3.5 font-semibold dark:border-petroleo-800">Productos vendidos</h3>
        <table class="tabla">
            <thead><tr><th>Producto</th><th class="text-right">Cantidad</th><th class="text-right">Total</th></tr></thead>
            <tbody>
                <tr v-for="p in cierre.productos" :key="p.codigo">
                    <td>{{ p.nombre }} <span class="text-xs text-petroleo-400">{{ p.codigo }}</span></td>
                    <td class="text-right">{{ cantidad(p.cantidad) }} {{ unidad(p.unidad, p.cantidad) }}</td>
                    <td class="cifra-suave text-right">{{ plata(p.total) }}</td>
                </tr>
            </tbody>
        </table>
    </section>
</template>
