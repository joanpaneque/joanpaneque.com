<script setup>
import Background from '@/Components/Pages/Welcome/Background.vue';
import AnimatedText from '@/Components/Pages/Welcome/AnimatedText.vue';
import Button from '@/Components/Button.vue';
import '@justinribeiro/lite-youtube';

</script>

<template>
    <div>
        <Background>
            <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 flex flex-col items-center justify-center gap-4 w-full max-w-[90%] md:w-fit px-4 md:px-0">
                <AnimatedText 
                    text=
                    "Reduce costes. Gana tiempo.
                    Automatiza con IA."
                />
                <div class="flex flex-col md:flex-row gap-4 mt-4">
                    <Button>Solicitar consulta gratuita</Button>
                    <Button>Ver cómo funciona</Button>
                </div>
            </div>
        </Background>
        <main class="relative z-50 py-20 pointer-events-none">
            <div class="mx-auto px-4">
                <div class="max-w-6xl mx-auto">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                        <div class="pointer-events-auto">
                            <lite-youtube class="rounded-[8px]" videoid="Q5H6KtXHkzY" params="rel=0"></lite-youtube>
                            <div class="mt-4">
                                <p class="text-lg font-semibold text-white">Sector hotelero</p>
                                <p class="text-gray-300">Room Service</p>
                            </div>
                        </div>
                        <div class="pointer-events-auto">
                            <lite-youtube class="rounded-[8px]" videoid="9RzSmkce9HE" params="rel=0"></lite-youtube>
                            <div class="mt-4">
                                <p class="text-lg font-semibold text-white">Sector marketing digital</p>
                                <p class="text-gray-300">Marca personal - Kirai</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</template>

<style scoped>
</style>
