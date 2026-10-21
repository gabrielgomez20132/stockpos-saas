<script setup>
import { computed, ref } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { Plus, Trash2, Inbox } from 'lucide-vue-next';
import Boton from './Boton.vue';
import Campo from './Campo.vue';
import Selector from './Selector.vue';
import Vacio from './Vacio.vue';
import { plata, fecha, hora } from '@/lib/formato';
import { confirmar } from '@/lib/confirmar';

const props = defineProps({
    movimientos: Array, idDesc: Number, titulo: String, textoBoton: { type: String, default: 'Agregar' },
    proveedores: Array, historial: Boolean, puedeCargar: { type: Boolean, default: true },
    placeholderDetalle: { type: String, default: 'Opcional' },
});
const page = usePage();
const form = useForm({ id_desc: props.idDesc, monto: '', detalle: '', id_proveedor: null });
const total = computed(() => props.movimientos.reduce((a, m) => a + Number(m.monto), 0));
const montoRef = ref(null);
const nombreProv = (id) => props.proveedores?.find((p) => p.id === id)?.nombre;

function guardar() {
    form.post('/caja/movimientos', { preserveScroll: true, onSuccess: () => form.reset('monto', 'detalle', 'id_proveedor') });
}
async function borrar(m) {
    if (await confirmar({ titulo: 'Borrar movimiento', texto: `¿Borrar ${plata(m.monto)}${m.detalle ? ' (' + m.detalle + ')' : ''}?`, boton: 'Borrar', peligro: true })) {
        router.delete(`/caja/movimientos/${m.id}`, { preserveScroll: true });
    }
}
</script>

<template>
    <div class="grid gap-5 lg:grid-cols-[22rem_1fr]">
        <form v-if="puedeCargar && !historial" class="panel h-fit space-y-4 p-5" @submit.prevent="guardar">
            <h2 class="font-semibold">{{ titulo }}</h2>
            <Selector v-if="proveedores" v-model="form.id_proveedor" label="Proveedor" vacio="— Elegí el proveedor —" :opciones="proveedores.filter((p) => p.activo)" :error="form.errors.id_proveedor" />
            <Campo ref="montoRef" v-model="form.monto" label="Monto" tipo="number" step="0.01" min="0.01" inputmode="decimal" prefijo="$" required :error="form.errors.monto" />
            <Campo v-model="form.detalle" label="Detalle" maxlength="100" :placeholder="placeholderDetalle" :error="form.errors.detalle" />
            <Boton tipo="submit" variante="oscuro" class="w-full" :icono="Plus" :cargando="form.processing">{{ textoBoton }}</Boton>
        </form>

        <section class="panel overflow-hidden" :class="{ 'lg:col-span-2': !puedeCargar || historial }">
            <div class="flex items-center justify-between border-b border-petroleo-100 px-5 py-3.5 dark:border-petroleo-800">
                <h2 class="font-semibold">{{ historial ? 'Resultados' : 'En este turno' }}</h2>
                <p class="text-sm">Total <span class="cifra ml-1 text-xl">{{ plata(total) }}</span></p>
            </div>
            <ul v-if="movimientos.length" class="divide-y divide-petroleo-50 dark:divide-petroleo-800">
                <li v-for="m in movimientos" :key="m.id" class="flex items-center gap-3 px-5 py-3">
                    <div class="min-w-0 flex-1">
                        <p class="truncate font-medium">{{ nombreProv(m.id_proveedor) || m.detalle || 'Sin detalle' }}</p>
                        <p class="text-xs text-petroleo-500">{{ fecha(m.created_at) }} {{ hora(m.created_at) }} · {{ m.admin }}<span v-if="nombreProv(m.id_proveedor) && m.detalle"> · {{ m.detalle }}</span></p>
                    </div>
                    <span class="cifra-suave text-lg">{{ plata(m.monto) }}</span>
                    <button v-if="m.caja_abierta && !historial" class="rounded-lg p-2 text-petroleo-400 hover:bg-red-50 hover:text-sale" aria-label="Borrar" @click="borrar(m)">
                        <Trash2 class="size-4" />
                    </button>
                </li>
            </ul>
            <Vacio v-else :icono="Inbox" titulo="No hay movimientos" :texto="historial ? 'Probá con otras fechas.' : 'Lo que cargues aparece acá.'" />
        </section>
    </div>
</template>
