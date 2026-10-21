<script setup>
import { computed } from 'vue';
import { plata } from '@/lib/formato';

// una etiqueta, medida en milimetros (sirve para la vista previa y para imprimir)
const props = defineProps({ producto: Object, config: Object, barra: String, sucursal: String, fecha: String });
const c = computed(() => props.config);
const H = computed(() => Number(c.value.alto) || 30);
const W = computed(() => Number(c.value.ancho) || 40);
const base = computed(() => Math.min(H.value, W.value * 0.9));
const tamPrecio = computed(() => ({ chico: 0.15, mediano: 0.21, grande: 0.28 }[c.value.tam_precio] ?? 0.24) * base.value);
const tamNombre = computed(() => Math.max(2, Math.min(4.2, base.value * 0.115)));
const tamCodigo = computed(() => Math.max(1.8, Math.min(3, base.value * 0.075)));
const altoBarras = computed(() => {
    let libre = H.value - 3 - (c.value.nombre ? tamNombre.value * 2.3 : 0) - (c.value.precio ? tamPrecio.value * 1.15 : 0) - (c.value.codigo ? tamCodigo.value * 1.3 : 0) - ((c.value.sucursal || c.value.fecha) ? 2.6 : 0);
    return Math.max(4, Math.min(libre, H.value * 0.45));
});
const mm = (v) => `${Math.round(v * 100) / 100}mm`;
</script>

<template>
    <div class="etiqueta" :style="{ width: mm(W), height: mm(H), padding: '1.2mm 1.5mm' }">
        <div v-if="c.nombre" class="nombre" :style="{ fontSize: mm(tamNombre), lineHeight: 1.12 }">{{ producto.nombre }}</div>
        <div v-if="c.precio" class="precio" :style="{ fontSize: mm(tamPrecio) }">{{ plata(producto.monto) }}</div>
        <template v-if="c.barras">
            <img v-if="barra" :src="barra" alt="" class="barras" :style="{ height: mm(altoBarras) }" />
            <div v-else class="sin-barras" :style="{ height: mm(altoBarras), fontSize: mm(tamCodigo) }">sin código de barras</div>
        </template>
        <div v-if="c.codigo" class="codigo" :style="{ fontSize: mm(tamCodigo) }">{{ producto.codigo }}</div>
        <div v-if="c.sucursal || c.fecha" class="pie" :style="{ fontSize: mm(Math.max(1.7, tamCodigo * 0.85)) }">
            <span>{{ c.sucursal ? sucursal : '' }}</span><span>{{ c.fecha ? fecha : '' }}</span>
        </div>
    </div>
</template>

<style scoped>
.etiqueta { box-sizing: border-box; display: flex; flex-direction: column; justify-content: space-between; align-items: center; overflow: hidden; background: #fff; color: #000; font-family: Arial, Helvetica, sans-serif; text-align: center; }
.nombre { width: 100%; font-weight: 600; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; word-break: break-word; }
.precio { font-weight: 800; line-height: 1; letter-spacing: -0.02em; }
.barras { width: 82%; object-fit: fill; image-rendering: crisp-edges; } /* margen en blanco a los costados para que lo lea el lector */
.sin-barras { display: grid; place-items: center; color: #999; }
.codigo { letter-spacing: 0.08em; line-height: 1; }
.pie { width: 100%; display: flex; justify-content: space-between; color: #333; line-height: 1; }
</style>
