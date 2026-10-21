<script setup>
import { ref, computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { FileText } from 'lucide-vue-next';
import Modal from './Modal.vue';
import Boton from './Boton.vue';
import Campo from './Campo.vue';
import { plata, numero } from '@/lib/formato';

const abierto = defineModel({ type: Boolean });
const props = defineProps({ venta: Object, monotributo: Boolean });

const conCuit = ref(false);
const extra = ref(false);
const form = useForm({ tipo: props.monotributo ? 'C' : 'B', tipo_pago: 'Efectivo', concepto: 'Detalle', nombre: '', cuit: '', detalle_opcional: '', monto_opcional: '' });
const total = computed(() => props.venta.monto - props.venta.descuento + (extra.value ? numero(form.monto_opcional) || 0 : 0));

function emitir() {
    form.transform((d) => ({
        ...d,
        tipo: props.monotributo ? 'C' : d.tipo,
        nombre: conCuit.value ? d.nombre : null, cuit: conCuit.value ? d.cuit : null,
        detalle_opcional: extra.value ? d.detalle_opcional : null, monto_opcional: extra.value ? numero(d.monto_opcional) : null,
    })).post(`/afip/facturar/${props.venta.id}`, { preserveScroll: true, onSuccess: () => (abierto.value = false) });
}
const opcion = (activo) => activo ? 'border-petroleo-800 bg-petroleo-800 text-white dark:bg-petroleo-600' : 'border-petroleo-200 dark:border-petroleo-700';
</script>

<template>
    <Modal v-model="abierto" titulo="Facturar venta" ancho="max-w-xl">
        <form id="form-fact" class="space-y-5" @submit.prevent="emitir">
            <div v-if="!monotributo">
                <span class="etiqueta">Tipo de factura</span>
                <div class="grid grid-cols-2 gap-2">
                    <button v-for="t in ['B', 'A']" :key="t" type="button" class="rounded-xl border px-3 py-2.5 text-left text-sm" :class="opcion(form.tipo === t)"
                        @click="form.tipo = t; if (t === 'A') conCuit = true">
                        <b class="text-base">Factura {{ t }}</b><br>{{ t === 'B' ? 'Consumidor final / monotributo' : 'Responsable inscripto' }}
                    </button>
                </div>
            </div>
            <p v-else class="rounded-xl bg-papel px-4 py-3 text-sm dark:bg-petroleo-950">Factura <b>C</b> (monotributo): sin IVA discriminado.</p>

            <div class="grid grid-cols-2 gap-2">
                <div><span class="etiqueta">Forma de pago</span>
                    <select v-model="form.tipo_pago" class="campo"><option>Efectivo</option><option>Tarjeta</option></select></div>
                <div><span class="etiqueta">Detalle</span>
                    <select v-model="form.concepto" class="campo"><option value="Detalle">Productos uno por uno</option><option value="Varios">Una línea "Varios"</option></select></div>
            </div>

            <div class="space-y-3">
                <label class="flex items-center gap-2 text-sm"><input v-model="conCuit" type="checkbox" :disabled="form.tipo === 'A'" class="size-4 accent-petroleo-700" />A nombre de un cliente con CUIT</label>
                <div v-if="conCuit" class="grid gap-3 sm:grid-cols-2">
                    <Campo v-model="form.nombre" label="Nombre o razón social" maxlength="35" required :error="form.errors.nombre" />
                    <Campo v-model="form.cuit" label="CUIT" inputmode="numeric" placeholder="20-12345678-9" required :error="form.errors.cuit" />
                </div>
                <label class="flex items-center gap-2 text-sm"><input v-model="extra" type="checkbox" class="size-4 accent-petroleo-700" />Agregar un ítem extra (ej: envío)</label>
                <div v-if="extra" class="grid gap-3 sm:grid-cols-[1fr_9rem]">
                    <Campo v-model="form.detalle_opcional" label="Detalle" maxlength="100" required :error="form.errors.detalle_opcional" />
                    <Campo v-model="form.monto_opcional" label="Importe" inputmode="decimal" prefijo="$" required :error="form.errors.monto_opcional" />
                </div>
            </div>
            <div class="flex items-end justify-between rounded-xl bg-papel px-4 py-3 dark:bg-petroleo-950">
                <span class="text-sm">Total a facturar</span><span class="cifra text-3xl">{{ plata(total) }}</span>
            </div>
        </form>
        <template #pie>
            <Boton @click="abierto = false">Cancelar</Boton>
            <Boton tipo="submit" form="form-fact" variante="oscuro" :icono="FileText" :cargando="form.processing">Pedir CAE y emitir</Boton>
        </template>
    </Modal>
</template>
