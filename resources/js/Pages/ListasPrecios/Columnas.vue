<script setup>
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { ArrowLeft, ArrowRight, Info } from 'lucide-vue-next';
import Encabezado from '@/Components/Encabezado.vue';
import Boton from '@/Components/Boton.vue';
import Campo from '@/Components/Campo.vue';
import { plata, numero } from '@/lib/formato';

const props = defineProps({ lista: Object, muestra: Array, opciones: Object, deLaAnterior: Boolean });

const form = useForm({
    fila_titulos: props.opciones.fila_titulos ?? null,
    codigo: props.opciones.codigo ?? null,
    descripcion: props.opciones.descripcion ?? null,
    costo: props.opciones.costo ?? null,
    descuento: props.opciones.descuento ?? 0,
    iva: Number(props.opciones.iva ?? 0),
    politica: props.opciones.politica ?? 'margen',
    margen: props.opciones.margen ?? 50,
    redondeo: Number(props.opciones.redondeo ?? 10),
});

const letra = (i) => (i < 26 ? String.fromCharCode(65 + i) : 'A' + String.fromCharCode(65 + i - 26));
const ancho = computed(() => props.muestra[0]?.length ?? 0);
const titulos = computed(() => (form.fila_titulos === null ? [] : props.muestra[form.fila_titulos] ?? []));
const columnas = computed(() => Array.from({ length: ancho.value }, (_, i) => ({ id: i, nombre: `${letra(i)}${titulos.value[i] ? ' · ' + String(titulos.value[i]).slice(0, 30) : ''}` })));
const marca = (i) => (i === form.codigo ? 'Código' : i === form.costo ? 'Precio' : i === form.descripcion ? 'Descripción' : null);

// ejemplo con el primer renglon que tenga precio
const num = (v) => numero(String(v ?? '').replace(/[$\s]/g, ''));
const ejemplo = computed(() => {
    if (form.codigo === null || form.costo === null) return null;
    const desde = form.fila_titulos === null ? 0 : form.fila_titulos + 1;
    const f = props.muestra.slice(desde).find((r) => String(r[form.codigo] ?? '').trim() && num(r[form.costo]) !== null);
    if (!f) return null;
    const lista = num(f[form.costo]);
    const costo = Math.round(lista * (1 - Number(form.descuento || 0) / 100) * (1 + Number(form.iva || 0) / 100) * 100) / 100;
    return { codigo: f[form.codigo], desc: form.descripcion !== null ? f[form.descripcion] : '', lista, costo };
});

const enviar = () => form.post(`/listas-precios/${props.lista.id}/columnas`);
</script>

<template>
    <Encabezado titulo="Elegí las columnas" :subtitulo="`${lista.proveedor} · ${lista.archivo} · ${lista.filas} filas`">
        <Boton href="/listas-precios" variante="fantasma" :icono="ArrowLeft">Volver</Boton>
    </Encabezado>

    <p v-if="deLaAnterior" class="mb-4 flex items-center gap-2 rounded-xl bg-sky-50 px-4 py-3 text-sm text-sky-900 dark:bg-sky-950/40 dark:text-sky-200"><Info class="size-4 shrink-0" />Usé las mismas columnas y opciones que la última lista de este proveedor. Revisalas por si cambió el formato.</p>

    <form class="grid gap-5 xl:grid-cols-[1fr_22rem]" @submit.prevent="enviar">
        <section class="panel min-w-0 overflow-hidden">
            <div class="border-b border-petroleo-100 px-5 py-3.5 dark:border-petroleo-800">
                <h2 class="font-semibold">Así viene el archivo</h2>
                <p class="text-sm text-petroleo-500">Primeras 25 filas. Tocá una fila para marcarla como la de los títulos.</p>
            </div>
            <div class="max-h-[32rem] overflow-auto">
                <table class="w-full text-sm">
                    <thead class="sticky top-0 z-10 bg-papel dark:bg-petroleo-950">
                        <tr>
                            <th class="w-10 px-2 py-2 text-xs text-petroleo-400">#</th>
                            <th v-for="i in ancho" :key="i" class="min-w-24 px-2 py-2 text-left text-xs font-semibold" :class="marca(i - 1) ? 'bg-cartel-300/40 text-petroleo-900 dark:text-cartel-200' : 'text-petroleo-400'">
                                {{ letra(i - 1) }}<span v-if="marca(i - 1)" class="ml-1 rounded bg-petroleo-800 px-1.5 py-0.5 text-[0.65rem] text-white">{{ marca(i - 1) }}</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(f, n) in muestra" :key="n" class="cursor-pointer border-t border-petroleo-50 hover:bg-petroleo-50/60 dark:border-petroleo-800 dark:hover:bg-petroleo-800/40"
                            :class="{ 'bg-petroleo-100 font-semibold dark:bg-petroleo-800': n === form.fila_titulos, 'opacity-40': form.fila_titulos !== null && n < form.fila_titulos }"
                            @click="form.fila_titulos = n">
                            <td class="px-2 py-1.5 text-center text-xs text-petroleo-400">{{ n + 1 }}</td>
                            <td v-for="(c, i) in f" :key="i" class="max-w-56 truncate px-2 py-1.5" :class="{ 'bg-cartel-300/15': marca(i) }">{{ c }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <aside class="space-y-4">
            <section class="panel space-y-3 p-5">
                <h2 class="font-semibold">Columnas</h2>
                <div><label class="etiqueta">Fila de títulos</label>
                    <select v-model="form.fila_titulos" class="campo"><option :value="null">No tiene títulos</option><option v-for="(f, n) in muestra" :key="n" :value="n">Fila {{ n + 1 }}{{ f.filter(Boolean).length ? ' · ' + f.filter(Boolean).slice(0, 3).join(', ').slice(0, 40) : '' }}</option></select></div>
                <div><label class="etiqueta">Código del producto *</label>
                    <select v-model="form.codigo" class="campo" required :class="{ 'border-sale': form.errors.codigo }"><option :value="null" disabled>Elegí…</option><option v-for="c in columnas" :key="c.id" :value="c.id">{{ c.nombre }}</option></select>
                    <p v-if="form.errors.codigo" class="mt-1 text-sm text-sale">{{ form.errors.codigo }}</p></div>
                <div><label class="etiqueta">Descripción</label>
                    <select v-model="form.descripcion" class="campo"><option :value="null">No usar</option><option v-for="c in columnas" :key="c.id" :value="c.id">{{ c.nombre }}</option></select></div>
                <div><label class="etiqueta">Precio de lista (costo) *</label>
                    <select v-model="form.costo" class="campo" required :class="{ 'border-sale': form.errors.costo }"><option :value="null" disabled>Elegí…</option><option v-for="c in columnas" :key="c.id" :value="c.id">{{ c.nombre }}</option></select>
                    <p v-if="form.errors.costo" class="mt-1 text-sm text-sale">{{ form.errors.costo }}</p></div>
            </section>

            <section class="panel space-y-3 p-5">
                <h2 class="font-semibold">Costo</h2>
                <div class="grid grid-cols-2 gap-3">
                    <Campo v-model="form.descuento" label="Bonificación %" tipo="number" step="0.01" min="0" max="90" :error="form.errors.descuento" />
                    <div><label class="etiqueta">Sumar IVA</label>
                        <select v-model="form.iva" class="campo"><option :value="0">No (ya incluye)</option><option :value="10.5">10,5%</option><option :value="21">21%</option><option :value="27">27%</option></select></div>
                </div>
                <p v-if="ejemplo" class="rounded-xl bg-papel p-3 text-xs dark:bg-petroleo-950">Ejemplo: <b>{{ ejemplo.codigo }}</b> {{ ejemplo.desc }} — lista {{ plata(ejemplo.lista) }} → costo <b>{{ plata(ejemplo.costo) }}</b></p>
            </section>

            <section class="panel space-y-3 p-5">
                <h2 class="font-semibold">Precio de venta</h2>
                <label class="flex items-start gap-2 text-sm"><input v-model="form.politica" type="radio" value="margen" class="mt-0.5 accent-petroleo-700" /><span><b>Mantener el margen</b> que tiene cada producto<br><span class="text-xs text-petroleo-500">Si el costo sube 10%, el precio sube 10%.</span></span></label>
                <label class="flex items-start gap-2 text-sm"><input v-model="form.politica" type="radio" value="fijo" class="mt-0.5 accent-petroleo-700" /><span><b>Margen fijo</b> sobre el costo nuevo</span></label>
                <Campo v-if="form.politica === 'fijo'" v-model="form.margen" label="Margen %" tipo="number" step="0.01" min="0" :error="form.errors.margen" />
                <label class="flex items-start gap-2 text-sm"><input v-model="form.politica" type="radio" value="no" class="mt-0.5 accent-petroleo-700" /><span><b>No cambiar</b> el precio de venta (solo el costo)</span></label>
                <div v-if="form.politica !== 'no'"><label class="etiqueta">Redondear el precio hacia arriba a</label>
                    <select v-model="form.redondeo" class="campo"><option :value="1">$ 1</option><option :value="10">$ 10</option><option :value="50">$ 50</option><option :value="100">$ 100</option></select></div>
            </section>

            <Boton tipo="submit" variante="principal" tam="lg" class="w-full" :icono="ArrowRight" :cargando="form.processing">Ver qué cambia</Boton>
            <p class="text-center text-xs text-petroleo-500">Todavía no se cambia ningún precio.</p>
        </aside>
    </form>
</template>
