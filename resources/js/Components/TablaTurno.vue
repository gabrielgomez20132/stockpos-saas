<script setup>
import { computed } from 'vue';
import { plata } from '@/lib/formato';

/** Resumen de un turno o rango: mismo calculo que la pantalla Movimientos de control-soft. */
const props = defineProps({ d: Object, verGanancia: { type: Boolean, default: true } });

const grupos = computed(() => [
    { titulo: 'Entró', tono: 'entra', filas: [
        ['Caja inicial', props.d.caja_inicial],
        ['Ventas de mercadería (bruto)', props.d.ingXmercaderias],
        ['Cobros de cuentas corrientes', props.d.ingXpago_deudas],
    ] },
    { titulo: 'No entró / salió', tono: 'sale', filas: [
        ['Descuentos', props.d.descuentos],
        ['Fiado (cuenta corriente)', props.d.fiado],
        ['Pagado con vales', props.d.total_vale ?? 0],
        ['Pagos a proveedores', props.d.gastXprov],
        ['Servicios', props.d.gastXserv],
        ['Gastos varios', props.d.gastosVarios],
        ['Retiros', props.d.retiros],
    ] },
    { titulo: 'Tiene que haber', tono: 'normal', filas: [
        ['Efectivo en el cajón', props.d.total_efec, true],
        ['Tarjeta de débito', props.d.total_tarj],
        ['Tarjeta de crédito', props.d.total_tarj_cred],
        ['QR', props.d.total_qr],
        ['Transferencia', props.d.total_transf],
        ['Cheque', props.d.total_cheque],
        ...(props.d.total_dolares ? [['Dólares', props.d.total_dolares]] : []),
    ] },
]);
const totalGeneral = computed(() => ['total_efec', 'total_tarj', 'total_tarj_cred', 'total_qr', 'total_transf', 'total_cheque', 'total_dolares']
    .reduce((a, k) => a + Number(props.d[k] || 0), 0));
</script>

<template>
    <div class="grid gap-4 lg:grid-cols-3">
        <section v-for="g in grupos" :key="g.titulo" class="panel p-5 print:break-inside-avoid print:border-petroleo-300">
            <h3 class="mb-2 font-semibold" :class="{ 'text-entra': g.tono === 'entra', 'text-sale': g.tono === 'sale' }">{{ g.titulo }}</h3>
            <dl class="divide-y divide-petroleo-50 dark:divide-petroleo-800">
                <div v-for="f in g.filas" :key="f[0]" class="flex items-baseline justify-between gap-3 py-2" :class="{ 'text-petroleo-300 dark:text-petroleo-600': !Number(f[1]) && !f[2] }">
                    <dt class="text-sm" :class="{ 'font-semibold text-base': f[2] }">{{ f[0] }}</dt>
                    <dd :class="f[2] ? 'cifra text-2xl text-entra' : 'cifra-suave'">{{ plata(f[1]) }}</dd>
                </div>
            </dl>
        </section>
    </div>
    <div class="mt-4 grid gap-4 sm:grid-cols-2">
        <div class="panel flex items-center justify-between p-5">
            <span class="font-semibold">Total en caja, todos los medios</span>
            <span class="cifra text-3xl">{{ plata(totalGeneral) }}</span>
        </div>
        <div v-if="verGanancia" class="panel flex items-center justify-between p-5">
            <span><span class="font-semibold">Ganancia estimada</span><br><span class="text-xs text-petroleo-500">Mercadería {{ plata(d.ganXmercaderias) }} + otros conceptos {{ plata(d.ganXservicios) }}</span></span>
            <span class="cifra text-3xl text-entra">{{ plata(Number(d.ganXmercaderias) + Number(d.ganXservicios)) }}</span>
        </div>
    </div>
</template>
