<script setup>
import { computed } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { ShoppingCart, Wallet, PackageX, Receipt } from 'lucide-vue-next';
import Encabezado from '@/Components/Encabezado.vue';
import Boton from '@/Components/Boton.vue';
import Dato from '@/Components/Dato.vue';
import Vacio from '@/Components/Vacio.vue';
import { plata, cantidad, unidad, hora } from '@/lib/formato';

const props = defineProps({ turno: Object, stockBajo: Array, cantStockBajo: Number, ultimas: Array });
const page = usePage();
const saludo = computed(() => {
    const h = new Date().getHours();
    return (h < 13 ? 'Buen día' : h < 20 ? 'Buenas tardes' : 'Buenas noches') + ', ' + page.props.auth.user.nombre.split(' ')[0];
});
</script>

<template>
    <Encabezado :titulo="saludo" :subtitulo="turno ? 'La caja está abierta.' : 'La caja está cerrada.'">
        <Boton v-if="turno" variante="principal" tam="lg" :icono="ShoppingCart" @click="router.post('/ventas')">Nueva venta</Boton>
        <Boton v-else href="/caja" variante="principal" tam="lg" :icono="Wallet">Abrir la caja</Boton>
    </Encabezado>

    <div v-if="turno" class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <Dato titulo="Vendido en el turno" :valor="plata(turno.total)" :nota="turno.ventas + (turno.ventas === 1 ? ' venta cobrada' : ' ventas cobradas')" />
        <Dato titulo="Efectivo en la caja" :valor="plata(turno.efectivo)" tono="entra" nota="Caja inicial + cobros − gastos − retiros" />
        <Dato v-if="turno.ganancia !== null" titulo="Ganancia estimada" :valor="plata(turno.ganancia)" nota="Precio de venta − costo" />
        <Dato titulo="Ventas sin cobrar" :valor="String(turno.pendientes)" :tono="turno.pendientes ? 'sale' : 'normal'" nota="Quedaron abiertas en Ventas" />
    </div>
    <div v-else class="panel flex flex-col items-start gap-4 p-6 sm:flex-row sm:items-center">
        <Wallet class="size-10 text-petroleo-300" stroke-width="1.5" />
        <div class="flex-1">
            <p class="font-semibold">Para vender, primero abrí la caja</p>
            <p class="text-sm text-petroleo-500">Cargá con cuánto efectivo arranca el turno (puede ser $ 0).</p>
        </div>
        <Boton href="/caja" variante="oscuro">Cargar caja inicial</Boton>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <section v-if="page.props.auth.permisos.includes('productos')" class="panel">
            <div class="flex items-center justify-between border-b border-petroleo-100 px-5 py-3.5 dark:border-petroleo-800">
                <h2 class="font-semibold">Para reponer</h2>
                <Link v-if="cantStockBajo" href="/productos?stock=bajo" class="text-sm font-medium text-petroleo-600 underline dark:text-petroleo-300">Ver los {{ cantStockBajo }}</Link>
            </div>
            <ul v-if="stockBajo.length" class="divide-y divide-petroleo-50 dark:divide-petroleo-800">
                <li v-for="p in stockBajo" :key="p.id">
                    <Link :href="`/productos/${p.id}/edit`" class="flex items-center gap-3 px-5 py-2.5 hover:bg-petroleo-50/60 dark:hover:bg-petroleo-800/40">
                        <span class="min-w-0 flex-1 truncate">{{ p.nombre }}</span>
                        <span class="cifra-suave text-sm" :class="p.quedan <= 0 ? 'text-sale' : 'text-cartel-700'">
                            {{ cantidad(p.quedan) }} {{ unidad(p.category?.unidad, p.quedan) }}
                        </span>
                    </Link>
                </li>
            </ul>
            <Vacio v-else :icono="PackageX" titulo="Nada para reponer" texto="Todos los productos están por encima del aviso de stock." />
        </section>

        <section class="panel">
            <div class="flex items-center justify-between border-b border-petroleo-100 px-5 py-3.5 dark:border-petroleo-800">
                <h2 class="font-semibold">Últimas ventas del turno</h2>
                <Link href="/ventas" class="text-sm font-medium text-petroleo-600 underline dark:text-petroleo-300">Ver todas</Link>
            </div>
            <ul v-if="ultimas.length" class="divide-y divide-petroleo-50 dark:divide-petroleo-800">
                <li v-for="v in ultimas" :key="v.id">
                    <Link :href="`/ventas/${v.id}`" class="flex items-center gap-3 px-5 py-2.5 hover:bg-petroleo-50/60 dark:hover:bg-petroleo-800/40">
                        <span class="w-14 text-sm text-petroleo-400">#{{ v.id }}</span>
                        <span class="min-w-0 flex-1 truncate">{{ v.cliente?.nombre ?? 'General' }}</span>
                        <span class="text-sm text-petroleo-400">{{ hora(v.created_at) }}</span>
                        <span class="cifra-suave w-24 text-right">{{ plata(v.monto - v.descuento) }}</span>
                    </Link>
                </li>
            </ul>
            <Vacio v-else :icono="Receipt" titulo="Todavía no hay ventas en este turno" />
        </section>
    </div>
</template>
