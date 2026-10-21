<script setup>
import { ref, watch } from 'vue';
import { Search, X, UserRound } from 'lucide-vue-next';
import http, { mensajeError } from '@/lib/http';
import { toastError } from '@/lib/toast';

// cliente del documento: se elige de la lista (completa los datos) o se escribe a mano
const c = defineModel({ type: Object, required: true }); // { id, nombre, doc, direccion, telefono }
const props = defineProps({ url: { type: String, required: true }, errores: { type: Object, default: () => ({}) } });

const q = ref('');
const resultados = ref([]);
const abierto = ref(false);
let t;
watch(q, (v) => {
    clearTimeout(t);
    if (!v.trim()) return (resultados.value = []);
    t = setTimeout(async () => {
        try { resultados.value = (await http.get(props.url, { params: { q: v } })).data; abierto.value = true; } catch (e) { toastError(mensajeError(e)); }
    }, 200);
});
function elegir(r) {
    c.value = { id: r.id, nombre: r.nombre ?? '', doc: r.dni ? String(r.dni) : '', direccion: r.direccion ?? '', telefono: r.telefono ?? '' };
    q.value = '';
    resultados.value = [];
    abierto.value = false;
}
const cerrarLuego = () => setTimeout(() => (abierto.value = false), 150);
const quitar = () => (c.value = { id: null, nombre: '', doc: '', direccion: '', telefono: '' });
</script>

<template>
    <div class="space-y-3">
        <div class="flex items-center justify-between gap-2">
            <p class="etiqueta mb-0">Cliente</p>
            <button v-if="c.id || c.nombre" type="button" class="inline-flex items-center gap-1 text-xs font-semibold text-petroleo-500 hover:text-sale" @click="quitar"><X class="size-3.5" />Quitar</button>
        </div>
        <div class="relative">
            <Search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-petroleo-300" />
            <input v-model="q" class="campo pl-9" placeholder="Buscar en tus clientes (nombre o DNI)" @blur="cerrarLuego" @focus="abierto = resultados.length > 0" />
            <ul v-if="abierto && resultados.length" class="absolute inset-x-0 top-full z-20 mt-1 max-h-60 divide-y divide-petroleo-50 overflow-y-auto rounded-xl border border-petroleo-100 bg-white shadow-xl dark:divide-petroleo-800 dark:border-petroleo-700 dark:bg-petroleo-900">
                <li v-for="r in resultados" :key="r.id">
                    <button type="button" class="flex w-full items-center gap-2 px-3 py-2 text-left hover:bg-petroleo-50 dark:hover:bg-petroleo-800" @mousedown.prevent="elegir(r)">
                        <UserRound class="size-4 shrink-0 text-petroleo-400" />
                        <span class="min-w-0 flex-1 truncate">{{ r.nombre }}</span>
                        <span v-if="r.dni" class="text-xs text-petroleo-400">{{ r.dni }}</span>
                    </button>
                </li>
            </ul>
        </div>
        <p v-if="c.id" class="text-xs text-entra">Cliente de la lista: los datos se completaron solos (podés cambiarlos para este documento).</p>
        <input v-model="c.nombre" class="campo" maxlength="100" placeholder="Nombre o razón social" aria-label="Nombre del cliente" />
        <div class="grid grid-cols-2 gap-2">
            <input v-model="c.doc" class="campo" maxlength="20" placeholder="DNI / CUIT" aria-label="DNI o CUIT" />
            <input v-model="c.telefono" class="campo" maxlength="40" placeholder="Teléfono" aria-label="Teléfono" />
        </div>
        <input v-model="c.direccion" class="campo" maxlength="150" placeholder="Dirección" aria-label="Dirección" />
        <p v-for="(e, k) in errores" :key="k" class="text-sm text-sale">{{ e }}</p>
    </div>
</template>
