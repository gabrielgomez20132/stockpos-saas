<script setup>
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { LogIn } from 'lucide-vue-next';
import Campo from '@/Components/Campo.vue';
import Boton from '@/Components/Boton.vue';

const page = usePage();
const form = useForm({ usuario: '', password: '', recordar: false });
const enviar = () => form.post('/login', { onFinish: () => form.reset('password') });
</script>

<template>
    <Head title="Ingresar" />
    <div class="grid min-h-dvh place-items-center bg-petroleo-800 px-4 py-10">
        <div class="w-full max-w-md">
            <div class="mb-6 flex items-center justify-center gap-3 text-white">
                <img src="/icono.svg" alt="" class="size-12 rounded-[0.7rem] ring-1 ring-petroleo-600" />
                <span class="text-3xl font-bold" style="font-variation-settings: 'wdth' 85">{{ page.props.app.nombre }}</span>
            </div>

            <form class="rounded-3xl bg-white p-6 shadow-2xl sm:p-8 dark:bg-petroleo-900" @submit.prevent="enviar">
                <h1 class="text-center text-3xl font-bold" style="font-variation-settings: 'wdth' 88">Hola de nuevo</h1>
                <p class="mt-1 mb-6 text-center text-petroleo-500 dark:text-petroleo-300">Ingresá para abrir la caja y empezar a vender.</p>

                <p v-if="page.props.flash.error" class="mb-4 rounded-xl bg-red-50 px-4 py-3 text-sm text-sale">{{ page.props.flash.error }}</p>

                <div class="space-y-4">
                    <Campo v-model="form.usuario" label="Email o DNI" autocomplete="username" autofocus required :error="form.errors.usuario" />
                    <Campo v-model="form.password" label="Contraseña" tipo="password" autocomplete="current-password" required :error="form.errors.password" />
                    <label class="flex items-center gap-2 text-sm">
                        <input v-model="form.recordar" type="checkbox" class="size-4 rounded accent-petroleo-700" /> Mantener la sesión iniciada en este equipo
                    </label>
                    <Boton tipo="submit" variante="principal" tam="lg" class="w-full" :cargando="form.processing" :icono="LogIn">Ingresar</Boton>
                </div>
            </form>

            <p class="mt-6 text-center text-sm text-petroleo-200">Ventas, caja, stock y facturación del local · desde la compu, la tablet o el celular</p>
        </div>
    </div>
</template>
