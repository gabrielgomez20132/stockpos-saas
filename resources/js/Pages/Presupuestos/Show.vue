<script setup>
import { router } from '@inertiajs/vue3';
import { ArrowLeft, Printer, Pencil, Copy, Trash2 } from 'lucide-vue-next';
import Encabezado from '@/Components/Encabezado.vue';
import Boton from '@/Components/Boton.vue';
import Insignia from '@/Components/Insignia.vue';
import DocDetalle from '@/Components/DocDetalle.vue';
import { plata, fecha, dia } from '@/lib/formato';
import { confirmar } from '@/lib/confirmar';
import { abrirImpresion } from '@/lib/documentos';

const props = defineProps({ presupuesto: Object });
async function eliminar() {
    if (!(await confirmar({ titulo: 'Eliminar presupuesto', texto: `Se borra el presupuesto ${props.presupuesto.numero}. No se puede deshacer.`, boton: 'Eliminar', peligro: true }))) return;
    router.delete(`/presupuestos/${props.presupuesto.id}`);
}
</script>

<template>
    <Encabezado :titulo="`Presupuesto ${presupuesto.numero}`" :subtitulo="`${fecha(presupuesto.fecha, true)} · ${presupuesto.usuario ?? ''}`">
        <Boton href="/presupuestos" variante="fantasma" :icono="ArrowLeft">Presupuestos</Boton>
        <Boton variante="oscuro" :icono="Printer" @click="abrirImpresion(`/presupuestos/${presupuesto.id}/imprimir`)">Imprimir / PDF</Boton>
    </Encabezado>

    <div class="grid gap-5 lg:grid-cols-[1fr_20rem]">
        <DocDetalle :doc="presupuesto" precios />
        <aside class="space-y-5">
            <div class="panel space-y-2 p-5 text-sm">
                <p class="flex items-center justify-between gap-2"><span class="text-petroleo-500">Válido hasta</span>
                    <span class="flex items-center gap-2 font-semibold">{{ dia(presupuesto.vence) }}<Insignia v-if="presupuesto.vencido" color="rojo">Vencido</Insignia><Insignia v-else color="verde">Vigente</Insignia></span></p>
                <p class="flex justify-between"><span class="text-petroleo-500">Subtotal</span><span>{{ plata(presupuesto.subtotal) }}</span></p>
                <p v-if="presupuesto.descuento > 0" class="flex justify-between"><span class="text-petroleo-500">Descuento</span><span>− {{ plata(presupuesto.descuento) }}</span></p>
                <p class="flex items-baseline justify-between border-t border-petroleo-100 pt-2 text-lg font-bold dark:border-petroleo-800"><span>Total</span><span>{{ plata(presupuesto.total) }}</span></p>
            </div>
            <div class="panel space-y-2 p-4">
                <Boton class="w-full" :icono="Pencil" :href="`/presupuestos/${presupuesto.id}/editar`">Editar</Boton>
                <Boton class="w-full" :icono="Copy" :href="`/presupuestos/nuevo?copiar=${presupuesto.id}`">Duplicar</Boton>
                <Boton class="w-full" variante="peligro" :icono="Trash2" @click="eliminar">Eliminar</Boton>
            </div>
        </aside>
    </div>
</template>
