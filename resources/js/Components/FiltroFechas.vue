<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { CalendarSearch, X } from 'lucide-vue-next';
import { hoyISO } from '@/lib/formato';
import Boton from './Boton.vue';

const props = defineProps({ filtro: Object, url: String, textoLimpiar: { type: String, default: 'Ver turno actual' } });
const desde = ref(props.filtro?.desde ?? hoyISO(-7));
const hasta = ref(props.filtro?.hasta ?? hoyISO());

function buscar() {
    router.get(props.url, { desde: desde.value, hasta: hasta.value }, { preserveState: true });
}
</script>

<template>
    <form class="flex flex-wrap items-end gap-2" @submit.prevent="buscar">
        <label class="text-sm">
            <span class="etiqueta">Desde</span>
            <input v-model="desde" type="date" required class="campo w-40" />
        </label>
        <label class="text-sm">
            <span class="etiqueta">Hasta</span>
            <input v-model="hasta" type="date" required :min="desde" class="campo w-40" />
        </label>
        <Boton tipo="submit" variante="oscuro" :icono="CalendarSearch">Buscar</Boton>
        <Boton v-if="filtro" :href="url" variante="fantasma" :icono="X">{{ textoLimpiar }}</Boton>
    </form>
</template>
