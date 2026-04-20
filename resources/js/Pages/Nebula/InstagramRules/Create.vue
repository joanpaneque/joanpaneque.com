<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import InstagramRuleForm from './InstagramRuleForm.vue';

const props = defineProps({
    defaults: {
        type: Object,
        required: true,
    },
});

const form = useForm({
    is_active: props.defaults.is_active ?? true,
    keywords: props.defaults.keywords?.length ? [...props.defaults.keywords] : [''],
    comment_reply_variants: props.defaults.comment_reply_variants?.length
        ? [...props.defaults.comment_reply_variants]
        : [''],
    dm_phase1_text: props.defaults.dm_phase1_text ?? '',
    dm_quick_replies:
        props.defaults.dm_quick_replies?.length > 0
            ? props.defaults.dm_quick_replies.map((r) => ({
                title: r.title ?? '',
                payload: r.payload ?? '',
            }))
            : [{ title: '', payload: '' }],
    dm_phase2_reply_variants: props.defaults.dm_phase2_reply_variants?.length
        ? [...props.defaults.dm_phase2_reply_variants]
        : [''],
});

function submit() {
    form.post('/nebula/instagram-rules');
}
</script>

<template>
    <Head title="Nueva regla Instagram" />
    <div class="min-h-screen bg-neutral-950 text-neutral-100">
        <header class="flex items-center justify-between border-b border-neutral-800 px-6 py-4">
            <Link href="/nebula" class="text-sm text-neutral-500 hover:text-neutral-300">← Nebula</Link>
            <span class="text-sm font-medium tracking-wide text-neutral-400">Nueva regla</span>
        </header>
        <div class="mx-auto max-w-3xl p-8">
            <InstagramRuleForm :form="form" @submit="submit">
                <template #actions>
                    <button
                        type="submit"
                        class="rounded-lg bg-teal-600 px-4 py-2 text-sm font-medium text-white hover:bg-teal-700 disabled:opacity-50"
                        :disabled="form.processing"
                    >
                        Guardar
                    </button>
                    <Link
                        href="/nebula/instagram-rules"
                        class="rounded-lg border border-neutral-700 px-4 py-2 text-sm text-neutral-300 hover:bg-neutral-900"
                    >
                        Cancelar
                    </Link>
                </template>
            </InstagramRuleForm>
        </div>
    </div>
</template>
