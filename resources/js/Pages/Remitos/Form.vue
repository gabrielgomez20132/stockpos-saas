<script setup>
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { ArrowLeft, Save, Info } from 'lucide-vue-next';
import Encabezado from '@/Components/Encabezado.vue';
import Boton from '@/Components/Boton.vue';
import Campo from '@/Components/Campo.vue';
import DocCliente from '@/Components/DocCliente.vue';
import DocRenglones from '@/Components/DocRenglones.vue';
import { plata, cantidad, unidad, numero, fecha } from '@/lib/formato';
import { aLineas, clienteDe, datos, subtotal, primerError } from '@/lib/documentos';

const props = defineProps({ venta: Object, sucursalNombre: String });
const v = props.venta;

// de una venta: se arranca con lo que falta entregar de cada producto (y los otros conceptos cobrados)
const completos = v ? v.lineas.filter((l) => l.cantidad <= 0) : [];
const inicial = v ? [
    ...v.lineas.filter((l) => l.cantidad > 0).map((l) => ({ ...l, id_producto: l.existe ? l.id_producto : null, max: l.existe ? l.cantidad : null,
        maxTexto: l.existe ? (l.cantidad < l.vendido ? `por entregar (de ${cantidad(l.vendido)} vendidos)` : 'vendidos') : '' })),
    ...v.servicios.map((s) => ({ id_producto: null, codigo: '', descripcion: s.descripcion, cantidad: 1, precio: s.precio })),
] : [];

const cliente = ref(clienteDe(v?.cliente));
const lineas = ref(aLineas(inicial));
const form = useForm({ observaciones: '' });

const renglones = ref(null);
const total = computed(() => subtotal(lineas.value));
const excedido = computed(() => lineas.value.some((l) => renglones.value?.excede(l)));
const listas = computed(() => lineas.value.length > 0 && lineas.value.every((l) => (l.descripcion ?? '').trim() && (numero(l.cantidad) || 0) > 0));

function guardar() {
    form.transform((d) => ({ ...d, id_order: v?.id ?? null, ...datos(cliente.value, lineas.value) })).post('/remitos');
}
</script>

<template>
    <Encabezado :titulo="v ? `Remito de la venta #${v.id}` : 'Nuevo remito'" :subtitulo="v ? `Venta del ${fecha(v.fecha, true)} · ${plata(v.total)}` : `Entrega sin venta: descuenta el stock de ${sucursalNombre}`">
        <Boton :href="v ? `/ventas/${v.id}` : '/remitos'" variante="fantasma" :icono="ArrowLeft">{{ v ? 'Venta' : 'Remitos' }}</Boton>
    </Encabezado>

    <div v-if="completos.length" class="panel mb-4 flex gap-3 p-4 text-sm">
        <Info class="size-5 shrink-0 text-petroleo-400" />
        <p>Ya se entregaron completos en otros remitos: <b>{{ completos.map((l) => l.descripcion).join(', ') }}</b>.</p>
    </div>

    <form class="grid gap-5 lg:grid-cols-[1fr_22rem]" @submit.prevent="guardar">
        <DocRenglones ref="renglones" v-model="lineas" :url="v ? null : '/remitos/productos'" :con-stock="!v"
            :vacio="v ? 'Todo lo de esta venta ya se entregó. Podés agregar renglones a mano.' : 'Agregá los productos que se entregan.'" />

        <aside class="space-y-5">
            <div class="panel p-5"><DocCliente v-model="cliente" url="/remitos/clientes" /></div>
            <div class="panel space-y-4 p-5">
                <Campo v-model="form.observaciones" label="Observaciones (opcional)" tipo="textarea" maxlength="1000" placeholder="Ej: entrega en domicilio, transporte, bultos..." />
                <p class="flex items-baseline justify-between text-sm"><span class="text-petroleo-500">Valor de la mercadería</span><span class="text-lg font-bold">{{ plata(total) }}</span></p>
                <p v-if="form.hasErrors" class="text-sm text-sale">{{ primerError(form.errors) }}</p>
                <Boton tipo="submit" variante="oscuro" class="w-full" :icono="Save" :disabled="!listas || excedido" :cargando="form.processing">Guardar remito</Boton>
                <p class="text-xs text-petroleo-500">
                    <template v-if="v">La mercadería ya salió del stock con la venta: el remito no lo vuelve a descontar. Podés entregar en partes bajando las cantidades.</template>
                    <template v-else>Los productos se descuentan del stock de {{ sucursalNombre }} al guardar. Si anulás el remito, el stock vuelve. Los renglones a mano no mueven stock.</template>
                    Los precios se muestran solo si los elegís al imprimir.
                </p>
            </div>
        </aside>
    </form>
</template>
