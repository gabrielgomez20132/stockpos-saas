<script setup>
import { Head } from '@inertiajs/vue3';
defineProps({ titulo: String, subtitulo: String });
</script>

<template>
    <Head :title="titulo" />
    <header class="mb-5 flex flex-wrap items-end justify-between gap-3">
        <div class="min-w-0">
            <slot name="antes" />
            <h1 class="text-2xl font-bold tracking-tight sm:text-[1.75rem]" style="font-variation-settings: 'wdth' 88">{{ titulo }}</h1>
            <p v-if="subtitulo" class="mt-0.5 text-sm text-petroleo-500 dark:text-petroleo-300">{{ subtitulo }}</p>
        </div>
        <div v-if="$slots.default" class="flex flex-wrap items-center gap-2"><slot /></div>
    </header>
</template>
