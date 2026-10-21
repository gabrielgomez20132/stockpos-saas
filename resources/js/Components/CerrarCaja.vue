<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { LockKeyhole } from 'lucide-vue-next';
import Boton from './Boton.vue';
import Modal from './Modal.vue';

const abierto = ref(false);
const cerrando = ref(false);
function cerrar() {
    router.post('/caja/cerrar', {}, { onStart: () => (cerrando.value = true), onFinish: () => { cerrando.value = false; abierto.value = false; } });
}
</script>

<template>
    <Boton variante="peligro" :icono="LockKeyhole" @click="abierto = true">Cerrar caja</Boton>
    <Modal v-model="abierto" titulo="Cerrar la caja" ancho="max-w-md">
        <p>Se guarda el resumen del turno (ventas, medios de pago, gastos y retiros) y la caja queda cerrada hasta que alguien cargue una nueva caja inicial.</p>
        <p class="mt-3 text-sm text-petroleo-500">Las ventas que quedaron sin cobrar pasan al turno siguiente.</p>
        <template #pie>
            <Boton @click="abierto = false">Cancelar</Boton>
            <Boton variante="peligro" :cargando="cerrando" @click="cerrar">Cerrar y ver resumen</Boton>
        </template>
    </Modal>
</template>
