<script setup>
import { computed } from 'vue';
import HojaImpresion from '@/Components/HojaImpresion.vue';
import EmisorCabecera from '@/Components/EmisorCabecera.vue';
import { plata, cantidad, fecha, hora, dia } from '@/lib/formato';

const props = defineProps({ cbte: Object, items: Array, emisor: Object, qr: String, qrUrl: String });
const nombre = computed(() => (props.cbte.ndc ? 'Nota de crédito' : 'Factura'));
const conIva = computed(() => props.cbte.letra === 'A');
const p2 = (v) => plata(v, { centavos: true });
const base = computed(() => props.cbte.impTotal - (props.cbte.montoOpcional || 0));
</script>

<template>
    <HojaImpresion :titulo="`${nombre} ${cbte.letra} ${cbte.nroCbte}`">
        <p class="mb-2 text-center text-[11px]">ORIGINAL</p>
        <EmisorCabecera :emisor="emisor" fiscal />
        <hr />
        <div class="flex items-center justify-between gap-2">
            <div><p class="font-bold">{{ nombre }}</p><p>Nº {{ cbte.nroCbte }}</p></div>
            <span class="grid size-12 place-items-center border-2 border-black text-3xl font-black">{{ cbte.letra }}</span>
            <div class="text-right"><p>{{ fecha(cbte.cbteFch) }}</p><p>{{ hora(cbte.cbteFch) }} hs</p></div>
        </div>
        <hr />
        <template v-if="cbte.docTipo === 80">
            <p>Señor/es: <b>{{ cbte.nombreRS }}</b></p>
            <p>CUIT: <b>{{ cbte.docNro }}</b></p>
            <p v-if="cbte.letra === 'A'">IVA: <b>Responsable Inscripto</b></p>
        </template>
        <p v-else>Consumidor final</p>
        <p v-if="cbte.ndc && cbte.nroCbteAsoc">Anula la factura {{ cbte.letra }} {{ cbte.nroCbteAsoc }}</p>
        <hr />
        <table>
            <tbody>
                <template v-if="cbte.concepto !== 'V' && items.length && !cbte.ndc">
                    <template v-for="(i, k) in items" :key="k">
                        <tr><td colspan="2">{{ i.nombre }}</td></tr>
                        <tr><td class="pl-2">{{ cantidad(i.cantidad) }} x {{ p2(i.precio) }}</td><td class="text-right">{{ p2(i.subtotal) }}</td></tr>
                    </template>
                    <tr v-if="cbte.descuento > 0"><td>Descuento</td><td class="text-right">− {{ p2(cbte.descuento) }}</td></tr>
                </template>
                <tr v-else><td>{{ cbte.ndc ? 'Anulación de venta' : 'Varios' }}</td><td class="text-right">{{ p2(base) }}</td></tr>
                <tr v-if="cbte.montoOpcional"><td>{{ cbte.detalleOpcional }}</td><td class="text-right">{{ p2(cbte.montoOpcional) }}</td></tr>
            </tbody>
        </table>
        <hr />
        <template v-if="conIva">
            <div class="flex justify-between"><span>Neto gravado</span><span>{{ p2(cbte.impNeto) }}</span></div>
            <div class="flex justify-between"><span>IVA 21%</span><span>{{ p2(cbte.impIVA) }}</span></div>
        </template>
        <div class="flex justify-between text-[17px] font-bold"><span>TOTAL</span><span>{{ p2(cbte.impTotal) }}</span></div>
        <p v-if="cbte.letra === 'B' && cbte.impIVA > 0" class="mt-1 text-[11px]">Régimen de Transparencia Fiscal al Consumidor (Ley 27.743): IVA contenido {{ p2(cbte.impIVA) }}</p>
        <p class="mt-1">Forma de pago: {{ cbte.tipoPago === 'EFTV' ? 'Efectivo' : 'Tarjeta' }}</p>
        <hr />
        <div class="flex items-center gap-3">
            <a :href="qrUrl" target="_blank" rel="noopener"><img :src="qr" alt="Código QR de AFIP" class="size-28" /></a>
            <div class="min-w-0">
                <img src="/logo-afip.svg" alt="AFIP" class="mb-1 h-6" />
                <p class="font-semibold">Comprobante autorizado</p>
                <p>CAE: <b>{{ cbte.caeNum }}</b></p>
                <p>Vto. CAE: {{ dia(cbte.caeFvt) }}</p>
            </div>
        </div>
    </HojaImpresion>
</template>
