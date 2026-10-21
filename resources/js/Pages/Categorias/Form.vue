<script setup>
import { useForm } from '@inertiajs/vue3';
import Encabezado from '@/Components/Encabezado.vue';
import Boton from '@/Components/Boton.vue';
import Campo from '@/Components/Campo.vue';

const props = defineProps({ categoria: Object, unidades: Array });
const form = useForm({ nombre: props.categoria?.nombre ?? '', unidad: props.categoria?.unidad ?? 'Uds' });
const guardar = () => (props.categoria ? form.put(`/categorias/${props.categoria.id}`) : form.post('/categorias'));
</script>

<template>
    <Encabezado :titulo="categoria ? 'Editar categoría' : 'Nueva categoría'" />
    <form class="panel max-w-md space-y-4 p-5" @submit.prevent="guardar">
        <Campo v-model="form.nombre" label="Nombre" maxlength="25" required autofocus :error="form.errors.nombre" />
        <fieldset>
            <legend class="etiqueta">Se vende por</legend>
            <div class="grid grid-cols-2 gap-2">
                <label v-for="u in unidades" :key="u" class="flex cursor-pointer items-center gap-2 rounded-xl border border-petroleo-200 p-3 has-[:checked]:border-petroleo-700 has-[:checked]:bg-petroleo-50 dark:border-petroleo-700 dark:has-[:checked]:bg-petroleo-800">
                    <input v-model="form.unidad" type="radio" :value="u" class="accent-petroleo-700" />{{ u === 'Kg' ? 'Peso (Kg)' : 'Unidad (Uds)' }}
                </label>
            </div>
            <p v-if="form.errors.unidad" class="mt-1 text-sm text-sale">{{ form.errors.unidad }}</p>
        </fieldset>
        <div class="flex gap-2"><Boton href="/categorias">Cancelar</Boton><Boton tipo="submit" variante="oscuro" :cargando="form.processing">Guardar</Boton></div>
    </form>
</template>
