<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    House, Receipt, Wallet, Undo2, Users, Truck, Package, Tags, ChartColumn, FileText, Settings2,
    UserCog, ShieldCheck, LogOut, Menu, X, ShoppingCart, Lock, Moon, Sun, Printer, Gift, SlidersHorizontal, FileSpreadsheet, ArrowLeftRight, Store, ChevronsUpDown, WifiOff, CloudUpload, Barcode, ClipboardList,
} from 'lucide-vue-next';
import Toasts from '@/Components/Toasts.vue';
import VentaSinConexion from '@/Components/VentaSinConexion.vue';
import { estado as offline, actualizarCatalogo, sincronizar, contar, probar } from '@/lib/sinConexion';
import ConfirmHost from '@/Components/ConfirmHost.vue';
import { toast, toastError } from '@/lib/toast';

const page = usePage();
const user = computed(() => page.props.auth?.user);
const permisos = computed(() => page.props.auth?.permisos ?? []);
const puede = (p) => permisos.value.includes(p);
const cajaAbierta = computed(() => page.props.caja?.abierta);
const menuAbierto = ref(false);
const modulos = computed(() => page.props.modulos ?? {});
const sucursal = computed(() => page.props.sucursal);
function cambiarSucursal(e) {
    const id = Number(e.target.value);
    if (id && id !== sucursal.value?.id) router.post('/sucursal', { id });
}

const secciones = computed(() => [
    { items: [
        { nombre: 'Inicio', url: '/inicio', icono: House },
        { nombre: 'Ventas', url: '/ventas', icono: Receipt },
        { nombre: 'Caja', url: '/caja', icono: Wallet, activo: '/caja' },
        puede('devoluciones') && { nombre: 'Devoluciones', url: '/devoluciones', icono: Undo2 },
        puede('presupuestos') && modulos.value.presupuestos && { nombre: 'Presupuestos', url: '/presupuestos', icono: FileText },
        puede('remitos') && modulos.value.remitos && { nombre: 'Remitos', url: '/remitos', icono: ClipboardList },
    ] },
    { titulo: 'Personas', items: [
        puede('clientes') && { nombre: 'Clientes', url: '/clientes', icono: Users },
        puede('proveedores') && { nombre: 'Proveedores', url: '/proveedores', icono: Truck },
        puede('encargados') && { nombre: 'Encargados', url: '/encargados', icono: UserCog },
    ] },
    { titulo: 'Mercadería', items: [
        puede('productos') && { nombre: 'Productos', url: '/productos', icono: Package },
        puede('combos') && modulos.value.combos && { nombre: 'Combos y promos', url: '/combos', icono: Gift },
        puede('listas-precios') && modulos.value.listas_precios && { nombre: 'Listas de precios', url: '/listas-precios', icono: FileSpreadsheet },
        puede('transferencias') && modulos.value.multisucursal && { nombre: 'Transferencias', url: '/transferencias', icono: ArrowLeftRight },
        puede('productos') && { nombre: 'Etiquetas', url: '/etiquetas', icono: Barcode },
        puede('categorias') && { nombre: 'Categorías', url: '/categorias', icono: Tags },
    ] },
    { titulo: 'Administración', items: [
        puede('informe') && { nombre: 'Reportes', url: '/reportes', icono: ChartColumn },
        puede('afip') && { nombre: 'Facturación', url: '/afip', icono: FileText, exacto: true },
        puede('afip') && { nombre: 'Configuración AFIP', url: '/afip/configuracion', icono: Settings2 },
        puede('roles-permisos') && { nombre: 'Roles y permisos', url: '/roles', icono: ShieldCheck },
        puede('configuracion') && { nombre: 'Configuración', url: '/configuracion', icono: SlidersHorizontal },
    ] },
].map((s) => ({ ...s, items: s.items.filter(Boolean) })).filter((s) => s.items.length));

function activo(item) {
    const url = page.url.split('?')[0];
    if (item.exacto) return url === item.url;
    return url === item.url || url.startsWith((item.activo ?? item.url) + '/');
}

const vendiendo = ref(false);
function vender() {
    if (!offline.conectado) return (ventaOffline.value = true);
    if (!cajaAbierta.value) return router.visit('/caja');
    router.post('/ventas', {}, { onStart: () => (vendiendo.value = true), onFinish: () => (vendiendo.value = false) });
}

const oscuro = ref(document.documentElement.classList.contains('dark'));
function tema() {
    oscuro.value = !oscuro.value;
    document.documentElement.classList.toggle('dark', oscuro.value);
    try { localStorage.setItem('tema', oscuro.value ? 'oscuro' : 'claro'); } catch (e) { /* sin storage */ }
}

// mensajes del servidor
const paraImprimir = ref(null);
watch(() => page.props.flash, (f) => {
    if (f?.ok) toast(f.ok);
    if (f?.error) toastError(f.error);
    if (f?.abrir) paraImprimir.value = f.abrir;
}, { immediate: true, deep: true });

// el navegador bloquea ventanas que no abre el usuario: se ofrece un boton
function imprimir() {
    window.open(paraImprimir.value, 'impresion', 'width=480,height=760');
    paraImprimir.value = null;
}

router.on('navigate', () => (menuAbierto.value = false));

// ---------------------------------------------------------- sin conexion
const ventaOffline = ref(false);
const sidActual = () => page.props.sucursal?.id ?? 1;
async function enviarPendientes(avisar = true) {
    if (!offline.pendientes) return;
    try {
        const r = await sincronizar();
        if (!r) return;
        if (r.registradas) {
            toast(`Volvió la conexión: se registraron ${r.registradas} ventas hechas sin conexión.`);
            if (page.url.startsWith('/ventas') || page.url.startsWith('/inicio') || page.url.startsWith('/caja')) router.reload();
        }
        r.avisos.slice(0, 5).forEach((a) => toast(a));
        r.errores.forEach((e) => toastError(e));
        if (r.caja && avisar) toastError(r.caja);
    } catch { /* sigue sin conexion */ }
}
async function guardarCatalogo(forzar = false) {
    if (!offline.conectado || !cajaAbierta.value) return;
    try { await actualizarCatalogo(sidActual(), { forzar }); } catch { /* reintenta despues */ }
}
async function revisar() {
    const antes = offline.conectado;
    if (!navigator.onLine) offline.conectado = false;
    else if (!antes || offline.pendientes) await probar();
    if (offline.conectado) {
        await enviarPendientes(!antes);
        guardarCatalogo();
    }
}
let reloj;
const alOffline = () => (offline.conectado = false);
const alOnline = () => revisar();
onMounted(async () => {
    await contar();
    window.addEventListener('offline', alOffline);
    window.addEventListener('online', alOnline);
    reloj = setInterval(revisar, 30000);
    if (navigator.onLine) await probar(); else offline.conectado = false;
    if (offline.conectado) { await enviarPendientes(); guardarCatalogo(); }
});
onBeforeUnmount(() => { clearInterval(reloj); window.removeEventListener('offline', alOffline); window.removeEventListener('online', alOnline); });
// la navegacion falla por falta de red: se pasa a modo sin conexion
router.on('exception', () => probar());
router.on('networkError', () => probar());
watch(() => [page.props.sucursal?.id, cajaAbierta.value], () => guardarCatalogo(true));
function salir() {
    try { navigator.serviceWorker?.controller?.postMessage('limpiar'); } catch { /* sin service worker */ }
}
</script>

<template>
    <main v-if="!user" class="mx-auto max-w-3xl px-4 py-10"><slot /></main>
    <div v-else class="min-h-dvh lg:pl-64 print:pl-0">
        <!-- barra lateral (escritorio) / cajon (celular) -->
        <Transition enter-from-class="opacity-0" leave-to-class="opacity-0" enter-active-class="transition" leave-active-class="transition">
            <div v-if="menuAbierto" class="fixed inset-0 z-40 bg-petroleo-950/50 lg:hidden" @click="menuAbierto = false" />
        </Transition>
        <aside class="print:hidden fixed inset-y-0 left-0 z-50 flex w-72 flex-col bg-petroleo-800 text-petroleo-100 transition-transform lg:w-64 lg:translate-x-0 dark:bg-petroleo-900"
            :class="menuAbierto ? 'translate-x-0' : '-translate-x-full'">
            <div class="flex items-center justify-between px-5 pt-5 pb-4">
                <Link href="/inicio" class="flex items-center gap-2.5">
                    <img v-if="page.props.app?.logo" :src="page.props.app?.logo" alt="" class="h-9 max-w-28 rounded object-contain" />
                    <img v-else src="/icono.svg" alt="" class="size-9" />
                    <span class="text-lg font-bold text-white" style="font-variation-settings: 'wdth' 85">{{ page.props.app?.nombre ?? 'StokPOS' }}</span>
                </Link>
                <button class="rounded-lg p-1.5 hover:bg-petroleo-700 lg:hidden" aria-label="Cerrar menú" @click="menuAbierto = false"><X class="size-5" /></button>
            </div>

            <div v-if="sucursal" class="px-4 pb-3">
                <label class="relative flex items-center gap-2 rounded-xl bg-petroleo-950/30 px-3 py-2 text-sm" :class="{ 'hover:bg-petroleo-950/45': sucursal.puedeCambiar }">
                    <Store class="size-4 shrink-0 text-cartel-400" />
                    <span class="min-w-0 flex-1"><span class="block text-[0.7rem] text-petroleo-300">Sucursal</span><span class="block truncate font-semibold text-white">{{ sucursal.nombre }}</span></span>
                    <template v-if="sucursal.puedeCambiar && sucursal.lista.length > 1">
                        <ChevronsUpDown class="size-4 text-petroleo-300" />
                        <select class="absolute inset-0 cursor-pointer opacity-0" :value="sucursal.id" aria-label="Cambiar de sucursal" @change="cambiarSucursal">
                            <option v-for="s in sucursal.lista" :key="s.id" :value="s.id">{{ s.nombre }}</option>
                        </select>
                    </template>
                </label>
            </div>

            <div class="px-4 pb-3">
                <button type="button" :disabled="vendiendo" class="flex w-full items-center justify-center gap-2 rounded-xl py-3 text-base font-bold transition-colors"
                    :class="cajaAbierta ? 'bg-cartel-500 text-petroleo-950 hover:bg-cartel-400' : 'bg-petroleo-700 text-petroleo-200 hover:bg-petroleo-600'"
                    @click="vender">
                    <component :is="cajaAbierta ? ShoppingCart : Lock" class="size-5" />
                    {{ cajaAbierta ? 'Vender' : 'Caja cerrada' }}
                </button>
            </div>

            <nav class="scroll-menu flex-1 overflow-y-auto px-3 pb-4">
                <div v-for="(s, i) in secciones" :key="i" class="mt-3 first:mt-0">
                    <p v-if="s.titulo" class="px-3 pb-1 text-xs font-medium text-petroleo-300">{{ s.titulo }}</p>
                    <Link v-for="it in s.items" :key="it.url" :href="it.url"
                        class="flex items-center gap-3 rounded-lg px-3 py-2 text-[0.95rem] transition-colors"
                        :class="activo(it) ? 'bg-petroleo-950/40 font-semibold text-white' : 'text-petroleo-100 hover:bg-petroleo-700/60'">
                        <component :is="it.icono" class="size-[1.15rem] shrink-0" :class="activo(it) ? 'text-cartel-400' : 'text-petroleo-300'" />
                        {{ it.nombre }}
                    </Link>
                </div>
            </nav>

            <div class="border-t border-petroleo-700 p-3">
                <Link href="/perfil" class="flex items-center gap-3 rounded-lg px-3 py-2 hover:bg-petroleo-700/60">
                    <span class="grid size-8 place-items-center rounded-full bg-petroleo-600 text-sm font-bold text-white">{{ user?.nombre?.charAt(0) }}</span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-semibold text-white">{{ user?.nombre }}</span>
                        <span class="block truncate text-xs text-petroleo-300">{{ user?.roles?.join(', ') || 'Sin rol' }}</span>
                    </span>
                </Link>
                <div class="mt-1 flex gap-1">
                    <button class="flex flex-1 items-center justify-center gap-2 rounded-lg px-3 py-2 text-sm text-petroleo-200 hover:bg-petroleo-700/60" @click="tema">
                        <component :is="oscuro ? Sun : Moon" class="size-4" /> {{ oscuro ? 'Claro' : 'Oscuro' }}
                    </button>
                    <Link href="/logout" method="post" as="button" @click="salir" class="flex flex-1 items-center justify-center gap-2 rounded-lg px-3 py-2 text-sm text-petroleo-200 hover:bg-petroleo-700/60">
                        <LogOut class="size-4" /> Salir
                    </Link>
                </div>
            </div>
        </aside>

        <!-- barra superior (celular / tablet) -->
        <header class="print:hidden sticky top-0 z-30 flex items-center gap-3 border-b border-petroleo-100 bg-white/95 px-4 py-2.5 backdrop-blur lg:hidden dark:border-petroleo-800 dark:bg-petroleo-900/95">
            <button class="-ml-1 rounded-lg p-2 hover:bg-petroleo-50 dark:hover:bg-petroleo-800" aria-label="Abrir menú" @click="menuAbierto = true"><Menu class="size-6" /></button>
            <span class="min-w-0 flex-1 truncate font-bold" style="font-variation-settings: 'wdth' 85">{{ page.props.app?.nombre ?? 'StokPOS' }}<span v-if="sucursal" class="ml-1.5 text-xs font-medium text-petroleo-500">· {{ sucursal.nombre }}</span></span>
            <span class="flex items-center gap-1.5 text-xs font-medium" :class="cajaAbierta ? 'text-entra' : 'text-petroleo-400'">
                <span class="size-2 rounded-full" :class="cajaAbierta ? 'bg-entra' : 'bg-petroleo-300'" />
                {{ cajaAbierta ? 'Caja abierta' : 'Caja cerrada' }}
            </span>
        </header>

        <main class="mx-auto w-full max-w-7xl px-4 pt-5 pb-28 sm:px-6 lg:px-8 lg:pt-8 lg:pb-10">
            <div v-if="!offline.conectado" class="print:hidden mb-4 flex flex-wrap items-center gap-3 rounded-xl border border-sale/40 bg-red-50 px-4 py-3 text-sm dark:bg-red-950/40">
                <WifiOff class="size-5 shrink-0 text-sale" />
                <span class="min-w-0 flex-1"><b class="text-sale">Sin conexión con el sistema.</b> Podés seguir vendiendo: las ventas se guardan en este equipo y se envían solas cuando vuelva la conexión.</span>
                <button type="button" class="rounded-lg bg-sale px-3 py-2 font-semibold text-white" @click="ventaOffline = true">Vender sin conexión</button>
            </div>
            <div v-else-if="offline.pendientes" class="print:hidden mb-4 flex flex-wrap items-center gap-3 rounded-xl border border-cartel-500 bg-cartel-300/25 px-4 py-3 text-sm">
                <CloudUpload class="size-5 shrink-0 text-cartel-700" />
                <span class="min-w-0 flex-1">{{ offline.pendientes }} {{ offline.pendientes === 1 ? 'venta hecha' : 'ventas hechas' }} sin conexión {{ offline.enviando ? 'enviándose…' : cajaAbierta ? 'esperando para enviarse.' : 'esperando: abrí la caja para registrarlas.' }}</span>
                <button type="button" class="rounded-lg bg-petroleo-800 px-3 py-2 font-semibold text-white disabled:opacity-50" :disabled="offline.enviando" @click="enviarPendientes()">Enviar ahora</button>
                <button type="button" class="rounded-lg px-3 py-2 font-semibold underline" @click="ventaOffline = true">Ver</button>
            </div>
            <slot />
        </main>

        <!-- barra inferior (celular) -->
        <nav class="print:hidden fixed inset-x-0 bottom-0 z-30 grid grid-cols-5 border-t border-petroleo-100 bg-white pb-[env(safe-area-inset-bottom)] lg:hidden dark:border-petroleo-800 dark:bg-petroleo-900">
            <Link v-for="it in [{ n: 'Inicio', u: '/inicio', i: House }, { n: 'Ventas', u: '/ventas', i: Receipt }]" :key="it.u" :href="it.u"
                class="flex flex-col items-center gap-0.5 py-2 text-[0.7rem]" :class="activo({ url: it.u }) ? 'text-petroleo-800 font-semibold dark:text-white' : 'text-petroleo-400'">
                <component :is="it.i" class="size-6" />{{ it.n }}
            </Link>
            <div class="relative flex justify-center">
                <button type="button" aria-label="Vender" :disabled="vendiendo"
                    class="absolute -top-5 grid size-16 place-items-center rounded-2xl shadow-lg ring-4 ring-papel dark:ring-petroleo-950"
                    :class="cajaAbierta ? 'bg-cartel-500 text-petroleo-950' : 'bg-petroleo-700 text-petroleo-200'" @click="vender">
                    <component :is="cajaAbierta ? ShoppingCart : Lock" class="size-7" />
                </button>
                <span class="mt-auto pb-2 text-[0.7rem] font-semibold">{{ cajaAbierta ? 'Vender' : 'Abrir caja' }}</span>
            </div>
            <Link href="/caja" class="flex flex-col items-center gap-0.5 py-2 text-[0.7rem]" :class="activo({ url: '/caja' }) ? 'text-petroleo-800 font-semibold dark:text-white' : 'text-petroleo-400'">
                <Wallet class="size-6" />Caja
            </Link>
            <button class="flex flex-col items-center gap-0.5 py-2 text-[0.7rem] text-petroleo-400" @click="menuAbierto = true"><Menu class="size-6" />Más</button>
        </nav>

        <div v-if="paraImprimir" class="fixed inset-x-4 bottom-24 z-[55] mx-auto flex max-w-sm items-center gap-3 rounded-2xl bg-cartel-500 p-3 pl-4 text-petroleo-950 shadow-xl lg:right-6 lg:bottom-24 lg:left-auto">
            <Printer class="size-5 shrink-0" />
            <span class="flex-1 text-sm font-semibold">Comprobante listo</span>
            <button class="rounded-lg bg-petroleo-900 px-3 py-2 text-sm font-semibold text-white" @click="imprimir">Imprimir</button>
            <button class="p-1" aria-label="Cerrar" @click="paraImprimir = null"><X class="size-4" /></button>
        </div>
        <VentaSinConexion v-if="ventaOffline" @cerrar="ventaOffline = false" />
        <Toasts />
        <ConfirmHost />
    </div>
</template>
