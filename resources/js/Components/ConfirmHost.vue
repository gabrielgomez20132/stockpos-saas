<script setup>
import { computed } from 'vue';
import Modal from './Modal.vue';
import Boton from './Boton.vue';
import { dialogo, responder } from '@/lib/confirmar';

const abierto = computed({ get: () => dialogo.abierto, set: (v) => !v && responder(false) });
</script>

<template>
    <Modal v-model="abierto" :titulo="dialogo.titulo" ancho="max-w-md">
        <p class="text-petroleo-700 dark:text-petroleo-200">{{ dialogo.texto }}</p>
        <template #pie>
            <Boton variante="suave" @click="responder(false)">Cancelar</Boton>
            <Boton :variante="dialogo.peligro ? 'peligro' : 'oscuro'" @click="responder(true)">{{ dialogo.boton }}</Boton>
        </template>
    </Modal>
</template>
