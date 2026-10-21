<script setup>
import { useForm } from '@inertiajs/vue3';
import Encabezado from '@/Components/Encabezado.vue';
import Boton from '@/Components/Boton.vue';
import Campo from '@/Components/Campo.vue';

const props = defineProps({ encargado: Object, roles: Array, sucursales: Array });
const e = props.encargado;
const form = useForm({ nombre: e?.nombre ?? '', dni: e?.dni ?? '', email: e?.email ?? '', telefono: e?.telefono ?? '', password: '', password_confirmation: '', roles: e?.roles ?? [], ...(props.sucursales ? { id_sucursal: e?.id_sucursal ?? (props.sucursales.length === 1 ? props.sucursales[0].id : null) } : {}) });
const guardar = () => (e ? form.put(`/encargados/${e.id}`) : form.post('/encargados'));
</script>

<template>
    <Encabezado :titulo="e ? 'Editar encargado' : 'Nuevo encargado'" />
    <form class="panel max-w-2xl space-y-4 p-5" @submit.prevent="guardar">
        <Campo v-model="form.nombre" label="Nombre" maxlength="50" required :error="form.errors.nombre" />
        <div class="grid gap-4 sm:grid-cols-2">
            <Campo v-model="form.dni" label="DNI (para entrar)" inputmode="numeric" required :error="form.errors.dni" />
            <Campo v-model="form.email" label="Email (opcional)" tipo="email" maxlength="50" :error="form.errors.email" />
            <Campo v-model="form.password" :label="e ? 'Nueva contraseña' : 'Contraseña'" tipo="password" autocomplete="new-password" :required="!e" :ayuda="e ? 'Dejala vacía para no cambiarla.' : 'Mínimo 6 caracteres.'" :error="form.errors.password" />
            <Campo v-model="form.password_confirmation" label="Repetir contraseña" tipo="password" autocomplete="new-password" :required="!e && !!form.password" />
        </div>
        <div v-if="sucursales">
            <label class="etiqueta" for="suc">Sucursal</label>
            <select id="suc" v-model="form.id_sucursal" class="campo">
                <option v-if="sucursales.length > 1" :value="null">Todas (puede elegir en qué sucursal trabaja)</option>
                <option v-for="s in sucursales" :key="s.id" :value="s.id">Solo {{ s.nombre }}</option>
            </select>
            <p v-if="form.errors.id_sucursal" class="mt-1 text-sm text-sale">{{ form.errors.id_sucursal }}</p>
            <p v-else class="mt-1 text-xs text-petroleo-500">Asignado a una sucursal, solo vende, abre caja y ve el stock de esa sucursal.</p>
        </div>
        <fieldset v-if="roles.length">
            <legend class="etiqueta">Roles</legend>
            <div class="grid gap-2 sm:grid-cols-2">
                <label v-for="r in roles" :key="r.id" class="flex cursor-pointer items-start gap-3 rounded-xl border border-petroleo-200 p-3 has-[:checked]:border-petroleo-700 has-[:checked]:bg-petroleo-50 dark:border-petroleo-700 dark:has-[:checked]:bg-petroleo-800">
                    <input v-model="form.roles" type="checkbox" :value="r.id" class="mt-0.5 size-4 accent-petroleo-700" />
                    <span><span class="font-medium">{{ r.name }}</span><br><span class="text-xs text-petroleo-500">{{ r.description }}</span></span>
                </label>
            </div>
        </fieldset>
        <p v-else class="text-sm text-petroleo-500">Los roles los asigna quien tiene el permiso "Roles y permisos".</p>
        <div class="flex gap-2"><Boton href="/encargados">Cancelar</Boton><Boton tipo="submit" variante="oscuro" :cargando="form.processing">Guardar</Boton></div>
    </form>
</template>
