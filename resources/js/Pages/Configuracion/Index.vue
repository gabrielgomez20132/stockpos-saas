<script setup>
import { ref } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { Layers, Plus, X, Trash2, Pencil, Check, FileSpreadsheet, Store, ArrowRight, FileText, ClipboardList } from 'lucide-vue-next';
import { Link } from '@inertiajs/vue3';
import Interruptor from '@/Components/Interruptor.vue';
import Modal from '@/Components/Modal.vue';
import Encabezado from '@/Components/Encabezado.vue';
import Boton from '@/Components/Boton.vue';
import Campo from '@/Components/Campo.vue';
import Insignia from '@/Components/Insignia.vue';
import { confirmar } from '@/lib/confirmar';

const props = defineProps({
    variantes: Boolean, atributos: Array, productosConVariantes: Number,
    listasPrecios: Boolean, presupuestos: Boolean, remitos: Boolean, multisucursal: Boolean, puedeSucursales: Boolean, sucursales: Array,
});

function modulo(url, campo, valor) {
    router.put(url, { [campo]: valor }, { preserveScroll: true, onStart: () => (cambiando.value = true), onFinish: () => (cambiando.value = false) });
}
async function alternarMulti() {
    if (!props.multisucursal && !(await confirmar({ titulo: 'Habilitar multisucursal', texto: 'Cada sucursal va a tener su propio stock y su propia caja. Lo que ya tenés queda en "Casa Central". ¿Habilitar?', boton: 'Habilitar' }))) return;
    modulo('/configuracion/multisucursal', 'habilitado', !props.multisucursal);
}

const sucForm = useForm({ id: null, nombre: '', direccion: '', telefono: '' });
const sucAbierto = ref(false);
function editarSucursal(s = null) {
    sucForm.clearErrors();
    Object.assign(sucForm, s ? { id: s.id, nombre: s.nombre, direccion: s.direccion ?? '', telefono: s.telefono ?? '' } : { id: null, nombre: '', direccion: '', telefono: '' });
    sucAbierto.value = true;
}
function guardarSucursal() {
    const op = { preserveScroll: true, onSuccess: () => (sucAbierto.value = false) };
    sucForm.id ? sucForm.put(`/configuracion/sucursales/${sucForm.id}`, op) : sucForm.post('/configuracion/sucursales', op);
}
async function bajaSucursal(s) {
    if (s.activa && !(await confirmar({ titulo: 'Dar de baja la sucursal', texto: `"${s.nombre}" deja de aparecer para vender. Sus ventas, cierres y stock se conservan.`, boton: 'Dar de baja', peligro: true }))) return;
    router.put(`/configuracion/sucursales/${s.id}`, { nombre: s.nombre, direccion: s.direccion, telefono: s.telefono, activa: !s.activa }, { preserveScroll: true });
}
const page = usePage();

const cambiando = ref(false);
function interruptor() {
    router.put('/configuracion/variantes', { habilitadas: !props.variantes }, {
        preserveScroll: true, onStart: () => (cambiando.value = true), onFinish: () => (cambiando.value = false),
    });
}

const nuevo = useForm({ nombre: '' });
const crear = () => nuevo.post('/configuracion/atributos', { preserveScroll: true, onSuccess: () => nuevo.reset() });

const nuevosValores = ref({});
function agregarValor(a) {
    const v = (nuevosValores.value[a.id] || '').trim();
    if (!v) return;
    router.post(`/configuracion/atributos/${a.id}/valores`, { valor: v }, { preserveScroll: true, onSuccess: () => (nuevosValores.value[a.id] = '') });
}
const quitarValor = (v) => router.delete(`/configuracion/valores/${v.id}`, { preserveScroll: true });

const editando = ref(null);
const nombreEdit = ref('');
function editar(a) { editando.value = a.id; nombreEdit.value = a.nombre; }
function guardarNombre(a) {
    router.put(`/configuracion/atributos/${a.id}`, { nombre: nombreEdit.value, activo: a.activo }, { preserveScroll: true, onSuccess: () => (editando.value = null) });
}
const alternar = (a) => router.put(`/configuracion/atributos/${a.id}`, { nombre: a.nombre, activo: !a.activo }, { preserveScroll: true });
async function borrar(a) {
    if (await confirmar({ titulo: 'Borrar atributo', texto: `¿Borrar "${a.nombre}" y sus valores? Las variantes que ya creaste no cambian.`, boton: 'Borrar', peligro: true })) {
        router.delete(`/configuracion/atributos/${a.id}`, { preserveScroll: true });
    }
}
</script>

<template>
    <Encabezado titulo="Configuración" subtitulo="Ajustes generales del sistema." />

    <section class="panel mb-5 flex flex-wrap items-center gap-4 p-5">
        <Layers class="size-8 text-petroleo-400" stroke-width="1.5" />
        <div class="min-w-0 flex-1 basis-64">
            <h2 class="font-semibold">Variantes de productos</h2>
            <p class="text-sm text-petroleo-500">Talles, colores y tamaños para ropa, calzado y similares. Cada variante tiene su propio código, stock y precio.</p>
            <p v-if="productosConVariantes" class="mt-1 text-xs text-petroleo-500">{{ productosConVariantes }} productos ya tienen variantes.</p>
        </div>
        <Interruptor :activo="variantes" :disabled="cambiando" etiqueta="Habilitar variantes" @cambiar="interruptor" />
    </section>

    <section class="panel mb-5 flex flex-wrap items-center gap-4 p-5">
        <FileSpreadsheet class="size-8 text-petroleo-400" stroke-width="1.5" />
        <div class="min-w-0 flex-1 basis-64">
            <h2 class="font-semibold">Listas de precios de proveedores</h2>
            <p class="text-sm text-petroleo-500">Subís el Excel o CSV que te manda el proveedor, el sistema calcula los costos nuevos, te muestra qué sube y qué baja, y vos elegís qué aplicar.</p>
            <Link v-if="listasPrecios" href="/listas-precios" class="mt-1 inline-flex items-center gap-1 text-sm font-semibold text-petroleo-700 underline dark:text-petroleo-200">Ir a Listas de precios<ArrowRight class="size-3.5" /></Link>
        </div>
        <Interruptor :activo="listasPrecios" :disabled="cambiando" etiqueta="Habilitar listas de precios" @cambiar="modulo('/configuracion/listas-precios', 'habilitadas', !listasPrecios)" />
    </section>

    <section class="panel mb-5 flex flex-wrap items-center gap-4 p-5">
        <FileText class="size-8 text-petroleo-400" stroke-width="1.5" />
        <div class="min-w-0 flex-1 basis-64">
            <h2 class="font-semibold">Presupuestos</h2>
            <p class="text-sm text-petroleo-500">Armás un presupuesto con productos del catálogo o renglones a mano, con validez y descuento, y lo imprimís en hoja A4 (o PDF) con tu logo. No toca el stock ni la caja.</p>
            <Link v-if="presupuestos" href="/presupuestos" class="mt-1 inline-flex items-center gap-1 text-sm font-semibold text-petroleo-700 underline dark:text-petroleo-200">Ir a Presupuestos<ArrowRight class="size-3.5" /></Link>
        </div>
        <Interruptor :activo="presupuestos" :disabled="cambiando" etiqueta="Habilitar presupuestos" @cambiar="modulo('/configuracion/presupuestos', 'habilitadas', !presupuestos)" />
    </section>

    <section class="panel mb-5 flex flex-wrap items-center gap-4 p-5">
        <ClipboardList class="size-8 text-petroleo-400" stroke-width="1.5" />
        <div class="min-w-0 flex-1 basis-64">
            <h2 class="font-semibold">Remitos</h2>
            <p class="text-sm text-petroleo-500">Remito de entrega en hoja A4 con tu logo, con o sin precios. Desde una venta (no vuelve a descontar stock, permite entregas parciales) o suelto (descuenta stock; si lo anulás, vuelve).</p>
            <Link v-if="remitos" href="/remitos" class="mt-1 inline-flex items-center gap-1 text-sm font-semibold text-petroleo-700 underline dark:text-petroleo-200">Ir a Remitos<ArrowRight class="size-3.5" /></Link>
        </div>
        <Interruptor :activo="remitos" :disabled="cambiando" etiqueta="Habilitar remitos" @cambiar="modulo('/configuracion/remitos', 'habilitadas', !remitos)" />
    </section>

    <section class="panel mb-5 p-5">
        <div class="flex flex-wrap items-center gap-4">
            <Store class="size-8 text-petroleo-400" stroke-width="1.5" />
            <div class="min-w-0 flex-1 basis-64">
                <h2 class="font-semibold">Multisucursal</h2>
                <p class="text-sm text-petroleo-500">Varios locales: cada uno con su stock y su caja, los mismos productos y precios, transferencias de mercadería y reportes por sucursal.</p>
                <p v-if="!puedeSucursales" class="mt-1 text-xs text-petroleo-500">Lo habilita el dueño (permiso de Encargados).</p>
            </div>
            <Interruptor :activo="multisucursal" :disabled="cambiando || !puedeSucursales" etiqueta="Habilitar multisucursal" @cambiar="alternarMulti" />
        </div>

        <div v-if="multisucursal" class="mt-5 border-t border-petroleo-100 pt-4 dark:border-petroleo-800">
            <div class="mb-3 flex items-center justify-between gap-3">
                <h3 class="font-semibold">Sucursales</h3>
                <Boton v-if="puedeSucursales" tam="sm" variante="oscuro" :icono="Plus" @click="editarSucursal()">Nueva sucursal</Boton>
            </div>
            <ul class="divide-y divide-petroleo-50 rounded-xl border border-petroleo-100 dark:divide-petroleo-800 dark:border-petroleo-800">
                <li v-for="s in sucursales" :key="s.id" class="flex flex-wrap items-center gap-3 px-4 py-3" :class="{ 'opacity-60': !s.activa }">
                    <div class="min-w-0 flex-1 basis-48">
                        <p class="font-medium">{{ s.nombre }} <Insignia v-if="s.id === 1" color="azul" class="ml-1">Principal</Insignia><Insignia v-if="!s.activa" class="ml-1">De baja</Insignia></p>
                        <p class="text-xs text-petroleo-500">{{ [s.direccion, s.telefono].filter(Boolean).join(' · ') || 'Sin dirección' }} · {{ s.encargados }} encargados asignados</p>
                    </div>
                    <Insignia :color="s.caja ? 'verde' : 'gris'">{{ s.caja ? 'Caja abierta' : 'Caja cerrada' }}</Insignia>
                    <template v-if="puedeSucursales">
                        <Boton tam="sm" variante="fantasma" :icono="Pencil" aria-label="Editar" @click="editarSucursal(s)" />
                        <Boton v-if="s.id !== 1" tam="sm" variante="fantasma" @click="bajaSucursal(s)">{{ s.activa ? 'Dar de baja' : 'Reactivar' }}</Boton>
                    </template>
                </li>
            </ul>
            <p class="mt-2 text-xs text-petroleo-500">Asigná cada encargado a su sucursal desde <Link href="/encargados" class="underline">Encargados</Link>. Los que no tienen sucursal pueden cambiar de local desde el menú.</p>
        </div>
    </section>

    <Modal v-model="sucAbierto" :titulo="sucForm.id ? 'Editar sucursal' : 'Nueva sucursal'" ancho="max-w-md">
        <form id="form-suc" class="space-y-4" @submit.prevent="guardarSucursal">
            <Campo v-model="sucForm.nombre" label="Nombre" maxlength="60" placeholder="Ej: Sucursal Centro" required :error="sucForm.errors.nombre" />
            <Campo v-model="sucForm.direccion" label="Dirección (opcional)" maxlength="120" :error="sucForm.errors.direccion" />
            <Campo v-model="sucForm.telefono" label="Teléfono (opcional)" maxlength="40" :error="sucForm.errors.telefono" />
            <p v-if="!sucForm.id" class="text-sm text-petroleo-500">El stock de la sucursal nueva arranca en 0: cargalo con una transferencia desde otra sucursal o editando cada producto estando en esa sucursal.</p>
        </form>
        <template #pie>
            <Boton @click="sucAbierto = false">Cancelar</Boton>
            <Boton tipo="submit" form="form-suc" variante="oscuro" :cargando="sucForm.processing">Guardar</Boton>
        </template>
    </Modal>

    <template v-if="variantes">
        <div class="mt-8 mb-3 flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="text-lg font-semibold">Atributos y valores de las variantes</h2>
                <p class="text-sm text-petroleo-500">Son las opciones que vas a poder elegir al crear las variantes de un producto.</p>
            </div>
            <form class="flex gap-2" @submit.prevent="crear">
                <input v-model="nuevo.nombre" class="campo w-48" maxlength="30" placeholder="Nuevo atributo (ej: Número)" required />
                <Boton tipo="submit" variante="oscuro" :icono="Plus" :cargando="nuevo.processing">Agregar</Boton>
            </form>
        </div>
        <p v-if="nuevo.errors.nombre" class="mb-2 text-sm text-sale">{{ nuevo.errors.nombre }}</p>
        <p v-if="page.props.errors?.valor" class="mb-2 text-sm text-sale">{{ page.props.errors.valor }}</p>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <section v-for="a in atributos" :key="a.id" class="panel p-5" :class="{ 'opacity-60': !a.activo }">
                <div class="mb-3 flex items-center gap-2">
                    <template v-if="editando === a.id">
                        <input v-model="nombreEdit" class="campo flex-1" maxlength="30" @keydown.enter.prevent="guardarNombre(a)" />
                        <Boton tam="sm" variante="oscuro" :icono="Check" aria-label="Guardar" @click="guardarNombre(a)" />
                    </template>
                    <template v-else>
                        <h3 class="flex-1 font-semibold">{{ a.nombre }}</h3>
                        <Insignia v-if="!a.activo">Oculto</Insignia>
                        <Boton tam="sm" variante="fantasma" :icono="Pencil" aria-label="Renombrar" @click="editar(a)" />
                        <Boton tam="sm" variante="fantasma" :icono="Trash2" aria-label="Borrar" @click="borrar(a)" />
                    </template>
                </div>
                <div class="flex flex-wrap gap-1.5">
                    <span v-for="v in a.valores" :key="v.id" class="inline-flex items-center gap-1 rounded-lg border border-petroleo-200 bg-papel py-1 pr-1 pl-2.5 text-sm dark:border-petroleo-700 dark:bg-petroleo-950">
                        {{ v.valor }}
                        <button type="button" class="rounded p-0.5 text-petroleo-400 hover:bg-red-50 hover:text-sale" :aria-label="'Quitar ' + v.valor" @click="quitarValor(v)"><X class="size-3.5" /></button>
                    </span>
                    <span v-if="!a.valores.length" class="text-sm text-petroleo-400">Sin valores todavía</span>
                </div>
                <form class="mt-3 flex gap-2" @submit.prevent="agregarValor(a)">
                    <input v-model="nuevosValores[a.id]" class="campo" maxlength="30" :placeholder="`Agregar ${a.nombre.toLowerCase()}…`" />
                    <Boton tipo="submit" :icono="Plus" aria-label="Agregar valor" />
                </form>
                <label class="mt-3 flex items-center gap-2 text-sm"><input type="checkbox" :checked="a.activo" class="size-4 accent-petroleo-700" @change="alternar(a)" />Mostrar al crear variantes</label>
            </section>
        </div>
    </template>
</template>
