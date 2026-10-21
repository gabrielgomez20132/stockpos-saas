<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { ArrowLeft, Printer, Ban, Receipt } from 'lucide-vue-next';
import Encabezado from '@/Components/Encabezado.vue';
import Boton from '@/Components/Boton.vue';
import Insignia from '@/Components/Insignia.vue';
import DocDetalle from '@/Components/DocDetalle.vue';
import { plata, fecha } from '@/lib/formato';
import { confirmar } from '@/lib/confirmar';
import { abrirImpresion } from '@/lib/documentos';

const props = defineProps({ remito: Object });
const precios = ref(false);
const anulando = ref(false);
async function anular() {
    const r = props.remito;
    if (!(await confirmar({ titulo: 'Anular remito', texto: r.stock ? `El remito ${r.numero} queda anulado y la mercadería vuelve al stock.` : `El remito ${r.numero} queda anulado (el stock no cambia: salió con la venta).`, boton: 'Anular', peligro: true }))) return;
    router.post(`/remitos/${r.id}/anular`, {}, { onStart: () => (anulando.value = true), onFinish: () => (anulando.value = false) });
}
</script>

<template>
    <Encabezado :titulo="`Remito ${remito.numero}`" :subtitulo="`${fecha(remito.fecha, true)} · ${remito.usuario ?? ''}`">
        <Boton href="/remitos" variante="fantasma" :icono="ArrowLeft">Remitos</Boton>
    </Encabezado>

    <div class="grid gap-5 lg:grid-cols-[1fr_20rem]">
        <DocDetalle :doc="remito" precios />
        <aside class="space-y-5">
            <div class="panel space-y-2 p-5 text-sm">
                <div class="flex flex-wrap gap-1.5">
                    <Insignia v-if="remito.anulado" color="rojo">Anulado</Insignia>
                    <Insignia v-if="remito.venta" color="azul">De la venta #{{ remito.venta }}</Insignia>
                    <Insignia v-else color="amarillo">Suelto: {{ remito.anulado ? 'el stock volvió' : 'descontó stock' }}</Insignia>
                </div>
                <p v-if="remito.anulado" class="text-petroleo-500">Anulado el {{ fecha(remito.anulado, true) }}<template v-if="remito.anulado_por"> por {{ remito.anulado_por }}</template>.</p>
                <p class="flex items-baseline justify-between border-t border-petroleo-100 pt-2 dark:border-petroleo-800"><span class="text-petroleo-500">Valor de la mercadería</span><span class="text-lg font-bold">{{ plata(remito.total) }}</span></p>
                <Link v-if="remito.venta" :href="`/ventas/${remito.venta}`" class="inline-flex items-center gap-1 font-semibold text-petroleo-700 underline dark:text-petroleo-200"><Receipt class="size-4" />Ver la venta</Link>
            </div>
            <div class="panel space-y-3 p-4">
                <label class="flex items-center gap-2 text-sm"><input v-model="precios" type="checkbox" class="size-4 accent-petroleo-700" />Mostrar precios en el remito</label>
                <Boton variante="oscuro" class="w-full" :icono="Printer" @click="abrirImpresion(`/remitos/${remito.id}/imprimir${precios ? '?precios=1' : ''}`)">Imprimir / PDF</Boton>
                <Boton v-if="!remito.anulado" variante="peligro" class="w-full" :icono="Ban" :cargando="anulando" @click="anular">Anular remito</Boton>
            </div>
        </aside>
    </div>
</template>
