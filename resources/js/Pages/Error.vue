<script setup>
import { computed } from 'vue';
import Encabezado from '@/Components/Encabezado.vue';
import Boton from '@/Components/Boton.vue';

const props = defineProps({ status: Number, mensaje: String });
const textos = {
    403: ['No tenés acceso a esta sección', 'Pedile a quien administra el sistema que te habilite el permiso.'],
    404: ['No encontramos esa página', 'Puede que el registro se haya borrado o que el enlace esté mal.'],
    500: ['Algo falló en el servidor', 'Probá de nuevo en un momento. Si sigue pasando, revisá storage/logs/laravel.log.'],
    503: ['Sistema en mantenimiento', 'Volvé a intentar en unos minutos.'],
};
const t = computed(() => textos[props.status] ?? textos[500]);
</script>

<template>
    <div class="mx-auto max-w-lg py-16 text-center">
        <p class="cifra text-8xl text-petroleo-200 dark:text-petroleo-700">{{ status }}</p>
        <Encabezado :titulo="t[0]" class="mt-4 justify-center text-center" />
        <p class="-mt-2 text-petroleo-500">{{ mensaje && status === 403 && mensaje !== 'This action is unauthorized.' ? mensaje : t[1] }}</p>
        <Boton href="/inicio" variante="oscuro" class="mt-6">Ir al inicio</Boton>
    </div>
</template>
