<script setup>
import { useId } from 'vue';

defineOptions({ inheritAttrs: false });
const model = defineModel();
defineProps({ label: String, error: String, ayuda: String, tipo: { type: String, default: 'text' }, prefijo: String });
const id = useId();
</script>

<template>
    <div>
        <label v-if="label" :for="id" class="etiqueta">{{ label }}</label>
        <div class="relative">
            <span v-if="prefijo" class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-petroleo-400">{{ prefijo }}</span>
            <textarea v-if="tipo === 'textarea'" :id="id" v-model="model" v-bind="$attrs" class="campo min-h-24" :class="{ 'border-sale': error }" />
            <input v-else :id="id" v-model="model" :type="tipo" v-bind="$attrs" class="campo" :class="{ 'border-sale': error, 'pl-7': prefijo }" />
        </div>
        <p v-if="error" class="mt-1 text-sm text-sale">{{ error }}</p>
        <p v-else-if="ayuda" class="mt-1 text-xs text-petroleo-500 dark:text-petroleo-300">{{ ayuda }}</p>
    </div>
</template>
