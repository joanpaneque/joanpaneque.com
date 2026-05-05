<script setup>
import { Head, Link, router } from '@inertiajs/vue3';

function logout() {
    router.post('/personal/logout');
}
</script>

<template>
    <Head title="Nebula" />
    <div class="min-h-screen bg-neutral-950 text-neutral-100">
        <header class="flex items-center justify-between border-b border-neutral-800 px-6 py-4">
            <span class="text-sm font-medium tracking-wide text-neutral-400">Nebula</span>
            <button
                type="button"
                class="text-sm text-neutral-500 transition hover:text-neutral-300"
                @click="logout"
            >
                Salir
            </button>
        </header>
        <main class="space-y-4 p-8">
            <nav class="flex flex-wrap gap-4 text-sm">
                <Link href="/nebula/instagram-rules" class="text-teal-500 hover:text-teal-400">
                    Reglas Instagram (keywords / DM)
                </Link>
                <a href="/auth/google" class="text-blue-400 hover:text-blue-300">
                    Conectar Google Calendar
                </a>
                <Link href="/calendar" class="text-indigo-400 hover:text-indigo-300">
                    Ver calendario
                </Link>
            </nav>
        </main>
    </div>
</template>
