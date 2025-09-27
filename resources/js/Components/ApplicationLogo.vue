<script setup>
import { computed } from 'vue';
import { useLayout } from '@/Layouts/composables/layout';

// Se utiliza el composable del layout para obtener el estado actual del tema.
const { isDarkTheme } = useLayout();

// Se crea una propiedad computada que devuelve la ruta del logo correcta
// basándose en si el modo oscuro está activo o no.
const logoSrc = computed(() => {
  return isDarkTheme.value 
    ? '/images/white_logo.png' 
    : '/images/black_logo.png';
});
</script>

<template>
    <figure>
        <!-- El atributo 'src' ahora está vinculado a la propiedad computada 'logoSrc' -->
        <img class="h-full" :src="logoSrc" alt="logo">
    </figure>
</template>