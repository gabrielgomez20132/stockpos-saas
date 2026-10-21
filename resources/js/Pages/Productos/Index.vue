<script setup>
import { computed, ref, watch } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { Plus, Search, Percent, Package, Pencil, Trash2, Upload, Download, FileSpreadsheet, Layers, Barcode } from 'lucide-vue-next';
import Encabezado from '@/Components/Encabezado.vue';
import Boton from '@/Components/Boton.vue';
import Campo from '@/Components/Campo.vue';
import Modal from '@/Components/Modal.vue';
import Insignia from '@/Components/Insignia.vue';
import Paginacion from '@/Components/Paginacion.vue';
import Vacio from '@/Components/Vacio.vue';
import { plata, cantidad, unidad } from '@/lib/formato';
import { confirmar } from '@/lib/confirmar';

const props = defineProps({ productos: Object, categorias: Array, filtros: Object, valorStock: Number, variantesHabilitadas: Boolean });
const f = ref({ ...props.filtros });
let t;
function filtrar(inmediato = false) {
    clearTimeout(t);
    const ir = () => router.get('/productos', Object.fromEntries(Object.entries({ q: f.value.q, categoria: f.value.categoria, stock: f.value.stock, baja: f.value.baja ? 1 : null }).filter(([, v]) => v)), { preserveState: true, replace: true });
    inmediato ? ir() : (t = setTimeout(ir, 300));
}
watch(() => f.value.q, () => filtrar());

const preciosAbierto = ref(false);
const precios = useForm({ porcentaje: '', id_categoria: null, costo: true });
const aplicarPrecios = () => precios.post('/productos/precios', { preserveScroll: true, onSuccess: () => { preciosAbierto.value = false; precios.reset(); } });

const page = usePage();
const marcados = ref([]);
const todosMarcados = computed(() => props.productos.data.length > 0 && props.productos.data.every((p) => marcados.value.includes(p.id)));
function marcarTodos(v) {
    const ids = props.productos.data.map((p) => p.id);
    marcados.value = v ? [...new Set([...marcados.value, ...ids])] : marcados.value.filter((i) => !ids.includes(i));
}
const urlEtiquetas = computed(() => (marcados.value.length ? `/etiquetas?ids=${marcados.value.join(',')}` : '/etiquetas'));
const importarAbierto = ref(false);
const imp = useForm({ archivo: null, actualizar_stock: false });
const resultado = ref(null);
function importar() {
    imp.post('/productos/importar', {
        forceFormData: true, preserveScroll: true,
        onSuccess: () => { resultado.value = page.props.flash.importacion; if (!resultado.value?.errores?.length) { importarAbierto.value = false; imp.reset(); } },
    });
}
function abrirImportar() { resultado.value = null; imp.reset(); imp.clearErrors(); importarAbierto.value = true; }

async function borrar(p) {
    if (await confirmar({ titulo: 'Borrar producto', texto: `¿Borrar "${p.nombre}"? Si tiene ventas registradas queda dado de baja en lugar de borrarse.`, boton: 'Borrar', peligro: true })) router.delete(`/productos/${p.id}`, { preserveScroll: true });
}
</script>

<template>
    <Encabezado titulo="Productos" :subtitulo="`${productos.total} productos · stock valuado al costo ${plata(valorStock)}`">
        <Boton :href="urlEtiquetas" :variante="marcados.length ? 'oscuro' : 'suave'" :icono="Barcode">Etiquetas{{ marcados.length ? ` (${marcados.length})` : '' }}</Boton>
        <Boton href="/productos/plantilla" externo :icono="FileSpreadsheet">Modelo Excel</Boton>
        <Boton :icono="Upload" @click="abrirImportar">Importar</Boton>
        <Boton href="/productos/exportar?formato=xlsx" externo :icono="Download">Excel</Boton>
        <Boton href="/productos/exportar?formato=csv" externo :icono="Download">CSV</Boton>
        <Boton :icono="Percent" @click="preciosAbierto = true">Actualizar precios</Boton>
        <Boton v-if="variantesHabilitadas" href="/productos/multiples" variante="exito" :icono="Layers">Múltiples productos</Boton>
        <Boton href="/productos/create" variante="oscuro" :icono="Plus">Nuevo producto</Boton>
    </Encabezado>

    <div class="mb-4 grid gap-2 sm:grid-cols-[1fr_12rem_11rem_auto]">
        <div class="relative">
            <Search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-petroleo-300" />
            <input v-model="f.q" class="campo pl-9" placeholder="Buscar por nombre o código" />
        </div>
        <select v-model="f.categoria" class="campo" @change="filtrar(true)">
            <option :value="null">Todas las categorías</option>
            <option v-for="c in categorias" :key="c.id" :value="c.id">{{ c.nombre }}</option>
        </select>
        <select v-model="f.stock" class="campo" @change="filtrar(true)">
            <option :value="null">Todo el stock</option><option value="bajo">Para reponer</option><option value="sin">Sin stock</option>
        </select>
        <label class="flex items-center gap-2 px-1 text-sm"><input v-model="f.baja" type="checkbox" class="size-4 accent-petroleo-700" @change="filtrar(true)" />Dados de baja</label>
    </div>

    <section class="panel overflow-hidden">
        <table v-if="productos.data.length" class="tabla hidden md:table">
            <thead><tr><th class="w-8"><input type="checkbox" class="size-4 accent-petroleo-700" :checked="todosMarcados" aria-label="Marcar todos para etiquetas" @change="marcarTodos($event.target.checked)" /></th><th class="w-12"></th><th>Producto</th><th>Categoría</th><th class="text-right">Stock</th><th class="text-right">Costo</th><th class="text-right">Venta</th><th class="text-right">Margen</th><th class="w-20"></th></tr></thead>
            <tbody>
                <tr v-for="p in productos.data" :key="p.id">
                    <td><input v-model="marcados" type="checkbox" :value="p.id" class="size-4 accent-petroleo-700" :aria-label="'Marcar ' + p.nombre" /></td>
                    <td><img v-if="p.imagen_url" :src="p.imagen_url" alt="" class="size-9 rounded-lg object-cover" /><span v-else class="grid size-9 place-items-center rounded-lg bg-petroleo-50 text-petroleo-300 dark:bg-petroleo-800"><Package class="size-4" /></span></td>
                    <td><Link :href="`/productos/${p.id}/edit`" class="font-medium hover:underline">{{ p.nombre }}</Link><br><span class="text-xs text-petroleo-400">{{ p.codigo }}</span></td>
                    <td class="text-petroleo-500">{{ p.category?.nombre }}</td>
                    <td class="text-right whitespace-nowrap">
                        <Link v-if="p.tiene_variantes" :href="`/productos/${p.id}/variantes`" class="hover:underline"><Insignia color="azul">{{ p.variantes_count }} variantes</Insignia> {{ cantidad(p.stock_variantes || 0) }}</Link>
                        <Insignia v-else-if="p.quedan <= 0" color="rojo">Sin stock</Insignia>
                        <span v-else :class="{ 'font-semibold text-cartel-700': p.quedan <= p.aviso }">{{ cantidad(p.quedan) }} {{ unidad(p.category?.unidad, p.quedan) }}</span>
                    </td>
                    <td class="text-right text-petroleo-500">{{ plata(p.costo) }}</td>
                    <td class="cifra-suave text-right text-base">{{ plata(p.monto) }}</td>
                    <td class="text-right text-petroleo-500">{{ p.costo > 0 ? Math.round((p.monto / p.costo - 1) * 100) + '%' : '—' }}</td>
                    <td class="text-right"><Boton :href="`/productos/${p.id}/edit`" tam="sm" variante="fantasma" :icono="Pencil" aria-label="Editar" /><Boton tam="sm" variante="fantasma" :icono="Trash2" aria-label="Borrar" @click="borrar(p)" /></td>
                </tr>
            </tbody>
        </table>
        <ul v-if="productos.data.length" class="divide-y divide-petroleo-50 md:hidden dark:divide-petroleo-800">
            <li v-for="p in productos.data" :key="p.id">
                <Link :href="`/productos/${p.id}/edit`" class="flex items-center gap-3 px-4 py-3">
                    <img v-if="p.imagen_url" :src="p.imagen_url" alt="" class="size-11 rounded-lg object-cover" /><span v-else class="grid size-11 place-items-center rounded-lg bg-petroleo-50 text-petroleo-300 dark:bg-petroleo-800"><Package class="size-5" /></span>
                    <span class="min-w-0 flex-1"><span class="block truncate font-medium">{{ p.nombre }}</span>
                        <span v-if="p.tiene_variantes" class="text-xs text-petroleo-500">{{ p.variantes_count }} variantes · {{ cantidad(p.stock_variantes || 0) }}</span>
                        <span v-else class="text-xs" :class="p.quedan <= p.aviso ? 'font-semibold text-sale' : 'text-petroleo-500'">{{ cantidad(p.quedan) }} {{ unidad(p.category?.unidad, p.quedan) }}</span></span>
                    <span class="cifra text-xl">{{ plata(p.monto) }}</span>
                </Link>
            </li>
        </ul>
        <Vacio v-if="!productos.data.length" :icono="Package" titulo="No hay productos con esos filtros">
            <Boton href="/productos/create" variante="oscuro" :icono="Plus">Cargar producto</Boton>
        </Vacio>
    </section>
    <Paginacion :datos="productos" />

    <Modal v-model="importarAbierto" titulo="Importar productos desde Excel" ancho="max-w-xl">
        <form id="form-imp" class="space-y-4" @submit.prevent="importar">
            <div class="rounded-xl bg-papel p-4 text-sm dark:bg-petroleo-950">
                <p>La primera fila tiene que tener los títulos: <b>Código, Nombre, Categoría, Unidad, Costo, Precio de venta, Stock, Stock mínimo, Activo</b>. Son obligatorios Código, Nombre y Precio de venta.</p>
                <ul class="mt-2 list-disc space-y-0.5 pl-5 text-petroleo-600 dark:text-petroleo-300">
                    <li>Si el código ya existe, el producto se <b>actualiza</b>. Si no, se <b>crea</b>.</li>
                    <li>Las categorías que no existan se crean solas. Unidad: <b>Uds</b> o <b>Kg</b>.</li>
                    <li>Si alguna fila tiene un error, no se importa nada y te dice cuál corregir.</li>
                </ul>
                <a href="/productos/plantilla" class="mt-3 inline-flex items-center gap-1.5 font-semibold text-petroleo-700 underline dark:text-petroleo-200"><FileSpreadsheet class="size-4" />Descargar el modelo de Excel</a>
            </div>
            <label class="block"><span class="etiqueta">Archivo (.xlsx o .csv)</span>
                <input type="file" accept=".xlsx,.csv" required class="block w-full text-sm" @change="imp.archivo = $event.target.files[0]" /></label>
            <p v-if="imp.errors.archivo" class="text-sm text-sale">{{ imp.errors.archivo }}</p>
            <label class="flex items-start gap-2 text-sm"><input v-model="imp.actualizar_stock" type="checkbox" class="mt-0.5 size-4 accent-petroleo-700" />
                <span>Reemplazar el stock de los productos que ya existen con el del archivo<br><span class="text-xs text-petroleo-500">Si no lo marcás, a los productos existentes solo se les actualizan nombre, precios y categoría. Los nuevos siempre se crean con el stock del archivo.</span></span></label>
            <div v-if="resultado?.errores?.length" class="max-h-56 overflow-y-auto rounded-xl bg-red-50 p-3 text-sm text-sale dark:bg-red-950/40">
                <p class="mb-1 font-semibold">Corregí estas filas y volvé a subir el archivo:</p>
                <ul class="list-disc space-y-0.5 pl-5"><li v-for="e in resultado.errores" :key="e">{{ e }}</li></ul>
            </div>
        </form>
        <template #pie>
            <Boton @click="importarAbierto = false">Cancelar</Boton>
            <Boton tipo="submit" form="form-imp" variante="oscuro" :icono="Upload" :cargando="imp.processing">Importar</Boton>
        </template>
    </Modal>

    <Modal v-model="preciosAbierto" titulo="Actualizar precios por porcentaje" ancho="max-w-md">
        <form id="form-precios" class="space-y-4" @submit.prevent="aplicarPrecios">
            <Campo v-model="precios.porcentaje" label="Porcentaje" tipo="number" step="0.01" placeholder="Ej: 10 para subir, -5 para bajar" required :error="precios.errors.porcentaje" />
            <div><span class="etiqueta">Categoría</span>
                <select v-model="precios.id_categoria" class="campo"><option :value="null">Todos los productos</option><option v-for="c in categorias" :key="c.id" :value="c.id">{{ c.nombre }}</option></select></div>
            <label class="flex items-center gap-2 text-sm"><input v-model="precios.costo" type="checkbox" class="size-4 accent-petroleo-700" />Actualizar también el costo</label>
            <p class="text-xs text-petroleo-500">Los precios se redondean hacia arriba al peso.</p>
        </form>
        <template #pie>
            <Boton @click="preciosAbierto = false">Cancelar</Boton>
            <Boton tipo="submit" form="form-precios" variante="oscuro" :cargando="precios.processing">Aplicar</Boton>
        </template>
    </Modal>
</template>
