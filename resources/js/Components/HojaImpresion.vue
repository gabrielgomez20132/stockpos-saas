<script setup>
import { onMounted } from 'vue';
import { Head } from '@inertiajs/vue3';
import { Printer, X } from 'lucide-vue-next';

const props = defineProps({ titulo: String, auto: { type: Boolean, default: true } });
const imprimir = () => window.print();
const cerrar = () => (window.opener ? window.close() : history.back());
onMounted(() => props.auto && setTimeout(imprimir, 400));
</script>

<template>
    <Head :title="titulo" />
    <div class="min-h-dvh bg-petroleo-100 py-4 print:bg-white print:py-0 dark:bg-petroleo-950">
        <div class="mx-auto mb-3 flex max-w-[80mm] gap-2 print:hidden" style="min-width: 300px">
            <button class="flex flex-1 items-center justify-center gap-2 rounded-xl bg-petroleo-800 py-2.5 text-sm font-semibold text-white" @click="imprimir"><Printer class="size-4" />Imprimir</button>
            <button class="flex items-center justify-center gap-2 rounded-xl border border-petroleo-300 bg-white px-4 py-2.5 text-sm" @click="cerrar"><X class="size-4" />Cerrar</button>
        </div>
        <article class="hoja mx-auto max-w-[80mm] bg-white p-4 text-[12.5px] leading-snug text-black shadow print:max-w-none print:p-0 print:shadow-none" style="min-width: 300px">
            <slot />
        </article>
    </div>
</template>

<style>
@media print {
    @page { margin: 4mm; }
    html.dark { color-scheme: light; }
}
.hoja hr { border: 0; border-top: 1px dashed #000; margin: 8px 0; }
.hoja table { width: 100%; border-collapse: collapse; }
.hoja td, .hoja th { padding: 2px 0; vertical-align: top; }
</style>
