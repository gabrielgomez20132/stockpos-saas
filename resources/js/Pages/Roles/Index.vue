<script setup>
import { computed, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { Plus, Trash2, Save, Pencil } from 'lucide-vue-next';
import Encabezado from '@/Components/Encabezado.vue';
import Boton from '@/Components/Boton.vue';
import Campo from '@/Components/Campo.vue';
import Modal from '@/Components/Modal.vue';
import { confirmar } from '@/lib/confirmar';

const props = defineProps({ roles: Array, permisos: Array, usuarios: Array, soySuper: Boolean });
const SUPER = 'Super admin';
const tab = ref('roles');
const sel = ref(Object.fromEntries(props.roles.map((r) => [r.id, r.permissions.map((p) => p.id)])));
const userSel = ref(Object.fromEntries(props.usuarios.map((u) => [u.id, [...u.roles]])));
watch(() => props.roles, (rs) => { sel.value = Object.fromEntries(rs.map((r) => [r.id, r.permissions.map((p) => p.id)])); });
watch(() => props.usuarios, (us) => { userSel.value = Object.fromEntries(us.map((u) => [u.id, [...u.roles]])); });

const guardarRol = (r) => router.put(`/roles/${r.id}/permisos`, { permisos: sel.value[r.id] }, { preserveScroll: true });
const guardarUsuario = (u) => router.put(`/roles/usuarios/${u.id}`, { roles: userSel.value[u.id] }, { preserveScroll: true });
async function borrar(r) {
    if (await confirmar({ titulo: 'Borrar rol', texto: `¿Borrar el rol "${r.name}"?`, boton: 'Borrar', peligro: true })) router.delete(`/roles/${r.id}`, { preserveScroll: true });
}
const grupos = computed(() => {
    const g = {};
    props.permisos.forEach((p) => (g[p.grupo] ??= []).push(p));
    return Object.entries(g);
});
const cambiado = (r) => {
    const a = [...(sel.value[r.id] ?? [])].sort().join();
    return a !== r.permissions.map((p) => p.id).sort().join();
};
function marcarTodo(r, v) { sel.value[r.id] = v ? props.permisos.map((p) => p.id) : []; }

const nuevo = ref(false);
const form = useForm({ id: null, name: '', description: '', copiar_de: null });
function abrirRol(r = null) {
    form.clearErrors();
    Object.assign(form, r ? { id: r.id, name: r.name, description: r.description ?? '', copiar_de: null } : { id: null, name: '', description: '', copiar_de: null });
    nuevo.value = true;
}
const crear = () => {
    const op = { preserveScroll: true, onSuccess: () => { nuevo.value = false; form.reset(); } };
    form.id ? form.put(`/roles/${form.id}`, op) : form.post('/roles', op);
};
</script>

<template>
    <Encabezado titulo="Roles y permisos" subtitulo="Qué puede hacer cada persona. El Super admin puede todo.">
        <Boton variante="oscuro" :icono="Plus" @click="abrirRol()">Nuevo rol</Boton>
    </Encabezado>
    <div class="mb-5 flex w-max rounded-xl bg-petroleo-100/70 p-1 dark:bg-petroleo-900">
        <button v-for="t in [['roles', 'Permisos por rol'], ['usuarios', 'Rol de cada persona']]" :key="t[0]" class="rounded-lg px-3.5 py-2 text-sm" :class="tab === t[0] ? 'bg-white font-semibold shadow-sm dark:bg-petroleo-700' : 'text-petroleo-600 dark:text-petroleo-300'" @click="tab = t[0]">{{ t[1] }}</button>
    </div>

    <div v-if="tab === 'roles'" class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        <section v-for="r in roles" :key="r.id" class="panel flex flex-col p-5">
            <div class="mb-3 flex items-start justify-between gap-2">
                <div class="min-w-0"><h2 class="font-semibold">{{ r.name }}</h2><p class="text-xs text-petroleo-500">{{ r.description }} · {{ r.users_count }} personas</p></div>
                <div v-if="r.name !== SUPER" class="flex shrink-0">
                    <Boton tam="sm" variante="fantasma" :icono="Pencil" aria-label="Renombrar rol" @click="abrirRol(r)" />
                    <Boton v-if="!r.users_count" tam="sm" variante="fantasma" :icono="Trash2" aria-label="Borrar rol" @click="borrar(r)" />
                </div>
            </div>
            <p v-if="r.name === SUPER" class="text-sm text-petroleo-500">Tiene todos los permisos siempre.</p>
            <template v-else>
                <div class="mb-2 flex gap-3 text-xs font-semibold">
                    <button type="button" class="text-petroleo-600 hover:underline dark:text-petroleo-300" @click="marcarTodo(r, true)">Marcar todo</button>
                    <button type="button" class="text-petroleo-600 hover:underline dark:text-petroleo-300" @click="marcarTodo(r, false)">Ninguno</button>
                    <span class="ml-auto font-normal text-petroleo-400">{{ (sel[r.id] ?? []).length }} de {{ permisos.length }}</span>
                </div>
                <div class="flex-1 space-y-3">
                    <div v-for="[g, lista] in grupos" :key="g">
                        <p class="mb-1 text-[0.7rem] font-semibold tracking-wide text-petroleo-400 uppercase">{{ g }}</p>
                        <label v-for="p in lista" :key="p.id" class="flex items-start gap-2 py-0.5 text-sm" :title="p.description">
                            <input v-model="sel[r.id]" type="checkbox" :value="p.id" class="mt-0.5 size-4 shrink-0 accent-petroleo-700" />
                            <span>{{ p.name }}<span v-if="p.description" class="block text-xs text-petroleo-400">{{ p.description }}</span></span>
                        </label>
                    </div>
                </div>
                <Boton class="mt-4" tam="sm" :variante="cambiado(r) ? 'oscuro' : 'suave'" :icono="Save" @click="guardarRol(r)">{{ cambiado(r) ? 'Guardar cambios' : 'Guardar permisos' }}</Boton>
            </template>
        </section>
    </div>

    <section v-else class="panel overflow-hidden">
        <ul class="divide-y divide-petroleo-50 dark:divide-petroleo-800">
            <li v-for="u in usuarios" :key="u.id" class="flex flex-wrap items-center gap-3 px-5 py-3">
                <div class="min-w-40 flex-1"><p class="font-medium">{{ u.nombre }}</p><p class="text-xs text-petroleo-500">DNI {{ u.dni }}</p></div>
                <div class="flex flex-wrap gap-2">
                    <label v-for="r in roles" :key="r.id" class="flex items-center gap-1.5 rounded-lg border border-petroleo-200 px-2.5 py-1.5 text-sm has-[:checked]:border-petroleo-700 has-[:checked]:bg-petroleo-50 has-[:disabled]:opacity-50 dark:border-petroleo-700 dark:has-[:checked]:bg-petroleo-800">
                        <input v-model="userSel[u.id]" type="checkbox" :value="r.id" :disabled="r.name === SUPER && !soySuper" class="accent-petroleo-700" />{{ r.name }}
                    </label>
                </div>
                <Boton tam="sm" :icono="Save" @click="guardarUsuario(u)">Guardar</Boton>
            </li>
        </ul>
    </section>

    <Modal v-model="nuevo" :titulo="form.id ? 'Editar rol' : 'Nuevo rol'" ancho="max-w-md">
        <form id="form-rol" class="space-y-4" @submit.prevent="crear">
            <Campo v-model="form.name" label="Nombre" maxlength="50" placeholder="Ej: Cajero, Repositor, Supervisor" required :error="form.errors.name" />
            <Campo v-model="form.description" label="Descripción" maxlength="150" :error="form.errors.description" />
            <div v-if="!form.id"><label class="etiqueta" for="copiar">Empezar con los permisos de</label>
                <select id="copiar" v-model="form.copiar_de" class="campo"><option :value="null">Ninguno (los elijo después)</option><option v-for="r in roles" :key="r.id" :value="r.id">{{ r.name }}</option></select></div>
        </form>
        <template #pie><Boton @click="nuevo = false">Cancelar</Boton><Boton tipo="submit" form="form-rol" variante="oscuro" :cargando="form.processing">{{ form.id ? 'Guardar' : 'Crear rol' }}</Boton></template>
    </Modal>
</template>
