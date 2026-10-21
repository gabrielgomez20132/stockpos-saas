<script setup>
import { computed, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { Printer, X } from 'lucide-vue-next';
import { plata, cantidad, fecha, dia } from '@/lib/formato';

// Presupuesto o remito en hoja A4, con el logo y los datos del negocio
const props = defineProps({ tipo: String, doc: Object, emisor: Object, precios: Boolean });
const esRemito = computed(() => props.tipo === 'remito');
const titulo = computed(() => (esRemito.value ? 'Remito' : 'Presupuesto'));
const conPrecios = ref(esRemito.value ? props.precios : true);
const duplicado = ref(false);
const copias = computed(() => (esRemito.value && duplicado.value ? ['ORIGINAL', 'DUPLICADO'] : [esRemito.value ? 'ORIGINAL' : null]));
const p2 = (v) => plata(v, { centavos: true });
const imprimir = () => window.print();
const cerrar = () => (window.opener ? window.close() : history.back());
</script>

<template>
    <Head :title="`${titulo} ${doc.numero}`" />
    <div class="min-h-dvh bg-petroleo-100 py-4 print:bg-white print:py-0">
        <div class="mx-auto mb-3 flex max-w-[210mm] flex-wrap items-center gap-x-4 gap-y-2 px-4 print:hidden">
            <button class="flex items-center justify-center gap-2 rounded-xl bg-petroleo-800 px-5 py-2.5 text-sm font-semibold text-white" @click="imprimir"><Printer class="size-4" />Imprimir / Guardar PDF</button>
            <label v-if="esRemito" class="flex items-center gap-2 text-sm"><input v-model="conPrecios" type="checkbox" class="size-4 accent-petroleo-700" />Mostrar precios</label>
            <label v-if="esRemito" class="flex items-center gap-2 text-sm"><input v-model="duplicado" type="checkbox" class="size-4 accent-petroleo-700" />Original y duplicado</label>
            <button class="ml-auto flex items-center justify-center gap-2 rounded-xl border border-petroleo-300 bg-white px-4 py-2.5 text-sm" @click="cerrar"><X class="size-4" />Cerrar</button>
            <p class="w-full text-xs text-petroleo-600">Para mandarlo por WhatsApp o mail: Imprimir → destino "Guardar como PDF".</p>
        </div>

        <article v-for="(copia, n) in copias" :key="n" class="a4 mx-auto flex max-w-[210mm] flex-col bg-white text-[12px] leading-snug text-black shadow print:max-w-none print:shadow-none" :class="{ 'mt-4 print:mt-0': n > 0 }">
            <header class="grid grid-cols-[1fr_auto_1fr] items-start gap-4 border-b-2 border-black pb-3">
                <div>
                    <img v-if="emisor.logo" :src="emisor.logo" alt="" class="mb-2 max-h-20 max-w-[70mm] object-contain" />
                    <p class="text-[15px] font-bold">{{ emisor.razon_social }}</p>
                    <p v-if="emisor.domicilio">{{ emisor.domicilio }}</p>
                    <p v-if="emisor.localidad">{{ emisor.localidad }}</p>
                    <p v-if="emisor.sucursal">Sucursal {{ emisor.sucursal.nombre }}<template v-if="emisor.sucursal.direccion"> · {{ emisor.sucursal.direccion }}</template><template v-if="emisor.sucursal.telefono"> · Tel. {{ emisor.sucursal.telefono }}</template></p>
                </div>
                <div class="flex flex-col items-center">
                    <span class="grid size-14 place-items-center border-2 border-black text-4xl font-black">X</span>
                    <p class="mt-1 w-28 text-center text-[9px] leading-tight">Documento no válido como factura</p>
                </div>
                <div class="text-right">
                    <p v-if="copia" class="text-[10px] font-semibold tracking-widest">{{ copia }}</p>
                    <p class="text-[20px] font-black uppercase">{{ titulo }}</p>
                    <p class="text-[14px] font-semibold">Nº {{ doc.numero }}</p>
                    <p>Fecha: {{ fecha(doc.fecha) }}</p>
                    <p v-if="doc.vence">Válido hasta: <b>{{ dia(doc.vence) }}</b></p>
                    <p v-if="doc.venta">Venta Nº {{ doc.venta }}</p>
                    <div class="mt-1 text-[10px]">
                        <p v-if="emisor.cuit">CUIT {{ emisor.cuit }}</p>
                        <p v-if="emisor.condicion_iva">{{ emisor.condicion_iva }}</p>
                        <p v-if="emisor.iibb">IIBB {{ emisor.iibb }}</p>
                        <p v-if="emisor.inicio_actividades">Inicio de actividades {{ emisor.inicio_actividades }}</p>
                    </div>
                </div>
            </header>

            <p v-if="doc.anulado" class="mt-3 border-2 border-black py-1 text-center text-[14px] font-black tracking-widest">ANULADO</p>

            <section class="mt-3 grid grid-cols-[auto_1fr] gap-x-3 gap-y-0.5 rounded border border-black px-3 py-2">
                <span class="font-semibold">{{ esRemito ? 'Destinatario:' : 'Cliente:' }}</span><span>{{ doc.cliente?.nombre || '—' }}</span>
                <template v-if="doc.cliente?.doc"><span class="font-semibold">DNI/CUIT:</span><span>{{ doc.cliente.doc }}</span></template>
                <template v-if="doc.cliente?.direccion"><span class="font-semibold">Dirección:</span><span>{{ doc.cliente.direccion }}</span></template>
                <template v-if="doc.cliente?.telefono"><span class="font-semibold">Teléfono:</span><span>{{ doc.cliente.telefono }}</span></template>
            </section>

            <table class="mt-3 w-full border-collapse">
                <thead>
                    <tr class="border-y border-black text-left">
                        <th class="w-[22mm] py-1.5 pr-2">Código</th>
                        <th class="py-1.5 pr-2">Descripción</th>
                        <th class="w-[20mm] py-1.5 text-right">Cantidad</th>
                        <template v-if="conPrecios"><th class="w-[28mm] py-1.5 text-right">Precio unit.</th><th class="w-[30mm] py-1.5 text-right">Subtotal</th></template>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(i, k) in doc.items" :key="k" class="fila border-b border-gray-300">
                        <td class="py-1 pr-2 align-top text-[10.5px]">{{ i.codigo }}</td>
                        <td class="py-1 pr-2 align-top">{{ i.descripcion }}</td>
                        <td class="py-1 text-right align-top">{{ cantidad(i.cantidad) }}</td>
                        <template v-if="conPrecios"><td class="py-1 text-right align-top">{{ p2(i.precio) }}</td><td class="py-1 text-right align-top">{{ p2(i.subtotal) }}</td></template>
                    </tr>
                </tbody>
            </table>

            <div v-if="conPrecios" class="mt-3 ml-auto w-[75mm] space-y-0.5">
                <template v-if="!esRemito && doc.descuento > 0">
                    <p class="flex justify-between"><span>Subtotal</span><span>{{ p2(doc.subtotal) }}</span></p>
                    <p class="flex justify-between"><span>Descuento</span><span>− {{ p2(doc.descuento) }}</span></p>
                </template>
                <p class="flex justify-between border-t-2 border-black pt-1 text-[15px] font-bold"><span>TOTAL</span><span>{{ p2(doc.total) }}</span></p>
            </div>

            <p v-if="doc.observaciones" class="mt-4 whitespace-pre-line"><b>Observaciones:</b> {{ doc.observaciones }}</p>

            <div class="flex-1" />

            <footer v-if="esRemito" class="mt-10 grid grid-cols-3 gap-8 text-center text-[11px]">
                <div class="border-t border-black pt-1">Firma</div>
                <div class="border-t border-black pt-1">Aclaración y DNI</div>
                <div class="border-t border-black pt-1">Fecha de recepción</div>
                <p class="col-span-3 -mt-4 text-[10px]">Recibí conforme la mercadería detallada.</p>
            </footer>
            <footer v-else class="mt-8 text-center text-[10px] text-gray-600">
                Presupuesto válido hasta el {{ dia(doc.vence) }}. Precios sujetos a disponibilidad de stock.
            </footer>
        </article>
    </div>
</template>

<style>
.a4 { padding: 12mm; min-height: 297mm; }
.a4 .fila { break-inside: avoid; }
@media print {
    @page { size: A4; margin: 10mm; }
    html.dark { color-scheme: light; }
    .a4 { padding: 0; min-height: 276mm; break-after: page; }
    .a4:last-of-type { break-after: auto; }
}
@media screen and (max-width: 640px) {
    .a4 { padding: 6mm; min-height: auto; }
}
</style>
