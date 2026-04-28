<script setup>
import { Head, useForm } from '@inertiajs/vue3';

const form = useForm({
    email: '',
    password: '',
});

function submit() {
    form.post('/admin/login');
}
</script>

<template>
    <div>
        <Head title="Admin · Acceso" />

        <div class="min-h-screen bg-zinc-950 flex items-center justify-center px-4">
            <div class="w-full max-w-md rounded-xl border border-white/10 bg-zinc-900/80 p-8 shadow-xl backdrop-blur-sm">
                <h1 class="text-xl font-semibold tracking-tight text-white mb-1">
                    Panel de administración
                </h1>
                <p class="text-sm text-zinc-500 mb-6">
                    Usa el mismo correo y contraseña que en usuarios de la aplicación.
                </p>
                <form class="space-y-4" @submit.prevent="submit">
                    <div>
                        <label for="email" class="block text-sm font-medium text-zinc-300 mb-1">Correo</label>
                        <input
                            id="email"
                            v-model="form.email"
                            type="email"
                            name="email"
                            required
                            autocomplete="username"
                            class="w-full rounded-lg border border-white/15 bg-zinc-950/50 px-3 py-2 text-zinc-100 placeholder-zinc-600 focus:border-teal-500/50 focus:outline-none focus:ring-2 focus:ring-teal-500/20"
                        >
                        <p v-if="form.errors.email" class="mt-1 text-sm text-red-400">{{ form.errors.email }}</p>
                    </div>
                    <div>
                        <label for="password" class="block text-sm font-medium text-zinc-300 mb-1">Contraseña</label>
                        <input
                            id="password"
                            v-model="form.password"
                            type="password"
                            name="password"
                            required
                            autocomplete="current-password"
                            class="w-full rounded-lg border border-white/15 bg-zinc-950/50 px-3 py-2 text-zinc-100 focus:border-teal-500/50 focus:outline-none focus:ring-2 focus:ring-teal-500/20"
                        >
                        <p v-if="form.errors.password" class="mt-1 text-sm text-red-400">{{ form.errors.password }}</p>
                    </div>
                    <button
                        type="submit"
                        class="w-full rounded-lg bg-teal-600 py-2.5 text-sm font-semibold text-white hover:bg-teal-500 disabled:opacity-50"
                        :disabled="form.processing"
                    >
                        Entrar
                    </button>
                </form>
            </div>
        </div>
    </div>
</template>
