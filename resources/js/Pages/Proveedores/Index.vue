<script setup>
import { Link, router } from '@inertiajs/vue3';
import { Plus, Trash2, Pencil, RotateCcw, Truck, Phone } from 'lucide-vue-next';
import Encabezado from '@/Components/Encabezado.vue';
import Boton from '@/Components/Boton.vue';
import Vacio from '@/Components/Vacio.vue';
import { plata } from '@/lib/formato';
import { confirmar } from '@/lib/confirmar';

defineProps({ proveedores: Array, activos: Boolean });
async function borrar(p) {
    if (await confirmar({ titulo: 'Mandar a la papelera', texto: `¿Mandar a ${p.nombre} a la papelera? Su cuenta corriente se conserva.`, boton: 'Mandar a la papelera', peligro: true })) router.delete(`/proveedores/${p.id}`);
}
</script>

<template>
    <Encabezado :titulo="activos ? 'Proveedores' : 'Proveedores en la papelera'" subtitulo="A quién le comprás y cuánto le debés.">
        <template v-if="activos">
            <Boton href="/proveedores/papelera" variante="fantasma" :icono="Trash2">Papelera</Boton>
            <Boton href="/proveedores/create" variante="oscuro" :icono="Plus">Nuevo proveedor</Boton>
        </template>
        <Boton v-else href="/proveedores">Volver</Boton>
    </Encabezado>
    <section class="panel overflow-hidden">
        <ul v-if="proveedores.length" class="divide-y divide-petroleo-50 dark:divide-petroleo-800">
            <li v-for="p in proveedores" :key="p.id" class="flex flex-wrap items-center gap-3 px-5 py-3">
                <div class="min-w-0 flex-1 basis-48">
                    <Link :href="`/proveedores/${p.id}/cuenta`" class="font-medium hover:underline">{{ p.nombre }}</Link>
                    <p class="text-xs text-petroleo-500">{{ [p.contacto, p.cuit && 'CUIT ' + p.cuit].filter(Boolean).join(' · ') }}</p>
                </div>
                <a v-if="p.telefono" :href="`tel:${p.telefono}`" class="flex items-center gap-1 text-sm text-petroleo-600 dark:text-petroleo-300"><Phone class="size-3.5" />{{ p.telefono }}</a>
                <Link :href="`/proveedores/${p.id}/cuenta`" class="cifra-suave w-32 text-right text-lg" :class="p.saldo > 0 ? 'text-sale' : 'text-petroleo-400 text-sm'">{{ p.saldo > 0 ? 'le debés ' + plata(p.saldo) : 'al día' }}</Link>
                <div class="flex gap-1">
                    <template v-if="activos">
                        <Boton :href="`/proveedores/${p.id}/edit`" tam="sm" variante="fantasma" :icono="Pencil" aria-label="Editar" />
                        <Boton tam="sm" variante="fantasma" :icono="Trash2" aria-label="Borrar" @click="borrar(p)" />
                    </template>
                    <Boton v-else tam="sm" :icono="RotateCcw" @click="router.post(`/proveedores/${p.id}/restaurar`)">Recuperar</Boton>
                </div>
            </li>
        </ul>
        <Vacio v-else :icono="Truck" :titulo="activos ? 'No hay proveedores' : 'La papelera está vacía'" />
    </section>
</template>
