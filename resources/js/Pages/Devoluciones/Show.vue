<script setup>
import { ArrowLeft, Printer } from 'lucide-vue-next';
import Encabezado from '@/Components/Encabezado.vue';
import Boton from '@/Components/Boton.vue';
import Insignia from '@/Components/Insignia.vue';
import { plata, cantidad, fecha, dia } from '@/lib/formato';

const props = defineProps({ devolucion: Object });
const imprimir = () => window.open(`/devoluciones/${props.devolucion.id}/vale`, 'impresion', 'width=480,height=760');
</script>

<template>
    <Encabezado :titulo="`Devolución #${devolucion.id}`" :subtitulo="`Venta #${devolucion.venta} · ${devolucion.cliente} · ${fecha(devolucion.fecha, true)} · ${devolucion.encargado}`">
        <Boton href="/devoluciones" variante="fantasma" :icono="ArrowLeft">Devoluciones</Boton>
        <Boton v-if="devolucion.vale" variante="oscuro" :icono="Printer" @click="imprimir">Imprimir vale</Boton>
    </Encabezado>
    <div class="grid gap-5 lg:grid-cols-[1fr_22rem]">
        <section class="panel overflow-hidden">
            <table class="tabla">
                <thead><tr><th>Producto</th><th class="text-right">Cant.</th><th class="text-right">Precio</th><th class="text-right">Subtotal</th></tr></thead>
                <tbody><tr v-for="(p, k) in devolucion.productos" :key="k"><td>{{ p.nombre }}</td><td class="text-right">{{ cantidad(p.cantidad) }}</td><td class="text-right">{{ plata(p.precio) }}</td><td class="cifra-suave text-right">{{ plata(p.subtotal) }}</td></tr></tbody>
            </table>
            <p v-if="devolucion.observaciones" class="border-t border-petroleo-100 px-5 py-3 text-sm dark:border-petroleo-800">Motivo: {{ devolucion.observaciones }}</p>
        </section>
        <aside v-if="devolucion.vale" class="panel h-fit p-5">
            <p class="text-sm text-petroleo-500">Vale</p>
            <p class="font-mono text-lg font-semibold">{{ devolucion.vale.codigo }}</p>
            <p class="cifra mt-3 text-5xl">{{ plata(devolucion.vale.disponible) }}</p>
            <p class="text-sm text-petroleo-500">disponibles de {{ plata(devolucion.vale.original) }}</p>
            <div class="mt-3 flex gap-2">
                <Insignia v-if="devolucion.vale.vencido" color="rojo">Vencido</Insignia>
                <Insignia v-else-if="!devolucion.vale.activo" color="gris">Usado</Insignia>
                <Insignia v-else color="verde">Vigente</Insignia>
                <span class="text-sm">Vence {{ dia(devolucion.vale.vence) }}</span>
            </div>
        </aside>
    </div>
</template>
