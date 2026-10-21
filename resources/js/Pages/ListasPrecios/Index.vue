<script setup>
import { ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { UploadCloud, Download, FileSpreadsheet, ArrowRight } from 'lucide-vue-next';
import Encabezado from '@/Components/Encabezado.vue';
import Boton from '@/Components/Boton.vue';
import Insignia from '@/Components/Insignia.vue';
import Paginacion from '@/Components/Paginacion.vue';
import { fecha } from '@/lib/formato';

const props = defineProps({ proveedores: Array, listas: Object, proveedor: Number });

const form = useForm({ id_proveedor: null, archivo: null });
const arrastrando = ref(false);
function elegir(f) { if (f) form.archivo = f; }
function soltar(e) { arrastrando.value = false; elegir(e.dataTransfer.files[0]); }
const enviar = () => form.post('/listas-precios', { forceFormData: true });

const filtro = ref(props.proveedor);
const filtrar = () => router.get('/listas-precios', filtro.value ? { proveedor: filtro.value } : {}, { preserveState: true, replace: true });

const estado = (l) => ({ aplicada: ['verde', 'Aplicada'], pendiente: ['amarillo', 'Para revisar'], descartada: ['gris', 'Descartada'] }[l.estado] ?? ['gris', l.estado]);
const variacion = (v) => (v === null || v === undefined ? '—' : `${v > 0 ? '+' : ''}${String(v).replace('.', ',')}%`);
function hace(f) {
    if (!f) return null;
    const dias = Math.floor((Date.now() - new Date(String(f).replace(' ', 'T'))) / 86400000);
    return dias <= 0 ? 'hoy' : dias === 1 ? 'ayer' : `hace ${dias} días`;
}
</script>

<template>
    <Encabezado titulo="Listas de precios de proveedores" subtitulo="Subí el Excel o CSV que te manda el proveedor. El sistema calcula los costos nuevos, te muestra qué cambia y vos decidís qué aplicar." />

    <div class="grid gap-5 lg:grid-cols-[minmax(0,26rem)_1fr]">
        <form class="panel h-fit space-y-4 p-5" @submit.prevent="enviar">
            <h2 class="font-semibold">Nueva actualización</h2>
            <div>
                <label class="etiqueta" for="prov">Proveedor</label>
                <select id="prov" v-model="form.id_proveedor" class="campo" :class="{ 'border-sale': form.errors.id_proveedor }" required>
                    <option :value="null" disabled>Elegí el proveedor…</option>
                    <option v-for="p in proveedores" :key="p.id" :value="p.id">{{ p.nombre }}</option>
                </select>
                <p v-if="form.errors.id_proveedor" class="mt-1 text-sm text-sale">{{ form.errors.id_proveedor }}</p>
                <p v-if="!proveedores.length" class="mt-1 text-sm text-petroleo-500">No hay proveedores. <Link href="/proveedores/create" class="font-semibold underline">Cargá uno</Link>.</p>
            </div>

            <label class="flex cursor-pointer flex-col items-center gap-2 rounded-2xl border-2 border-dashed px-4 py-8 text-center transition-colors"
                :class="arrastrando ? 'border-petroleo-500 bg-petroleo-50 dark:bg-petroleo-800' : 'border-petroleo-200 hover:bg-petroleo-50/60 dark:border-petroleo-700 dark:hover:bg-petroleo-800/40'"
                @dragover.prevent="arrastrando = true" @dragleave.prevent="arrastrando = false" @drop.prevent="soltar">
                <template v-if="!form.archivo">
                    <UploadCloud class="size-9 text-petroleo-400" stroke-width="1.5" />
                    <span class="font-medium">Arrastrá el archivo o hacé clic para elegirlo</span>
                    <span class="text-xs text-petroleo-500">Excel (.xlsx), LibreOffice (.ods) o CSV · hasta 30 MB</span>
                </template>
                <template v-else>
                    <FileSpreadsheet class="size-9 text-entra" stroke-width="1.5" />
                    <span class="max-w-full truncate font-medium">{{ form.archivo.name }}</span>
                    <span class="text-xs text-petroleo-500">{{ (form.archivo.size / 1024).toFixed(0) }} KB · hacé clic para cambiarlo</span>
                </template>
                <input type="file" accept=".xlsx,.ods,.csv,.txt" class="sr-only" @change="elegir($event.target.files[0])" />
            </label>
            <p v-if="form.errors.archivo" class="text-sm text-sale">{{ form.errors.archivo }}</p>

            <Boton tipo="submit" variante="principal" tam="lg" class="w-full" :icono="ArrowRight" :disabled="!form.archivo || !form.id_proveedor" :cargando="form.processing">Continuar a columnas</Boton>
            <a href="/listas-precios/ejemplo" class="flex items-center gap-1.5 text-sm text-petroleo-600 hover:underline dark:text-petroleo-300"><Download class="size-4" />Descargar una lista de ejemplo para probar</a>
        </form>

        <section class="panel overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-petroleo-100 px-5 py-3.5 dark:border-petroleo-800">
                <h2 class="font-semibold">Importaciones anteriores</h2>
                <select v-model="filtro" class="campo w-auto min-w-48" @change="filtrar">
                    <option :value="null">Todos los proveedores</option>
                    <option v-for="p in proveedores" :key="p.id" :value="p.id">{{ p.nombre }}</option>
                </select>
            </div>
            <div class="overflow-x-auto">
                <table class="tabla">
                    <thead><tr><th>Fecha</th><th>Proveedor</th><th>Archivo</th><th class="text-right">Vinculados</th><th class="text-right">Var. prom.</th><th>Estado</th></tr></thead>
                    <tbody>
                        <tr v-for="l in listas.data" :key="l.id" class="cursor-pointer" @click="router.visit(`/listas-precios/${l.id}`)">
                            <td class="whitespace-nowrap text-petroleo-500">{{ fecha(l.created_at, true) }}</td>
                            <td class="font-medium">{{ l.proveedor?.nombre }}</td>
                            <td class="max-w-48 truncate text-petroleo-500">{{ l.archivo }}</td>
                            <td class="text-right whitespace-nowrap">{{ l.vinculados }} / {{ l.filas }}</td>
                            <td class="text-right" :class="l.variacion_promedio > 0 ? 'text-sale' : l.variacion_promedio < 0 ? 'text-entra' : ''">{{ variacion(l.variacion_promedio) }}</td>
                            <td><Insignia :color="estado(l)[0]">{{ estado(l)[1] }}</Insignia></td>
                        </tr>
                        <tr v-if="!listas.data.length"><td colspan="6" class="py-10 text-center text-petroleo-500">Todavía no subiste ninguna lista.</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="px-4"><Paginacion :datos="listas" /></div>
        </section>
    </div>

    <section v-if="proveedores.length" class="panel mt-5 overflow-hidden">
        <h2 class="border-b border-petroleo-100 px-5 py-3.5 font-semibold dark:border-petroleo-800">Última actualización por proveedor</h2>
        <div class="grid sm:grid-cols-2 lg:grid-cols-4">
            <div v-for="p in proveedores" :key="p.id" class="border-b border-petroleo-50 px-5 py-4 sm:border-r dark:border-petroleo-800">
                <p class="truncate font-medium">{{ p.nombre }}</p>
                <p v-if="p.ultima" class="text-sm text-entra">Aplicada {{ hace(p.ultima) }} · {{ fecha(p.ultima) }}</p>
                <p v-else class="text-sm text-sale">Nunca se aplicó una lista</p>
            </div>
        </div>
    </section>
</template>
