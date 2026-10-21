<script setup>
import { CircleCheck, CircleAlert, X } from 'lucide-vue-next';
import { toasts, cerrarToast } from '@/lib/toast';
</script>

<template>
    <div class="pointer-events-none fixed inset-x-0 top-16 z-[80] flex flex-col items-center gap-2 px-4 lg:top-5 lg:right-6 lg:left-auto lg:items-end" aria-live="polite">
        <TransitionGroup enter-from-class="translate-y-2 opacity-0" leave-to-class="opacity-0" enter-active-class="transition duration-200" leave-active-class="transition duration-150">
            <div v-for="t in toasts" :key="t.id" class="pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-xl px-4 py-3 text-sm shadow-lg"
                :class="t.tipo === 'error' ? 'bg-sale text-white' : 'bg-petroleo-900 text-white'">
                <component :is="t.tipo === 'error' ? CircleAlert : CircleCheck" class="mt-0.5 size-4 shrink-0" :class="t.tipo === 'error' ? '' : 'text-cartel-400'" />
                <span class="flex-1">{{ t.texto }}</span>
                <button aria-label="Cerrar" class="opacity-70 hover:opacity-100" @click="cerrarToast(t.id)"><X class="size-4" /></button>
            </div>
        </TransitionGroup>
    </div>
</template>
