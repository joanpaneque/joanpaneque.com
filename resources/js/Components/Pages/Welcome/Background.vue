<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import Hyperspeed from '@/Components/VueBits/Components/Hyperspeed/Hyperspeed.vue';
import { hyperspeedPresets } from '@/Components/VueBits/Components/Hyperspeed/HyperspeedPresets';

const effectOptions = ref({
  ...hyperspeedPresets.akira,
  colors: {
    roadColor: 0x080808,
    islandColor: 0x0a0a0a,
    background: 0x000000,
    shoulderLines: 0x131318,
    brokenLines: 0x131318,
    leftCars: [0x1e3a8a, 0x1e40af, 0x2563eb],
    rightCars: [0x3b82f6, 0x60a5fa, 0x93c5fd],
    sticks: 0x3b82f6,
  },
});

const opacity = ref(1);

const handleScroll = () => {
  const scrollY = window.scrollY;
  const fadeDistance = window.innerHeight; // Fade en 100vh
  const newOpacity = Math.max(0, 1 - scrollY / fadeDistance);
  opacity.value = newOpacity;
};

onMounted(() => {
  window.addEventListener('scroll', handleScroll);
  handleScroll(); // Inicializar opacidad
});

onUnmounted(() => {
  window.removeEventListener('scroll', handleScroll);
});

</script>

<template>
    <div class="background-container">
        <div class="hyperspeed-wrapper" :style="{ opacity: opacity }">
            <Hyperspeed :effect-options="effectOptions" />
        </div>
        <div class="content-wrapper">
            <slot />
        </div>
    </div>
</template>

<style scoped>
.background-container {
    position: relative;
    width: 100%;
    min-height: 100vh;
    overflow: hidden;
    background: #000;
}

.hyperspeed-wrapper {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100vh;
    z-index: 1;
    transition: opacity 0.1s ease-out;
}

.content-wrapper {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100vh;
    z-index: 10;
    pointer-events: none;
}

.content-wrapper > * {
    pointer-events: auto;
}
</style>