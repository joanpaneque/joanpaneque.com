<script setup>
import { ref, onMounted } from 'vue';

const props = defineProps({
  text: {
    type: String,
    default: 'Reduce costes. Gana tiempo.\nAutomatiza con IA.'
  }
});

const words = ref([]);

onMounted(() => {
  // Separar el texto en palabras y manejar saltos de línea
  const lines = props.text.split('\n');
  const wordsArray = [];
  
  let totalWordsBefore = 0;
  
  lines.forEach((line, lineIndex) => {
    const lineWords = line.trim().split(/\s+/);
    lineWords.forEach((word, wordIndex) => {
      wordsArray.push({
        text: word,
        isBreak: false,
        delay: (totalWordsBefore + wordIndex) * 0.1
      });
    });
    
    totalWordsBefore += lineWords.length;
    
    // Agregar <br> después de cada línea excepto la última
    if (lineIndex < lines.length - 1) {
      wordsArray.push({
        text: '',
        isBreak: true,
        delay: (totalWordsBefore - 1) * 0.1 + 0.1 // Aparece justo después de la última palabra
      });
    }
  });
  
  words.value = wordsArray;
});
</script>

<template>
  <h1 class="text-white text-[2rem] md:text-5xl font-semibold text-center leading-snug">
    <template v-for="(word, index) in words" :key="index">
      <template v-if="word.isBreak">
        <br />
      </template>
      <span
        v-else
        :class="['inline-block', 'animate-word']"
        :style="{
          animationDelay: `${word.delay}s`
        }"
      >
        {{ word.text }}<span v-if="index < words.length - 1 && !words[index + 1]?.isBreak">&nbsp;</span>
      </span>
    </template>
  </h1>
</template>

<style scoped>
.animate-word {
  opacity: 0;
  transform: translateY(30px);
  animation: slideUp 0.6s ease-out forwards;
}

@keyframes slideUp {
  from {
    opacity: 0;
    transform: translateY(30px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}
</style>

