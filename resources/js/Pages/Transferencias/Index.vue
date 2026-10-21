<script setup>
import { router } from '@inertiajs/vue3';
import { Plus, ArrowLeftRight, ArrowRight } from 'lucide-vue-next';
import Encabezado from '@/Components/Encabezado.vue';
import Boton from '@/Components/Boton.vue';
import Insignia from '@/Components/Insignia.vue';
import Paginacion from '@/Components/Paginacion.vue';
import Vacio from '@/Components/Vacio.vue';
import { fecha } from '@/lib/formato';

defineProps({ transferencias: Object, actual: Number });
</script>

<template>
    <Encabezado titulo="Transferencias" subtitulo="Mercadería que se manda de una sucursal a otra: sale del stock de una y entra en la otra al instante.">
        <Boton href="/transferencias/nueva" variante="oscuro" :icono="Plus">Nueva transferencia</Boton>
    </Encabezado>
    <section class="panel overflow-hidden">
        <ul v-if="transferencias.data.length" class="divide-y divide-petroleo-50 dark:divide-petroleo-800">
            <li v-for="t in transferencias.data" :key="t.id">
                <button type="button" class="flex w-full flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3 text-left hover:bg-petroleo-50/60 dark:hover:bg-petroleo-800/40" @click="router.visit(`/transferencias/${t.id}`)">
                    <span class="w-12 text-petroleo-400">#{{ t.id }}</span>
                    <span class="flex min-w-0 flex-1 basis-56 items-center gap-2 font-medium">{{ t.sucursal_origen?.nombre }} <ArrowRight class="size-4 text-petroleo-400" /> {{ t.sucursal_destino?.nombre }}
                        <Insignia v-if="t.destino === actual" color="verde">Entrada</Insignia><Insignia v-else-if="t.origen === actual" color="amarillo">Salida</Insignia></span>
                    <span class="text-sm text-petroleo-500">{{ t.items_count }} productos · {{ t.user?.nombre }}</span>
                    <span class="w-32 text-right text-sm text-petroleo-500">{{ fecha(t.created_at, true) }}</span>
                </button>
            </li>
        </ul>
        <Vacio v-else :icono="ArrowLeftRight" titulo="Todavía no hay transferencias" texto="Usalas para mandar mercadería de un local a otro o para cargar el stock inicial de una sucursal nueva.">
            <Boton href="/transferencias/nueva" variante="oscuro" :icono="Plus">Nueva transferencia</Boton>
        </Vacio>
    </section>
    <Paginacion :datos="transferencias" />
</template>
