<script setup>
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    rules: {
        type: Array,
        required: true,
    },
});

const page = usePage();
const flashSuccess = computed(() => page.props.flash?.success);
const flashWarning = computed(() => page.props.flash?.warning);

function destroyRule(id) {
    if (!confirm('¿Eliminar esta regla?')) {
        return;
    }
    router.delete(`/nebula/instagram-rules/${id}`);
}
</script>

<template>
    <Head title="Reglas Instagram" />
    <div class="min-h-screen bg-neutral-950 text-neutral-100">
        <header class="flex items-center justify-between border-b border-neutral-800 px-6 py-4">
            <Link href="/nebula" class="text-sm text-neutral-500 hover:text-neutral-300">← Nebula</Link>
            <Link
                href="/nebula/instagram-rules/create"
                class="rounded-lg bg-teal-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-teal-700"
            >
                Nueva regla
            </Link>
        </header>
        <div class="mx-auto max-w-5xl p-8">
            <p v-if="flashSuccess" class="mb-6 rounded border border-teal-900/50 bg-teal-950/40 px-4 py-2 text-sm text-teal-200">
                {{ flashSuccess }}
            </p>
            <p v-if="flashWarning" class="mb-6 rounded border border-amber-900/50 bg-amber-950/40 px-4 py-2 text-sm text-amber-200">
                {{ flashWarning }}
            </p>
            <div v-if="!rules.length" class="rounded border border-dashed border-neutral-700 p-12 text-center text-sm text-neutral-500">
                No hay reglas. Crea una para enlazar keywords con respuestas y DMs.
            </div>
            <div v-else class="overflow-x-auto rounded border border-neutral-800">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-neutral-800 bg-neutral-900/50 text-xs uppercase text-neutral-500">
                        <tr>
                            <th class="px-4 py-3">Activa</th>
                            <th class="px-4 py-3">Keywords</th>
                            <th class="px-4 py-3">DM</th>
                            <th class="px-4 py-3" />
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-800">
                        <tr v-for="r in rules" :key="r.id" class="hover:bg-neutral-900/30">
                            <td class="px-4 py-3">{{ r.is_active ? 'Sí' : 'No' }}</td>
                            <td class="px-4 py-3 text-neutral-300">
                                {{ (r.keywords || []).join(', ') }}
                            </td>
                            <td class="px-4 py-3 text-neutral-500">
                                {{ r.dm_quick_replies?.length ? 'Sí' : 'No' }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                <Link
                                    :href="`/nebula/instagram-rules/${r.id}/edit`"
                                    class="mr-3 text-teal-500 hover:text-teal-400"
                                >
                                    Editar
                                </Link>
                                <button
                                    type="button"
                                    class="text-neutral-500 hover:text-red-400"
                                    @click="destroyRule(r.id)"
                                >
                                    Eliminar
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
