<script setup>
import Encabezado from '@/Components/Encabezado.vue';
import CajaNav from '@/Components/CajaNav.vue';
import ListaMovimientos from '@/Components/ListaMovimientos.vue';
import FiltroFechas from '@/Components/FiltroFechas.vue';
import { dia } from '@/lib/formato';

defineProps({ movimientos: Array, filtro: Object });
</script>

<template>
    <Encabezado titulo="Retiros" :subtitulo="filtro ? `Del ${dia(filtro.desde)} al ${dia(filtro.hasta)}` : 'Efectivo que se saca del cajón durante el turno.'" />
    <CajaNav />
    <div class="mb-5"><FiltroFechas :filtro="filtro" url="/caja/retiros" /></div>
    <ListaMovimientos :movimientos="movimientos" :id-desc="6" titulo="Registrar retiro" texto-boton="Registrar retiro" :historial="!!filtro" placeholder-detalle="Ej: retiro del dueño" />
</template>
