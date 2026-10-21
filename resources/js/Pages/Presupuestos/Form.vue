<script setup>
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { ArrowLeft, Save } from 'lucide-vue-next';
import Encabezado from '@/Components/Encabezado.vue';
import Boton from '@/Components/Boton.vue';
import Campo from '@/Components/Campo.vue';
import DocCliente from '@/Components/DocCliente.vue';
import DocRenglones from '@/Components/DocRenglones.vue';
import { plata, numero } from '@/lib/formato';
import { aLineas, clienteDe, datos, subtotal, primerError } from '@/lib/documentos';

const props = defineProps({ presupuesto: Object, copiaDe: String });
const p = props.presupuesto;
const editando = computed(() => !!p?.id);

const cliente = ref(clienteDe(p?.cliente));
const lineas = ref(aLineas(p?.items));
const form = useForm({ validez_dias: p?.validez_dias ?? 7, descuento: p?.descuento || '', observaciones: p?.observaciones ?? '' });

const sub = computed(() => subtotal(lineas.value));
const desc = computed(() => numero(form.descuento) || 0);
const total = computed(() => Math.max(0, Math.round((sub.value - desc.value) * 100) / 100));
const listas = computed(() => lineas.value.length > 0 && lineas.value.every((l) => (l.descripcion ?? '').trim() && (numero(l.cantidad) || 0) > 0));

function guardar() {
    const f = form.transform((d) => ({ ...d, descuento: numero(d.descuento) || 0, ...datos(cliente.value, lineas.value) }));
    editando.value ? f.put(`/presupuestos/${p.id}`) : f.post('/presupuestos');
}
</script>

<template>
    <Encabezado :titulo="editando ? `Presupuesto ${p.numero}` : 'Nuevo presupuesto'" :subtitulo="copiaDe ? `Copia del presupuesto ${copiaDe}` : 'No toca el stock ni la caja: es para imprimir y darle al cliente.'">
        <Boton :href="editando ? `/presupuestos/${p.id}` : '/presupuestos'" variante="fantasma" :icono="ArrowLeft">{{ editando ? 'Volver' : 'Presupuestos' }}</Boton>
    </Encabezado>

    <form class="grid gap-5 lg:grid-cols-[1fr_22rem]" @submit.prevent="guardar">
        <DocRenglones v-model="lineas" url="/presupuestos/productos" />

        <aside class="space-y-5">
            <div class="panel p-5"><DocCliente v-model="cliente" url="/presupuestos/clientes" /></div>
            <div class="panel space-y-4 p-5">
                <Campo v-model="form.validez_dias" label="Válido por (días)" tipo="number" min="1" max="365" required :error="form.errors.validez_dias" />
                <Campo v-model="form.descuento" label="Descuento" prefijo="$" inputmode="decimal" placeholder="0" :error="form.errors.descuento" />
                <Campo v-model="form.observaciones" label="Observaciones (opcional)" tipo="textarea" maxlength="1000" placeholder="Ej: precios con IVA incluido, entrega en 48 hs, forma de pago..." />
                <dl class="space-y-1 border-t border-petroleo-100 pt-3 text-sm dark:border-petroleo-800">
                    <div class="flex justify-between"><dt class="text-petroleo-500">Subtotal</dt><dd>{{ plata(sub) }}</dd></div>
                    <div v-if="desc" class="flex justify-between"><dt class="text-petroleo-500">Descuento</dt><dd :class="{ 'text-sale': desc > sub }">− {{ plata(desc) }}</dd></div>
                    <div class="flex items-baseline justify-between text-lg font-bold"><dt>Total</dt><dd>{{ plata(total) }}</dd></div>
                </dl>
                <p v-if="form.hasErrors" class="text-sm text-sale">{{ primerError(form.errors) }}</p>
                <Boton tipo="submit" variante="oscuro" class="w-full" :icono="Save" :disabled="!listas || desc > sub" :cargando="form.processing">{{ editando ? 'Guardar cambios' : 'Guardar presupuesto' }}</Boton>
                <p v-if="lineas.length && !listas" class="text-xs text-petroleo-500">Cada renglón necesita descripción y cantidad.</p>
            </div>
        </aside>
    </form>
</template>
