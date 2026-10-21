<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    Plus, Minus, Trash2, ScanBarcode, CirclePlus, ShoppingBasket, UserPlus, Ticket, FileText, FileX, ArrowLeft,
    ClipboardList, Banknote, CreditCard, QrCode, Landmark, ScrollText, BookUser, TicketPercent, X, Lock, Gift,
} from 'lucide-vue-next';
import Encabezado from '@/Components/Encabezado.vue';
import Boton from '@/Components/Boton.vue';
import Campo from '@/Components/Campo.vue';
import Modal from '@/Components/Modal.vue';
import Insignia from '@/Components/Insignia.vue';
import Vacio from '@/Components/Vacio.vue';
import FacturarModal from '@/Components/FacturarModal.vue';
import http, { mensajeError } from '@/lib/http';
import { toast, toastError } from '@/lib/toast';
import { confirmar } from '@/lib/confirmar';
import { plata, cantidad, unidad, numero, fecha, hora } from '@/lib/formato';

const props = defineProps({
    venta: Object, items: Array, afip: Object,
    clientes: Array, canales: Array, medios: Array, cajaAbierta: Boolean, puedeCrearClientes: Boolean, remitos: Array,
});

const GENERAL = 2;
const pagina = usePage();
const puede = (p) => (pagina.props.auth?.permisos ?? []).includes(p);
const items = ref(props.items);
watch(() => props.items, (v) => (items.value = v));
const total = computed(() => items.value.reduce((a, i) => a + Number(i.subtotal), 0));
const editable = computed(() => !props.venta.completada);

// ------------------------------------------------------------ carga de productos
const cant = ref('1');
const codigo = ref('');
const codigoRef = ref(null);
const sugerencias = ref([]);
const resaltada = ref(-1);
const ocupado = ref(false);
const elegir = ref([]);
let timer = null;

const enfocar = () => nextTick(() => codigoRef.value?.focus());
onMounted(() => editable.value && enfocar());

function aplicar(r) {
    items.value = r.data.items;
    if (r.data.message) toast(r.data.message);
}

watch(codigo, (v) => {
    clearTimeout(timer);
    resaltada.value = -1;
    const q = (v || '').trim();
    // un lector de codigo de barras "tipea" numeros largos muy rapido: ahi no se sugiere
    if (q.length < 2 || /^\d{7,}$/.test(q)) return (sugerencias.value = []);
    timer = setTimeout(async () => {
        try {
            const r = await http.get('/buscar/productos', { params: { q } });
            if (codigo.value.trim() === q) sugerencias.value = r.data;
        } catch { sugerencias.value = []; }
    }, 220);
});

async function agregar(producto = null) {
    const c = numero(cant.value);
    if (!c || c <= 0) return toastError('La cantidad tiene que ser mayor a cero.');
    if (!producto && !codigo.value.trim()) return;
    ocupado.value = true;
    try {
        const r = await http.post(`/ventas/${props.venta.id}/items`, producto ? { [producto.tipo === 'combo' ? 'combo_id' : 'product_id']: producto.id, cantidad: c } : { codigo: codigo.value.trim(), cantidad: c });
        if (r.data.elegir) {
            elegir.value = r.data.elegir;
            return;
        }
        aplicar(r);
        codigo.value = '';
        cant.value = '1';
        sugerencias.value = [];
        elegir.value = [];
    } catch (e) {
        toastError(mensajeError(e));
    } finally {
        ocupado.value = false;
        enfocar();
    }
}

function teclaProducto(e) {
    if (e.key === 'ArrowDown' && sugerencias.value.length) { e.preventDefault(); resaltada.value = Math.min(resaltada.value + 1, sugerencias.value.length - 1); }
    else if (e.key === 'ArrowUp' && sugerencias.value.length) { e.preventDefault(); resaltada.value = Math.max(resaltada.value - 1, -1); }
    else if (e.key === 'Escape') sugerencias.value = [];
    else if (e.key === 'Enter') {
        e.preventDefault();
        const s = resaltada.value >= 0 ? sugerencias.value[resaltada.value] : null;
        agregar(s);
    }
}

function cerrarSugerencias() { setTimeout(() => (sugerencias.value = []), 150); }
function elegirMedio(id) {
    modo.value = 'normal';
    medio1.value = id;
    if (medio2.value === id) medio2.value = props.medios.find((x) => x.id !== id).id;
}

function pasoDe(i) { return i.fraccionable ? 0.1 : 1; }
async function guardarCantidad(i, nueva) {
    nueva = Math.round(nueva * 1000) / 1000;
    if (nueva <= 0) return toast('Para sacar el producto usá el tacho.', 'error');
    if (!i.fraccionable && !Number.isInteger(nueva)) return toastError('Este producto se vende por unidad.');
    if (nueva === Number(i.cantidad)) return;
    try { aplicar(await http.put(`/ventas/items/${i.id}`, { cantidad: nueva })); } catch (e) { toastError(mensajeError(e)); router.reload({ only: ['items'] }); }
}
function cambiarDesdeInput(i, ev) {
    const n = numero(ev.target.value);
    if (!n || n <= 0) { ev.target.value = cantidad(i.cantidad); return; }
    guardarCantidad(i, n);
}
async function quitar(i) {
    try {
        aplicar(await http.delete(i.tipo === 'svc' ? `/ventas/servicios/${i.id}` : `/ventas/items/${i.id}`));
    } catch (e) { toastError(mensajeError(e)); }
    enfocar();
}

// otro concepto (servicio)
const svcAbierto = ref(false);
const svc = ref({ descripcion: '', monto: '' });
async function agregarServicio() {
    try {
        aplicar(await http.post(`/ventas/${props.venta.id}/servicios`, svc.value));
        svc.value = { descripcion: '', monto: '' };
        svcAbierto.value = false;
    } catch (e) { toastError(mensajeError(e)); }
}

// ------------------------------------------------------------------- cobro
const clientes = ref(props.clientes ?? []);
const form = useForm({
    id_cliente: GENERAL, canal_id: props.canales?.[0]?.id ?? 1,
    medios: ['efectivo'], descuento_pct: '', descuento_monto: '', pago: '', monto_primero: '', id_vale: null,
});
const general = computed(() => Number(form.id_cliente) === GENERAL);
const modo = ref('normal'); // normal | fiado | vale
const mixto = ref(false);
const medio1 = ref('efectivo');
const medio2 = ref('debito');
const iconos = { efectivo: Banknote, debito: CreditCard, credito: CreditCard, qr: QrCode, transferencia: Landmark, cheque: ScrollText };

const descuento = computed(() => {
    const pct = Math.max(0, Math.min(100, numero(form.descuento_pct) || 0));
    const d = Math.ceil(Math.round(total.value * pct / 100 * 1e4) / 1e4) + Math.max(0, numero(form.descuento_monto) || 0);
    return Math.min(d, total.value);
});
const aPagar = computed(() => Math.round((total.value - descuento.value) * 100) / 100);

// vales
const vales = ref([]);
const codigoVale = ref('');
const valeCodigo = ref(null);
watch(() => form.id_cliente, async (id) => {
    vales.value = [];
    form.id_vale = null;
    valeCodigo.value = null;
    codigoVale.value = '';
    if (Number(id) === GENERAL && modo.value === 'fiado') modo.value = 'normal';
    if (Number(id) !== GENERAL) {
        try { vales.value = (await http.get(`/buscar/vales/cliente/${id}`)).data; } catch { vales.value = []; }
    }
});
let timerVale = null;
watch(codigoVale, (v) => {
    clearTimeout(timerVale);
    valeCodigo.value = null;
    if ((v || '').trim().length < 4) return;
    timerVale = setTimeout(async () => {
        try { valeCodigo.value = (await http.get('/buscar/vales/codigo', { params: { codigo: v.trim() } })).data; } catch { valeCodigo.value = null; }
    }, 300);
});
const vale = computed(() => (valeCodigo.value && !form.id_vale ? valeCodigo.value : vales.value.find((x) => x.id === form.id_vale)) || null);
const montoVale = computed(() => (vale.value ? Math.min(Number(vale.value.monto_disponible), aPagar.value) : 0));
const restoVale = computed(() => Math.round((aPagar.value - montoVale.value) * 100) / 100);
const medioResto = ref('efectivo');

// lo que se paga en mano y el vuelto
const aCobrar = computed(() => (modo.value === 'vale' ? restoVale.value : aPagar.value));
const pagaCon = computed(() => numero(form.pago));
const pagado = computed(() => (pagaCon.value === null ? aCobrar.value : Math.min(pagaCon.value, aCobrar.value)));
const vuelto = computed(() => (pagaCon.value === null ? 0 : Math.max(0, pagaCon.value - aCobrar.value)));
const queda = computed(() => (modo.value === 'normal' ? Math.max(0, Math.round((aCobrar.value - pagado.value) * 100) / 100) : 0));
const primero = computed(() => Math.max(0, Math.min(numero(form.monto_primero) || 0, pagado.value)));
const segundo = computed(() => Math.round((pagado.value - primero.value) * 100) / 100);
const usaEfectivo = computed(() => (modo.value === 'vale' ? medioResto.value === 'efectivo' && restoVale.value > 0 : modo.value === 'normal' && (medio1.value === 'efectivo' || (mixto.value && medio2.value === 'efectivo'))));

const aviso = computed(() => {
    if (!props.cajaAbierta) return 'La caja está cerrada';
    if (total.value <= 0) return 'Agregá al menos un producto';
    if (modo.value === 'fiado' && general.value) return 'Elegí un cliente con nombre para fiar';
    if (modo.value === 'vale') {
        if (!vale.value) return 'Elegí o escaneá el vale';
        if (restoVale.value > 0 && medioResto.value === 'efectivo' && pagaCon.value !== null && pagaCon.value < restoVale.value) return `Faltan ${plata(restoVale.value - pagaCon.value)}`;
        return '';
    }
    if (modo.value === 'normal') {
        if (mixto.value && medio1.value === medio2.value) return 'Elegí dos medios distintos';
        if (queda.value > 0 && general.value) return `Faltan ${plata(queda.value)} (para dejar saldo, elegí un cliente)`;
    }
    return '';
});

function cobrar() {
    if (aviso.value) return;
    let medios;
    if (modo.value === 'fiado') medios = ['fiado'];
    else if (modo.value === 'vale') medios = restoVale.value > 0 ? ['vale', medioResto.value] : ['vale'];
    else medios = mixto.value ? [medio1.value, medio2.value] : [medio1.value];

    const vueltoFinal = usaEfectivo.value ? vuelto.value : 0;
    form.transform((d) => ({
        ...d, medios,
        id_vale: vale.value?.id ?? null,
        pago: modo.value === 'normal' && pagaCon.value !== null ? Math.min(pagaCon.value, aCobrar.value) : null,
        monto_primero: mixto.value ? primero.value : null,
        descuento_pct: numero(d.descuento_pct) || 0,
        descuento_monto: numero(d.descuento_monto) || 0,
    })).post(`/ventas/${props.venta.id}/cobrar`, {
        preserveScroll: true,
        onSuccess: () => vueltoFinal > 0 && toast(`Vuelto: ${plata(vueltoFinal)}`, 'ok', 9000),
    });
}

// alta rapida de cliente
const nuevoCli = ref(false);
const nombreCli = ref('');
async function crearCliente() {
    try {
        const r = await http.post('/clientes/rapido', { nombre: nombreCli.value });
        clientes.value.push(r.data);
        form.id_cliente = r.data.id;
        nuevoCli.value = false;
        nombreCli.value = '';
    } catch (e) { toastError(mensajeError(e)); }
}

async function anular() {
    if (await confirmar({ titulo: 'Anular venta', texto: 'Se borra la venta y los productos vuelven al stock.', boton: 'Anular venta', peligro: true })) {
        router.delete(`/ventas/${props.venta.id}`);
    }
}

// ------------------------------------------------------------ venta cobrada
const factAbierto = ref(false);
async function notaCredito() {
    if (await confirmar({ titulo: 'Nota de crédito', texto: 'Se emite una nota de crédito por el total de la factura en AFIP. La venta queda anulada y los productos vuelven al stock.', boton: 'Emitir nota de crédito', peligro: true })) {
        router.post(`/afip/nota-credito/${props.venta.id}`, {}, { preserveScroll: true });
    }
}
const abrirVentana = (url) => window.open(url, 'impresion', 'width=480,height=760');
</script>

<template>
    <Encabezado :titulo="`Venta #${venta.id}`" :subtitulo="editable ? `Atiende ${venta.encargado}` : `${fecha(venta.created_at)} ${hora(venta.created_at)} · ${venta.encargado}`">
        <template v-if="editable">
            <Boton v-if="puede('anular-ventas')" variante="fantasma" :icono="Trash2" @click="anular">Anular</Boton>
        </template>
        <template v-else>
            <Boton href="/ventas" variante="fantasma" :icono="ArrowLeft">Ventas</Boton>
            <Boton v-if="venta.deHoy && pagina.props.caja?.abierta" variante="principal" :icono="ShoppingBasket" @click="router.post('/ventas')">Nueva venta</Boton>
        </template>
    </Encabezado>

    <!-- ===================================================== VENTA ABIERTA -->
    <div v-if="editable" class="grid gap-5 pb-20 lg:grid-cols-[1fr_24rem] lg:pb-0">
        <div class="min-w-0 space-y-4">
            <div v-if="!cajaAbierta" class="flex items-center gap-3 rounded-xl border border-cartel-500 bg-cartel-300/20 px-4 py-3 text-sm">
                <Lock class="size-5 text-cartel-700" /> La caja está cerrada. <Link href="/caja" class="font-semibold underline">Abrila</Link> para seguir con esta venta.
            </div>

            <!-- carga -->
            <form class="panel p-4" autocomplete="off" @submit.prevent="agregar()">
                <div class="flex gap-2">
                    <label class="w-20 shrink-0 sm:w-24">
                        <span class="etiqueta">Cant.</span>
                        <input v-model="cant" inputmode="decimal" class="campo text-center text-lg font-semibold" @focus="$event.target.select()" />
                    </label>
                    <div class="relative min-w-0 flex-1">
                        <span class="etiqueta">Producto</span>
                        <div class="relative">
                            <ScanBarcode class="pointer-events-none absolute top-1/2 left-3 size-5 -translate-y-1/2 text-petroleo-300" />
                            <input ref="codigoRef" v-model="codigo" class="campo pl-10 text-lg" placeholder="Escaneá o escribí código / nombre" 
                                @keydown="teclaProducto" @blur="cerrarSugerencias" />
                        </div>
                        <ul v-if="sugerencias.length" class="absolute inset-x-0 top-full z-20 mt-1 max-h-80 overflow-y-auto rounded-xl border border-petroleo-100 bg-white shadow-xl dark:border-petroleo-700 dark:bg-petroleo-900">
                            <li v-for="(s, k) in sugerencias" :key="s.tipo + s.id">
                                <button type="button" class="flex w-full items-center gap-3 px-3 py-2.5 text-left" :class="k === resaltada ? 'bg-petroleo-50 dark:bg-petroleo-800' : 'hover:bg-petroleo-50 dark:hover:bg-petroleo-800'"
                                    @mousedown.prevent="agregar(s)">
                                    <img v-if="s.imagen" :src="s.imagen" alt="" class="size-9 rounded-lg object-cover" />
                                    <span v-else-if="s.tipo === 'combo'" class="grid size-9 place-items-center rounded-lg bg-cartel-300/40 text-cartel-700"><Gift class="size-4" /></span>
                                    <span v-else class="grid size-9 place-items-center rounded-lg bg-petroleo-50 text-petroleo-300 dark:bg-petroleo-800"><ShoppingBasket class="size-4" /></span>
                                    <span class="min-w-0 flex-1"><span class="block truncate font-medium">{{ s.nombre }}</span>
                                        <span v-if="s.tipo === 'combo'" class="text-xs font-semibold text-cartel-700">Combo{{ s.codigo ? ' · ' + s.codigo : '' }}</span>
                                        <span v-else class="text-xs text-petroleo-400">{{ s.codigo }} · quedan {{ cantidad(s.quedan) }} {{ unidad(s.unidad, s.quedan) }}</span></span>
                                    <span class="cifra-suave">{{ plata(s.monto) }}</span>
                                </button>
                            </li>
                        </ul>
                    </div>
                </div>
                <div class="mt-3 flex gap-2">
                    <Boton tipo="submit" variante="oscuro" class="flex-1 sm:flex-none" :icono="Plus" :cargando="ocupado">Agregar</Boton>
                    <Boton variante="suave" :icono="CirclePlus" @click="svcAbierto = true">Otro concepto</Boton>
                </div>
            </form>

            <!-- detalle -->
            <section class="panel overflow-hidden">
                <ul v-if="items.length" class="divide-y divide-petroleo-50 dark:divide-petroleo-800">
                    <li v-for="i in items" :key="i.tipo + i.id" class="flex flex-wrap items-center gap-x-3 gap-y-2 px-4 py-3">
                        <img v-if="i.imagen" :src="i.imagen" alt="" class="size-11 shrink-0 rounded-lg object-cover" />
                        <span v-else-if="i.tipo === 'combo'" class="grid size-11 shrink-0 place-items-center rounded-lg bg-cartel-300/40 text-cartel-700"><Gift class="size-5" /></span>
                        <span v-else class="grid size-11 shrink-0 place-items-center rounded-lg bg-petroleo-50 text-petroleo-300 dark:bg-petroleo-800"><ShoppingBasket class="size-5" /></span>
                        <div class="min-w-0 flex-1 basis-40">
                            <p class="truncate font-medium">{{ i.nombre }} <span v-if="i.tipo === 'combo'" class="ml-1 rounded-full bg-cartel-300/40 px-2 py-0.5 text-xs font-semibold text-cartel-700">Combo</span></p>
                            <p class="text-xs text-petroleo-500">{{ plata(i.precio) }}{{ i.unidad ? ' / ' + unidad(i.unidad, 1) : '' }}</p>
                            <p v-if="i.detalle" class="text-xs text-petroleo-500">{{ i.detalle.join(' + ') }}</p>
                        </div>
                        <div v-if="i.tipo !== 'svc'" class="flex items-center rounded-xl border border-petroleo-200 dark:border-petroleo-700">
                            <button type="button" class="grid size-10 place-items-center text-petroleo-500 disabled:opacity-30" aria-label="Restar" :disabled="Number(i.cantidad) <= pasoDe(i)" @click="guardarCantidad(i, Number(i.cantidad) - pasoDe(i))"><Minus class="size-4" /></button>
                            <input :value="cantidad(i.cantidad)" inputmode="decimal" class="w-14 bg-transparent text-center font-semibold outline-none" @focus="$event.target.select()" @change="cambiarDesdeInput(i, $event)" @keydown.enter="$event.target.blur()" />
                            <button type="button" class="grid size-10 place-items-center text-petroleo-500" aria-label="Sumar" @click="guardarCantidad(i, Number(i.cantidad) + pasoDe(i))"><Plus class="size-4" /></button>
                        </div>
                        <span class="cifra-suave w-24 text-right text-lg">{{ plata(i.subtotal) }}</span>
                        <button type="button" class="rounded-lg p-2 text-petroleo-400 hover:bg-red-50 hover:text-sale" :aria-label="'Quitar ' + i.nombre" @click="quitar(i)"><Trash2 class="size-4" /></button>
                    </li>
                </ul>
                <Vacio v-else :icono="ShoppingBasket" titulo="La venta está vacía" texto="Escaneá un código de barras o buscá el producto por nombre." />
            </section>
        </div>

        <!-- cobro -->
        <aside id="cobro" class="panel h-fit p-4 lg:sticky lg:top-6">
            <div class="flex items-end justify-between gap-3 border-b border-dashed border-petroleo-200 pb-3 dark:border-petroleo-700">
                <span class="text-sm text-petroleo-500">Total</span>
                <span class="cifra text-5xl leading-none">{{ plata(aPagar) }}</span>
            </div>
            <p v-if="descuento > 0" class="mt-1 text-right text-sm text-petroleo-500">{{ plata(total) }} − descuento {{ plata(descuento) }}</p>

            <div class="mt-4 space-y-4">
                <div>
                    <div class="mb-1 flex items-center justify-between">
                        <span class="etiqueta mb-0">Cliente</span>
                        <button v-if="puedeCrearClientes" type="button" class="flex items-center gap-1 text-xs font-semibold text-petroleo-600 dark:text-petroleo-300" @click="nuevoCli = true"><UserPlus class="size-3.5" />Nuevo</button>
                    </div>
                    <select v-model="form.id_cliente" class="campo">
                        <option v-for="c in clientes" :key="c.id" :value="c.id">{{ c.id === 2 ? 'General (consumidor final)' : c.nombre }}</option>
                    </select>
                </div>

                <div v-if="canales.length > 1">
                    <span class="etiqueta">Canal</span>
                    <select v-model="form.canal_id" class="campo"><option v-for="c in canales" :key="c.id" :value="c.id">{{ c.nombre }}</option></select>
                </div>

                <div v-if="puede('descuentos')" class="grid grid-cols-2 gap-2">
                    <label><span class="etiqueta">Descuento %</span><input v-model="form.descuento_pct" inputmode="decimal" placeholder="0" class="campo" /></label>
                    <label><span class="etiqueta">Descuento $</span><input v-model="form.descuento_monto" inputmode="decimal" placeholder="0" class="campo" /></label>
                </div>

                <!-- como paga -->
                <div>
                    <span class="etiqueta">Cómo paga</span>
                    <div class="grid grid-cols-3 gap-1.5">
                        <button v-for="m in medios" :key="m.id" type="button"
                            class="flex flex-col items-center gap-1 rounded-xl border px-1 py-2 text-xs font-semibold transition-colors"
                            :class="modo === 'normal' && medio1 === m.id ? 'border-petroleo-800 bg-petroleo-800 text-white dark:border-petroleo-400 dark:bg-petroleo-600' : 'border-petroleo-200 hover:bg-petroleo-50 dark:border-petroleo-700 dark:hover:bg-petroleo-800'"
                            @click="elegirMedio(m.id)">
                            <component :is="iconos[m.id]" class="size-5" />{{ m.nombre }}
                        </button>
                        <button type="button" class="flex flex-col items-center gap-1 rounded-xl border px-1 py-2 text-xs font-semibold disabled:opacity-40"
                            :class="modo === 'fiado' ? 'border-sale bg-sale text-white' : 'border-petroleo-200 hover:bg-petroleo-50 dark:border-petroleo-700 dark:hover:bg-petroleo-800'"
                            :disabled="general" :title="general ? 'Elegí un cliente con nombre' : ''" @click="modo = 'fiado'">
                            <BookUser class="size-5" />Cuenta cte.
                        </button>
                        <button type="button" class="flex flex-col items-center gap-1 rounded-xl border px-1 py-2 text-xs font-semibold"
                            :class="modo === 'vale' ? 'border-entra bg-entra text-white' : 'border-petroleo-200 hover:bg-petroleo-50 dark:border-petroleo-700 dark:hover:bg-petroleo-800'" @click="modo = 'vale'">
                            <TicketPercent class="size-5" />Vale
                        </button>
                    </div>
                </div>

                <!-- pago normal -->
                <template v-if="modo === 'normal'">
                    <label class="flex items-center gap-2 text-sm"><input v-model="mixto" type="checkbox" class="size-4 accent-petroleo-700" />Paga con dos medios</label>
                    <div v-if="mixto" class="space-y-2 rounded-xl bg-papel p-3 dark:bg-petroleo-950">
                        <label class="block"><span class="etiqueta">Con {{ medios.find((m) => m.id === medio1).nombre.toLowerCase() }}</span>
                            <input v-model="form.monto_primero" inputmode="decimal" placeholder="0" class="campo" /></label>
                        <label class="block"><span class="etiqueta">Y el resto ({{ plata(segundo) }}) con</span>
                            <select v-model="medio2" class="campo"><option v-for="m in medios.filter((x) => x.id !== medio1)" :key="m.id" :value="m.id">{{ m.nombre }}</option></select></label>
                    </div>
                    <label class="block"><span class="etiqueta">{{ usaEfectivo ? 'Paga con' : 'Monto que paga' }}</span>
                        <input v-model="form.pago" inputmode="decimal" :placeholder="plata(aCobrar) + ' (justo)'" class="campo text-lg" /></label>
                </template>

                <p v-if="modo === 'fiado'" class="rounded-xl bg-red-50 p-3 text-sm text-sale dark:bg-red-950/40">Queda todo en la cuenta corriente del cliente: {{ plata(aPagar) }}.</p>

                <!-- vale -->
                <template v-if="modo === 'vale'">
                    <div v-if="!general">
                        <span class="etiqueta">Vale del cliente</span>
                        <select v-model="form.id_vale" class="campo">
                            <option :value="null">{{ vales.length ? 'Elegí el vale…' : 'No tiene vales con saldo' }}</option>
                            <option v-for="v in vales" :key="v.id" :value="v.id">{{ v.codigo_vale }} — {{ plata(v.monto_disponible) }}</option>
                        </select>
                    </div>
                    <label v-if="general || !form.id_vale" class="block"><span class="etiqueta">{{ general ? 'Código del vale' : 'O escaneá el código' }}</span>
                        <input v-model="codigoVale" class="campo" placeholder="VALE-20261020-1234" /></label>
                    <p v-if="codigoVale.trim().length >= 4 && !valeCodigo && !form.id_vale" class="-mt-2 text-xs text-sale">No se encontró un vale vigente con ese código.</p>
                    <div v-if="vale" class="rounded-xl bg-green-50 p-3 text-sm dark:bg-green-950/40">
                        Se usan <b>{{ plata(montoVale) }}</b> del vale {{ vale.codigo_vale }}.
                        <span v-if="Number(vale.monto_disponible) > montoVale"> Le quedan {{ plata(vale.monto_disponible - montoVale) }}.</span>
                    </div>
                    <template v-if="vale && restoVale > 0">
                        <label class="block"><span class="etiqueta">Faltan {{ plata(restoVale) }}, los paga con</span>
                            <select v-model="medioResto" class="campo"><option v-for="m in medios.filter((x) => ['efectivo', 'debito', 'transferencia', 'qr'].includes(x.id))" :key="m.id" :value="m.id">{{ m.nombre }}</option></select></label>
                        <label v-if="medioResto === 'efectivo'" class="block"><span class="etiqueta">Paga con</span><input v-model="form.pago" inputmode="decimal" :placeholder="plata(restoVale)" class="campo" /></label>
                    </template>
                </template>

                <div v-if="vuelto > 0 && usaEfectivo" class="flex items-center justify-between rounded-xl bg-cartel-300/40 px-4 py-3 dark:bg-cartel-700/30">
                    <span class="font-semibold">Su vuelto</span><span class="cifra text-3xl">{{ plata(vuelto) }}</span>
                </div>
                <div v-if="queda > 0 && !general" class="flex items-center justify-between rounded-xl bg-red-50 px-4 py-3 text-sale dark:bg-red-950/40">
                    <span class="font-semibold">Queda debiendo</span><span class="cifra text-2xl">{{ plata(queda) }}</span>
                </div>

                <p v-if="form.hasErrors" class="text-sm text-sale">{{ Object.values(form.errors)[0] }}</p>

                <Boton variante="principal" tam="lg" class="w-full text-lg" :disabled="!!aviso" :cargando="form.processing" @click="cobrar">
                    {{ aviso || 'Cobrar ' + plata(aPagar) }}
                </Boton>
            </div>
        </aside>

        <!-- barra fija en el celular -->
        <div class="fixed inset-x-0 bottom-[calc(4.25rem+env(safe-area-inset-bottom))] z-20 flex items-center gap-3 border-t border-petroleo-100 bg-white px-4 py-2.5 lg:hidden dark:border-petroleo-800 dark:bg-petroleo-900">
            <span class="cifra flex-1 text-3xl">{{ plata(aPagar) }}</span>
            <a href="#cobro" class="rounded-xl bg-cartel-500 px-5 py-2.5 font-bold text-petroleo-950">Cobrar</a>
        </div>
    </div>

    <!-- ===================================================== VENTA COBRADA -->
    <div v-else class="grid gap-5 lg:grid-cols-[1fr_22rem]">
        <section class="panel overflow-hidden">
            <table class="tabla">
                <thead><tr><th>Producto</th><th class="text-right">Cant.</th><th class="hidden text-right sm:table-cell">Precio</th><th class="text-right">Subtotal</th></tr></thead>
                <tbody>
                    <tr v-for="i in items" :key="i.tipo + i.id">
                        <td>{{ i.nombre }}</td>
                        <td class="text-right whitespace-nowrap">{{ cantidad(i.cantidad) }} {{ unidad(i.unidad, i.cantidad) }}</td>
                        <td class="hidden text-right sm:table-cell">{{ plata(i.precio) }}</td>
                        <td class="cifra-suave text-right">{{ plata(i.subtotal) }}</td>
                    </tr>
                </tbody>
            </table>
            <Vacio v-if="!items.length" :icono="ShoppingBasket" titulo="Sin productos" />
        </section>

        <aside class="space-y-4">
            <div class="panel p-5">
                <div class="flex items-end justify-between border-b border-dashed border-petroleo-200 pb-3 dark:border-petroleo-700">
                    <span class="text-sm text-petroleo-500">Total</span>
                    <span class="cifra text-5xl leading-none">{{ plata(venta.monto - venta.descuento) }}</span>
                </div>
                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex justify-between gap-3"><dt class="text-petroleo-500">Cliente</dt><dd class="text-right font-medium">{{ venta.cliente }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-petroleo-500">Canal</dt><dd>{{ venta.canal }}</dd></div>
                    <div v-if="venta.descuento > 0" class="flex justify-between gap-3"><dt class="text-petroleo-500">Descuento</dt><dd>{{ plata(venta.descuento) }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-petroleo-500">Pago</dt><dd class="text-right">{{ venta.detalle_pago || venta.forma_pago }}</dd></div>
                </dl>
                <div class="mt-3 flex flex-wrap gap-1.5">
                    <Insignia v-if="venta.fiado > 0" color="rojo">Debe {{ plata(venta.fiado) }}</Insignia>
                    <Insignia v-if="venta.factura" color="azul">Factura {{ venta.factura.nroCbte }}</Insignia>
                    <Insignia v-if="venta.nota_credito" color="rojo">Anulada con NC {{ venta.nota_credito.nroCbte }}</Insignia>
                </div>
            </div>

            <div class="panel space-y-2 p-4">
                <Boton class="w-full" :icono="Ticket" @click="abrirVentana(`/ventas/${venta.id}/ticket`)">Imprimir ticket</Boton>
                <template v-if="remitos">
                    <Boton class="w-full" :icono="ClipboardList" :href="`/remitos/nuevo?venta=${venta.id}`">{{ remitos.some((r) => !r.anulado) ? 'Otro remito (entrega parcial)' : 'Hacer remito' }}</Boton>
                    <p v-if="remitos.length" class="text-center text-xs text-petroleo-500">Remitos:
                        <template v-for="(r, k) in remitos" :key="r.id"><Link :href="`/remitos/${r.id}`" class="font-semibold underline" :class="{ 'line-through opacity-60': r.anulado }">{{ r.numero }}</Link><template v-if="k < remitos.length - 1">, </template></template>
                    </p>
                </template>
                <template v-if="!venta.factura">
                    <Boton v-if="afip.listo && venta.monto > 0" variante="oscuro" class="w-full" :icono="FileText" @click="factAbierto = true">Facturar</Boton>
                    <p v-else-if="!afip.listo" class="text-center text-xs text-petroleo-500">La facturación electrónica no está configurada.</p>
                </template>
                <template v-else>
                    <Boton class="w-full" :icono="FileText" @click="abrirVentana(`/afip/comprobante/${venta.factura.id}`)">Ver factura</Boton>
                    <Boton v-if="venta.nota_credito" class="w-full" :icono="FileX" @click="abrirVentana(`/afip/comprobante/${venta.nota_credito.id}`)">Ver nota de crédito</Boton>
                    <Boton v-else-if="afip.listo" variante="peligro" class="w-full" :icono="FileX" @click="notaCredito">Anular con nota de crédito</Boton>
                </template>
            </div>
        </aside>
        <FacturarModal v-model="factAbierto" :venta="venta" :monotributo="afip.monotributo" />
    </div>

    <!-- elegir entre varios productos -->
    <Modal :model-value="elegir.length > 0" titulo="¿Cuál de estos?" @update:model-value="(v) => !v && (elegir = [])">
        <ul class="-mx-2 divide-y divide-petroleo-50 dark:divide-petroleo-800">
            <li v-for="p in elegir" :key="(p.tipo || 'prod') + p.id">
                <button class="flex w-full items-center gap-3 rounded-lg px-2 py-3 text-left hover:bg-petroleo-50 dark:hover:bg-petroleo-800" @click="agregar(p)">
                    <span class="min-w-0 flex-1"><span class="block truncate font-medium">{{ p.nombre }}</span><span v-if="p.tipo === 'combo'" class="text-xs font-semibold text-cartel-700">Combo</span><span v-else class="text-xs text-petroleo-400">{{ p.codigo }} · quedan {{ cantidad(p.quedan) }}</span></span>
                    <span class="cifra-suave">{{ plata(p.monto) }}</span>
                </button>
            </li>
        </ul>
    </Modal>

    <Modal v-model="svcAbierto" titulo="Cobrar otro concepto" ancho="max-w-md">
        <form id="form-svc" class="space-y-4" @submit.prevent="agregarServicio">
            <Campo v-model="svc.descripcion" label="Concepto" placeholder="Fotocopias, carga SUBE, delivery…" maxlength="200" required />
            <Campo v-model="svc.monto" label="Importe" inputmode="decimal" prefijo="$" required />
        </form>
        <template #pie>
            <Boton @click="svcAbierto = false">Cancelar</Boton>
            <Boton tipo="submit" form="form-svc" variante="oscuro" :icono="Plus">Agregar a la venta</Boton>
        </template>
    </Modal>

    <Modal v-model="nuevoCli" titulo="Nuevo cliente" ancho="max-w-md">
        <form id="form-cli" @submit.prevent="crearCliente"><Campo v-model="nombreCli" label="Nombre" maxlength="50" required autofocus /></form>
        <template #pie>
            <Boton @click="nuevoCli = false">Cancelar</Boton>
            <Boton tipo="submit" form="form-cli" variante="oscuro" :icono="UserPlus">Crear y elegir</Boton>
        </template>
    </Modal>
</template>
