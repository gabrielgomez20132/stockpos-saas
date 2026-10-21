<script setup>
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Link } from '@inertiajs/vue3';
import { ImagePlus, Wand2, X, Layers, LayoutGrid } from 'lucide-vue-next';
import Encabezado from '@/Components/Encabezado.vue';
import Boton from '@/Components/Boton.vue';
import Campo from '@/Components/Campo.vue';
import Selector from '@/Components/Selector.vue';
import { plata, cantidad, fecha, unidad as uni } from '@/lib/formato';

const props = defineProps({ producto: Object, categorias: Array, ingresos: Array, vendido30: Number, variantes: Object, stockSucursal: Object });
const p = props.producto;
const form = useForm({
    nombre: p?.nombre ?? '', codigo: p?.codigo ?? '', id_categoria: p?.id_categoria ?? props.categorias[0]?.id ?? null,
    costo: p?.costo ?? '', monto: p?.monto ?? '', aviso: p?.aviso ?? 0,
    stock_inicial: 0, quedan: p?.quedan ?? 0, ingreso: '', activo: p?.activo ?? true,
    archivo: null, quitar_imagen: false, otro: false,
});
const preview = ref(p?.imagen_url ?? null);
const unidad = computed(() => props.categorias.find((c) => c.id === form.id_categoria)?.unidad ?? '');
const margen = computed(() => (Number(form.costo) > 0 ? Math.round((Number(form.monto) / Number(form.costo) - 1) * 100) : null));

function foto(e) {
    const f = e.target.files[0];
    form.archivo = f ?? null;
    form.quitar_imagen = false;
    preview.value = f ? URL.createObjectURL(f) : p?.imagen_url ?? null;
}
function sinFoto() { form.archivo = null; form.quitar_imagen = true; preview.value = null; }
function generarCodigo() { form.codigo = '2' + String(Date.now()).slice(-11); }

function guardar(otro = false) {
    form.otro = otro;
    if (p) form.transform((d) => ({ ...d, _method: 'put' })).post(`/productos/${p.id}`, { forceFormData: true });
    else form.post('/productos', { forceFormData: true, onSuccess: () => otro && form.reset() });
}
</script>

<template>
    <Encabezado :titulo="p ? p.nombre : 'Nuevo producto'" :subtitulo="p ? `Vendido en los últimos 30 días: ${cantidad(vendido30)} ${uni(unidad, vendido30)}` : ''" />
    <form class="grid gap-5 lg:grid-cols-[1fr_20rem]" @submit.prevent="guardar(false)">
        <div class="panel space-y-4 p-5">
            <Campo v-model="form.nombre" label="Nombre" maxlength="55" required :error="form.errors.nombre" />
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <Campo v-model="form.codigo" label="Código de barras" maxlength="100" required :error="form.errors.codigo" />
                    <button type="button" class="mt-1 flex items-center gap-1 text-xs font-semibold text-petroleo-600 dark:text-petroleo-300" @click="generarCodigo"><Wand2 class="size-3.5" />No tiene código: generar uno</button>
                </div>
                <Selector v-model="form.id_categoria" label="Categoría" :opciones="categorias.map((c) => ({ id: c.id, nombre: `${c.nombre} (${c.unidad})` }))" required :error="form.errors.id_categoria" />
            </div>
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3">
                <Campo v-model="form.costo" label="Costo" tipo="number" step="0.01" min="0" prefijo="$" required :error="form.errors.costo" />
                <Campo v-model="form.monto" label="Precio de venta" tipo="number" step="0.01" min="0" prefijo="$" required :ayuda="margen !== null ? `Margen ${margen}%` : ''" :error="form.errors.monto" />
                <Campo v-model="form.aviso" :label="`Avisar con (${unidad})`" tipo="number" step="any" min="0" required ayuda="Stock mínimo para reponer" :error="form.errors.aviso" />
            </div>
            <div v-if="!p" class="grid grid-cols-2 gap-4 sm:grid-cols-3">
                <Campo v-model="form.stock_inicial" :label="`Stock inicial (${unidad})`" :ayuda="stockSucursal ? `En ${stockSucursal.nombre}; las demás sucursales arrancan en 0` : ''" tipo="number" step="any" min="0" required :error="form.errors.stock_inicial" />
            </div>
            <div v-else-if="p.tiene_variantes" class="rounded-xl bg-papel p-4 text-sm dark:bg-petroleo-950">
                El stock se lleva por variante: hoy suman <b>{{ cantidad(variantes.stock) }} {{ uni(unidad, variantes.stock) }}</b> en {{ variantes.cantidad }} variantes.
                Cambiar el nombre, la categoría o la foto acá también cambia las variantes.
                <label class="mt-2 flex items-center gap-2"><input v-model="form.activo" type="checkbox" class="size-4 accent-petroleo-700" />Activo</label>
            </div>
            <div v-else class="grid grid-cols-2 gap-4 rounded-xl bg-papel p-4 sm:grid-cols-3 dark:bg-petroleo-950">
                <p v-if="stockSucursal" class="col-span-full -mb-1 text-sm font-semibold text-petroleo-600 dark:text-petroleo-300">Stock en {{ stockSucursal.nombre }}</p>
                <Campo v-model="form.quedan" :label="`Stock actual (${unidad})`" tipo="number" step="any" min="0" required ayuda="Corregilo si contaste distinto" :error="form.errors.quedan" />
                <Campo v-model="form.ingreso" :label="`Entra mercadería (${unidad})`" tipo="number" step="any" min="0" placeholder="0" ayuda="Se suma al stock actual" :error="form.errors.ingreso" />
                <label class="flex items-center gap-2 self-center text-sm"><input v-model="form.activo" type="checkbox" class="size-4 accent-petroleo-700" />Activo (se puede vender)</label>
            </div>
        </div>

        <aside class="space-y-4">
            <div v-if="stockSucursal?.stock?.length > 1" class="panel p-5">
                <p class="mb-2 text-sm font-semibold text-petroleo-500">Stock por sucursal</p>
                <ul class="space-y-1 text-sm">
                    <li v-for="s in stockSucursal.stock" :key="s.id" class="flex justify-between"><span>{{ s.nombre }}</span><b :class="{ 'text-sale': s.quedan <= 0 }">{{ cantidad(s.quedan) }} {{ uni(unidad, s.quedan) }}</b></li>
                </ul>
                <Boton href="/transferencias/nueva" tam="sm" class="mt-3 w-full">Transferir entre sucursales</Boton>
            </div>
            <div v-if="variantes && (variantes.habilitadas || p?.tiene_variantes || p?.id_padre)" class="panel p-5">
                <p class="mb-2 flex items-center gap-2 text-sm font-semibold text-petroleo-500"><Layers class="size-4" />Variantes</p>
                <template v-if="p?.id_padre">
                    <p class="text-sm">Es la variante <b>{{ p.texto_variante }}</b> de</p>
                    <Link :href="`/productos/${p.padre.id}/variantes`" class="text-sm font-semibold underline">{{ p.padre.nombre }}</Link>
                </template>
                <template v-else>
                    <p class="font-medium">Talles, colores y tamaños</p>
                    <p class="text-sm text-petroleo-500">{{ !p ? 'Guardá el producto primero para gestionar variantes.' : variantes.cantidad ? `${variantes.cantidad} variantes` : 'Sin variantes' }}</p>
                    <Boton class="mt-3 w-full" variante="exito" :icono="LayoutGrid" :disabled="!p" :href="p ? `/productos/${p.id}/variantes` : undefined">Gestionar</Boton>
                </template>
            </div>
            <div class="panel p-5">
                <span class="etiqueta">Foto</span>
                <div class="relative grid aspect-square place-items-center overflow-hidden rounded-xl border-2 border-dashed border-petroleo-200 bg-papel dark:border-petroleo-700 dark:bg-petroleo-950">
                    <img v-if="preview" :src="preview" alt="" class="size-full object-cover" />
                    <label v-else class="flex cursor-pointer flex-col items-center gap-2 text-sm text-petroleo-500"><ImagePlus class="size-8" />Elegir o sacar foto
                        <input type="file" accept="image/*" capture="environment" class="sr-only" @change="foto" /></label>
                    <button v-if="preview" type="button" class="absolute top-2 right-2 rounded-full bg-white/90 p-1.5 shadow" aria-label="Quitar foto" @click="sinFoto"><X class="size-4" /></button>
                </div>
                <p v-if="form.errors.archivo" class="mt-1 text-sm text-sale">{{ form.errors.archivo }}</p>
            </div>
            <div class="flex flex-col gap-2">
                <Boton tipo="submit" variante="oscuro" :cargando="form.processing">Guardar</Boton>
                <Boton v-if="!p" @click="guardar(true)">Guardar y cargar otro</Boton>
                <Boton href="/productos" variante="fantasma">Cancelar</Boton>
            </div>
        </aside>
    </form>

    <section v-if="p && ingresos?.length" class="panel mt-5 overflow-hidden">
        <h2 class="border-b border-petroleo-100 px-5 py-3.5 font-semibold dark:border-petroleo-800">Ingresos y cambios de precio</h2>
        <table class="tabla">
            <thead><tr><th>Fecha</th><th>Quién</th><th v-if="stockSucursal">Sucursal</th><th class="text-right">Entró</th><th class="text-right">Costo</th><th class="text-right">Venta</th></tr></thead>
            <tbody><tr v-for="i in ingresos" :key="i.id"><td>{{ fecha(i.created_at, true) }}</td><td>{{ i.user?.nombre }}</td><td v-if="stockSucursal">{{ i.sucursal }}</td><td class="text-right">{{ i.cantidad ? cantidad(i.cantidad) : '—' }}</td><td class="text-right">{{ plata(i.costo) }}</td><td class="text-right">{{ plata(i.monto) }}</td></tr></tbody>
        </table>
    </section>
</template>
