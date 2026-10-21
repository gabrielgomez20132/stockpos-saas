<script setup>
import { ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { Plus, Undo2, Search } from 'lucide-vue-next';
import Encabezado from '@/Components/Encabezado.vue';
import Boton from '@/Components/Boton.vue';
import Insignia from '@/Components/Insignia.vue';
import Paginacion from '@/Components/Paginacion.vue';
import Vacio from '@/Components/Vacio.vue';
import { plata, fecha } from '@/lib/formato';

const props = defineProps({ devoluciones: Object, q: String });
const q = ref(props.q ?? '');
let t;
watch(q, (v) => { clearTimeout(t); t = setTimeout(() => router.get('/devoluciones', v.trim() ? { q: v.trim() } : {}, { preserveState: true, replace: true }), 350); });
const estado = (v) => {
    if (!v) return ['gris', 'Sin vale'];
    if (!v.activo || Number(v.monto_disponible) <= 0) return ['gris', 'Usado'];
    if (new Date(v.fecha_vencimiento + 'T23:59:59') < new Date()) return ['rojo', 'Vencido'];
    return ['verde', 'Saldo ' + plata(v.monto_disponible)];
};
</script>

<template>
    <Encabezado titulo="Devoluciones" subtitulo="Productos devueltos de ventas cobradas. Cada devolución genera un vale de crédito.">
        <Boton href="/devoluciones/crear" variante="oscuro" :icono="Plus">Nueva devolución</Boton>
    </Encabezado>
    <div class="relative mb-4 max-w-md">
        <Search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-petroleo-300" />
        <input v-model="q" class="campo pl-9" placeholder="Buscar por Nº de venta, cliente o fecha (ej: 20/10)" />
    </div>
    <section class="panel overflow-hidden">
        <ul v-if="devoluciones.data.length" class="divide-y divide-petroleo-50 dark:divide-petroleo-800">
            <li v-for="d in devoluciones.data" :key="d.id">
                <Link :href="`/devoluciones/${d.id}`" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3 hover:bg-petroleo-50/60 dark:hover:bg-petroleo-800/40">
                    <span class="w-12 text-petroleo-400">#{{ d.id }}</span>
                    <span class="min-w-0 flex-1"><span class="font-medium">{{ d.cliente?.nombre ?? 'General' }}</span><br><span class="text-xs text-petroleo-500">Venta #{{ d.id_order_original }} · {{ fecha(d.created_at, true) }} · {{ d.encargado?.nombre }}</span></span>
                    <Insignia :color="estado(d.vale)[0]">{{ estado(d.vale)[1] }}</Insignia>
                    <span class="cifra-suave w-24 text-right text-lg">{{ plata(d.monto_total) }}</span>
                </Link>
            </li>
        </ul>
        <Vacio v-else-if="q" :icono="Search" titulo="No hay devoluciones que coincidan" texto="Probá con el número de venta, el nombre del cliente o una fecha como 20/10." />
        <Vacio v-else :icono="Undo2" titulo="No hay devoluciones" texto="Cuando un cliente devuelva algo, registralo acá y se le imprime un vale." />
    </section>
    <Paginacion :datos="devoluciones" />
</template>
