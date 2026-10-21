<script setup>
defineProps({
    titulo: String, valor: [String, Number], tono: { type: String, default: 'normal' }, nota: String,
    icono: [Object, Function], color: { type: String, default: 'petroleo' }, // verde | ambar | azul | petroleo
});
</script>

<template>
    <div class="panel p-4">
        <div class="flex items-start justify-between gap-3">
            <p class="text-sm text-petroleo-500 dark:text-petroleo-300">{{ titulo }}</p>
            <span v-if="icono" class="grid size-10 shrink-0 place-items-center rounded-xl" :class="{
                verde: 'bg-green-50 text-entra dark:bg-green-900/30 dark:text-green-300',
                ambar: 'bg-cartel-300/30 text-cartel-700 dark:bg-cartel-700/25 dark:text-cartel-300',
                azul: 'bg-sky-50 text-sky-700 dark:bg-sky-900/30 dark:text-sky-300',
                petroleo: 'bg-petroleo-50 text-petroleo-600 dark:bg-petroleo-800 dark:text-petroleo-200',
            }[color]"><component :is="icono" class="size-5" /></span>
        </div>
        <p class="cifra text-3xl leading-none" :class="[icono ? '-mt-2' : 'mt-1', { 'text-entra': tono === 'entra', 'text-sale': tono === 'sale' }]">{{ valor }}</p>
        <p v-if="nota" class="mt-1.5 text-xs text-petroleo-500 dark:text-petroleo-300">{{ nota }}</p>
    </div>
</template>
