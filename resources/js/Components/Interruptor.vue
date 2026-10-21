<script setup>
defineProps({ activo: Boolean, disabled: Boolean, etiqueta: String });
defineEmits(['cambiar']);
</script>

<template>
    <div class="flex shrink-0 items-center gap-3">
        <button type="button" role="switch" :aria-checked="activo" :disabled="disabled" class="relative h-8 w-14 shrink-0 rounded-full transition-colors disabled:opacity-50"
            :class="activo ? 'bg-entra' : 'bg-petroleo-200 dark:bg-petroleo-700'" @click="$emit('cambiar')">
            <span class="absolute top-1 left-1 size-6 rounded-full bg-white shadow transition-transform" :class="{ 'translate-x-6': activo }" />
            <span class="sr-only">{{ etiqueta }}</span>
        </button>
        <span class="w-24 text-sm font-semibold" :class="activo ? 'text-entra' : 'text-petroleo-400'">{{ activo ? 'Habilitado' : 'Deshabilitado' }}</span>
    </div>
</template>
