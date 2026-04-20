<script setup>
defineEmits(['submit']);

defineProps({
    form: {
        type: Object,
        required: true,
    },
});

function addKeyword(form) {
    form.keywords.push('');
}

function removeKeyword(form, i) {
    if (form.keywords.length > 1) {
        form.keywords.splice(i, 1);
    }
}

function addCommentVariant(form) {
    form.comment_reply_variants.push('');
}

function removeCommentVariant(form, i) {
    if (form.comment_reply_variants.length > 1) {
        form.comment_reply_variants.splice(i, 1);
    }
}

function addQuickReply(form) {
    if (form.dm_quick_replies.length >= 13) {
        return;
    }
    form.dm_quick_replies.push({ title: '', payload: '' });
}

function removeQuickReply(form, i) {
    if (form.dm_quick_replies.length > 1) {
        form.dm_quick_replies.splice(i, 1);
    }
}

function addPhase2(form) {
    form.dm_phase2_reply_variants.push('');
}

function removePhase2(form, i) {
    if (form.dm_phase2_reply_variants.length > 1) {
        form.dm_phase2_reply_variants.splice(i, 1);
    }
}
</script>

<template>
    <form class="space-y-8" @submit.prevent="$emit('submit')">
        <section class="space-y-3">
            <h2 class="text-sm font-medium text-neutral-400">General</h2>
            <label class="flex items-center gap-2 text-sm text-neutral-300">
                <input v-model="form.is_active" type="checkbox" class="rounded border-neutral-600 bg-neutral-900">
                Activa
            </label>
        </section>

        <section class="space-y-3">
            <h2 class="text-sm font-medium text-neutral-400">Comentarios</h2>
            <p class="text-xs text-neutral-500">
                El comentario debe coincidir exactamente con una keyword (sin distinguir mayúsculas). No puede haber la misma keyword en dos reglas distintas.
            </p>
            <div class="space-y-2">
                <div v-for="(_, i) in form.keywords" :key="'kw-' + i" class="flex gap-2">
                    <input
                        v-model="form.keywords[i]"
                        type="text"
                        class="flex-1 rounded border border-neutral-700 bg-neutral-900 px-3 py-2 text-sm text-neutral-100"
                        placeholder="Ej. IA"
                    >
                    <button
                        type="button"
                        class="rounded border border-neutral-700 px-2 text-xs text-neutral-400 hover:bg-neutral-800"
                        @click="removeKeyword(form, i)"
                    >
                        Quitar
                    </button>
                </div>
                <button type="button" class="text-xs text-teal-500 hover:text-teal-400" @click="addKeyword(form)">
                    + Añadir keyword
                </button>
            </div>
            <p v-if="form.errors.keywords" class="text-sm text-red-400">{{ form.errors.keywords }}</p>

            <div class="space-y-2">
                <label class="text-xs text-neutral-500">Respuestas públicas al comentario (una al azar)</label>
                <div v-for="(_, i) in form.comment_reply_variants" :key="'cv-' + i" class="flex gap-2">
                    <textarea
                        v-model="form.comment_reply_variants[i]"
                        rows="2"
                        class="flex-1 rounded border border-neutral-700 bg-neutral-900 px-3 py-2 text-sm text-neutral-100"
                    />
                    <button
                        type="button"
                        class="self-start rounded border border-neutral-700 px-2 text-xs text-neutral-400 hover:bg-neutral-800"
                        @click="removeCommentVariant(form, i)"
                    >
                        Quitar
                    </button>
                </div>
                <button type="button" class="text-xs text-teal-500 hover:text-teal-400" @click="addCommentVariant(form)">
                    + Variante
                </button>
            </div>
        </section>

        <section class="space-y-3">
            <h2 class="text-sm font-medium text-neutral-400">Mensaje directo (opcional)</h2>
            <p class="text-xs text-neutral-500">
                Requiere token Instagram API (IGAA…). Si rellenas botones, también hace falta el texto del primer mensaje y las respuestas de fase 2. Los botones son quick replies (máx. 13, título máx. 20 caracteres en Instagram).
            </p>
            <div>
                <label class="block text-xs text-neutral-500 mb-1">Texto del primer DM (fase 1)</label>
                <textarea
                    v-model="form.dm_phase1_text"
                    rows="3"
                    class="w-full rounded border border-neutral-700 bg-neutral-900 px-3 py-2 text-sm text-neutral-100"
                    placeholder="Mensaje que verá el usuario junto a los botones"
                />
                <p v-if="form.errors.dm_phase1_text" class="mt-1 text-sm text-red-400">{{ form.errors.dm_phase1_text }}</p>
            </div>

            <div class="space-y-2">
                <label class="text-xs text-neutral-500">Botones (quick reply)</label>
                <div
                    v-for="(_, i) in form.dm_quick_replies"
                    :key="'qr-' + i"
                    class="grid gap-2 rounded border border-neutral-800 p-3 sm:grid-cols-2"
                >
                    <div>
                        <span class="text-xs text-neutral-600">Título (≤20)</span>
                        <input
                            v-model="form.dm_quick_replies[i].title"
                            type="text"
                            maxlength="20"
                            class="mt-1 w-full rounded border border-neutral-700 bg-neutral-900 px-3 py-2 text-sm text-neutral-100"
                            placeholder="OBTENER ACCESO"
                        >
                    </div>
                    <div>
                        <span class="text-xs text-neutral-600">Payload (opcional, para el webhook)</span>
                        <input
                            v-model="form.dm_quick_replies[i].payload"
                            type="text"
                            class="mt-1 w-full rounded border border-neutral-700 bg-neutral-900 px-3 py-2 text-sm text-neutral-100"
                            placeholder="Si vacío, se usa el título"
                        >
                    </div>
                    <div class="sm:col-span-2 flex justify-end">
                        <button
                            type="button"
                            class="text-xs text-neutral-500 hover:text-neutral-300"
                            @click="removeQuickReply(form, i)"
                        >
                            Quitar botón
                        </button>
                    </div>
                </div>
                <button type="button" class="text-xs text-teal-500 hover:text-teal-400" @click="addQuickReply(form)">
                    + Botón
                </button>
            </div>

            <div class="space-y-2">
                <label class="text-xs text-neutral-500">Respuestas cuando pulsan el botón (fase 2, una al azar)</label>
                <div v-for="(_, i) in form.dm_phase2_reply_variants" :key="'p2-' + i" class="flex gap-2">
                    <textarea
                        v-model="form.dm_phase2_reply_variants[i]"
                        rows="2"
                        class="flex-1 rounded border border-neutral-700 bg-neutral-900 px-3 py-2 text-sm text-neutral-100"
                    />
                    <button
                        type="button"
                        class="self-start rounded border border-neutral-700 px-2 text-xs text-neutral-400 hover:bg-neutral-800"
                        @click="removePhase2(form, i)"
                    >
                        Quitar
                    </button>
                </div>
                <button type="button" class="text-xs text-teal-500 hover:text-teal-400" @click="addPhase2(form)">
                    + Variante fase 2
                </button>
                <p v-if="form.errors.dm_phase2_reply_variants" class="text-sm text-red-400">{{ form.errors.dm_phase2_reply_variants }}</p>
            </div>
        </section>

        <div class="flex gap-3 pt-4">
            <slot name="actions" />
        </div>
    </form>
</template>
