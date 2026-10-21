<script setup>
import { useForm } from '@inertiajs/vue3';
import Encabezado from '@/Components/Encabezado.vue';
import Boton from '@/Components/Boton.vue';
import Campo from '@/Components/Campo.vue';

const props = defineProps({ cliente: Object });
const form = useForm({ nombre: props.cliente?.nombre ?? '', dni: props.cliente?.dni ?? '', telefono: props.cliente?.telefono ?? '', direccion: props.cliente?.direccion ?? '', observaciones: props.cliente?.observaciones ?? '' });
const guardar = () => (props.cliente ? form.put(`/clientes/${props.cliente.id}`) : form.post('/clientes'));
</script>

<template>
    <Encabezado :titulo="cliente ? 'Editar cliente' : 'Nuevo cliente'" />
    <form class="panel max-w-2xl space-y-4 p-5" @submit.prevent="guardar">
        <Campo v-model="form.nombre" label="Nombre" maxlength="50" required autofocus :error="form.errors.nombre" />
        <div class="grid gap-4 sm:grid-cols-2">
            <Campo v-model="form.dni" label="DNI" inputmode="numeric" :error="form.errors.dni" />
            <Campo v-model="form.telefono" label="Teléfono" maxlength="40" :error="form.errors.telefono" />
        </div>
        <Campo v-model="form.direccion" label="Dirección" maxlength="150" :error="form.errors.direccion" />
        <Campo v-model="form.observaciones" label="Observaciones" tipo="textarea" :error="form.errors.observaciones" />
        <div class="flex gap-2"><Boton href="/clientes">Cancelar</Boton><Boton tipo="submit" variante="oscuro" :cargando="form.processing">Guardar cliente</Boton></div>
    </form>
</template>
