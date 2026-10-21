<script setup>
defineProps({ titulo: String, texto: String, icono: [Object, Function] });
</script>

<template>
    <div class="flex flex-col items-center px-6 py-12 text-center">
        <component :is="icono" v-if="icono" class="mb-3 size-10 text-petroleo-300" stroke-width="1.5" />
        <p class="font-semibold">{{ titulo }}</p>
        <p v-if="texto" class="mt-1 max-w-sm text-sm text-petroleo-500 dark:text-petroleo-300">{{ texto }}</p>
        <div v-if="$slots.default" class="mt-4"><slot /></div>
    </div>
</template>
