<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';

const page = usePage();
const ctrl = computed(() => page.props.auth.permisos.includes('control'));
const tabs = computed(() => [
    { n: 'Caja inicial', u: '/caja' },
    { n: 'Retiros', u: '/caja/retiros' },
    ctrl.value && { n: 'Proveedores', u: '/caja/gastos/proveedores' },
    ctrl.value && { n: 'Servicios', u: '/caja/gastos/servicios' },
    ctrl.value && { n: 'Gastos varios', u: '/caja/gastos/varios' },
    ctrl.value && { n: 'Movimientos', u: '/caja/movimientos' },
    { n: 'Cierres', u: '/caja/cierres' },
].filter(Boolean));
const actual = computed(() => page.url.split('?')[0]);
</script>

<template>
    <nav class="-mx-4 mb-5 overflow-x-auto px-4 sm:mx-0 sm:px-0">
        <div class="flex w-max gap-1 rounded-xl bg-petroleo-100/70 p-1 dark:bg-petroleo-900">
            <Link v-for="t in tabs" :key="t.u" :href="t.u" class="rounded-lg px-3.5 py-2 text-sm whitespace-nowrap"
                :class="actual === t.u || (t.u === '/caja/cierres' && actual.startsWith('/caja/cierres')) ? 'bg-white font-semibold shadow-sm dark:bg-petroleo-700' : 'text-petroleo-600 hover:text-petroleo-900 dark:text-petroleo-300'">
                {{ t.n }}
            </Link>
        </div>
    </nav>
</template>
