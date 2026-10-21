<script setup>
import { computed, onMounted, onBeforeUnmount } from 'vue';
import { Head } from '@inertiajs/vue3';
import Etiqueta from '@/Components/Etiqueta.vue';

const props = defineProps({ config: Object, etiquetas: Array, barras: Object, sucursal: String, negocio: String });
const c = props.config;
const hoy = new Date().toLocaleDateString('es-AR', { day: '2-digit', month: '2-digit', year: '2-digit' });
const HOJAS = { A4: [210, 297], Carta: [216, 279], Oficio: [216, 356] };
const hoja = computed(() => HOJAS[c.hoja] ?? HOJAS.A4);
const porFila = computed(() => c.columnas);
const filasPorHoja = computed(() => Math.max(1, Math.floor((hoja.value[1] - 2 * c.margen + Number(c.separacion)) / (Number(c.alto) + Number(c.separacion)))));
const entraAncho = computed(() => c.columnas * c.ancho + (c.columnas - 1) * c.separacion <= hoja.value[0] - 2 * c.margen + 0.5);

let estilo;
onMounted(() => {
    estilo = document.createElement('style');
    estilo.textContent = c.formato === 'rollo'
        ? `@page { size: ${c.ancho}mm ${c.alto}mm; margin: 0 } html, body { margin: 0 !important; background: #fff }`
        : `@page { size: ${c.hoja === 'A4' ? 'A4' : `${hoja.value[0]}mm ${hoja.value[1]}mm`}; margin: ${c.margen}mm } html, body { margin: 0 !important; background: #fff }`;
    document.head.appendChild(estilo);
    const imgs = [...document.images];
    Promise.all(imgs.map((i) => (i.complete ? null : new Promise((r) => { i.onload = i.onerror = r; })))).then(() => setTimeout(() => window.print(), 300));
});
onBeforeUnmount(() => estilo?.remove());
const imprimir = () => window.print();
</script>

<template>
    <Head title="Etiquetas" />
    <div class="barra-pantalla print:hidden">
        <b>{{ etiquetas.length }} etiquetas de {{ c.ancho }} × {{ c.alto }} mm</b>
        <span v-if="c.formato === 'rollo'">· impresora de etiquetas (una por etiqueta). En la ventana de impresión elegí la impresora y tamaño de papel {{ c.ancho }}×{{ c.alto }} mm, márgenes "Ninguno" y escala 100%.</span>
        <span v-else>· hoja {{ c.hoja }}, {{ c.columnas }} por fila y {{ filasPorHoja }} filas por hoja. Imprimí con escala 100% (sin "ajustar a la página").
            <b v-if="!entraAncho" style="color:#b83a26">Ojo: {{ c.columnas }} columnas de {{ c.ancho }} mm no entran en el ancho de la hoja.</b></span>
        <button type="button" @click="imprimir">Imprimir</button>
    </div>

    <div v-if="c.formato === 'rollo'" class="rollo">
        <div v-for="(e, i) in etiquetas" :key="i" class="pagina" :class="{ guias: c.guias }">
            <Etiqueta :producto="e" :config="c" :barra="barras[e.codigo]" :sucursal="sucursal" :fecha="hoy" />
        </div>
    </div>
    <div v-else class="hoja" :style="{ gridTemplateColumns: `repeat(${porFila}, ${c.ancho}mm)`, gap: `${c.separacion}mm` }">
        <div v-for="(e, i) in etiquetas" :key="i" class="celda" :class="{ guias: c.guias }">
            <Etiqueta :producto="e" :config="c" :barra="barras[e.codigo]" :sucursal="sucursal" :fecha="hoy" />
        </div>
    </div>
</template>

<style scoped>
.barra-pantalla { position: sticky; top: 0; z-index: 5; display: flex; flex-wrap: wrap; gap: 6px 10px; align-items: center; padding: 10px 14px; background: #0e3b43; color: #fff; font: 13px/1.4 Arial, sans-serif; }
.barra-pantalla button { margin-left: auto; background: #f2b705; color: #0e3b43; border: 0; border-radius: 8px; padding: 8px 16px; font-weight: 700; cursor: pointer; }
.rollo { display: flex; flex-wrap: wrap; gap: 4mm; padding: 6mm; background: #e9efee; }
.pagina { background: #fff; }
.hoja { display: grid; padding: 6mm; background: #fff; }
.guias { outline: 0.15mm dashed #bbb; }
@media print {
    .rollo { display: block; padding: 0; gap: 0; background: #fff; }
    .pagina { page-break-after: always; break-after: page; outline: none; }
    .pagina:last-child { page-break-after: auto; break-after: auto; }
    .hoja { padding: 0; }
    .celda { break-inside: avoid; }
}
</style>
