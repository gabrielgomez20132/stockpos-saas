<script setup>
import { useForm, usePage } from '@inertiajs/vue3';
import Encabezado from '@/Components/Encabezado.vue';
import Campo from '@/Components/Campo.vue';
import Boton from '@/Components/Boton.vue';

const page = usePage();
const form = useForm({ actual: '', password: '', password_confirmation: '' });
const guardar = () => form.put('/perfil/clave', { preserveScroll: true, onSuccess: () => form.reset() });
</script>

<template>
    <Encabezado titulo="Mi perfil" :subtitulo="page.props.auth.user.nombre + (page.props.auth.user.email ? ' · ' + page.props.auth.user.email : '')" />
    <form class="panel max-w-lg space-y-4 p-5" @submit.prevent="guardar">
        <h2 class="font-semibold">Cambiar contraseña</h2>
        <Campo v-model="form.actual" label="Contraseña actual" tipo="password" autocomplete="current-password" required :error="form.errors.actual" />
        <Campo v-model="form.password" label="Nueva contraseña" tipo="password" autocomplete="new-password" required ayuda="Mínimo 6 caracteres." :error="form.errors.password" />
        <Campo v-model="form.password_confirmation" label="Repetí la nueva contraseña" tipo="password" autocomplete="new-password" required />
        <Boton tipo="submit" variante="oscuro" :cargando="form.processing">Guardar contraseña</Boton>
    </form>
</template>
