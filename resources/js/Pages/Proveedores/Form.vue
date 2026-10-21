<script setup>
import { useForm } from '@inertiajs/vue3';
import Encabezado from '@/Components/Encabezado.vue';
import Boton from '@/Components/Boton.vue';
import Campo from '@/Components/Campo.vue';

const props = defineProps({ proveedor: Object });
const p = props.proveedor;
const form = useForm({ nombre: p?.nombre ?? '', telefono: p?.telefono ?? '', cuit: p?.cuit ?? '', contacto: p?.contacto ?? '', direccion: p?.direccion ?? '', observaciones: p?.observaciones ?? '' });
const guardar = () => (p ? form.put(`/proveedores/${p.id}`) : form.post('/proveedores'));
</script>

<template>
    <Encabezado :titulo="p ? 'Editar proveedor' : 'Nuevo proveedor'" />
    <form class="panel max-w-2xl space-y-4 p-5" @submit.prevent="guardar">
        <Campo v-model="form.nombre" label="Nombre" maxlength="50" required :error="form.errors.nombre" />
        <div class="grid gap-4 sm:grid-cols-2">
            <Campo v-model="form.contacto" label="Persona de contacto" maxlength="80" :error="form.errors.contacto" />
            <Campo v-model="form.telefono" label="Teléfono" maxlength="40" :error="form.errors.telefono" />
            <Campo v-model="form.cuit" label="CUIT" maxlength="20" :error="form.errors.cuit" />
            <Campo v-model="form.direccion" label="Dirección" maxlength="150" :error="form.errors.direccion" />
        </div>
        <Campo v-model="form.observaciones" label="Observaciones" tipo="textarea" placeholder="Días de visita, condiciones de pago…" :error="form.errors.observaciones" />
        <div class="flex gap-2"><Boton href="/proveedores">Cancelar</Boton><Boton tipo="submit" variante="oscuro" :cargando="form.processing">Guardar proveedor</Boton></div>
    </form>
</template>
