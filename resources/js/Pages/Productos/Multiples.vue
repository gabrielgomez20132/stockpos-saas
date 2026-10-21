<script setup>
import { computed, ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import { Plus, Trash2, Copy, ImagePlus, X, Layers, Settings2, PackagePlus } from 'lucide-vue-next';
import Encabezado from '@/Components/Encabezado.vue';
import Boton from '@/Components/Boton.vue';
import Campo from '@/Components/Campo.vue';
import Selector from '@/Components/Selector.vue';
import { plata, cantidad, numero } from '@/lib/formato';

const props = defineProps({ categorias: Array, atributos: Array });

const vacia = () => ({ valores: Object.fromEntries(props.atributos.map((a) => [a.id, null])), codigo: '', cantidad: 1, aviso: 1, archivo: null });
const form = useForm({
    nombre: '', codigo: '', id_categoria: props.categorias[0]?.id ?? null, costo: '', monto: '',
    filas: [vacia()],
});
const fotos = ref([null]); // previews

const unidad = computed(() => props.categorias.find((c) => c.id === form.id_categoria)?.unidad ?? '');
const porPeso = computed(() => ['kg', 'lt', 'gr', 'mt'].includes(String(unidad.value).toLowerCase()));
const margen = computed(() => (Number(form.costo) > 0 ? Math.round((Number(form.monto) / Number(form.costo) - 1) * 100) : null));
const totalStock = computed(() => form.filas.reduce((a, f) => a + (numero(f.cantidad) || 0), 0));

function agregar() { form.filas.push(vacia()); fotos.value.push(null); }
function duplicar(i) {
    const f = form.filas[i];
    form.filas.splice(i + 1, 0, { ...f, valores: { ...f.valores }, codigo: '', archivo: null });
    fotos.value.splice(i + 1, 0, null);
}
function quitar(i) { form.filas.splice(i, 1); fotos.value.splice(i, 1); }
function foto(i, e) {
    const archivo = e.target.files[0] ?? null;
    form.filas[i].archivo = archivo;
    fotos.value[i] = archivo ? URL.createObjectURL(archivo) : null;
}
function sinFoto(i) { form.filas[i].archivo = null; fotos.value[i] = null; }

const texto = (f) => props.atributos.map((a) => f.valores[a.id]).filter(Boolean).join(' / ');
const err = (i, campo) => form.errors[`filas.${i}.${campo}`];
const errValores = (i) => Object.entries(form.errors).find(([k]) => k.startsWith(`filas.${i}.valores`))?.[1];

function guardar() {
    form.post('/productos/multiples', { forceFormData: true, preserveScroll: true });
}
</script>

<template>
    <Encabezado titulo="Crear múltiples productos" subtitulo="Un producto con todos sus talles, colores y tamaños de una sola vez">
        <Boton href="/configuracion" variante="fantasma" :icono="Settings2">Talles y colores</Boton>
    </Encabezado>

    <div v-if="!atributos.length" class="panel p-5 text-sm">
        No hay atributos activos (talle, color, tamaño). <Link href="/configuracion" class="font-semibold underline">Cargalos en Configuración</Link>.
    </div>

    <form v-else class="space-y-5" @submit.prevent="guardar">
        <section class="panel p-5">
            <h2 class="mb-4 font-semibold">Datos base del producto</h2>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-[2fr_1.3fr_1fr_1fr]">
                <Campo v-model="form.nombre" label="Nombre del producto" maxlength="55" placeholder="Ej: Remera básica" required :error="form.errors.nombre" />
                <Selector v-model="form.id_categoria" label="Categoría" :opciones="categorias.map((c) => ({ id: c.id, nombre: `${c.nombre} (${c.unidad})` }))" required :error="form.errors.id_categoria" />
                <Campo v-model="form.costo" label="Costo" tipo="number" step="0.01" min="0" prefijo="$" placeholder="0,00" required :error="form.errors.costo" />
                <Campo v-model="form.monto" label="Precio de venta" tipo="number" step="0.01" min="0" prefijo="$" placeholder="0,00" required :ayuda="margen !== null ? `Margen ${margen}%` : ''" :error="form.errors.monto" />
            </div>
            <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-[2fr_3.3fr]">
                <Campo v-model="form.codigo" label="Código del producto (opcional)" maxlength="90" placeholder="Si lo dejás vacío se genera solo" :error="form.errors.codigo"
                    ayuda="Escaneándolo en la venta te ofrece elegir la variante." />
            </div>
        </section>

        <section class="panel p-5">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="flex items-center gap-2 font-semibold"><Layers class="size-4 text-petroleo-500" />Variantes del producto</h2>
                    <p class="text-sm text-petroleo-500">{{ form.filas.length }} variantes · {{ cantidad(totalStock) }} {{ unidad }} en total · cada una con su stock y código</p>
                </div>
                <Boton variante="exito" :icono="Plus" @click="agregar">Agregar variante</Boton>
            </div>
            <p v-if="form.errors.filas" class="mb-3 text-sm text-sale">{{ form.errors.filas }}</p>

            <div class="space-y-3">
                <article v-for="(f, i) in form.filas" :key="i" class="rounded-2xl border border-petroleo-100 p-4 dark:border-petroleo-800">
                    <div class="mb-3 flex items-center justify-between gap-2">
                        <p class="text-sm font-semibold text-petroleo-600 dark:text-petroleo-300">Variante {{ i + 1 }}<span v-if="texto(f)" class="font-normal text-petroleo-400"> · {{ form.nombre || 'Producto' }} - {{ texto(f) }}</span></p>
                        <div class="flex gap-1">
                            <Boton tam="sm" variante="fantasma" :icono="Copy" aria-label="Duplicar" title="Duplicar (para cambiar solo el talle o el color)" @click="duplicar(i)" />
                            <Boton tam="sm" variante="peligro" :icono="Trash2" aria-label="Eliminar" :disabled="form.filas.length === 1" @click="quitar(i)" />
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-[repeat(var(--n),minmax(0,1fr))_1.4fr_0.8fr_0.8fr_auto]" :style="{ '--n': atributos.length }">
                        <Selector v-for="a in atributos" :key="a.id" v-model="f.valores[a.id]" :label="a.nombre" vacio="Elegir…" :opciones="a.valores.map((v) => ({ id: v.valor, nombre: v.valor }))" />
                        <Campo v-model="f.codigo" label="Código de barras" maxlength="100" placeholder="Automático" :error="err(i, 'codigo')" />
                        <Campo v-model="f.cantidad" :label="`Ingreso (${unidad})`" tipo="number" :step="porPeso ? 'any' : '1'" min="0" required :error="err(i, 'cantidad')" />
                        <Campo v-model="f.aviso" label="Mínimo" tipo="number" :step="porPeso ? 'any' : '1'" min="0" required ayuda="Aviso de reponer" :error="err(i, 'aviso')" />
                        <div>
                            <span class="etiqueta">Imagen</span>
                            <div class="relative size-[2.875rem]">
                                <img v-if="fotos[i]" :src="fotos[i]" alt="" class="size-full rounded-xl object-cover" />
                                <label v-else class="grid size-full cursor-pointer place-items-center rounded-xl border-2 border-dashed border-petroleo-200 text-petroleo-400 hover:bg-petroleo-50 dark:border-petroleo-700 dark:hover:bg-petroleo-800" title="Elegir o sacar foto">
                                    <ImagePlus class="size-5" />
                                    <input type="file" accept="image/*" capture="environment" class="sr-only" @change="foto(i, $event)" />
                                </label>
                                <button v-if="fotos[i]" type="button" class="absolute -top-1.5 -right-1.5 rounded-full bg-white p-0.5 shadow" aria-label="Quitar foto" @click="sinFoto(i)"><X class="size-3.5" /></button>
                            </div>
                        </div>
                    </div>
                    <p v-if="errValores(i) || err(i, 'archivo')" class="mt-2 text-sm text-sale">{{ errValores(i) || err(i, 'archivo') }}</p>
                </article>
            </div>
            <button type="button" class="mt-3 flex w-full items-center justify-center gap-2 rounded-2xl border-2 border-dashed border-petroleo-200 py-3 text-sm font-semibold text-petroleo-500 hover:bg-petroleo-50 dark:border-petroleo-700 dark:hover:bg-petroleo-800" @click="agregar">
                <Plus class="size-4" />Agregar otra variante
            </button>
        </section>

        <div class="sticky bottom-20 z-10 flex flex-wrap items-center justify-end gap-2 rounded-2xl bg-white/90 p-3 shadow-lg backdrop-blur md:static md:bg-transparent md:p-0 md:shadow-none dark:bg-petroleo-900/90 md:dark:bg-transparent">
            <span v-if="form.monto" class="mr-auto text-sm text-petroleo-500">Cada variante a {{ plata(form.monto) }} · después podés cambiar precios por variante</span>
            <Boton href="/productos" variante="fantasma">Cancelar</Boton>
            <Boton tipo="submit" variante="exito" :icono="PackagePlus" :cargando="form.processing">Crear productos</Boton>
        </div>
    </form>
</template>
