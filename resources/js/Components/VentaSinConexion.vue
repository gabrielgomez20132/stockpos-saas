<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { WifiOff, X, Search, Minus, Plus, Trash2, Check, Printer, CloudUpload, ListChecks } from 'lucide-vue-next';
import Boton from '@/Components/Boton.vue';
import { estado, catalogo, guardarVenta, ventas as pendientesDb, descartar, sincronizar } from '@/lib/sinConexion';
import { toast, toastError } from '@/lib/toast';
import { plata, cantidad, numero } from '@/lib/formato';
import { confirmar } from '@/lib/confirmar';
import { crearLectora } from '@/lib/lectora';
import { leerEtiqueta, codigosPlu, importe as importeTexto } from '@/lib/balanza';

const emit = defineEmits(['cerrar']);
const page = usePage();
const sid = computed(() => page.props.sucursal?.id ?? 1);
const puedeDescuento = computed(() => (page.props.auth?.permisos ?? []).includes('descuentos'));

const cat = ref(null);
const q = ref('');
const items = ref([]); // { id, nombre, codigo, monto, cantidad, fraccionable, unidad, stock }
const medio = ref('efectivo');
const recibido = ref('');
const descuento = ref('');
const ultima = ref(null);
const verPendientes = ref(false);
const pendientes = ref([]);
const buscador = ref(null);
const cobro = ref(null);
const irACobrar = () => cobro.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });

onMounted(async () => {
    cat.value = await catalogo(sid.value);
    nextTick(() => buscador.value?.focus());
});

const resultados = computed(() => {
    const t = q.value.trim().toLowerCase();
    if (!t || !cat.value) return [];
    const exacto = cat.value.productos.filter((p) => p.c.toLowerCase() === t);
    if (exacto.length) return exacto;
    return cat.value.productos.filter((p) => p.n.toLowerCase().includes(t) || p.c.toLowerCase().includes(t)).slice(0, 12);
});

// igual que en la venta normal: no se vende mas de lo que hay en stock
// (el stock guardado en el equipo se descuenta con cada venta sin conexion)
const fmtCant = (i, v) => `${cantidad(v)} ${i.unidad ?? ''}`.trim();
function sinStock(i, quiere) {
    toastError(i.stock > 0 ? `De ${i.nombre} solo quedan ${fmtCant(i, i.stock)} en stock.` : `${i.nombre} no tiene stock.`);
    return false;
}
// panaderia (la configuracion viaja con el catalogo guardado)
const pan = computed(() => cat.value?.panaderia ?? null);
const imp = ref(''); // venta por importe
function agregar(p, cant = 1, deEtiqueta = false) {
    if (!deEtiqueta && pan.value?.importe && String(imp.value).trim()) {
        const v = importeTexto(imp.value);
        if (!v || v <= 0) return toastError('El importe tiene que ser mayor a cero.');
        if (!p.f) return toastError(`${p.n} se vende por unidad: la venta por importe es para productos por peso (Kg).`);
        if (!(p.m > 0)) return toastError(`${p.n} no tiene precio.`);
        cant = Math.round((v / p.m) * 1000) / 1000;
        if (cant < 0.001) return toastError('Ese importe no llega a 1 gramo.');
        imp.value = '';
    }
    const ya = items.value.find((i) => i.id === p.id);
    const nueva = Math.round(((ya ? numero(ya.cantidad) : 0) + cant) * 1000) / 1000;
    if (nueva > p.q + 1e-9) {
        sinStock({ nombre: p.n, stock: p.q, unidad: p.u }, nueva);
        q.value = '';
        return;
    }
    if (ya) ya.cantidad = nueva;
    else items.value.unshift({ id: p.id, nombre: p.n, codigo: p.c, monto: p.m, cantidad: cant, fraccionable: p.f, unidad: p.u, stock: p.q });
    q.value = '';
    ultima.value = null;
    nextTick(() => buscador.value?.focus());
}
const lectora = crearLectora(() => enter());
function enter() {
    lectora.cancelar();
    const t = q.value.trim();
    if (pan.value?.balanza && cat.value && !cat.value.productos.some((p) => p.c === t)) {
        let e = null;
        try { e = leerEtiqueta(t, pan.value.etiqueta); } catch (err) { q.value = ''; return toastError(err.message); }
        if (e) {
            const codigos = codigosPlu(e.plu);
            const p = cat.value.productos.find((x) => codigos.includes(x.c));
            q.value = '';
            if (!p) return toastError(`La etiqueta es del producto con código ${e.plu.replace(/^0+/, '')}, pero no está en el catálogo guardado.`);
            const cant = e.tipo === 'precio' ? Math.round((e.valor / p.m) * 1000) / 1000 : e.valor;
            return agregar(p, cant, true);
        }
    }
    if (resultados.value.length === 1) agregar(resultados.value[0]);
    else if (resultados.value.length && resultados.value[0].c.toLowerCase() === q.value.trim().toLowerCase()) agregar(resultados.value[0]);
    else if (q.value.trim()) toastError('No encontré ese código en el catálogo guardado.');
}
function mover(i, d) {
    const paso = i.fraccionable ? 0.1 : 1;
    const v = Math.round((numero(i.cantidad) + d * paso) * 1000) / 1000;
    if (v <= 0) items.value.splice(items.value.indexOf(i), 1);
    else if (d > 0 && v > i.stock + 1e-9) sinStock(i, v);
    else i.cantidad = v;
}
const sub = (i) => Math.ceil(Math.round(i.monto * (numero(i.cantidad) || 0) * 1e4) / 1e4);
const total = computed(() => items.value.reduce((a, i) => a + sub(i), 0));
const desc = computed(() => (puedeDescuento.value ? Math.min(total.value, Math.max(0, numero(descuento.value) || 0)) : 0));
const aPagar = computed(() => Math.round((total.value - desc.value) * 100) / 100);
const vuelto = computed(() => (medio.value === 'efectivo' && numero(recibido.value) ? numero(recibido.value) - aPagar.value : null));
const medios = [['efectivo', 'Efectivo'], ['debito', 'Débito'], ['credito', 'Crédito'], ['qr', 'QR'], ['transferencia', 'Transferencia']];

const guardando = ref(false);
async function cobrar() {
    if (!items.value.length) return;
    if (vuelto.value !== null && vuelto.value < 0) return toastError('El efectivo recibido no alcanza.');
    const falta = items.value.find((i) => (numero(i.cantidad) || 0) > i.stock + 1e-9);
    if (falta) return sinStock(falta);
    const malos = items.value.filter((i) => !i.fraccionable && Math.abs(numero(i.cantidad) - Math.round(numero(i.cantidad))) > 1e-9);
    if (malos.length) return toastError(`${malos[0].nombre} se vende por unidad entera.`);
    guardando.value = true;
    try {
        const v = await guardarVenta({
            id_sucursal: sid.value, medio: medio.value, descuento: desc.value, total: aPagar.value,
            items: items.value.map((i) => ({ id: i.id, nombre: i.nombre, cantidad: numero(i.cantidad), monto: i.monto })),
            vendedor: page.props.auth?.user?.nombre,
        });
        ultima.value = { ...v, vuelto: vuelto.value };
        cat.value = await catalogo(sid.value);
        items.value = [];
        recibido.value = '';
        descuento.value = '';
        medio.value = 'efectivo';
        toast(`Venta guardada sin conexión (N° ${v.numero}). Se envía sola cuando vuelva la conexión.`);
    } catch (e) {
        toastError('No se pudo guardar la venta en este equipo: ' + (e?.message ?? e));
    } finally {
        guardando.value = false;
        nextTick(() => buscador.value?.focus());
    }
}

function imprimir(v) {
    const w = window.open('', 'ticket', 'width=380,height=600');
    if (!w) return;
    const filas = v.items.map((i) => `<tr><td>${cantidad(i.cantidad)} x ${esc(i.nombre)}</td><td style="text-align:right">${plata(Math.ceil(i.monto * i.cantidad))}</td></tr>`).join('');
    w.document.write(`<html><head><title>Ticket</title><style>body{font:13px monospace;margin:8px;width:72mm}table{width:100%;border-collapse:collapse}h1{font-size:15px;text-align:center;margin:0 0 4px}p{margin:2px 0;text-align:center}</style></head><body>
        <h1>${esc(page.props.app?.nombre ?? 'StokPOS')}</h1><p>${page.props.sucursal ? esc(page.props.sucursal.nombre) + '<br>' : ''}${fh(v.fecha)}</p><p>Venta sin conexión N° ${v.numero}</p><hr>
        <table>${filas}</table><hr>${v.descuento ? `<p style="text-align:right">Descuento ${plata(v.descuento)}</p>` : ''}<p style="text-align:right;font-size:16px"><b>TOTAL ${plata(v.total)}</b></p>
        <p style="text-align:right">${esc(medios.find((m) => m[0] === v.medio)?.[1] ?? v.medio)}</p><hr><p>No válido como factura</p><script>window.print();<\/script></body></html>`);
    w.document.close();
}
const fh = (d) => new Date(d).toLocaleString('es-AR', { day: '2-digit', month: '2-digit', year: '2-digit', hour: '2-digit', minute: '2-digit', hour12: false });
const esc = (s) => String(s ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

async function abrirPendientes() {
    pendientes.value = (await pendientesDb()).sort((a, b) => a.numero - b.numero);
    verPendientes.value = true;
}
async function quitarPendiente(v) {
    if (await confirmar({ titulo: 'Descartar venta', texto: `La venta sin conexión N° ${v.numero} por ${plata(v.total)} no se va a registrar. ¿Descartarla?`, boton: 'Descartar', peligro: true })) {
        await descartar(v.uuid);
        abrirPendientes();
    }
}
async function enviarAhora() {
    try {
        const r = await sincronizar();
        if (r?.registradas) toast(`Se registraron ${r.registradas} ventas hechas sin conexión.`);
        if (r?.caja) toastError(r.caja);
        r?.errores?.forEach((e) => toastError(e));
        abrirPendientes();
    } catch {
        toastError('Todavía no hay conexión con el sistema.');
    }
}
watch(() => estado.conectado, (c) => { if (c && !items.value.length && !verPendientes.value) toast('Volvió la conexión. Podés volver a la venta normal.'); });
</script>

<template>
    <div class="fixed inset-0 z-[60] flex flex-col bg-papel dark:bg-petroleo-950">
        <header class="flex items-center gap-2 border-b border-petroleo-100 bg-white px-3 py-2 pt-[max(0.5rem,env(safe-area-inset-top))] sm:gap-3 sm:px-4 sm:py-3 dark:border-petroleo-800 dark:bg-petroleo-900">
            <span class="flex shrink-0 items-center gap-1.5 rounded-full px-2.5 py-1 text-sm font-semibold" :class="estado.conectado ? 'bg-green-100 text-entra' : 'bg-red-100 text-sale'" :title="estado.conectado ? 'Conexión OK' : 'Sin conexión'">
                <WifiOff v-if="!estado.conectado" class="size-4" /><Check v-else class="size-4" /><span class="hidden sm:inline">{{ estado.conectado ? 'Conexión OK' : 'Sin conexión' }}</span>
            </span>
            <div class="min-w-0 flex-1">
                <p class="truncate leading-tight font-bold">Venta sin conexión<span v-if="page.props.sucursal" class="font-normal text-petroleo-500"> · {{ page.props.sucursal.nombre }}</span></p>
                <p class="truncate text-xs text-petroleo-500">{{ cat ? `${cat.productos.length} productos guardados · ${fh(cat.generado)}` : 'Sin catálogo guardado en este equipo' }}</p>
            </div>
            <Boton tam="sm" :icono="ListChecks" class="shrink-0" :aria-label="`Pendientes (${estado.pendientes})`" @click="abrirPendientes"><span class="hidden sm:inline">Pendientes</span> ({{ estado.pendientes }})</Boton>
            <Boton tam="sm" variante="fantasma" :icono="X" class="shrink-0" aria-label="Salir" @click="emit('cerrar')"><span class="hidden sm:inline">Salir</span></Boton>
        </header>

        <div v-if="!cat" class="grid flex-1 place-items-center p-6 text-center">
            <div class="max-w-md">
                <WifiOff class="mx-auto mb-3 size-10 text-petroleo-300" />
                <p class="font-semibold">Este equipo todavía no guardó el catálogo de productos.</p>
                <p class="mt-1 text-sm text-petroleo-500">El catálogo se guarda solo mientras hay conexión y la caja está abierta. Cuando vuelva la conexión, entrá una vez al sistema y después vas a poder vender sin conexión.</p>
            </div>
        </div>

        <div v-else class="grid min-h-0 flex-1 content-start gap-3 overflow-y-auto p-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] sm:gap-4 sm:p-4 lg:grid-cols-[1fr_22rem] lg:content-stretch">
            <section class="panel flex flex-col overflow-visible lg:min-h-0">
                <div class="relative border-b border-petroleo-100 p-3 dark:border-petroleo-800">
                    <div class="flex gap-2">
                        <input v-if="pan?.importe" v-model="imp" inputmode="decimal" class="campo w-28 shrink-0 text-center text-lg" placeholder="$ importe" aria-label="Vender por importe en pesos (opcional)" title="Opcional: escribí el importe (ej: 1500) y después el producto por peso" />
                        <div class="relative min-w-0 flex-1">
                            <Search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-petroleo-300" />
                            <input ref="buscador" v-model="q" class="campo pl-9 text-lg" placeholder="Escaneá o escribí el producto" @keydown.enter.prevent="enter" @input="lectora.entrada" />
                        </div>
                    </div>
                    <ul v-if="resultados.length" class="absolute inset-x-3 top-full z-20 mt-1 max-h-80 divide-y divide-petroleo-50 overflow-y-auto rounded-xl border border-petroleo-100 bg-white shadow-xl dark:divide-petroleo-800 dark:border-petroleo-700 dark:bg-petroleo-900">
                        <li v-for="p in resultados" :key="p.id">
                            <button type="button" class="flex w-full items-center gap-3 px-4 py-2.5 text-left hover:bg-petroleo-50 disabled:cursor-not-allowed disabled:opacity-50 dark:hover:bg-petroleo-800" :disabled="p.q <= 0" @click="agregar(p)">
                                <span class="min-w-0 flex-1"><span class="block truncate font-medium">{{ p.n }}</span><span class="text-xs" :class="p.q > 0 ? 'text-petroleo-400' : 'font-semibold text-sale'">{{ p.c }} · {{ p.q > 0 ? `stock ${cantidad(p.q)}` : 'sin stock' }}</span></span>
                                <span class="cifra-suave">{{ plata(p.m) }}</span>
                            </button>
                        </li>
                    </ul>
                </div>
                <ul v-if="items.length" class="divide-y divide-petroleo-50 dark:divide-petroleo-800 lg:flex-1 lg:overflow-y-auto">
                    <li v-for="i in items" :key="i.id" class="flex flex-wrap items-center gap-x-3 gap-y-2 px-4 py-2.5">
                        <div class="min-w-0 flex-1 basis-full sm:basis-40"><p class="truncate font-medium">{{ i.nombre }}</p>
                            <p class="text-xs" :class="numero(i.cantidad) > i.stock ? 'font-semibold text-sale' : 'text-petroleo-400'">{{ plata(i.monto) }} c/u · {{ numero(i.cantidad) > i.stock ? `solo quedan ${fmtCant(i, i.stock)}` : `stock ${fmtCant(i, i.stock)}` }}</p></div>
                        <div class="flex items-center rounded-xl border border-petroleo-200 dark:border-petroleo-700">
                            <button type="button" class="grid size-10 place-items-center" aria-label="Menos" @click="mover(i, -1)"><Minus class="size-4" /></button>
                            <input v-model="i.cantidad" inputmode="decimal" class="w-14 bg-transparent text-center font-semibold outline-none" />
                            <button type="button" class="grid size-10 place-items-center" aria-label="Más" @click="mover(i, 1)"><Plus class="size-4" /></button>
                        </div>
                        <span class="cifra-suave ml-auto w-24 text-right">{{ plata(sub(i)) }}</span>
                        <button type="button" class="p-1 text-petroleo-400 hover:text-sale" aria-label="Quitar" @click="items.splice(items.indexOf(i), 1)"><Trash2 class="size-4" /></button>
                    </li>
                </ul>
                <div v-else class="grid place-items-center p-6 text-center text-sm text-petroleo-500 sm:p-8 lg:flex-1">
                    <div v-if="ultima" class="space-y-3">
                        <p class="text-base font-semibold text-entra">Venta N° {{ ultima.numero }} guardada · {{ plata(ultima.total) }}</p>
                        <p v-if="ultima.vuelto" class="cifra text-3xl">Vuelto {{ plata(ultima.vuelto) }}</p>
                        <Boton :icono="Printer" @click="imprimir(ultima)">Imprimir ticket</Boton>
                    </div>
                    <p v-else>Escaneá el primer producto.<br>Las ventas se guardan en este equipo y se envían solas cuando vuelve la conexión.</p>
                </div>
            </section>

            <aside ref="cobro" class="panel h-fit scroll-mt-3 space-y-4 p-4 sm:p-5">
                <div class="flex items-end justify-between"><span class="text-sm text-petroleo-500">Total</span><span class="cifra text-5xl">{{ plata(aPagar) }}</span></div>
                <p v-if="desc" class="text-right text-sm text-petroleo-500">{{ plata(total) }} − descuento {{ plata(desc) }}</p>
                <div class="grid grid-cols-3 gap-2">
                    <button v-for="m in medios" :key="m[0]" type="button" class="rounded-xl border px-2 py-2.5 text-sm font-semibold"
                        :class="medio === m[0] ? 'border-petroleo-800 bg-petroleo-800 text-white dark:bg-petroleo-600' : 'border-petroleo-200 hover:bg-petroleo-50 dark:border-petroleo-700 dark:hover:bg-petroleo-800'" @click="medio = m[0]">{{ m[1] }}</button>
                </div>
                <label v-if="medio === 'efectivo'" class="block"><span class="etiqueta">Paga con</span><input v-model="recibido" inputmode="decimal" class="campo text-lg" placeholder="Opcional, para el vuelto" /></label>
                <p v-if="vuelto !== null" class="text-right text-lg" :class="vuelto < 0 ? 'text-sale' : 'font-semibold'">{{ vuelto < 0 ? `Faltan ${plata(-vuelto)}` : `Vuelto ${plata(vuelto)}` }}</p>
                <label v-if="puedeDescuento" class="block"><span class="etiqueta">Descuento $</span><input v-model="descuento" inputmode="decimal" class="campo" placeholder="0" /></label>
                <Boton variante="principal" tam="lg" class="w-full" :icono="Check" :disabled="!items.length" :cargando="guardando" @click="cobrar">Cobrar y guardar</Boton>
                <p class="text-xs text-petroleo-500">Sin conexión se vende a Consumidor final, con un medio de pago. Combos, fiado, vales y facturas: cuando vuelva la conexión.</p>
            </aside>
        </div>

        <div v-if="cat && items.length" class="flex items-center gap-3 border-t border-petroleo-100 bg-white px-4 py-2.5 pb-[max(0.625rem,env(safe-area-inset-bottom))] lg:hidden dark:border-petroleo-800 dark:bg-petroleo-900">
            <div class="min-w-0 flex-1"><p class="text-xs text-petroleo-500">{{ items.length }} {{ items.length === 1 ? 'producto' : 'productos' }}</p><p class="cifra text-2xl leading-none">{{ plata(aPagar) }}</p></div>
            <Boton variante="principal" :icono="Check" @click="irACobrar">Cobrar</Boton>
        </div>

        <div v-if="verPendientes" class="fixed inset-0 z-[70] grid place-items-center bg-petroleo-950/50 p-4" @click.self="verPendientes = false">
            <section class="panel max-h-[85dvh] w-full max-w-xl overflow-hidden">
                <div class="flex items-center justify-between gap-3 border-b border-petroleo-100 px-5 py-3.5 dark:border-petroleo-800">
                    <h2 class="font-semibold">Ventas sin enviar ({{ pendientes.length }})</h2>
                    <div class="flex gap-1"><Boton tam="sm" :icono="CloudUpload" :cargando="estado.enviando" :disabled="!pendientes.length" @click="enviarAhora">Enviar ahora</Boton>
                        <Boton tam="sm" variante="fantasma" :icono="X" aria-label="Cerrar" @click="verPendientes = false" /></div>
                </div>
                <ul class="max-h-[65dvh] divide-y divide-petroleo-50 overflow-y-auto dark:divide-petroleo-800">
                    <li v-for="v in pendientes" :key="v.uuid" class="flex items-center gap-3 px-5 py-3">
                        <div class="min-w-0 flex-1"><p class="font-medium">N° {{ v.numero }} · {{ plata(v.total) }} <span class="text-xs font-normal text-petroleo-500">{{ fh(v.fecha) }}</span></p>
                            <p class="truncate text-xs text-petroleo-500">{{ v.items.map((i) => `${cantidad(i.cantidad)} ${i.nombre}`).join(', ') }}</p>
                            <p v-if="v.mensaje" class="text-xs font-semibold text-sale">{{ v.mensaje }}</p></div>
                        <Boton tam="sm" variante="fantasma" :icono="Printer" aria-label="Imprimir" @click="imprimir(v)" />
                        <Boton v-if="v.estado === 'error'" tam="sm" variante="fantasma" :icono="Trash2" aria-label="Descartar" @click="quitarPendiente(v)" />
                    </li>
                    <li v-if="!pendientes.length" class="py-10 text-center text-sm text-petroleo-500">No hay ventas pendientes de enviar.</li>
                </ul>
            </section>
        </div>
    </div>
</template>
