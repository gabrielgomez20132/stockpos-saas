<script setup>
import { ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { Plus, FileText, Search } from 'lucide-vue-next';
import Encabezado from '@/Components/Encabezado.vue';
import Boton from '@/Components/Boton.vue';
import Insignia from '@/Components/Insignia.vue';
import Paginacion from '@/Components/Paginacion.vue';
import Vacio from '@/Components/Vacio.vue';
import { plata, fecha, dia } from '@/lib/formato';

const props = defineProps({ presupuestos: Object, filtros: Object });
const q = ref(props.filtros?.q ?? '');
let t;
watch(q, (v) => { clearTimeout(t); t = setTimeout(() => router.get('/presupuestos', v.trim() ? { q: v.trim() } : {}, { preserveState: true, replace: true }), 350); });
</script>

<template>
    <Encabezado titulo="Presupuestos" subtitulo="Se imprimen en hoja A4 (o PDF) con tu logo. No tocan el stock ni la caja.">
        <Boton href="/presupuestos/nuevo" variante="oscuro" :icono="Plus">Nuevo presupuesto</Boton>
    </Encabezado>
    <div class="relative mb-4 max-w-md">
        <Search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-petroleo-300" />
        <input v-model="q" class="campo pl-9" placeholder="Buscar por número o cliente" />
    </div>
    <section class="panel overflow-hidden">
        <ul v-if="presupuestos.data.length" class="divide-y divide-petroleo-50 dark:divide-petroleo-800">
            <li v-for="p in presupuestos.data" :key="p.id">
                <Link :href="`/presupuestos/${p.id}`" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3 hover:bg-petroleo-50/60 dark:hover:bg-petroleo-800/40">
                    <span class="w-28 font-mono text-sm text-petroleo-400">{{ p.numero }}</span>
                    <span class="min-w-0 flex-1 basis-48"><span class="font-medium">{{ p.cliente || 'Sin cliente' }}</span><br><span class="text-xs text-petroleo-500">{{ fecha(p.fecha, true) }} · {{ p.items }} renglones · {{ p.usuario }}</span></span>
                    <Insignia :color="p.vencido ? 'rojo' : 'verde'">{{ p.vencido ? 'Venció' : 'Vence' }} {{ dia(p.vence) }}</Insignia>
                    <span class="cifra-suave w-28 text-right text-lg">{{ plata(p.total) }}</span>
                </Link>
            </li>
        </ul>
        <Vacio v-else :icono="FileText" :titulo="q ? 'No hay presupuestos con esa búsqueda' : 'Todavía no hay presupuestos'" texto="Armá uno con productos del catálogo o renglones a mano y imprimilo con tu logo.">
            <Boton href="/presupuestos/nuevo" variante="oscuro" :icono="Plus">Nuevo presupuesto</Boton>
        </Vacio>
    </section>
    <Paginacion :datos="presupuestos" />
</template>
