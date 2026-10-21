<script setup>
import { computed, ref, watch } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Sparkles, Save, Trash2, Layers, Settings2 } from 'lucide-vue-next';
import Encabezado from '@/Components/Encabezado.vue';
import Boton from '@/Components/Boton.vue';
import Insignia from '@/Components/Insignia.vue';
import Vacio from '@/Components/Vacio.vue';
import { plata, cantidad, unidad, numero } from '@/lib/formato';
import { confirmar } from '@/lib/confirmar';

const props = defineProps({ producto: Object, variantes: Array, atributos: Array, habilitadas: Boolean, sucursalNombre: String });

// ---- generar combinaciones
const sel = ref(Object.fromEntries(props.atributos.map((a) => [a.nombre, []])));
const extra = ref({});
const combinaciones = computed(() => {
    const listas = Object.values(sel.value).filter((l) => l.length);
    return listas.length ? listas.reduce((n, l) => n * l.length, 1) : 0;
});
function agregarExtra(a) {
    const v = (extra.value[a.nombre] || '').trim();
    if (v && !sel.value[a.nombre].includes(v)) sel.value[a.nombre].push(v);
    extra.value[a.nombre] = '';
}
const generando = ref(false);
function generar() {
    router.post(`/productos/${props.producto.id}/variantes`, { seleccion: sel.value }, {
        preserveScroll: true,
        onStart: () => (generando.value = true),
        onFinish: () => (generando.value = false),
        onSuccess: () => (sel.value = Object.fromEntries(props.atributos.map((a) => [a.nombre, []]))),
    });
}

// ---- tabla editable
const form = useForm({ variantes: props.variantes.map((v) => ({ ...v })) });
watch(() => props.variantes, (vs) => { form.variantes = vs.map((v) => ({ ...v })); form.defaults(); });
const total = computed(() => form.variantes.reduce((a, v) => a + (numero(v.quedan) || 0), 0));
function guardar() {
    form.transform((d) => ({ variantes: d.variantes.map((v) => ({ id: v.id, codigo: v.codigo, costo: numero(v.costo) ?? 0, monto: numero(v.monto) ?? 0, quedan: numero(v.quedan) ?? 0, aviso: numero(v.aviso) ?? 0, activo: v.activo })) }))
        .put(`/productos/${props.producto.id}/variantes`, { preserveScroll: true });
}
function aplicarATodas(campo) {
    const v = form.variantes[0]?.[campo];
    form.variantes.forEach((x) => (x[campo] = v));
}
async function quitar(v) {
    if (await confirmar({ titulo: 'Quitar variante', texto: `¿Quitar "${Object.values(v.variante || {}).join(' / ')}"? Si ya se vendió, queda dada de baja.`, boton: 'Quitar', peligro: true })) {
        router.delete(`/productos/${props.producto.id}/variantes/${v.id}`, { preserveScroll: true });
    }
}
const error = computed(() => Object.values(form.errors)[0]);
</script>

<template>
    <Encabezado :titulo="producto.nombre" :subtitulo="`Variantes · ${variantes.length} combinaciones · stock${sucursalNombre ? ' en ' + sucursalNombre : ' total'} ${cantidad(total)} ${unidad(producto.unidad, total)}`">
        <Boton :href="`/productos/${producto.id}/edit`" variante="fantasma" :icono="ArrowLeft">Volver al producto</Boton>
    </Encabezado>

    <div v-if="!habilitadas" class="mb-5 flex flex-wrap items-center gap-3 rounded-xl border border-cartel-500 bg-cartel-300/20 px-4 py-3 text-sm">
        <span class="flex-1">Las variantes están deshabilitadas. Las existentes se venden igual, pero no se pueden crear nuevas.</span>
        <Boton href="/configuracion" tam="sm" variante="oscuro" :icono="Settings2">Habilitar</Boton>
    </div>

    <section v-if="habilitadas" class="panel mb-5 p-5">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
            <div>
                <h2 class="font-semibold">Crear combinaciones</h2>
                <p class="text-sm text-petroleo-500">Marcá los valores. Se crea una variante por cada combinación que todavía no exista.</p>
            </div>
            <Link href="/configuracion" class="text-sm font-medium text-petroleo-600 underline dark:text-petroleo-300">Editar talles, colores…</Link>
        </div>
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <fieldset v-for="a in atributos" :key="a.id">
                <legend class="etiqueta">{{ a.nombre }}</legend>
                <div class="flex flex-wrap gap-1.5">
                    <label v-for="v in [...new Set([...a.valores.map((x) => x.valor), ...sel[a.nombre]])]" :key="v"
                        class="cursor-pointer rounded-lg border border-petroleo-200 px-3 py-1.5 text-sm select-none has-[:checked]:border-petroleo-800 has-[:checked]:bg-petroleo-800 has-[:checked]:text-white dark:border-petroleo-700 dark:has-[:checked]:bg-petroleo-600">
                        <input v-model="sel[a.nombre]" type="checkbox" :value="v" class="sr-only" />{{ v }}
                    </label>
                </div>
                <form class="mt-2 flex gap-1.5" @submit.prevent="agregarExtra(a)">
                    <input v-model="extra[a.nombre]" class="campo py-1.5 text-sm" maxlength="30" :placeholder="'Otro ' + a.nombre.toLowerCase() + '…'" />
                </form>
            </fieldset>
        </div>
        <div class="mt-4 flex flex-wrap items-center gap-3 border-t border-petroleo-100 pt-4 dark:border-petroleo-800">
            <Boton variante="oscuro" :icono="Sparkles" :disabled="!combinaciones" :cargando="generando" @click="generar">
                {{ combinaciones ? `Crear ${combinaciones} combinaciones` : 'Elegí al menos un valor' }}
            </Boton>
            <span class="text-sm text-petroleo-500">Arrancan con el precio del producto ({{ plata(producto.monto) }}) y stock 0.</span>
        </div>
    </section>

    <section class="panel overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-petroleo-100 px-5 py-3.5 dark:border-petroleo-800">
            <h2 class="font-semibold">Variantes</h2>
            <Boton v-if="variantes.length" variante="oscuro" :icono="Save" :cargando="form.processing" :disabled="!form.isDirty" @click="guardar">Guardar cambios</Boton>
        </div>
        <p v-if="error" class="px-5 pt-3 text-sm text-sale">{{ error }}</p>
        <div v-if="variantes.length" class="overflow-x-auto">
            <table class="tabla min-w-[52rem]">
                <thead>
                    <tr>
                        <th>Variante</th><th>Código de barras</th>
                        <th class="w-28">Costo <button v-if="form.variantes.length > 1" class="ml-1 text-[0.65rem] underline" @click="aplicarATodas('costo')">copiar a todas</button></th>
                        <th class="w-28">Precio <button v-if="form.variantes.length > 1" class="ml-1 text-[0.65rem] underline" @click="aplicarATodas('monto')">copiar a todas</button></th>
                        <th class="w-24">Stock<span v-if="sucursalNombre" class="block text-[0.65rem] font-normal">en {{ sucursalNombre }}</span></th><th class="w-24">Avisar con</th><th class="w-16">Activa</th><th class="w-10"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="v in form.variantes" :key="v.id" :class="{ 'opacity-50': !v.activo }">
                        <td>
                            <div class="flex flex-wrap gap-1"><Insignia v-for="(val, at) in v.variante" :key="at" :color="at === 'Color' ? 'amarillo' : 'gris'">{{ val }}</Insignia></div>
                        </td>
                        <td><input v-model="v.codigo" class="campo py-1.5 text-sm" maxlength="100" /></td>
                        <td><input v-model="v.costo" inputmode="decimal" class="campo py-1.5 text-sm" /></td>
                        <td><input v-model="v.monto" inputmode="decimal" class="campo py-1.5 text-sm font-semibold" /></td>
                        <td><input v-model="v.quedan" inputmode="decimal" class="campo py-1.5 text-sm" :class="{ 'border-sale': Number(v.quedan) <= Number(v.aviso) }" /></td>
                        <td><input v-model="v.aviso" inputmode="decimal" class="campo py-1.5 text-sm" /></td>
                        <td class="text-center"><input v-model="v.activo" type="checkbox" class="size-4 accent-petroleo-700" /></td>
                        <td><button class="rounded-lg p-2 text-petroleo-400 hover:bg-red-50 hover:text-sale" aria-label="Quitar" @click="quitar(v)"><Trash2 class="size-4" /></button></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <Vacio v-else :icono="Layers" titulo="Todavía no tiene variantes" :texto="habilitadas ? 'Marcá talles, colores o tamaños arriba y tocá Crear.' : ''" />
    </section>
</template>
