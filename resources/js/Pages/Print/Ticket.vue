<script setup>
import HojaImpresion from '@/Components/HojaImpresion.vue';
import EmisorCabecera from '@/Components/EmisorCabecera.vue';
import { plata, cantidad, fecha, hora } from '@/lib/formato';

defineProps({ venta: Object, items: Array, emisor: Object });
</script>

<template>
    <HojaImpresion :titulo="`Ticket venta #${venta.id}`">
        <EmisorCabecera :emisor="emisor" />
        <hr />
        <div class="flex justify-between"><span>Venta #{{ venta.id }}</span><span>{{ fecha(venta.fecha) }} {{ hora(venta.fecha) }}</span></div>
        <p v-if="venta.cliente && venta.cliente !== 'General'">Cliente: {{ venta.cliente }}</p>
        <hr />
        <table>
            <tbody>
                <template v-for="i in items" :key="i.tipo + i.id">
                    <tr><td colspan="2">{{ i.nombre }}</td></tr>
                    <tr><td class="pl-2">{{ cantidad(i.cantidad) }} x {{ plata(i.precio, { centavos: true }) }}</td><td class="text-right">{{ plata(i.subtotal) }}</td></tr>
                </template>
            </tbody>
        </table>
        <hr />
        <div v-if="venta.descuento > 0" class="flex justify-between"><span>Subtotal</span><span>{{ plata(venta.monto) }}</span></div>
        <div v-if="venta.descuento > 0" class="flex justify-between"><span>Descuento</span><span>− {{ plata(venta.descuento) }}</span></div>
        <div class="flex justify-between text-[17px] font-bold"><span>TOTAL</span><span>{{ plata(venta.total) }}</span></div>
        <p v-if="venta.detalle_pago" class="mt-1">{{ venta.detalle_pago }}</p>
        <hr />
        <p class="text-center font-semibold">Comprobante no válido como factura</p>
        <p class="mt-1 text-center">¡Gracias por su compra!</p>
    </HojaImpresion>
</template>
