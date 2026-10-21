<script setup>
import { ArrowLeft, ArrowRight, Printer } from 'lucide-vue-next';
import Encabezado from '@/Components/Encabezado.vue';
import Boton from '@/Components/Boton.vue';
import { cantidad, unidad, fecha } from '@/lib/formato';

defineProps({ transferencia: Object });
const imprimir = () => window.print();
</script>

<template>
    <Encabezado :titulo="`Transferencia #${transferencia.id}`" :subtitulo="`${fecha(transferencia.fecha, true)} · ${transferencia.usuario ?? ''}`">
        <Boton href="/transferencias" variante="fantasma" :icono="ArrowLeft" class="print:hidden">Transferencias</Boton>
        <Boton :icono="Printer" class="print:hidden" @click="imprimir">Imprimir</Boton>
    </Encabezado>
    <section class="panel overflow-hidden">
        <div class="flex flex-wrap items-center gap-3 border-b border-petroleo-100 px-5 py-4 text-lg font-semibold dark:border-petroleo-800">
            {{ transferencia.origen }} <ArrowRight class="size-5 text-petroleo-400" /> {{ transferencia.destino }}
        </div>
        <p v-if="transferencia.observaciones" class="border-b border-petroleo-100 px-5 py-3 text-sm dark:border-petroleo-800">{{ transferencia.observaciones }}</p>
        <table class="tabla">
            <thead><tr><th>Producto</th><th>Código</th><th class="text-right">Cantidad</th></tr></thead>
            <tbody><tr v-for="(i, k) in transferencia.items" :key="k"><td class="font-medium">{{ i.nombre }}</td><td class="text-petroleo-500">{{ i.codigo }}</td><td class="text-right">{{ cantidad(i.cantidad) }} {{ unidad(i.unidad, i.cantidad) }}</td></tr></tbody>
        </table>
    </section>
</template>
