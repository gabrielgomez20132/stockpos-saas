<script setup>
import { plata, cantidad } from '@/lib/formato';

// cliente, renglones y observaciones de un presupuesto o remito (pantalla, no impresion)
defineProps({ doc: Object, precios: Boolean });
</script>

<template>
    <section class="panel overflow-hidden">
        <div class="border-b border-petroleo-100 px-5 py-4 text-sm dark:border-petroleo-800">
            <template v-if="doc.cliente?.nombre">
                <p class="text-base font-semibold">{{ doc.cliente.nombre }}</p>
                <p class="text-petroleo-500">
                    <template v-if="doc.cliente.doc">DNI/CUIT {{ doc.cliente.doc }}</template>
                    <template v-if="doc.cliente.telefono"> · Tel. {{ doc.cliente.telefono }}</template>
                    <template v-if="doc.cliente.direccion"> · {{ doc.cliente.direccion }}</template>
                </p>
            </template>
            <p v-else class="text-petroleo-500">Sin cliente</p>
        </div>
        <div class="overflow-x-auto">
            <table class="tabla">
                <thead><tr><th>Descripción</th><th class="text-right">Cantidad</th><template v-if="precios"><th class="text-right">Precio</th><th class="text-right">Subtotal</th></template></tr></thead>
                <tbody>
                    <tr v-for="(i, k) in doc.items" :key="k">
                        <td><span class="font-medium">{{ i.descripcion }}</span><span v-if="i.codigo" class="block text-xs text-petroleo-400">{{ i.codigo }}</span></td>
                        <td class="text-right">{{ cantidad(i.cantidad) }}</td>
                        <template v-if="precios"><td class="text-right">{{ plata(i.precio) }}</td><td class="text-right font-semibold">{{ plata(i.subtotal) }}</td></template>
                    </tr>
                </tbody>
            </table>
        </div>
        <p v-if="doc.observaciones" class="border-t border-petroleo-100 px-5 py-3 text-sm whitespace-pre-line dark:border-petroleo-800"><span class="font-semibold">Observaciones:</span> {{ doc.observaciones }}</p>
    </section>
</template>
