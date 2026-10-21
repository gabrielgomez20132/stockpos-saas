<script setup>
import { Link, router } from '@inertiajs/vue3';
import { Plus, Pencil, Trash2, Pause, Play, Gift } from 'lucide-vue-next';
import Encabezado from '@/Components/Encabezado.vue';
import Boton from '@/Components/Boton.vue';
import Insignia from '@/Components/Insignia.vue';
import Dato from '@/Components/Dato.vue';
import Vacio from '@/Components/Vacio.vue';
import { plata, cantidad, dia } from '@/lib/formato';
import { confirmar } from '@/lib/confirmar';

defineProps({ combos: Array, resumen: Object });
const estados = { vigente: ['verde', 'Vigente'], programado: ['azul', 'Programado'], vencido: ['rojo', 'Vencido'], inactivo: ['gris', 'Pausado'] };
async function borrar(c) {
    if (await confirmar({ titulo: 'Borrar combo', texto: `¿Borrar "${c.nombre}"? Si ya se vendió, queda pausado para no perder el historial.`, boton: 'Borrar', peligro: true })) router.delete(`/combos/${c.id}`, { preserveScroll: true });
}
const vigencia = (c) => (c.desde || c.hasta ? `${c.desde ? 'desde ' + dia(c.desde) : ''} ${c.hasta ? 'hasta ' + dia(c.hasta) : ''}`.trim() : 'Sin fecha de vencimiento');
</script>

<template>
    <Encabezado titulo="Combos y promociones" subtitulo="Un precio fijo por varios productos. Se venden desde la pantalla de venta como cualquier producto.">
        <Boton href="/combos/create" variante="oscuro" :icono="Plus">Nuevo combo</Boton>
    </Encabezado>

    <div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <Dato titulo="Vigentes ahora" :valor="String(resumen.vigentes)" :nota="`de ${resumen.total} combos cargados`" />
        <Dato titulo="Combos vendidos" :valor="String(resumen.veces)" nota="En ventas cobradas" />
        <Dato titulo="Ganancia de los combos" :valor="plata(resumen.ganancia)" tono="entra" />
    </div>

    <div v-if="combos.length" class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        <section v-for="c in combos" :key="c.id" class="panel flex flex-col p-5" :class="{ 'opacity-70': c.estado !== 'vigente' }">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <h2 class="font-semibold">{{ c.nombre }}</h2>
                    <p class="text-xs text-petroleo-500">{{ c.codigo ? 'Código ' + c.codigo + ' · ' : '' }}{{ vigencia(c) }}</p>
                </div>
                <Insignia :color="estados[c.estado][0]">{{ estados[c.estado][1] }}</Insignia>
            </div>
            <ul class="mt-3 flex-1 space-y-1 text-sm">
                <li v-for="(p, k) in c.productos" :key="k" class="flex gap-2"><span class="w-10 shrink-0 text-right font-semibold">{{ cantidad(p.cantidad) }}×</span>{{ p.nombre }}</li>
            </ul>
            <div class="mt-4 flex items-end justify-between border-t border-dashed border-petroleo-200 pt-3 dark:border-petroleo-700">
                <div class="text-xs text-petroleo-500">
                    <p>Por separado {{ plata(c.precio_lista) }}<span v-if="c.precio_lista > c.precio" class="text-entra"> · ahorra {{ plata(c.precio_lista - c.precio) }}</span></p>
                    <p>Ganancia por combo <b :class="c.precio - c.costo < 0 ? 'text-sale' : 'text-entra'">{{ plata(c.precio - c.costo) }}</b></p>
                    <p>Alcanza el stock para <b>{{ c.alcanza }}</b> · vendidos <b>{{ c.veces }}</b></p>
                </div>
                <span class="cifra text-3xl">{{ plata(c.precio) }}</span>
            </div>
            <div class="mt-3 flex gap-1">
                <Boton :href="`/combos/${c.id}/edit`" tam="sm" :icono="Pencil">Editar</Boton>
                <Boton tam="sm" variante="fantasma" :icono="c.activo ? Pause : Play" @click="router.post(`/combos/${c.id}/activar`, {}, { preserveScroll: true })">{{ c.activo ? 'Pausar' : 'Activar' }}</Boton>
                <Boton tam="sm" variante="fantasma" :icono="Trash2" aria-label="Borrar" class="ml-auto" @click="borrar(c)" />
            </div>
        </section>
    </div>
    <div v-else class="panel">
        <Vacio :icono="Gift" titulo="Todavía no hay combos" texto="Por ejemplo: 1 Fernet + 1 Coca-Cola 1,5 L + 1 hielo a un precio especial.">
            <Boton href="/combos/create" variante="oscuro" :icono="Plus">Crear el primero</Boton>
        </Vacio>
    </div>
</template>
