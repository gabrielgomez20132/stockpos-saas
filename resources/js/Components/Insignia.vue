<script setup>
defineProps({ color: { type: String, default: 'gris' } });
</script>

<template>
    <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold whitespace-nowrap" :class="{
        gris: 'bg-petroleo-100 text-petroleo-700 dark:bg-petroleo-800 dark:text-petroleo-200',
        verde: 'bg-green-100 text-entra dark:bg-green-900/40 dark:text-green-300',
        rojo: 'bg-red-100 text-sale dark:bg-red-900/40 dark:text-red-300',
        amarillo: 'bg-cartel-300/40 text-cartel-700 dark:bg-cartel-700/30 dark:text-cartel-300',
        azul: 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-300',
    }[color]"><slot /></span>
</template>
