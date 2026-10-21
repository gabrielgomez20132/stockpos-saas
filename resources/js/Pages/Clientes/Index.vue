<script setup>
import { ref, watch } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { Plus, Trash2, Pencil, History, RotateCcw, Users, Search, HandCoins } from 'lucide-vue-next';
import Encabezado from '@/Components/Encabezado.vue';
import Boton from '@/Components/Boton.vue';
import Campo from '@/Components/Campo.vue';
import Modal from '@/Components/Modal.vue';
import Vacio from '@/Components/Vacio.vue';
import { plata } from '@/lib/formato';
import { confirmar } from '@/lib/confirmar';

const props = defineProps({ clientes: Array, activos: Boolean, filtros: Object, totalDeuda: Number, cajaAbierta: Boolean });
const q = ref(props.filtros.q);
let t;
watch(q, (v) => { clearTimeout(t); t = setTimeout(() => router.get(props.activos ? '/clientes' : '/clientes/papelera', { q: v || undefined, deudores: props.filtros.deudores || undefined }, { preserveState: true, replace: true }), 300); });
const soloDeudores = () => router.get('/clientes', { q: q.value || undefined, deudores: props.filtros.deudores ? undefined : 1 }, { preserveState: true });

const cobrando = ref(null);
const pago = useForm({ monto: '' });
function abrirPago(c) { cobrando.value = c; pago.monto = c.saldo; }
function cobrar() { pago.post(`/clientes/${cobrando.value.id}/pago`, { preserveScroll: true, onSuccess: () => (cobrando.value = null) }); }

async function borrar(c) {
    if (await confirmar({ titulo: 'Mandar a la papelera', texto: `${c.nombre} deja de aparecer en la venta. Se puede recuperar.`, boton: 'Mandar a la papelera', peligro: true })) router.delete(`/clientes/${c.id}`, { preserveScroll: true });
}
</script>

<template>
    <Encabezado :titulo="activos ? 'Clientes' : 'Clientes en la papelera'" :subtitulo="activos && totalDeuda > 0 ? `Te deben ${plata(totalDeuda)} en total.` : ''">
        <template v-if="activos">
            <Boton href="/clientes/papelera" variante="fantasma" :icono="Trash2">Papelera</Boton>
            <Boton href="/clientes/create" variante="oscuro" :icono="Plus">Nuevo cliente</Boton>
        </template>
        <Boton v-else href="/clientes" variante="suave">Volver a clientes</Boton>
    </Encabezado>

    <div class="mb-4 flex flex-wrap items-center gap-3">
        <div class="relative w-full max-w-sm">
            <Search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-petroleo-300" />
            <input v-model="q" class="campo pl-9" placeholder="Buscar por nombre o DNI" />
        </div>
        <label v-if="activos" class="flex items-center gap-2 text-sm"><input type="checkbox" :checked="filtros.deudores" class="size-4 accent-petroleo-700" @change="soloDeudores" />Solo los que deben</label>
    </div>

    <section class="panel overflow-hidden">
        <ul v-if="clientes.length" class="divide-y divide-petroleo-50 dark:divide-petroleo-800">
            <li v-for="c in clientes" :key="c.id" class="flex flex-wrap items-center gap-x-3 gap-y-2 px-4 py-3 sm:px-5">
                <div class="min-w-0 flex-1 basis-40">
                    <p class="truncate font-medium">{{ c.nombre }}</p>
                    <p class="text-xs text-petroleo-500">{{ [c.dni && 'DNI ' + c.dni, c.telefono].filter(Boolean).join(' · ') || ' ' }}</p>
                </div>
                <span v-if="c.saldo > 0" class="cifra-suave text-lg text-sale">debe {{ plata(c.saldo) }}</span>
                <span v-else-if="c.saldo < 0" class="text-sm text-entra">a favor {{ plata(-c.saldo) }}</span>
                <span v-else class="text-sm text-petroleo-400">al día</span>
                <div class="flex gap-1">
                    <template v-if="activos">
                        <Boton v-if="c.saldo > 0" tam="sm" variante="exito" :icono="HandCoins" @click="abrirPago(c)">Cobrar</Boton>
                        <Boton :href="`/clientes/${c.id}/historial`" tam="sm" variante="fantasma" :icono="History" aria-label="Historial" />
                        <Boton :href="`/clientes/${c.id}/edit`" tam="sm" variante="fantasma" :icono="Pencil" aria-label="Editar" />
                        <Boton tam="sm" variante="fantasma" :icono="Trash2" aria-label="Borrar" @click="borrar(c)" />
                    </template>
                    <Boton v-else tam="sm" :icono="RotateCcw" @click="router.post(`/clientes/${c.id}/restaurar`)">Recuperar</Boton>
                </div>
            </li>
        </ul>
        <Vacio v-else :icono="Users" :titulo="activos ? 'No hay clientes' : 'La papelera está vacía'" :texto="activos ? 'Los clientes con nombre pueden comprar fiado y tener vales.' : ''" />
    </section>

    <Modal :model-value="!!cobrando" :titulo="`Cobrar a ${cobrando?.nombre}`" ancho="max-w-sm" @update:model-value="(v) => !v && (cobrando = null)">
        <form id="form-pago" class="space-y-3" @submit.prevent="cobrar">
            <p class="text-sm">Debe <b>{{ plata(cobrando?.saldo) }}</b>. El pago entra como efectivo en la caja del turno.</p>
            <p v-if="!cajaAbierta" class="text-sm text-sale">La caja está cerrada: abrila para registrar el cobro.</p>
            <Campo v-model="pago.monto" label="Monto que paga" tipo="number" step="0.01" min="0.01" :max="cobrando?.saldo" prefijo="$" required :error="pago.errors.monto" />
        </form>
        <template #pie>
            <Boton @click="cobrando = null">Cancelar</Boton>
            <Boton tipo="submit" form="form-pago" variante="exito" :disabled="!cajaAbierta" :cargando="pago.processing">Registrar pago</Boton>
        </template>
    </Modal>
</template>
