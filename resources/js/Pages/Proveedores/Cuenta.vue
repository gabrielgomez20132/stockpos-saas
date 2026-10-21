<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { ArrowLeft, PackagePlus, HandCoins, Inbox } from 'lucide-vue-next';
import Encabezado from '@/Components/Encabezado.vue';
import Boton from '@/Components/Boton.vue';
import Campo from '@/Components/Campo.vue';
import Modal from '@/Components/Modal.vue';
import Dato from '@/Components/Dato.vue';
import Paginacion from '@/Components/Paginacion.vue';
import Vacio from '@/Components/Vacio.vue';
import { plata, fecha } from '@/lib/formato';

const props = defineProps({ proveedor: Object, movimientos: Object, compras: Number, pagos: Number, saldo: Number, cajaAbierta: Boolean });
const modal = ref(null); // compra | pago
const form = useForm({ monto: '', detalle: '', medio: props.cajaAbierta ? 'caja' : 'otro' });
function abrir(m) { form.reset(); form.clearErrors(); form.medio = props.cajaAbierta ? 'caja' : 'otro'; modal.value = m; }
function guardar() { form.post(`/proveedores/${props.proveedor.id}/${modal.value}`, { preserveScroll: true, onSuccess: () => (modal.value = null) }); }
</script>

<template>
    <Encabezado :titulo="proveedor.nombre" :subtitulo="[proveedor.contacto, proveedor.telefono, proveedor.cuit && 'CUIT ' + proveedor.cuit].filter(Boolean).join(' · ') || 'Cuenta corriente'">
        <Boton href="/proveedores" variante="fantasma" :icono="ArrowLeft">Proveedores</Boton>
        <Boton :icono="PackagePlus" @click="abrir('compra')">Cargar compra</Boton>
        <Boton variante="oscuro" :icono="HandCoins" @click="abrir('pago')">Registrar pago</Boton>
    </Encabezado>
    <div class="mb-5 grid gap-3 sm:grid-cols-3">
        <Dato titulo="Le debés" :valor="plata(Math.max(0, saldo))" :tono="saldo > 0 ? 'sale' : 'normal'" :nota="saldo < 0 ? 'Saldo a favor: ' + plata(-saldo) : ''" />
        <Dato titulo="Compras" :valor="plata(compras)" />
        <Dato titulo="Pagos" :valor="plata(pagos)" tono="entra" />
    </div>
    <section class="panel overflow-hidden">
        <ul v-if="movimientos.data.length" class="divide-y divide-petroleo-50 dark:divide-petroleo-800">
            <li v-for="m in movimientos.data" :key="m.id" class="flex items-center gap-3 px-5 py-3">
                <span class="min-w-0 flex-1"><span class="font-medium">{{ m.tipo === 'D' ? 'Compra' : 'Pago' }}</span> <span class="text-sm text-petroleo-500">{{ m.detalle }}</span><br><span class="text-xs text-petroleo-500">{{ fecha(m.created_at, true) }} · {{ m.encargado }}</span></span>
                <span class="cifra-suave text-lg" :class="m.tipo === 'D' ? 'text-sale' : 'text-entra'">{{ m.tipo === 'D' ? '+' : '−' }} {{ plata(m.monto) }}</span>
            </li>
        </ul>
        <Vacio v-else :icono="Inbox" titulo="Sin movimientos" texto="Cargá las compras a cuenta y los pagos para saber cuánto le debés." />
    </section>
    <Paginacion :datos="movimientos" />

    <Modal :model-value="!!modal" :titulo="modal === 'compra' ? 'Cargar compra a cuenta' : 'Registrar pago'" ancho="max-w-md" @update:model-value="(v) => !v && (modal = null)">
        <form id="form-cta" class="space-y-4" @submit.prevent="guardar">
            <Campo v-model="form.monto" label="Importe" tipo="number" step="0.01" min="0.01" prefijo="$" required :error="form.errors.monto" />
            <Campo v-model="form.detalle" :label="modal === 'compra' ? 'Factura / remito (opcional)' : 'Detalle (opcional)'" maxlength="150" :error="form.errors.detalle" />
            <fieldset v-if="modal === 'pago'">
                <legend class="etiqueta">¿De dónde sale la plata?</legend>
                <div class="grid grid-cols-2 gap-2">
                    <label class="flex cursor-pointer items-start gap-2 rounded-xl border border-petroleo-200 p-3 text-sm has-[:checked]:border-petroleo-700 has-[:disabled]:opacity-50 dark:border-petroleo-700">
                        <input v-model="form.medio" type="radio" value="caja" :disabled="!cajaAbierta" class="mt-0.5 accent-petroleo-700" /><span><b>Del cajón</b><br>Se descuenta del efectivo del turno</span></label>
                    <label class="flex cursor-pointer items-start gap-2 rounded-xl border border-petroleo-200 p-3 text-sm has-[:checked]:border-petroleo-700 dark:border-petroleo-700">
                        <input v-model="form.medio" type="radio" value="otro" class="mt-0.5 accent-petroleo-700" /><span><b>Otro medio</b><br>Transferencia, cuenta del dueño…</span></label>
                </div>
            </fieldset>
        </form>
        <template #pie>
            <Boton @click="modal = null">Cancelar</Boton>
            <Boton tipo="submit" form="form-cta" variante="oscuro" :cargando="form.processing">{{ modal === 'compra' ? 'Cargar compra' : 'Registrar pago' }}</Boton>
        </template>
    </Modal>
</template>
