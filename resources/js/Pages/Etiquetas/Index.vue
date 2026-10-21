<script setup>
import { computed, ref, watch } from 'vue';
import { Search, Plus, Minus, Trash2, Printer, FileDown, ListPlus, RotateCcw, Tag, PackageCheck } from 'lucide-vue-next';
import { generarPdf } from '@/lib/etiquetasPdf';
import Encabezado from '@/Components/Encabezado.vue';
import Boton from '@/Components/Boton.vue';
import Etiqueta from '@/Components/Etiqueta.vue';
import http, { mensajeError } from '@/lib/http';
import { toast, toastError } from '@/lib/toast';
import { plata, cantidad } from '@/lib/formato';

const props = defineProps({ categorias: Array, config: Object, defecto: Object, seleccion: Array, multisucursal: Boolean });

const cfg = ref({ ...props.config });
const elegidos = ref(props.seleccion.map((p) => ({ ...p })));

// ---- buscar productos
const q = ref('');
const categoria = ref(null);
const resultados = ref([]);
const buscando = ref(false);
let t;
async function buscar(todos = false) {
    if (!q.value.trim() && !categoria.value) return (resultados.value = []);
    buscando.value = true;
    try {
        resultados.value = (await http.get('/etiquetas/buscar', { params: { q: q.value, categoria: categoria.value, todos: todos ? 1 : 0 } })).data;
        return resultados.value;
    } catch (e) { toastError(mensajeError(e)); return []; } finally { buscando.value = false; }
}
watch([q, categoria], () => { clearTimeout(t); t = setTimeout(() => buscar(), 250); });
const esta = (id) => elegidos.value.some((e) => e.id === id);
function agregar(p, copias = 1) {
    const ya = elegidos.value.find((e) => e.id === p.id);
    if (ya) ya.copias = Math.min(999, ya.copias + copias);
    else elegidos.value.push({ ...p, copias });
}
function enter() {
    const exacto = resultados.value.find((p) => p.codigo === q.value.trim());
    if (exacto || resultados.value.length === 1) { agregar(exacto ?? resultados.value[0]); q.value = ''; }
}
const cargandoTodo = ref(false);
async function todoElCatalogo(yPdf = false) {
    cargandoTodo.value = true;
    try {
        const lista = (await http.get('/etiquetas/buscar', { params: { catalogo: 1 } })).data;
        let n = 0;
        lista.forEach((p) => { if (!esta(p.id)) { agregar(p); n++; } });
        toast(n ? `Se agregaron ${n} productos (todos los activos).` : 'Ya estaban todos los productos.');
        if (yPdf && elegidos.value.length) await pdf();
    } catch (e) { toastError(mensajeError(e)); } finally { cargandoTodo.value = false; }
}
async function agregarTodos() {
    const lista = await buscar(true);
    lista.forEach((p) => !esta(p.id) && agregar(p));
    toast(`Se agregaron ${lista.length} productos.`);
}
const marcados = ref([]);
function agregarMarcados() { resultados.value.filter((p) => marcados.value.includes(p.id)).forEach((p) => !esta(p.id) && agregar(p)); marcados.value = []; }
function copias(e, d) { e.copias = Math.max(1, Math.min(999, (Number(e.copias) || 1) + d)); }
function copiasStock() { elegidos.value.forEach((e) => (e.copias = Math.max(1, Math.min(999, Math.ceil(e.quedan || 0) || 1)))); }
function copiasTodas(n) { elegidos.value.forEach((e) => (e.copias = n)); }
const total = computed(() => elegidos.value.reduce((a, e) => a + (Number(e.copias) || 0), 0));

// ---- vista previa
const MEDIDAS = [[40, 30], [50, 25], [50, 30], [58, 40], [60, 40], [38, 25], [32, 19], [100, 50]];
const muestra = computed(() => elegidos.value[0] ?? resultados.value[0] ?? { nombre: 'Coca-Cola 500 ml', codigo: '7790895000997', monto: 1500 });
const barra = computed(() => `/etiquetas/barra?codigo=${encodeURIComponent(muestra.value.codigo)}`);
const escala = computed(() => Math.min(3.2, 260 / (cfg.value.ancho * 3.78)));
const HOJAS = { A4: [210, 297], Carta: [216, 279], Oficio: [216, 356] };
const entran = computed(() => {
    const [w, h] = HOJAS[cfg.value.hoja];
    const m = Number(cfg.value.margen), s = Number(cfg.value.separacion);
    const cols = Math.floor((w - 2 * m + s) / (Number(cfg.value.ancho) + s));
    const filas = Math.floor((h - 2 * m + s) / (Number(cfg.value.alto) + s));
    return { cols: Math.max(0, cols), filas: Math.max(0, filas) };
});
watch(() => [cfg.value.ancho, cfg.value.alto, cfg.value.hoja, cfg.value.margen, cfg.value.separacion], () => {
    if (cfg.value.formato === 'hoja' && cfg.value.columnas > entran.value.cols && entran.value.cols > 0) cfg.value.columnas = entran.value.cols;
});
const hoy = new Date().toLocaleDateString('es-AR', { day: '2-digit', month: '2-digit', year: '2-digit' });

// ---- imprimir / pdf
const datos = () => ({ items: elegidos.value.map((e) => ({ id: e.id, copias: Number(e.copias) || 1 })), config: cfg.value });
const imprimiendo = ref(false);
async function imprimir() {
    if (!elegidos.value.length) return toastError('Elegí al menos un producto.');
    const w = window.open('', 'etiquetas', 'width=900,height=800'); // se abre ya (si no, el navegador la bloquea)
    imprimiendo.value = true;
    try {
        const { data } = await http.post('/etiquetas/preparar', datos());
        if (w) w.location.href = data.url; else window.location.href = data.url;
    } catch (e) { w?.close(); toastError(mensajeError(e)); } finally { imprimiendo.value = false; }
}
const bajando = ref(false);
async function pdf() {
    if (!elegidos.value.length) return toastError('Elegí al menos un producto.');
    bajando.value = true;
    try {
        const { data } = await http.post('/etiquetas/pdf', datos());
        const doc = await generarPdf(data, cfg.value.formato === 'hoja' && cfg.value.guias);
        doc.save(`etiquetas_${cfg.value.ancho}x${cfg.value.alto}_${new Date().toISOString().slice(0, 10)}.pdf`);
        toast(`PDF listo: ${data.etiquetas.length} etiquetas.`);
    } catch (e) { toastError(e?.response ? mensajeError(e) : 'No se pudo armar el PDF: ' + (e?.message ?? e)); } finally { bajando.value = false; }
}
</script>

<template>
    <Encabezado titulo="Etiquetas" subtitulo="Códigos de barras con nombre y precio, para impresora de etiquetas o en hojas A4: imprimí directo o descargá el PDF. Elegí los productos de a uno o varios a la vez." />

    <div class="grid gap-5 xl:grid-cols-[1fr_24rem]">
        <div class="min-w-0 space-y-5">
            <!-- buscar -->
            <section class="panel overflow-visible">
                <div class="grid gap-2 border-b border-petroleo-100 p-4 sm:grid-cols-[1fr_14rem] dark:border-petroleo-800">
                    <div class="relative">
                        <Search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-petroleo-300" />
                        <input v-model="q" class="campo pl-9" placeholder="Escaneá o buscá productos por nombre o código" autofocus @keydown.enter.prevent="enter" />
                    </div>
                    <select v-model="categoria" class="campo"><option :value="null">Todas las categorías</option><option v-for="c in categorias" :key="c.id" :value="c.id">{{ c.nombre }}</option></select>
                </div>
                <template v-if="resultados.length">
                    <div class="flex flex-wrap items-center gap-2 px-4 py-2 text-sm">
                        <span class="text-petroleo-500">{{ resultados.length }} encontrados</span>
                        <Boton tam="sm" class="ml-auto" :icono="ListPlus" :disabled="!marcados.length" @click="agregarMarcados">Agregar marcados ({{ marcados.length }})</Boton>
                        <Boton tam="sm" :icono="PackageCheck" :cargando="buscando" @click="agregarTodos">Agregar todos{{ categoria ? ' de la categoría' : '' }}</Boton>
                    </div>
                    <ul class="max-h-80 divide-y divide-petroleo-50 overflow-y-auto border-t border-petroleo-100 dark:divide-petroleo-800 dark:border-petroleo-800">
                        <li v-for="p in resultados" :key="p.id" class="flex items-center gap-3 px-4 py-2">
                            <input v-model="marcados" type="checkbox" :value="p.id" :disabled="esta(p.id)" class="size-4 accent-petroleo-700" :aria-label="'Marcar ' + p.nombre" />
                            <span class="min-w-0 flex-1"><span class="block truncate font-medium">{{ p.nombre }}</span><span class="text-xs text-petroleo-400">{{ p.codigo }} · {{ p.categoria }} · stock {{ cantidad(p.quedan) }}</span></span>
                            <span class="cifra-suave text-sm">{{ plata(p.monto) }}</span>
                            <Boton tam="sm" :variante="esta(p.id) ? 'fantasma' : 'suave'" :icono="Plus" @click="agregar(p)">{{ esta(p.id) ? '+1' : 'Agregar' }}</Boton>
                        </li>
                    </ul>
                </template>
            </section>

            <!-- elegidos -->
            <section class="panel overflow-hidden">
                <div class="flex flex-wrap items-center gap-2 border-b border-petroleo-100 px-4 py-3 dark:border-petroleo-800">
                    <h2 class="font-semibold">Para imprimir</h2>
                    <Boton tam="sm" variante="oscuro" :icono="PackageCheck" :cargando="cargandoTodo" @click="todoElCatalogo(false)">Todos los productos</Boton>
                    <span class="text-sm text-petroleo-500">{{ elegidos.length }} productos · {{ total }} etiquetas</span>
                    <div v-if="elegidos.length" class="ml-auto flex flex-wrap gap-1">
                        <Boton tam="sm" variante="fantasma" @click="copiasTodas(1)">1 de cada uno</Boton>
                        <Boton tam="sm" variante="fantasma" @click="copiasStock">Tantas como stock</Boton>
                        <Boton tam="sm" variante="fantasma" :icono="Trash2" @click="elegidos = []">Vaciar</Boton>
                    </div>
                </div>
                <ul v-if="elegidos.length" class="max-h-[28rem] divide-y divide-petroleo-50 overflow-y-auto dark:divide-petroleo-800">
                    <li v-for="(e, i) in elegidos" :key="e.id" class="flex flex-wrap items-center gap-3 px-4 py-2">
                        <span class="min-w-0 flex-1 basis-48"><span class="block truncate font-medium">{{ e.nombre }}</span><span class="text-xs text-petroleo-400">{{ e.codigo }} · {{ plata(e.monto) }}</span></span>
                        <span class="text-xs text-petroleo-500">copias</span>
                        <div class="flex items-center rounded-xl border border-petroleo-200 dark:border-petroleo-700">
                            <button type="button" class="grid size-9 place-items-center" aria-label="Menos" @click="copias(e, -1)"><Minus class="size-4" /></button>
                            <input v-model.number="e.copias" inputmode="numeric" class="w-12 bg-transparent text-center font-semibold outline-none" :aria-label="'Copias de ' + e.nombre" />
                            <button type="button" class="grid size-9 place-items-center" aria-label="Más" @click="copias(e, 1)"><Plus class="size-4" /></button>
                        </div>
                        <button type="button" class="p-1 text-petroleo-400 hover:text-sale" aria-label="Quitar" @click="elegidos.splice(i, 1)"><Trash2 class="size-4" /></button>
                    </li>
                </ul>
                <div v-else class="flex flex-col items-center gap-2 px-6 py-12 text-center text-sm text-petroleo-500">
                    <Tag class="size-8 text-petroleo-300" stroke-width="1.5" />
                    Buscá productos arriba, o marcalos en <a href="/productos" class="font-semibold underline">Productos</a> y tocá "Etiquetas".
                    <div class="mt-2 flex flex-wrap justify-center gap-2">
                        <Boton variante="oscuro" :icono="PackageCheck" :cargando="cargandoTodo" @click="todoElCatalogo(false)">Agregar todos los productos</Boton>
                        <Boton :icono="FileDown" :cargando="cargandoTodo || bajando" @click="todoElCatalogo(true)">PDF de todos los productos</Boton>
                    </div>
                </div>
            </section>
        </div>

        <!-- configuracion -->
        <aside class="space-y-4">
            <section class="panel p-5">
                <h2 class="mb-3 font-semibold">Vista previa</h2>
                <div class="grid min-h-40 place-items-center overflow-hidden rounded-xl bg-petroleo-100/60 p-4 dark:bg-petroleo-950">
                    <div :style="{ width: `${cfg.ancho * 3.78 * escala}px`, height: `${cfg.alto * 3.78 * escala}px` }" class="shadow-md">
                        <div :style="{ transform: `scale(${escala})`, transformOrigin: 'top left' }">
                            <Etiqueta :producto="muestra" :config="cfg" :barra="barra" :sucursal="multisucursal ? 'Sucursal' : ''" :fecha="hoy" />
                        </div>
                    </div>
                </div>
                <p class="mt-2 text-center text-xs text-petroleo-500">{{ cfg.ancho }} × {{ cfg.alto }} mm</p>
            </section>

            <section class="panel space-y-3 p-5">
                <h2 class="font-semibold">Tamaño</h2>
                <div class="flex flex-wrap gap-1.5">
                    <button v-for="m in MEDIDAS" :key="m.join()" type="button" class="rounded-lg border px-2.5 py-1 text-xs font-semibold"
                        :class="cfg.ancho == m[0] && cfg.alto == m[1] ? 'border-petroleo-800 bg-petroleo-800 text-white' : 'border-petroleo-200 hover:bg-petroleo-50 dark:border-petroleo-700'" @click="cfg.ancho = m[0]; cfg.alto = m[1]">{{ m[0] }}×{{ m[1] }}</button>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <label><span class="etiqueta">Ancho (mm)</span><input v-model.number="cfg.ancho" type="number" min="15" max="200" step="1" class="campo" /></label>
                    <label><span class="etiqueta">Alto (mm)</span><input v-model.number="cfg.alto" type="number" min="10" max="200" step="1" class="campo" /></label>
                </div>
            </section>

            <section class="panel space-y-2 p-5">
                <h2 class="mb-1 font-semibold">Qué muestra</h2>
                <label v-for="o in [['nombre', 'Nombre del producto'], ['precio', 'Precio'], ['barras', 'Código de barras'], ['codigo', 'Número del código'], ['fecha', 'Fecha de hoy'], ...(multisucursal ? [['sucursal', 'Sucursal']] : [])]" :key="o[0]" class="flex items-center gap-2 text-sm">
                    <input v-model="cfg[o[0]]" type="checkbox" class="size-4 accent-petroleo-700" />{{ o[1] }}</label>
                <div v-if="cfg.precio" class="pt-1"><span class="etiqueta">Tamaño del precio</span>
                    <div class="flex rounded-xl bg-petroleo-100/70 p-1 dark:bg-petroleo-900">
                        <button v-for="t in [['chico', 'Chico'], ['mediano', 'Mediano'], ['grande', 'Grande']]" :key="t[0]" type="button" class="flex-1 rounded-lg py-1.5 text-sm"
                            :class="cfg.tam_precio === t[0] ? 'bg-white font-semibold shadow-sm dark:bg-petroleo-700' : 'text-petroleo-600'" @click="cfg.tam_precio = t[0]">{{ t[1] }}</button>
                    </div></div>
            </section>

            <section class="panel space-y-3 p-5">
                <h2 class="font-semibold">Impresión</h2>
                <label class="flex items-start gap-2 text-sm"><input v-model="cfg.formato" type="radio" value="rollo" class="mt-0.5 accent-petroleo-700" /><span><b>Impresora de etiquetas</b> (rollo)<br><span class="text-xs text-petroleo-500">Zebra, Xprinter, Brother… una etiqueta por página.</span></span></label>
                <label class="flex items-start gap-2 text-sm"><input v-model="cfg.formato" type="radio" value="hoja" class="mt-0.5 accent-petroleo-700" /><span><b>Hojas</b> (A4, carta u oficio)<br><span class="text-xs text-petroleo-500">Hojas de etiquetas autoadhesivas o papel común para recortar.</span></span></label>
                <template v-if="cfg.formato === 'hoja'">
                    <div class="grid grid-cols-2 gap-3">
                        <label><span class="etiqueta">Hoja</span><select v-model="cfg.hoja" class="campo"><option>A4</option><option>Carta</option><option>Oficio</option></select></label>
                        <label><span class="etiqueta">Columnas</span><input v-model.number="cfg.columnas" type="number" min="1" max="12" class="campo" /></label>
                        <label><span class="etiqueta">Margen (mm)</span><input v-model.number="cfg.margen" type="number" min="0" max="30" class="campo" /></label>
                        <label><span class="etiqueta">Separación (mm)</span><input v-model.number="cfg.separacion" type="number" min="0" max="20" class="campo" /></label>
                    </div>
                    <p class="text-xs" :class="cfg.columnas > entran.cols ? 'font-semibold text-sale' : 'text-petroleo-500'">En la hoja entran {{ entran.cols }} columnas × {{ entran.filas }} filas = {{ entran.cols * entran.filas }} por hoja.</p>
                </template>
                <label class="flex items-center gap-2 text-sm"><input v-model="cfg.guias" type="checkbox" class="size-4 accent-petroleo-700" />Líneas de corte (en pantalla y en el PDF en hojas)</label>
                <Boton variante="principal" tam="lg" class="w-full" :icono="Printer" :disabled="!elegidos.length" :cargando="imprimiendo" @click="imprimir">Imprimir {{ total }} etiquetas</Boton>
            </section>

            <section class="panel space-y-3 p-5">
                <h2 class="font-semibold">PDF</h2>
                <p class="text-sm text-petroleo-500">{{ cfg.formato === 'rollo' ? `Una página de ${cfg.ancho}×${cfg.alto} mm por etiqueta, para la impresora de etiquetas.` : `Hojas ${cfg.hoja} con ${cfg.columnas} columnas y ${entran.filas} filas por hoja.` }} Las barras salen nítidas a cualquier tamaño.</p>
                <Boton class="w-full" variante="oscuro" :icono="FileDown" :disabled="!elegidos.length" :cargando="bajando" @click="pdf">Descargar PDF</Boton>
                <button type="button" class="flex items-center gap-1 text-xs text-petroleo-500 hover:underline" @click="cfg = { ...defecto }"><RotateCcw class="size-3" />Volver a la configuración de fábrica</button>
            </section>
        </aside>
    </div>
</template>
