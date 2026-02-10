<script setup>
import { ref, computed } from 'vue';

const cardRef = ref(null);
const rotation = ref({ x: 0, y: 0 });
const glowPosition = ref({ x: 50, y: 50 });
const opacity = ref(0);

const handleMouseMove = (e) => {
    if (!cardRef.value) return;

    const rect = cardRef.value.getBoundingClientRect();
    const x = e.clientX - rect.left;
    const y = e.clientY - rect.top;

    // Calcular rotación (máximo 10 grados)
    const centerX = rect.width / 2;
    const centerY = rect.height / 2;
    
    const rotateX = ((y - centerY) / centerY) * -5; // Invertido para sensación natural
    const rotateY = ((x - centerX) / centerX) * 5;

    rotation.value = { x: rotateX, y: rotateY };

    // Posición del brillo
    glowPosition.value = {
        x: (x / rect.width) * 100,
        y: (y / rect.height) * 100
    };
    
    opacity.value = 1;
};

const handleMouseLeave = () => {
    // Volver suavemente al centro
    rotation.value = { x: 0, y: 0 };
    opacity.value = 0;
};

const cardStyle = computed(() => ({
    transform: `perspective(1000px) rotateX(${rotation.value.x}deg) rotateY(${rotation.value.y}deg)`,
    transition: 'transform 0.1s ease-out'
}));

const glowStyle = computed(() => ({
    background: `radial-gradient(circle at ${glowPosition.value.x}% ${glowPosition.value.y}%, rgba(255, 255, 255, 0.15), transparent 50%)`,
    opacity: opacity.value,
    transition: 'opacity 0.3s ease'
}));
</script>

<template>
    <section class="py-20 px-4 flex justify-center items-center perspective-1000 relative z-50 pointer-events-auto">
        <div 
            ref="cardRef"
            class="relative w-full max-w-4xl bg-black/40 backdrop-blur-xl border border-white/10 rounded-2xl p-8 md:p-12 overflow-hidden group shadow-2xl transition-all duration-300 hover:border-white/20"
            :style="cardStyle"
            @mousemove="handleMouseMove"
            @mouseleave="handleMouseLeave"
        >
            <!-- Glow effect overlay -->
            <div 
                class="absolute inset-0 pointer-events-none z-0"
                :style="glowStyle"
            ></div>

            <!-- Content -->
            <div class="relative z-10 flex flex-col gap-6 text-gray-300 font-light text-lg md:text-xl leading-relaxed">
                <!-- Quote Icon -->
                <div class="absolute -top-4 -left-2 text-6xl text-white/5 font-serif select-none">"</div>

                <p>
                    <span class="text-white font-medium">Trabajar con Joan Paneque</span> es tener una <span class="text-blue-400 font-semibold glow-text">visión muy futurista</span> de lo que puede ser tu proyecto.
                </p>
                
                <p>
                    Una cosa es lo que tienes en mente, y otra es cómo él te lo traduce en una herramienta que no solo te sirve a nivel de producto, sino que te ofrece <span class="text-emerald-400 font-semibold glow-text">la mejor fórmula</span>.
                </p>

                <p>
                    Para quienes somos expertos en marketing pero ajenos a la tecnología, es vital tener a alguien que te guíe y sea <span class="text-purple-400 font-semibold glow-text">proactivo</span> en la solución que te entrega.
                </p>

                <div class="mt-4 pt-6 border-t border-white/10 flex items-center gap-4">
                    <img 
                        src="/ylenia.png" 
                        alt="Ylenia Porras" 
                        class="h-12 w-12 rounded-full object-cover ring-2 ring-white/10 transition-transform duration-300 hover:scale-110"
                    />
                    <div>
                        <a href="https://www.linkedin.com/in/yleniapr/" target="_blank" rel="noopener noreferrer" class="text-white font-medium text-base hover:text-blue-400 transition-colors">Ylenia Porras</a>
                        <p class="text-sm text-gray-400">Diseño estrategias de marca personal y embajadores corporativos.</p>
                    </div>
                </div>
            </div>

            <!-- Decorative corner accents -->
            <div class="absolute top-0 left-0 w-20 h-20 border-t-2 border-l-2 border-white/5 rounded-tl-2xl"></div>
            <div class="absolute bottom-0 right-0 w-20 h-20 border-b-2 border-r-2 border-white/5 rounded-br-2xl"></div>
        </div>
    </section>
</template>

<style scoped>
.glow-text {
    text-shadow: 0 0 10px currentColor;
}

.perspective-1000 {
    perspective: 1000px;
}
</style>
