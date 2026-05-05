<script setup>
import { Head, Link, router } from '@inertiajs/vue3';

function logout() {
    router.post('/admin/logout');
}
</script>

<template>
    <div>
        <Head title="Admin · Automatizaciones" />

        <div class="min-h-screen bg-zinc-950 text-zinc-100">
            <header class="border-b border-white/10 bg-zinc-900/80 backdrop-blur-sm">
                <div class="mx-auto flex max-w-3xl flex-wrap items-center justify-between gap-4 px-4 py-4 sm:px-6">
                    <div>
                        <h1 class="text-lg font-semibold tracking-tight text-white">
                            Automatizaciones
                        </h1>
                        <p v-if="$page.props.admin?.user?.email" class="mt-0.5 text-xs text-zinc-500">
                            {{ $page.props.admin.user.email }}
                        </p>
                    </div>
                    <button
                        type="button"
                        class="rounded-lg border border-white/15 bg-white/5 px-3 py-1.5 text-sm text-zinc-200 transition hover:bg-white/10"
                        @click="logout"
                    >
                        Cerrar sesión
                    </button>
                </div>
            </header>

            <main class="mx-auto max-w-3xl px-4 py-8 sm:px-6">
                <p class="text-sm leading-relaxed text-zinc-400">
                    Aquí podrás configurar reglas e integraciones cuando las añadamos. De momento no hay acciones disponibles.
                </p>

                <div class="mt-8 rounded-xl border border-white/10 bg-zinc-900/50 p-6">
                    <p class="text-sm text-zinc-500">
                        Sin automatizaciones configuradas.
                    </p>
                </div>

                <nav class="mt-10 flex flex-wrap gap-4 text-sm">
                    <Link
                        href="/"
                        class="text-zinc-500 underline-offset-4 hover:text-zinc-400 hover:underline"
                    >
                        Inicio del sitio
                    </Link>
                </nav>
            </main>
        </div>
    </div>
</template>
