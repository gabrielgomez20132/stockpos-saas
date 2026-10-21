<script setup>
import { router } from '@inertiajs/vue3';
import { FileText, CheckCheck, Settings2, TriangleAlert } from 'lucide-vue-next';
import Encabezado from '@/Components/Encabezado.vue';
import Boton from '@/Components/Boton.vue';
import Dato from '@/Components/Dato.vue';
import FiltroFechas from '@/Components/FiltroFechas.vue';
import Insignia from '@/Components/Insignia.vue';
import Vacio from '@/Components/Vacio.vue';
import { plata, fecha, dia } from '@/lib/formato';
import { confirmar } from '@/lib/confirmar';

const props = defineProps({ registros: Array, totales: Object, filtro: Object, config: Object });
const ver = (id) => window.open(`/afip/comprobante/${id}`, 'impresion', 'width=480,height=760');
async function rendir() {
    if (await confirmar({ titulo: 'Marcar como rendidos', texto: `Los ${props.totales.comprobantes} comprobantes pendientes pasan a "rendidos" (por ejemplo, después de pasárselos al contador).`, boton: 'Marcar como rendidos' })) router.post('/afip/marcar-pagadas');
}
</script>

<template>
    <Encabezado titulo="Facturación electrónica" :subtitulo="filtro ? `Del ${dia(filtro.desde)} al ${dia(filtro.hasta)}` : 'Comprobantes pendientes de rendir.'">
        <Boton href="/afip/configuracion" :icono="Settings2">Configuración</Boton>
        <Boton v-if="!filtro && registros.length" variante="oscuro" :icono="CheckCheck" @click="rendir">Marcar como rendidos</Boton>
    </Encabezado>

    <div v-if="!config.listo" class="mb-5 flex flex-wrap items-center gap-3 rounded-xl border border-cartel-500 bg-cartel-300/20 px-4 py-3 text-sm">
        <TriangleAlert class="size-5 text-cartel-700" />
        <span class="flex-1">{{ config.soap ? 'La facturación todavía no está lista: faltan datos fiscales o credenciales.' : 'Falta activar la extensión SOAP de PHP (en Laragon: Menú › PHP › Extensions › soap).' }}</span>
        <Boton href="/afip/configuracion" tam="sm" variante="oscuro">Configurar</Boton>
    </div>
    <p v-else class="mb-4 text-sm">Ambiente: <Insignia :color="config.ambiente === 'produccion' ? 'verde' : 'amarillo'">{{ config.ambiente === 'produccion' ? 'Producción (facturas reales)' : 'Homologación (pruebas)' }}</Insignia></p>

    <div class="mb-5"><FiltroFechas :filtro="filtro" url="/afip" texto-limpiar="Ver pendientes" /></div>

    <div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <Dato titulo="Total facturado" :valor="plata(totales.impTotal)" :nota="totales.comprobantes + ' comprobantes'" />
        <Dato titulo="Neto" :valor="plata(totales.netoTotal)" />
        <Dato titulo="IVA" :valor="plata(totales.ivaTotal)" />
    </div>

    <section class="panel overflow-hidden">
        <table v-if="registros.length" class="tabla">
            <thead><tr><th>Comprobante</th><th class="hidden sm:table-cell">Fecha</th><th class="hidden md:table-cell">Cliente</th><th class="hidden lg:table-cell">CAE</th><th class="text-right">Total</th></tr></thead>
            <tbody>
                <tr v-for="r in registros" :key="r.id" class="cursor-pointer" @click="ver(r.id)">
                    <td><span class="font-medium">{{ r.ndc ? 'NC' : 'Factura' }} {{ r.letra }}</span> {{ r.nroCbte }} <Insignia v-if="r.pagada" class="ml-1">Rendido</Insignia></td>
                    <td class="hidden sm:table-cell">{{ fecha(r.cbteFch, true) }}</td>
                    <td class="hidden md:table-cell">{{ r.docTipo === 80 ? r.nombreRS + ' (' + r.docNro + ')' : 'Consumidor final' }}</td>
                    <td class="hidden text-petroleo-500 lg:table-cell">{{ r.caeNum }}</td>
                    <td class="cifra-suave text-right" :class="{ 'text-sale': r.ndc }">{{ r.ndc ? '− ' : '' }}{{ plata(r.impTotal, { centavos: true }) }}</td>
                </tr>
            </tbody>
        </table>
        <Vacio v-else :icono="FileText" titulo="No hay comprobantes" :texto="filtro ? 'Probá con otras fechas.' : 'Las facturas se emiten desde cada venta cobrada.'" />
    </section>
</template>
