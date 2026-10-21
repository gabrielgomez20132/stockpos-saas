<script setup>
defineProps({ emisor: Object, fiscal: Boolean });
</script>

<template>
    <header class="text-center">
        <img v-if="emisor.logo" :src="emisor.logo" alt="" class="mx-auto mb-2 max-h-16 max-w-[60%] object-contain" />
        <p class="text-[15px] font-bold">{{ emisor.razon_social }}</p>
        <p v-if="emisor.domicilio">{{ emisor.domicilio }}</p>
        <p v-if="emisor.localidad">{{ emisor.localidad }}</p>
        <p v-if="emisor.sucursal" class="font-semibold">Sucursal {{ emisor.sucursal.nombre }}<template v-if="emisor.sucursal.direccion"> · {{ emisor.sucursal.direccion }}</template></p>
        <template v-if="fiscal">
            <p v-if="emisor.cuit">CUIT {{ emisor.cuit }}</p>
            <p v-if="emisor.condicion_iva">{{ emisor.condicion_iva }}</p>
            <p v-if="emisor.iibb">IIBB {{ emisor.iibb }}</p>
            <p v-if="emisor.inicio_actividades">Inicio de actividades {{ emisor.inicio_actividades }}</p>
        </template>
    </header>
</template>
