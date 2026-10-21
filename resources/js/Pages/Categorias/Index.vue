<script setup>
import { router } from '@inertiajs/vue3';
import { Plus, Trash2, Pencil, RotateCcw, Tags } from 'lucide-vue-next';
import Encabezado from '@/Components/Encabezado.vue';
import Boton from '@/Components/Boton.vue';
import Insignia from '@/Components/Insignia.vue';
import Vacio from '@/Components/Vacio.vue';
import { confirmar } from '@/lib/confirmar';

defineProps({ categorias: Array, activas: Boolean });
async function borrar(c) {
    if (await confirmar({ titulo: 'Mandar a la papelera', texto: `"${c.nombre}" deja de aparecer al cargar productos. Sus productos no se tocan.`, boton: 'Mandar a la papelera', peligro: true })) router.delete(`/categorias/${c.id}`);
}
</script>

<template>
    <Encabezado :titulo="activas ? 'Categorías' : 'Categorías en la papelera'" subtitulo="Por unidad (Uds) o por peso (Kg, admite decimales).">
        <template v-if="activas">
            <Boton href="/categorias/papelera" variante="fantasma" :icono="Trash2">Papelera</Boton>
            <Boton href="/categorias/create" variante="oscuro" :icono="Plus">Nueva categoría</Boton>
        </template>
        <Boton v-else href="/categorias">Volver</Boton>
    </Encabezado>
    <section class="panel overflow-hidden">
        <ul v-if="categorias.length" class="grid divide-y divide-petroleo-50 sm:grid-cols-2 sm:divide-y-0 lg:grid-cols-3 dark:divide-petroleo-800">
            <li v-for="c in categorias" :key="c.id" class="flex items-center gap-3 border-petroleo-50 px-5 py-3 sm:border-b dark:border-petroleo-800">
                <div class="min-w-0 flex-1"><p class="font-medium">{{ c.nombre }}</p><p class="text-xs text-petroleo-500">{{ c.productos_count }} productos</p></div>
                <Insignia>{{ c.unidad }}</Insignia>
                <template v-if="activas">
                    <Boton :href="`/categorias/${c.id}/edit`" tam="sm" variante="fantasma" :icono="Pencil" aria-label="Editar" />
                    <Boton tam="sm" variante="fantasma" :icono="Trash2" aria-label="Borrar" @click="borrar(c)" />
                </template>
                <Boton v-else tam="sm" :icono="RotateCcw" @click="router.post(`/categorias/${c.id}/restaurar`)">Recuperar</Boton>
            </li>
        </ul>
        <Vacio v-else :icono="Tags" :titulo="activas ? 'No hay categorías' : 'La papelera está vacía'" />
    </section>
</template>
