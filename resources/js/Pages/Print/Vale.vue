<script setup>
import HojaImpresion from '@/Components/HojaImpresion.vue';
import EmisorCabecera from '@/Components/EmisorCabecera.vue';
import { plata, cantidad, fecha, hora, dia } from '@/lib/formato';

defineProps({ devolucion: Object, barras: String, emisor: Object });
</script>

<template>
    <HojaImpresion :titulo="`Vale ${devolucion.vale?.codigo}`">
        <EmisorCabecera :emisor="emisor" />
        <hr />
        <p class="text-center text-[16px] font-bold">VALE DE CRÉDITO</p>
        <p class="my-2 text-center text-[26px] font-black">{{ plata(devolucion.vale?.original) }}</p>
        <img v-if="barras" :src="barras" alt="" class="mx-auto h-14 w-full object-contain" />
        <p class="text-center font-mono text-[13px] font-semibold">{{ devolucion.vale?.codigo }}</p>
        <hr />
        <div class="flex justify-between"><span>Emitido</span><span>{{ dia(devolucion.vale?.emision) }}</span></div>
        <div class="flex justify-between font-bold"><span>Vence</span><span>{{ dia(devolucion.vale?.vence) }}</span></div>
        <div class="flex justify-between"><span>Cliente</span><span>{{ devolucion.cliente }}</span></div>
        <div class="flex justify-between"><span>Devolución</span><span>#{{ devolucion.id }} (venta #{{ devolucion.venta }})</span></div>
        <hr />
        <table>
            <tbody>
                <tr v-for="(p, k) in devolucion.productos" :key="k"><td>{{ cantidad(p.cantidad) }} x {{ p.nombre }}</td><td class="text-right">{{ plata(p.subtotal) }}</td></tr>
            </tbody>
        </table>
        <hr />
        <p class="text-center text-[11px]">Presentá este vale en tu próxima compra. Se puede usar en partes hasta agotar el saldo. No se canjea por dinero.</p>
        <p class="mt-1 text-center text-[11px]">{{ fecha(devolucion.fecha) }} {{ hora(devolucion.fecha) }} · {{ devolucion.encargado }}</p>
    </HojaImpresion>
</template>
