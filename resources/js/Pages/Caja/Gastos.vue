<script setup>
import { computed } from 'vue';
import Encabezado from '@/Components/Encabezado.vue';
import CajaNav from '@/Components/CajaNav.vue';
import ListaMovimientos from '@/Components/ListaMovimientos.vue';
import FiltroFechas from '@/Components/FiltroFechas.vue';
import { dia } from '@/lib/formato';

const props = defineProps({ tipo: String, movimientos: Array, proveedores: Array, filtro: Object });
const info = computed(() => ({
    proveedores: { t: 'Pagos a proveedores', id: 7, d: 'Pagos en efectivo del cajón a proveedores.', ph: 'Ej: factura 0001-123' },
    servicios: { t: 'Servicios', id: 4, d: 'Luz, internet, alquiler y otros servicios pagados con efectivo del cajón.', ph: 'Ej: boleta de luz' },
    varios: { t: 'Gastos varios', id: 3, d: 'Cualquier otro gasto pagado con efectivo del cajón.', ph: 'Ej: artículos de limpieza' },
}[props.tipo]));
</script>

<template>
    <Encabezado :titulo="info.t" :subtitulo="filtro ? `Del ${dia(filtro.desde)} al ${dia(filtro.hasta)}` : info.d" />
    <CajaNav />
    <div class="mb-5"><FiltroFechas :filtro="filtro" :url="`/caja/gastos/${tipo}`" /></div>
    <ListaMovimientos :key="tipo" :movimientos="movimientos" :id-desc="info.id" titulo="Registrar gasto" texto-boton="Registrar gasto"
        :proveedores="tipo === 'proveedores' ? proveedores : null" :historial="!!filtro" :placeholder-detalle="info.ph" />
</template>
