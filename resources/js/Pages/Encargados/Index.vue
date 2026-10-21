<script setup>
import { router } from '@inertiajs/vue3';
import { Plus, Trash2, Pencil, RotateCcw, UserCog } from 'lucide-vue-next';
import Encabezado from '@/Components/Encabezado.vue';
import Boton from '@/Components/Boton.vue';
import Insignia from '@/Components/Insignia.vue';
import Vacio from '@/Components/Vacio.vue';
import { confirmar } from '@/lib/confirmar';

defineProps({ encargados: Array, activos: Boolean, sucursales: Object });
async function baja(e) {
    if (await confirmar({ titulo: 'Dar de baja', texto: `${e.nombre} no va a poder entrar al sistema. Se puede recuperar desde la papelera.`, boton: 'Dar de baja', peligro: true })) router.delete(`/encargados/${e.id}`);
}
</script>

<template>
    <Encabezado :titulo="activos ? 'Encargados' : 'Encargados dados de baja'" subtitulo="Las personas que atienden y entran al sistema con su DNI.">
        <template v-if="activos">
            <Boton href="/encargados/papelera" variante="fantasma" :icono="Trash2">Papelera</Boton>
            <Boton href="/encargados/create" variante="oscuro" :icono="Plus">Nuevo encargado</Boton>
        </template>
        <Boton v-else href="/encargados">Volver</Boton>
    </Encabezado>
    <section class="panel overflow-hidden">
        <ul v-if="encargados.length" class="divide-y divide-petroleo-50 dark:divide-petroleo-800">
            <li v-for="e in encargados" :key="e.id" class="flex flex-wrap items-center gap-3 px-5 py-3">
                <span class="grid size-9 place-items-center rounded-full bg-petroleo-100 font-bold text-petroleo-700 dark:bg-petroleo-800 dark:text-petroleo-100">{{ e.nombre.charAt(0) }}</span>
                <div class="min-w-0 flex-1"><p class="font-medium">{{ e.nombre }}</p><p class="text-xs text-petroleo-500">DNI {{ e.dni }}<span v-if="e.email"> · {{ e.email }}</span></p></div>
                <div class="flex flex-wrap gap-1"><Insignia v-for="r in e.roles" :key="r.id" :color="r.name === 'Super admin' ? 'amarillo' : 'gris'">{{ r.name }}</Insignia><Insignia v-if="!e.roles.length" color="rojo">Sin rol</Insignia>
                    <Insignia v-if="sucursales" color="azul">{{ e.id_sucursal ? sucursales[e.id_sucursal] : 'Todas las sucursales' }}</Insignia></div>
                <div class="flex gap-1">
                    <template v-if="activos">
                        <Boton :href="`/encargados/${e.id}/edit`" tam="sm" variante="fantasma" :icono="Pencil" aria-label="Editar" />
                        <Boton tam="sm" variante="fantasma" :icono="Trash2" aria-label="Dar de baja" @click="baja(e)" />
                    </template>
                    <Boton v-else tam="sm" :icono="RotateCcw" @click="router.post(`/encargados/${e.id}/restaurar`)">Recuperar</Boton>
                </div>
            </li>
        </ul>
        <Vacio v-else :icono="UserCog" :titulo="activos ? 'No hay encargados' : 'Nadie dado de baja'" />
    </section>
</template>
