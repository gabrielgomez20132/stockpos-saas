<script setup>
import Encabezado from '@/Components/Encabezado.vue';
import CajaNav from '@/Components/CajaNav.vue';
import TablaTurno from '@/Components/TablaTurno.vue';
import FiltroFechas from '@/Components/FiltroFechas.vue';
import { dia } from '@/lib/formato';

defineProps({ datos: Object, filtro: Object });
</script>

<template>
    <Encabezado titulo="Movimientos" :subtitulo="filtro ? `Del ${dia(filtro.desde)} al ${dia(filtro.hasta)}` : 'Números del turno abierto, en vivo.'" />
    <CajaNav />
    <div class="mb-5"><FiltroFechas :filtro="filtro" url="/caja/movimientos" /></div>
    <TablaTurno :d="datos" />
</template>
