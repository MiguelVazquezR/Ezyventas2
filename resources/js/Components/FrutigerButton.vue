<script setup>
// No se requiere lógica adicional, solo el template y estilos
</script>

<template>
    <!-- Quitamos el texto fijo y usamos un slot para reutilizarlo -->
    <button class="button">
        <path d="m18 0 8 12 10-8-4 20H4L0 4l10 8 8-12z"></path>Iniciar sesión
    </button>

</template>

<style scoped>
/* inspired from this svgbackgrounds.com/ */
.button {
  width: fit-content;
  display: flex;
  padding: 0.8em 1.1em;
  gap: 0.4rem;
  border: none;
  font-weight: bold;
  border-radius: 30px;
  cursor: pointer;
  text-shadow: 2px 2px 3px rgba(230, 133, 6, 0.777);
  background: linear-gradient(
      15deg,
      #e67402,
      #de6e0c,
      #e48304,
      #e78402,
      #ff9204,
      #e59808,
      #e78402,
      #da6b0a,
      #e87603
    )
    no-repeat;
  background-size: 300%;
  background-position: left center;
  transition: 0.3s ease;
  color: #fff;
}

.button:hover {
  background-size: 320%;
  background-position: right center;
}

.button:hover svg {
  fill: #fff;
}

.button svg {
  width: 23px;
  fill: #f09f33;
  transition: 0.3s ease;
}

</style>