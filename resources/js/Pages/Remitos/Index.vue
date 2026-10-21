<script setup>
import { ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { Plus, ClipboardList, Search } from 'lucide-vue-next';
import Encabezado from '@/Components/Encabezado.vue';
import Boton from '@/Components/Boton.vue';
import Insignia from '@/Components/Insignia.vue';
import Paginacion from '@/Components/Paginacion.vue';
import Vacio from '@/Components/Vacio.vue';
import { plata, fecha } from '@/lib/formato';

const props = defineProps({ remitos: Object, filtros: Object });
const q = ref(props.filtros?.q ?? '');
let t;
watch(q, (v) => { clearTimeout(t); t = setTimeout(() => router.get('/remitos', v.trim() ? { q: v.trim() } : {}, { preserveState: true, replace: true }), 350); });
</script>

<template>
    <Encabezado titulo="Remitos" subtitulo="De una venta (desde el detalle de la venta, no vuelve a descontar stock) o sueltos (descuentan stock).">
        <Boton href="/remitos/nuevo" variante="oscuro" :icono="Plus">Remito suelto</Boton>
    </Encabezado>
    <div class="relative mb-4 max-w-md">
        <Search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-petroleo-300" />
        <input v-model="q" class="campo pl-9" placeholder="Buscar por número, Nº de venta o cliente" />
    </div>
    <section class="panel overflow-hidden">
        <ul v-if="remitos.data.length" class="divide-y divide-petroleo-50 dark:divide-petroleo-800">
            <li v-for="r in remitos.data" :key="r.id">
                <Link :href="`/remitos/${r.id}`" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3 hover:bg-petroleo-50/60 dark:hover:bg-petroleo-800/40" :class="{ 'opacity-60': r.anulado }">
                    <span class="w-28 font-mono text-sm text-petroleo-400">{{ r.numero }}</span>
                    <span class="min-w-0 flex-1 basis-48"><span class="font-medium">{{ r.cliente || 'Sin cliente' }}</span><br><span class="text-xs text-petroleo-500">{{ fecha(r.fecha, true) }} · {{ r.items }} renglones · {{ r.usuario }}</span></span>
                    <Insignia v-if="r.anulado" color="rojo">Anulado</Insignia>
                    <Insignia v-else-if="r.venta" color="azul">Venta #{{ r.venta }}</Insignia>
                    <Insignia v-else color="amarillo">Suelto</Insignia>
                    <span class="cifra-suave w-28 text-right text-lg">{{ plata(r.total) }}</span>
                </Link>
            </li>
        </ul>
        <Vacio v-else :icono="ClipboardList" :titulo="q ? 'No hay remitos con esa búsqueda' : 'Todavía no hay remitos'" texto="Para el remito de una venta, abrí la venta y tocá 'Remito'. Para una entrega sin venta, hacé un remito suelto.">
            <Boton href="/remitos/nuevo" variante="oscuro" :icono="Plus">Remito suelto</Boton>
        </Vacio>
    </section>
    <Paginacion :datos="remitos" />
</template>
