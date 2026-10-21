<script setup>
import { watch, onBeforeUnmount } from 'vue';
import { X } from 'lucide-vue-next';

const abierto = defineModel({ type: Boolean, default: false });
const props = defineProps({ titulo: String, ancho: { type: String, default: 'max-w-lg' }, cerrable: { type: Boolean, default: true } });

function tecla(e) {
    if (e.key === 'Escape' && props.cerrable) abierto.value = false;
}
watch(abierto, (v) => {
    document.body.style.overflow = v ? 'hidden' : '';
    v ? window.addEventListener('keydown', tecla) : window.removeEventListener('keydown', tecla);
});
onBeforeUnmount(() => {
    document.body.style.overflow = '';
    window.removeEventListener('keydown', tecla);
});
</script>

<template>
    <Teleport to="body">
        <Transition enter-from-class="opacity-0" leave-to-class="opacity-0" enter-active-class="transition duration-150" leave-active-class="transition duration-100">
            <div v-if="abierto" class="fixed inset-0 z-[75] flex items-end justify-center bg-petroleo-950/50 sm:items-center sm:p-4" @mousedown.self="cerrable && (abierto = false)">
                <div role="dialog" aria-modal="true" :aria-label="titulo"
                    class="max-h-[92dvh] w-full overflow-y-auto rounded-t-3xl bg-white pb-[env(safe-area-inset-bottom)] shadow-2xl sm:rounded-2xl dark:bg-petroleo-900" :class="ancho">
                    <div class="sticky top-0 z-10 flex items-center justify-between gap-3 border-b border-petroleo-100 bg-white px-5 py-4 dark:border-petroleo-800 dark:bg-petroleo-900">
                        <h2 class="text-lg font-semibold">{{ titulo }}</h2>
                        <button v-if="cerrable" type="button" class="rounded-lg p-1.5 text-petroleo-500 hover:bg-petroleo-50 dark:hover:bg-petroleo-800" aria-label="Cerrar" @click="abierto = false">
                            <X class="size-5" />
                        </button>
                    </div>
                    <div class="p-5"><slot /></div>
                    <div v-if="$slots.pie" class="flex flex-col-reverse gap-2 border-t border-petroleo-100 px-5 py-4 sm:flex-row sm:justify-end dark:border-petroleo-800">
                        <slot name="pie" />
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
