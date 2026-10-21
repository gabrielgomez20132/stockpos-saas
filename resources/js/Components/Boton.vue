<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { LoaderCircle } from 'lucide-vue-next';

const props = defineProps({
    href: String,
    tipo: { type: String, default: 'button' },
    variante: { type: String, default: 'suave' }, // principal | oscuro | suave | peligro | fantasma | exito
    tam: { type: String, default: 'md' }, // sm | md | lg
    cargando: Boolean,
    disabled: Boolean,
    externo: Boolean,
    icono: [Object, Function],
});

const clases = computed(() => [
    'inline-flex items-center justify-center gap-2 rounded-xl font-semibold transition-colors select-none disabled:cursor-not-allowed disabled:opacity-50',
    {
        sm: 'px-2.5 py-1.5 text-sm',
        md: 'px-4 py-2.5 text-sm',
        lg: 'px-5 py-3.5 text-base',
    }[props.tam],
    {
        principal: 'bg-cartel-500 text-petroleo-950 hover:bg-cartel-400 active:bg-cartel-600',
        oscuro: 'bg-petroleo-800 text-white hover:bg-petroleo-700 dark:bg-petroleo-600 dark:hover:bg-petroleo-500',
        suave: 'border border-petroleo-200 bg-white text-petroleo-800 hover:bg-petroleo-50 dark:border-petroleo-700 dark:bg-petroleo-900 dark:text-petroleo-100 dark:hover:bg-petroleo-800',
        peligro: 'bg-sale text-white hover:bg-red-800',
        exito: 'bg-entra text-white hover:bg-green-800',
        fantasma: 'text-petroleo-700 hover:bg-petroleo-100 dark:text-petroleo-200 dark:hover:bg-petroleo-800',
    }[props.variante],
]);
</script>

<template>
    <a v-if="href && externo" :href="href" :class="clases" target="_blank" rel="noopener">
        <component :is="icono" v-if="icono" class="size-4 shrink-0" />
        <slot />
    </a>
    <Link v-else-if="href" :href="href" :class="clases">
        <component :is="icono" v-if="icono" class="size-4 shrink-0" />
        <slot />
    </Link>
    <button v-else :type="tipo" :class="clases" :disabled="disabled || cargando">
        <LoaderCircle v-if="cargando" class="size-4 shrink-0 animate-spin" />
        <component :is="icono" v-else-if="icono" class="size-4 shrink-0" />
        <slot />
    </button>
</template>
