<script setup>
import { ref } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
const page = usePage();
import { ShieldCheck, ShieldAlert, Upload, KeyRound, PlugZap, ImagePlus } from 'lucide-vue-next';
import Encabezado from '@/Components/Encabezado.vue';
import Boton from '@/Components/Boton.vue';
import Campo from '@/Components/Campo.vue';
import Insignia from '@/Components/Insignia.vue';
import { dia } from '@/lib/formato';

const props = defineProps({ cfg: Object, estados: Object, soap: Boolean, listo: Boolean, logo: String });
const datos = useForm({ ...props.cfg });
const guardar = () => datos.put('/afip/configuracion', { preserveScroll: true });

const amb = ref(props.cfg.ambiente || 'homologacion');
const cred = useForm({ certificado: null, clave: null, passphrase: '' });
const subir = () => cred.post(`/afip/configuracion/${amb.value}/credenciales`, { forceFormData: true, preserveScroll: true, onSuccess: () => cred.reset() });
const reemplazar = ref(false);
const csrForm = ref(null);
const probando = ref(false);
const probar = () => router.post('/afip/configuracion/probar', {}, { preserveScroll: true, onStart: () => (probando.value = true), onFinish: () => (probando.value = false) });

const logoForm = useForm({ logo: null, quitar: false });
const subirLogo = (e) => { logoForm.logo = e.target.files[0]; logoForm.post('/afip/configuracion/logo', { forceFormData: true, preserveScroll: true }); };
const quitarLogo = () => { logoForm.quitar = true; logoForm.post('/afip/configuracion/logo', { preserveScroll: true, onFinish: () => (logoForm.quitar = false) }); };
const nombres = { homologacion: 'Homologación (pruebas)', produccion: 'Producción (real)' };
</script>

<template>
    <Encabezado titulo="Configuración AFIP" subtitulo="Datos del negocio que salen en tickets y facturas, y credenciales para facturar.">
        <Boton :icono="PlugZap" :cargando="probando" @click="probar">Probar conexión</Boton>
    </Encabezado>

    <div v-if="!soap" class="mb-5 rounded-xl border border-cartel-500 bg-cartel-300/20 px-4 py-3 text-sm">
        Para facturar hace falta la extensión <b>SOAP</b> de PHP. En Laragon: clic derecho › PHP › Extensions › <b>soap</b>, y reiniciá Apache.
    </div>

    <div class="grid gap-5 lg:grid-cols-2">
        <form class="panel space-y-4 p-5" @submit.prevent="guardar">
            <h2 class="font-semibold">Datos fiscales</h2>
            <Campo v-model="datos.razon_social" label="Razón social / nombre del negocio" maxlength="120" required :error="datos.errors.razon_social" />
            <div class="grid gap-4 sm:grid-cols-2">
                <Campo v-model="datos.cuit" label="CUIT" inputmode="numeric" placeholder="20-12345678-9" required :error="datos.errors.cuit" />
                <Campo v-model="datos.punto_venta" label="Punto de venta" tipo="number" min="1" required ayuda="El de factura electrónica (web service)" :error="datos.errors.punto_venta" />
                <Campo v-model="datos.condicion_iva" label="Condición frente al IVA" list="condiciones" placeholder="Monotributo / Responsable inscripto" :error="datos.errors.condicion_iva" />
                <Campo v-model="datos.iibb" label="Ingresos brutos" maxlength="40" :error="datos.errors.iibb" />
                <Campo v-model="datos.domicilio" label="Domicilio" maxlength="150" :error="datos.errors.domicilio" />
                <Campo v-model="datos.localidad" label="Localidad" maxlength="100" :error="datos.errors.localidad" />
                <Campo v-model="datos.inicio_actividades" label="Inicio de actividades" tipo="date" :error="datos.errors.inicio_actividades" />
            </div>
            <datalist id="condiciones"><option>Monotributo</option><option>Responsable inscripto</option><option>Exento</option></datalist>
            <fieldset>
                <legend class="etiqueta">Ambiente</legend>
                <div class="grid grid-cols-2 gap-2">
                    <label v-for="(n, a) in nombres" :key="a" class="flex cursor-pointer items-center gap-2 rounded-xl border border-petroleo-200 p-3 text-sm has-[:checked]:border-petroleo-700 has-[:checked]:bg-petroleo-50 dark:border-petroleo-700 dark:has-[:checked]:bg-petroleo-800">
                        <input v-model="datos.ambiente" type="radio" :value="a" class="accent-petroleo-700" />{{ n }}
                    </label>
                </div>
            </fieldset>
            <Boton tipo="submit" variante="oscuro" :cargando="datos.processing">Guardar datos</Boton>
        </form>

        <div class="space-y-5">
            <section class="panel p-5">
                <h2 class="mb-3 font-semibold">Credenciales</h2>
                <div class="mb-4 flex rounded-xl bg-petroleo-100/70 p-1 dark:bg-petroleo-950">
                    <button v-for="(n, a) in nombres" :key="a" type="button" class="flex-1 rounded-lg px-3 py-2 text-sm" :class="amb === a ? 'bg-white font-semibold shadow-sm dark:bg-petroleo-700' : ''" @click="amb = a">{{ n }}</button>
                </div>
                <div class="mb-4 rounded-xl p-3 text-sm" :class="estados[amb].ok ? 'bg-green-50 dark:bg-green-950/40' : 'bg-papel dark:bg-petroleo-950'">
                    <p class="flex items-center gap-2 font-semibold">
                        <component :is="estados[amb].ok ? ShieldCheck : ShieldAlert" class="size-5" :class="estados[amb].ok ? 'text-entra' : 'text-cartel-700'" />
                        {{ estados[amb].ok ? 'Certificado y clave correctos' : 'Faltan credenciales o hay problemas' }}
                    </p>
                    <p v-if="estados[amb].vence" class="mt-1">Vence el {{ dia(estados[amb].vence) }} <Insignia :color="estados[amb].dias < 30 ? 'rojo' : 'gris'">{{ estados[amb].dias }} días</Insignia></p>
                    <ul v-if="estados[amb].problemas.length" class="mt-1 list-disc pl-5 text-petroleo-600 dark:text-petroleo-300"><li v-for="p in estados[amb].problemas" :key="p">{{ p }}</li></ul>
                </div>

                <form class="space-y-3" @submit.prevent="subir">
                    <label class="block text-sm"><span class="etiqueta">Certificado (.crt)</span><input type="file" accept=".crt,.pem,.cer" class="block w-full text-sm" @change="cred.certificado = $event.target.files[0]" /></label>
                    <label class="block text-sm"><span class="etiqueta">Clave privada (.key)</span><input type="file" accept=".key,.pem" class="block w-full text-sm" @change="cred.clave = $event.target.files[0]" /></label>
                    <Campo v-model="cred.passphrase" label="Contraseña de la clave (si tiene)" tipo="password" autocomplete="off" />
                    <Boton tipo="submit" :icono="Upload" :cargando="cred.processing">Subir credenciales</Boton>
                </form>

                <hr class="my-5 border-petroleo-100 dark:border-petroleo-800" />
                <form ref="csrForm" method="post" :action="`/afip/configuracion/${amb}/csr`" class="space-y-2 text-sm">
                    <input type="hidden" name="_token" :value="page.props.csrf_token" />
                    <p><b>¿No tenés certificado?</b> Generá la clave acá (nunca sale del servidor) y subí el pedido (.csr) en AFIP › Administración de certificados digitales. Después cargá el .crt que te da AFIP.</p>
                    <label v-if="estados[amb].hay_key" class="flex items-center gap-2"><input v-model="reemplazar" type="checkbox" name="reemplazar" value="1" class="accent-petroleo-700" />Reemplazar la clave existente (el certificado actual deja de servir)</label>
                    <Boton tipo="submit" variante="fantasma" :icono="KeyRound" :disabled="estados[amb].hay_key && !reemplazar">Generar clave y descargar pedido</Boton>
                </form>
            </section>

            <section class="panel p-5">
                <h2 class="mb-3 font-semibold">Logo del negocio</h2>
                <div class="flex items-center gap-4">
                    <img v-if="logo" :src="logo" alt="Logo" class="h-16 max-w-40 rounded-lg border border-petroleo-100 object-contain p-1" />
                    <label class="flex cursor-pointer items-center gap-2 rounded-xl border border-petroleo-200 px-4 py-2.5 text-sm font-semibold dark:border-petroleo-700"><ImagePlus class="size-4" />{{ logo ? 'Cambiar' : 'Subir logo' }}<input type="file" accept="image/png,image/jpeg,image/webp" class="sr-only" @change="subirLogo" /></label>
                    <Boton v-if="logo" variante="fantasma" @click="quitarLogo">Quitar</Boton>
                </div>
                <p class="mt-2 text-xs text-petroleo-500">Sale arriba en tickets, vales y facturas. PNG o JPG de hasta 2 MB.</p>
            </section>
        </div>
    </div>
</template>
