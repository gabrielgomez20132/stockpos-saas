<script setup>
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Search, Trash2, Package, Plus } from 'lucide-vue-next';
import Encabezado from '@/Components/Encabezado.vue';
import Boton from '@/Components/Boton.vue';
import Campo from '@/Components/Campo.vue';
import Vacio from '@/Components/Vacio.vue';
import http from '@/lib/http';
import { plata, cantidad, numero } from '@/lib/formato';

const props = defineProps({ combo: Object });
const c = props.combo;
const form = useForm({
    nombre: c?.nombre ?? '', codigo: c?.codigo ?? '', precio: c?.precio ?? '', desde: c?.desde ?? '', hasta: c?.hasta ?? '', activo: c?.activo ?? true,
    items: (c?.items ?? []).map((i) => ({ ...i })),
});

const q = ref('');
const resultados = ref([]);
let t;
watch(q, (v) => {
    clearTimeout(t);
    if ((v || '').trim().length < 2) return (resultados.value = []);
    t = setTimeout(async () => {
        try { resultados.value = (await http.get('/buscar/productos', { params: { q: v.trim() } })).data.filter((r) => r.tipo !== 'combo'); } catch { resultados.value = []; }
    }, 220);
});
function sumar(p) {
    const ya = form.items.find((i) => i.id_producto === p.id);
    if (ya) ya.cantidad = Number(ya.cantidad) + 1;
    else form.items.push({ id_producto: p.id, cantidad: 1, nombre: p.nombre, codigo: p.codigo, monto: p.monto, costo: null });
    q.value = '';
    resultados.value = [];
}
const lista = computed(() => form.items.reduce((a, i) => a + (Number(i.monto) || 0) * (numero(i.cantidad) || 0), 0));
const ahorro = computed(() => lista.value - (numero(form.precio) || 0));

function guardar() {
    form.transform((d) => ({
        ...d, codigo: d.codigo || null, desde: d.desde || null, hasta: d.hasta || null, precio: numero(d.precio),
        items: d.items.map((i) => ({ id_producto: i.id_producto, cantidad: numero(i.cantidad) })),
    }));
    c ? form.put(`/combos/${c.id}`) : form.post('/combos');
}
const error = computed(() => Object.entries(form.errors).find(([k]) => k.startsWith('items'))?.[1]);
</script>

<template>
    <Encabezado :titulo="c ? 'Editar combo' : 'Nuevo combo'" subtitulo="Elegí los productos que lleva y el precio especial." />
    <form class="grid gap-5 lg:grid-cols-[1fr_22rem]" @submit.prevent="guardar">
        <section class="panel p-5">
            <h2 class="mb-3 font-semibold">Productos del combo</h2>
            <div class="relative mb-4">
                <Search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-petroleo-300" />
                <input v-model="q" class="campo pl-9" placeholder="Buscar producto por nombre o código para agregarlo" />
                <ul v-if="resultados.length" class="absolute inset-x-0 top-full z-20 mt-1 max-h-72 overflow-y-auto rounded-xl border border-petroleo-100 bg-white shadow-xl dark:border-petroleo-700 dark:bg-petroleo-900">
                    <li v-for="r in resultados" :key="r.id">
                        <button type="button" class="flex w-full items-center gap-3 px-3 py-2.5 text-left hover:bg-petroleo-50 dark:hover:bg-petroleo-800" @click="sumar(r)">
                            <Plus class="size-4 text-petroleo-400" />
                            <span class="min-w-0 flex-1"><span class="block truncate font-medium">{{ r.nombre }}</span><span class="text-xs text-petroleo-400">{{ r.codigo }} · stock {{ cantidad(r.quedan) }}</span></span>
                            <span class="cifra-suave">{{ plata(r.monto) }}</span>
                        </button>
                    </li>
                </ul>
            </div>
            <ul v-if="form.items.length" class="divide-y divide-petroleo-50 dark:divide-petroleo-800">
                <li v-for="(i, k) in form.items" :key="i.id_producto" class="flex items-center gap-3 py-2.5">
                    <input v-model="i.cantidad" inputmode="decimal" class="campo w-20 text-center" aria-label="Cantidad" />
                    <span class="min-w-0 flex-1"><span class="block truncate font-medium">{{ i.nombre }}</span><span class="text-xs text-petroleo-400">{{ i.codigo }} · {{ plata(i.monto) }} c/u</span></span>
                    <span class="cifra-suave w-24 text-right">{{ plata((Number(i.monto) || 0) * (numero(i.cantidad) || 0)) }}</span>
                    <button type="button" class="rounded-lg p-2 text-petroleo-400 hover:bg-red-50 hover:text-sale" aria-label="Quitar" @click="form.items.splice(k, 1)"><Trash2 class="size-4" /></button>
                </li>
            </ul>
            <Vacio v-else :icono="Package" titulo="Sin productos" texto="Buscá arriba y tocá cada producto para sumarlo al combo." />
            <p v-if="error" class="mt-2 text-sm text-sale">{{ error }}</p>
        </section>

        <aside class="panel h-fit space-y-4 p-5">
            <Campo v-model="form.nombre" label="Nombre" maxlength="80" placeholder="Fernet + Coca + Hielo" required :error="form.errors.nombre" />
            <Campo v-model="form.precio" label="Precio del combo" inputmode="decimal" prefijo="$" required :error="form.errors.precio" />
            <div class="rounded-xl bg-papel p-3 text-sm dark:bg-petroleo-950">
                <p class="flex justify-between"><span>Por separado</span><span>{{ plata(lista) }}</span></p>
                <p class="flex justify-between font-semibold" :class="ahorro >= 0 ? 'text-entra' : 'text-sale'"><span>{{ ahorro >= 0 ? 'El cliente ahorra' : 'Más caro que por separado' }}</span><span>{{ plata(Math.abs(ahorro)) }}</span></p>
            </div>
            <Campo v-model="form.codigo" label="Código (opcional)" maxlength="100" ayuda="Para cargarlo con el lector o escribiendo el código." :error="form.errors.codigo" />
            <div class="grid grid-cols-2 gap-2">
                <Campo v-model="form.desde" label="Desde" tipo="date" :error="form.errors.desde" />
                <Campo v-model="form.hasta" label="Hasta" tipo="date" :min="form.desde" :error="form.errors.hasta" />
            </div>
            <p class="-mt-2 text-xs text-petroleo-500">Dejalas vacías para que no venza.</p>
            <label class="flex items-center gap-2 text-sm"><input v-model="form.activo" type="checkbox" class="size-4 accent-petroleo-700" />Activo (aparece en la venta)</label>
            <div class="flex flex-col gap-2">
                <Boton tipo="submit" variante="oscuro" :cargando="form.processing">Guardar combo</Boton>
                <Boton href="/combos" variante="fantasma">Cancelar</Boton>
            </div>
        </aside>
    </form>
</template>
