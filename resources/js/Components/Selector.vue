<script setup>
import { useId } from 'vue';

defineOptions({ inheritAttrs: false });
const model = defineModel();
defineProps({
    label: String, error: String, ayuda: String,
    opciones: { type: Array, default: () => [] }, // [{ id, nombre }]
    vacio: String,
    valor: { type: String, default: 'id' }, texto: { type: String, default: 'nombre' },
});
const id = useId();
</script>

<template>
    <div>
        <label v-if="label" :for="id" class="etiqueta">{{ label }}</label>
        <select :id="id" v-model="model" v-bind="$attrs" class="campo pr-8" :class="{ 'border-sale': error }">
            <option v-if="vacio !== undefined" :value="null">{{ vacio }}</option>
            <slot>
                <option v-for="o in opciones" :key="o[valor]" :value="o[valor]">{{ o[texto] }}</option>
            </slot>
        </select>
        <p v-if="error" class="mt-1 text-sm text-sale">{{ error }}</p>
        <p v-else-if="ayuda" class="mt-1 text-xs text-petroleo-500">{{ ayuda }}</p>
    </div>
</template>
