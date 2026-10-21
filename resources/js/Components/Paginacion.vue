<script setup>
import { Link } from '@inertiajs/vue3';
defineProps({ datos: Object });
</script>

<template>
    <nav v-if="datos && datos.last_page > 1" class="mt-4 flex flex-wrap items-center justify-between gap-3 text-sm">
        <span class="text-petroleo-500">{{ datos.from }}–{{ datos.to }} de {{ datos.total }}</span>
        <div class="flex gap-1">
            <template v-for="(l, i) in datos.links" :key="i">
                <Link v-if="l.url" :href="l.url" preserve-scroll
                    class="min-w-9 rounded-lg px-3 py-1.5 text-center"
                    :class="l.active ? 'bg-petroleo-800 text-white' : 'border border-petroleo-200 bg-white hover:bg-petroleo-50 dark:border-petroleo-700 dark:bg-petroleo-900'">
                    <span v-html="l.label.replace('&laquo; Previous', '‹').replace('Next &raquo;', '›').replace('pagination.previous', '‹').replace('pagination.next', '›')" />
                </Link>
            </template>
        </div>
    </nav>
</template>
