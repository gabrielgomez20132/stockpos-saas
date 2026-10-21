<script setup>
import { Link } from '@inertiajs/vue3';
import { ArrowLeft } from 'lucide-vue-next';
import Encabezado from '@/Components/Encabezado.vue';
import Boton from '@/Components/Boton.vue';
import FiltroFechas from '@/Components/FiltroFechas.vue';
import Vacio from '@/Components/Vacio.vue';
import { plata, fecha, dia } from '@/lib/formato';

defineProps({ cliente: Object, saldo: Number, ventas: Array, movimientos: Array, filtro: Object });
</script>

<template>
    <Encabezado :titulo="cliente.nombre" :subtitulo="`Del ${dia(filtro.desde)} al ${dia(filtro.hasta)}`">
        <Boton href="/clientes" variante="fantasma" :icono="ArrowLeft">Clientes</Boton>
    </Encabezado>
    <div class="mb-5 flex flex-wrap items-end justify-between gap-4">
        <FiltroFechas :filtro="filtro" :url="`/clientes/${cliente.id}/historial`" texto-limpiar="Últimos 60 días" />
        <p class="text-right"><span class="text-sm text-petroleo-500">Saldo de la cuenta</span><br><span class="cifra text-4xl" :class="saldo > 0 ? 'text-sale' : 'text-entra'">{{ saldo > 0 ? plata(saldo) : 'Al día' }}</span></p>
    </div>
    <div class="grid gap-5 lg:grid-cols-2">
        <section class="panel overflow-hidden">
            <h2 class="border-b border-petroleo-100 px-5 py-3.5 font-semibold dark:border-petroleo-800">Compras</h2>
            <ul v-if="ventas.length" class="divide-y divide-petroleo-50 dark:divide-petroleo-800">
                <li v-for="v in ventas" :key="v.id">
                    <Link :href="`/ventas/${v.id}`" class="flex items-center gap-3 px-5 py-2.5 hover:bg-petroleo-50/60 dark:hover:bg-petroleo-800/40">
                        <span class="min-w-0 flex-1"><span class="font-medium">#{{ v.id }} · {{ v.forma_pago }}</span><br><span class="text-xs text-petroleo-500">{{ fecha(v.created_at, true) }} · {{ v.encargado }}</span></span>
                        <span class="text-right"><span class="cifra-suave">{{ plata(v.monto - v.descuento) }}</span><br><span v-if="v.fiado > 0" class="text-xs text-sale">fiado {{ plata(v.fiado) }}</span></span>
                    </Link>
                </li>
            </ul>
            <Vacio v-else titulo="Sin compras en estas fechas" />
        </section>
        <section class="panel overflow-hidden">
            <h2 class="border-b border-petroleo-100 px-5 py-3.5 font-semibold dark:border-petroleo-800">Cuenta corriente</h2>
            <ul v-if="movimientos.length" class="divide-y divide-petroleo-50 dark:divide-petroleo-800">
                <li v-for="m in movimientos" :key="m.id" class="flex items-center gap-3 px-5 py-2.5">
                    <span class="min-w-0 flex-1"><span class="font-medium">{{ m.tipo === 'D' ? 'Fiado' : 'Pago' }}</span> <span class="text-sm text-petroleo-500">{{ m.detalle }}</span><br><span class="text-xs text-petroleo-500">{{ fecha(m.created_at, true) }} · {{ m.encargado }}</span></span>
                    <span class="cifra-suave" :class="m.tipo === 'D' ? 'text-sale' : 'text-entra'">{{ m.tipo === 'D' ? '+' : '−' }} {{ plata(m.monto) }}</span>
                </li>
            </ul>
            <Vacio v-else titulo="Sin movimientos en estas fechas" />
        </section>
    </div>
</template>
