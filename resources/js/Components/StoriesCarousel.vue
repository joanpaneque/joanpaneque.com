<template>
  <div ref="containerRef" class="stories-container">
    <div 
      class="stories-wrapper"
      :class="{ dragging: isDragging }"
      :style="{ transform: `translateX(calc(-${currentIndex * 100}% + ${dragOffset}px))` }"
      @touchstart="handleTouchStart"
      @touchmove="handleTouchMove"
      @touchend="handleTouchEnd"
      @mousedown="handleMouseDown"
    >
      <div 
        v-for="(story, index) in stories" 
        :key="index"
        class="story-slide"
      >
        <div class="story-content">
          <div class="story-image" :style="{ background: story.gradient }">
            <div class="story-text">
              <h3>{{ story.title }}</h3>
              <p>{{ story.description }}</p>
            </div>
          </div>
        </div>
      </div>
    </div>
    
    <div class="story-indicators">
      <button 
        v-for="(story, index) in stories" 
        :key="index"
        class="indicator"
        :class="{ active: index === currentIndex }"
        @click="goToStory(index)"
      ></button>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue';

const containerRef = ref(null);
const currentIndex = ref(0);
const touchStartX = ref(0);
const touchEndX = ref(0);
const mouseStartX = ref(0);
const mouseCurrentX = ref(0);
const isDragging = ref(false);
const dragOffset = ref(0);

const stories = ref([
  {
    title: 'Automatiza facturas y contabilidad',
    description: 'Procesa facturas, genera reportes y gestiona la contabilidad automáticamente. Sin errores manuales.',
    gradient: 'linear-gradient(135deg, #1e3a8a 0%, #2563eb 50%, #3b82f6 100%)'
  },
  {
    title: 'Integra tus herramientas',
    description: 'Conecta CRM, ERP, email y más. Todo sincronizado y funcionando en tiempo real.',
    gradient: 'linear-gradient(135deg, #1e40af 0%, #3b82f6 50%, #60a5fa 100%)'
  },
  {
    title: 'Respuestas automáticas 24/7',
    description: 'Atiende clientes, agenda citas y responde consultas mientras duermes.',
    gradient: 'linear-gradient(135deg, #2563eb 0%, #60a5fa 50%, #93c5fd 100%)'
  }
]);

const handleTouchStart = (e) => {
  touchStartX.value = e.touches[0].clientX;
  isDragging.value = true;
  pauseAutoAdvance();
};

const handleTouchMove = (e) => {
  if (!isDragging.value) return;
  e.preventDefault();
  e.stopPropagation();
  touchEndX.value = e.touches[0].clientX;
  const diff = touchStartX.value - touchEndX.value;
  const containerWidth = containerRef.value ? containerRef.value.offsetWidth : 400;
  dragOffset.value = -diff;
  
  // Limitar el arrastre a una sola historia
  const maxOffset = containerWidth;
  
  // dragOffset negativo = arrastrar hacia la derecha = mostrar siguiente historia
  // dragOffset positivo = arrastrar hacia la izquierda = mostrar anterior historia
  if (dragOffset.value < 0 && currentIndex.value >= stories.value.length - 1) {
    // No puede arrastrar hacia la derecha si está en la última historia
    dragOffset.value = 0;
  } else if (dragOffset.value > 0 && currentIndex.value <= 0) {
    // No puede arrastrar hacia la izquierda si está en la primera historia
    dragOffset.value = 0;
  } else if (Math.abs(dragOffset.value) > maxOffset) {
    // Limitar a una historia completa
    dragOffset.value = dragOffset.value > 0 ? maxOffset : -maxOffset;
  }
};

const handleTouchEnd = (e) => {
  if (!isDragging.value) return;
  const diff = touchStartX.value - touchEndX.value;
  const containerWidth = containerRef.value ? containerRef.value.offsetWidth : 400;
  const threshold = containerWidth * 0.3; // 30% del ancho
  
  if (Math.abs(diff) > threshold) {
    if (diff > 0 && currentIndex.value < stories.value.length - 1) {
      currentIndex.value++;
    } else if (diff < 0 && currentIndex.value > 0) {
      currentIndex.value--;
    }
  }
  
  dragOffset.value = 0;
  isDragging.value = false;
  resumeAutoAdvance();
};

const handleMouseDown = (e) => {
  e.preventDefault();
  mouseStartX.value = e.clientX;
  mouseCurrentX.value = e.clientX;
  isDragging.value = true;
  pauseAutoAdvance();
};

const handleMouseMove = (e) => {
  if (!isDragging.value) return;
  e.preventDefault();
  e.stopPropagation();
  mouseCurrentX.value = e.clientX;
  const diff = mouseStartX.value - mouseCurrentX.value;
  const containerWidth = containerRef.value ? containerRef.value.offsetWidth : 400;
  dragOffset.value = -diff;
  
  // Limitar el arrastre a una sola historia
  const maxOffset = containerWidth;
  
  // dragOffset negativo = arrastrar hacia la derecha = mostrar siguiente historia
  // dragOffset positivo = arrastrar hacia la izquierda = mostrar anterior historia
  if (dragOffset.value < 0 && currentIndex.value >= stories.value.length - 1) {
    // No puede arrastrar hacia la derecha si está en la última historia
    dragOffset.value = 0;
  } else if (dragOffset.value > 0 && currentIndex.value <= 0) {
    // No puede arrastrar hacia la izquierda si está en la primera historia
    dragOffset.value = 0;
  } else if (Math.abs(dragOffset.value) > maxOffset) {
    // Limitar a una historia completa
    dragOffset.value = dragOffset.value > 0 ? maxOffset : -maxOffset;
  }
};

const handleMouseUp = (e) => {
  if (!isDragging.value) return;
  const diff = mouseStartX.value - mouseCurrentX.value;
  const containerWidth = containerRef.value ? containerRef.value.offsetWidth : 400;
  const threshold = containerWidth * 0.3; // 30% del ancho
  
  if (Math.abs(diff) > threshold) {
    if (diff > 0 && currentIndex.value < stories.value.length - 1) {
      currentIndex.value++;
    } else if (diff < 0 && currentIndex.value > 0) {
      currentIndex.value--;
    }
  }
  
  dragOffset.value = 0;
  isDragging.value = false;
  resumeAutoAdvance();
};

const goToStory = (index) => {
  currentIndex.value = index;
};

// Auto-avance (opcional)
let autoAdvanceInterval = null;
let isAutoAdvancePaused = false;

const pauseAutoAdvance = () => {
  isAutoAdvancePaused = true;
  if (autoAdvanceInterval) {
    clearInterval(autoAdvanceInterval);
    autoAdvanceInterval = null;
  }
};

const resumeAutoAdvance = () => {
  isAutoAdvancePaused = false;
  // Reiniciar el intervalo solo si no existe
  if (!autoAdvanceInterval) {
    autoAdvanceInterval = setInterval(() => {
      if (!isAutoAdvancePaused && !isDragging.value) {
        if (currentIndex.value < stories.value.length - 1) {
          currentIndex.value++;
        } else {
          currentIndex.value = 0;
        }
      }
    }, 5000);
  }
};

onMounted(() => {
  autoAdvanceInterval = setInterval(() => {
    if (!isAutoAdvancePaused && !isDragging.value) {
      if (currentIndex.value < stories.value.length - 1) {
        currentIndex.value++;
      } else {
        currentIndex.value = 0;
      }
    }
  }, 5000);
  
  // Event listeners globales para mouse
  window.addEventListener('mousemove', handleMouseMove);
  window.addEventListener('mouseup', handleMouseUp);
});

onUnmounted(() => {
  if (autoAdvanceInterval) {
    clearInterval(autoAdvanceInterval);
  }
  window.removeEventListener('mousemove', handleMouseMove);
  window.removeEventListener('mouseup', handleMouseUp);
});
</script>

<style scoped>
.stories-container {
  width: 100%;
  max-width: 400px;
  margin: 0 auto;
  position: relative;
  border-radius: 16px;
  overflow: hidden;
  background: #000;
  box-shadow: 0 10px 40px rgba(0, 0, 0, 0.5);
  z-index: 100;
  pointer-events: auto;
}

.stories-wrapper {
  display: flex;
  transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
  width: 100%;
  cursor: grab;
  user-select: none;
  touch-action: none;
  will-change: transform;
}

.stories-wrapper.dragging {
  cursor: grabbing;
  transition: none !important;
}

.story-slide {
  min-width: 100%;
  height: 600px;
  position: relative;
}

.story-content {
  width: 100%;
  height: 100%;
  position: relative;
  display: flex;
  flex-direction: column;
}

.story-image {
  flex: 1;
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 40px 24px;
  background: #000;
}

.story-text {
  text-align: center;
  color: white;
  z-index: 5;
}

.story-text h3 {
  font-size: 28px;
  font-weight: 700;
  margin-bottom: 16px;
  text-shadow: 0 2px 10px rgba(0, 0, 0, 0.5);
  color: #ffffff;
}

.story-text p {
  font-size: 16px;
  line-height: 1.6;
  opacity: 0.9;
  text-shadow: 0 2px 8px rgba(0, 0, 0, 0.5);
  color: rgba(255, 255, 255, 0.95);
}

.story-indicators {
  display: flex;
  justify-content: center;
  gap: 8px;
  padding: 16px;
  position: absolute;
  bottom: 20px;
  left: 50%;
  transform: translateX(-50%);
  z-index: 20;
}

.indicator {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  border: none;
  background: rgba(255, 255, 255, 0.4);
  cursor: pointer;
  transition: all 0.3s ease;
  padding: 0;
}

.indicator.active {
  background: white;
  width: 24px;
  border-radius: 4px;
}

@media (max-width: 640px) {
  .stories-container {
    max-width: 100%;
    border-radius: 0;
  }
  
  .story-slide {
    height: 100vh;
  }
}
</style>

