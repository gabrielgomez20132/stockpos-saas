<script setup>
defineProps({ titulo: String, valor: [String, Number], tono: { type: String, default: 'normal' }, nota: String });
</script>

<template>
    <div class="panel p-4">
        <p class="text-sm text-petroleo-500 dark:text-petroleo-300">{{ titulo }}</p>
        <p class="cifra mt-1 text-3xl leading-none" :class="{ 'text-entra': tono === 'entra', 'text-sale': tono === 'sale' }">{{ valor }}</p>
        <p v-if="nota" class="mt-1.5 text-xs text-petroleo-500 dark:text-petroleo-300">{{ nota }}</p>
    </div>
</template>
