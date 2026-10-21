<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { Lock } from 'lucide-vue-next';
import Encabezado from '@/Components/Encabezado.vue';
import CajaNav from '@/Components/CajaNav.vue';
import ListaMovimientos from '@/Components/ListaMovimientos.vue';
import CerrarCaja from '@/Components/CerrarCaja.vue';

defineProps({ movimientos: Array });
const abierta = computed(() => usePage().props.caja.abierta);
</script>

<template>
    <Encabezado titulo="Caja" :subtitulo="abierta ? 'Turno abierto. Podés sumar más efectivo inicial o cerrar el turno.' : 'Cargá el efectivo con el que arranca el turno para abrir la caja.'">
        <CerrarCaja v-if="abierta" />
    </Encabezado>
    <CajaNav />
    <div v-if="!abierta" class="mb-5 flex items-center gap-3 rounded-xl border border-cartel-500 bg-cartel-300/20 px-4 py-3 text-sm">
        <Lock class="size-5 shrink-0 text-cartel-700" /> La caja está cerrada: no se puede vender hasta cargar la caja inicial.
    </div>
    <ListaMovimientos :movimientos="movimientos" :id-desc="1" :titulo="abierta ? 'Sumar a la caja inicial' : 'Abrir caja'" :texto-boton="abierta ? 'Sumar' : 'Abrir caja'" placeholder-detalle="Ej: cambio en monedas" />
</template>
