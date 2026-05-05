<script setup>
import { ref, computed, onMounted, watch, nextTick } from 'vue';
import { Head } from '@inertiajs/vue3';
import {
    ArrowUp,
    ArrowDown,
    ArrowLeft,
    ArrowRight,
    ArrowUpLeft,
    ArrowUpRight,
    ArrowDownLeft,
    ArrowDownRight,
    Trash2,
    Plus,
} from 'lucide-vue-next';

const STORAGE_KEY = 'eisenhower:v1';

const QUADRANTS = [
    {
        id: 'do',
        title: 'Hacer',
        subtitle: 'urgente · importante',
        color: '#fb7185',
        soft: 'rgba(251, 113, 133, 0.10)',
        ring: 'rgba(251, 113, 133, 0.35)',
    },
    {
        id: 'plan',
        title: 'Planificar',
        subtitle: 'importante',
        color: '#5eead4',
        soft: 'rgba(94, 234, 212, 0.08)',
        ring: 'rgba(94, 234, 212, 0.32)',
    },
    {
        id: 'delegate',
        title: 'Delegar',
        subtitle: 'urgente',
        color: '#fbbf24',
        soft: 'rgba(251, 191, 36, 0.08)',
        ring: 'rgba(251, 191, 36, 0.32)',
    },
    {
        id: 'drop',
        title: 'Eliminar',
        subtitle: 'ni urgente ni importante',
        color: '#94a3b8',
        soft: 'rgba(148, 163, 184, 0.08)',
        ring: 'rgba(148, 163, 184, 0.30)',
    },
];

const MOVE_ICONS = {
    up: ArrowUp,
    down: ArrowDown,
    left: ArrowLeft,
    right: ArrowRight,
    'up-left': ArrowUpLeft,
    'up-right': ArrowUpRight,
    'down-left': ArrowDownLeft,
    'down-right': ArrowDownRight,
};

const MOVE_ACTIONS = {
    do: [
        { to: 'plan', dir: 'right' },
        { to: 'delegate', dir: 'down' },
        { to: 'drop', dir: 'down-right' },
    ],
    plan: [
        { to: 'do', dir: 'left' },
        { to: 'delegate', dir: 'down-left' },
        { to: 'drop', dir: 'down' },
    ],
    delegate: [
        { to: 'do', dir: 'up' },
        { to: 'plan', dir: 'up-right' },
        { to: 'drop', dir: 'right' },
    ],
    drop: [
        { to: 'do', dir: 'up-left' },
        { to: 'plan', dir: 'up' },
        { to: 'delegate', dir: 'left' },
    ],
};

const items = ref({ do: [], plan: [], delegate: [], drop: [] });
const drafts = ref({ do: '', plan: '', delegate: '', drop: '' });
const selectedId = ref(null);
const draggingId = ref(null);
const dragOverQ = ref(null);
const ready = ref(false);

const inputRefs = ref({});

function quadrantById(id) {
    return QUADRANTS.find((q) => q.id === id);
}

function uid() {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }
    return `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`;
}

function load() {
    try {
        const raw = localStorage.getItem(STORAGE_KEY);
        if (!raw) return;
        const data = JSON.parse(raw);
        if (data && typeof data === 'object') {
            for (const q of QUADRANTS) {
                if (Array.isArray(data[q.id])) {
                    items.value[q.id] = data[q.id]
                        .filter((it) => it && typeof it.text === 'string')
                        .map((it) => ({
                            id: typeof it.id === 'string' && it.id ? it.id : uid(),
                            text: String(it.text),
                        }));
                }
            }
        }
    } catch (_) {
        /* ignore */
    }
}

function save() {
    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(items.value));
    } catch (_) {
        /* ignore */
    }
}

watch(
    items,
    () => {
        if (ready.value) save();
    },
    { deep: true }
);

onMounted(() => {
    load();
    nextTick(() => {
        ready.value = true;
    });
});

function vibrate(ms = 8) {
    if (typeof navigator !== 'undefined' && typeof navigator.vibrate === 'function') {
        try {
            navigator.vibrate(ms);
        } catch (_) {
            /* ignore */
        }
    }
}

function commitAdd(qid) {
    const text = drafts.value[qid].trim();
    if (!text) return;
    items.value[qid].push({ id: uid(), text });
    drafts.value[qid] = '';
    vibrate(8);
    nextTick(() => {
        const el = inputRefs.value[qid];
        if (el) el.focus();
    });
}

function removeItem(qid, id) {
    items.value[qid] = items.value[qid].filter((it) => it.id !== id);
    selectedId.value = null;
    vibrate(20);
}

function moveItem(fromQ, id, toQ) {
    if (fromQ === toQ) return;
    const idx = items.value[fromQ].findIndex((it) => it.id === id);
    if (idx === -1) return;
    const [it] = items.value[fromQ].splice(idx, 1);
    items.value[toQ].push(it);
    selectedId.value = null;
    vibrate(12);
}

function toggleSelect(id) {
    selectedId.value = selectedId.value === id ? null : id;
}

function onDragStart(event, qid, id) {
    draggingId.value = id;
    selectedId.value = null;
    if (event.dataTransfer) {
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData(
            'application/x-eisenhower',
            JSON.stringify({ qid, id })
        );
        event.dataTransfer.setData('text/plain', JSON.stringify({ qid, id }));
    }
}

function onDragEnd() {
    draggingId.value = null;
    dragOverQ.value = null;
}

function onDragOver(event, qid) {
    if (!draggingId.value) return;
    event.preventDefault();
    if (event.dataTransfer) event.dataTransfer.dropEffect = 'move';
    if (dragOverQ.value !== qid) dragOverQ.value = qid;
}

function onDrop(event, toQ) {
    event.preventDefault();
    let payload = null;
    if (event.dataTransfer) {
        const raw =
            event.dataTransfer.getData('application/x-eisenhower') ||
            event.dataTransfer.getData('text/plain');
        if (raw) {
            try {
                payload = JSON.parse(raw);
            } catch (_) {
                /* ignore */
            }
        }
    }
    if (payload && payload.qid && payload.id) {
        moveItem(payload.qid, payload.id, toQ);
    }
    dragOverQ.value = null;
    draggingId.value = null;
}

function onDragLeaveQ(event, qid) {
    if (event.currentTarget && event.relatedTarget) {
        if (event.currentTarget.contains(event.relatedTarget)) return;
    }
    if (dragOverQ.value === qid) dragOverQ.value = null;
}

function setInputRef(qid) {
    return (el) => {
        if (el) inputRefs.value[qid] = el;
        else delete inputRefs.value[qid];
    };
}

const totalCount = computed(
    () =>
        items.value.do.length +
        items.value.plan.length +
        items.value.delegate.length +
        items.value.drop.length
);

function clearAll() {
    if (totalCount.value === 0) return;
    if (
        typeof window !== 'undefined' &&
        window.confirm('¿Borrar todos los elementos de la matriz?')
    ) {
        items.value = { do: [], plan: [], delegate: [], drop: [] };
        selectedId.value = null;
        vibrate(30);
    }
}
</script>

<template>
    <Head title="Matriz de Eisenhower" />

    <div class="eisenhower-root flex min-h-[100dvh] flex-col bg-[#0a0d12] text-slate-100">
        <header
            class="flex items-center justify-between gap-3 border-b border-white/5 px-3 py-2 sm:px-5 sm:py-3"
        >
            <div class="min-w-0">
                <h1 class="truncate text-sm font-semibold tracking-tight text-white sm:text-base">
                    Matriz de Eisenhower
                </h1>
                <p class="truncate text-[11px] text-slate-500 sm:text-xs">
                    {{ totalCount }} {{ totalCount === 1 ? 'elemento' : 'elementos' }}
                </p>
            </div>
            <button
                v-if="totalCount > 0"
                type="button"
                class="rounded-full border border-white/10 px-3 py-1.5 text-[11px] text-slate-400 transition hover:border-rose-400/50 hover:text-rose-300 sm:text-xs"
                @click="clearAll"
            >
                Borrar todo
            </button>
        </header>

        <main
            class="grid min-h-0 flex-1 grid-cols-2 grid-rows-2 gap-1.5 p-1.5 sm:gap-3 sm:p-3"
        >
            <section
                v-for="q in QUADRANTS"
                :key="q.id"
                class="quadrant flex min-h-0 flex-col overflow-hidden rounded-xl border transition-colors duration-150"
                :class="{ 'is-drop-target': dragOverQ === q.id }"
                :style="{
                    backgroundColor: q.soft,
                    borderColor: dragOverQ === q.id ? q.color : 'rgba(255,255,255,0.06)',
                }"
                @dragover="onDragOver($event, q.id)"
                @dragleave="onDragLeaveQ($event, q.id)"
                @drop="onDrop($event, q.id)"
            >
                <header
                    class="flex items-baseline justify-between gap-2 px-2.5 pt-2 pb-1 sm:px-4 sm:pt-3 sm:pb-2"
                >
                    <div class="flex min-w-0 items-baseline gap-2">
                        <span
                            class="h-2 w-2 shrink-0 rounded-full self-center"
                            :style="{ backgroundColor: q.color }"
                        />
                        <h2
                            class="truncate text-sm font-semibold tracking-tight sm:text-base"
                            :style="{ color: q.color }"
                        >
                            {{ q.title }}
                        </h2>
                    </div>
                    <span class="shrink-0 text-[10px] text-slate-500 sm:text-xs">
                        {{ items[q.id].length }}
                    </span>
                </header>

                <p
                    class="hidden truncate px-2.5 pb-1 text-[10px] uppercase tracking-wider text-slate-500/80 sm:block sm:px-4 sm:text-[11px]"
                >
                    {{ q.subtitle }}
                </p>

                <div
                    class="flex-1 overflow-y-auto px-1.5 pb-1 sm:px-2"
                    @click="selectedId = null"
                >
                    <ul class="flex flex-col gap-1 sm:gap-1.5">
                        <li
                            v-for="it in items[q.id]"
                            :key="it.id"
                            class="group relative"
                        >
                            <div
                                class="item-card cursor-pointer select-none rounded-lg border border-white/5 bg-[#10141b]/80 px-2.5 py-2 text-[13px] leading-snug shadow-sm transition active:scale-[0.99] sm:px-3 sm:py-2.5 sm:text-sm"
                                :class="{
                                    'is-selected': selectedId === it.id,
                                    'is-dragging': draggingId === it.id,
                                }"
                                :style="
                                    selectedId === it.id
                                        ? {
                                              borderColor: q.color,
                                              boxShadow: `0 0 0 1px ${q.ring} inset`,
                                          }
                                        : {}
                                "
                                draggable="true"
                                @click.stop="toggleSelect(it.id)"
                                @dragstart="onDragStart($event, q.id, it.id)"
                                @dragend="onDragEnd"
                            >
                                <div class="flex items-start gap-2">
                                    <span
                                        class="mt-1.5 inline-block h-1.5 w-1.5 shrink-0 rounded-full"
                                        :style="{ backgroundColor: q.color }"
                                    />
                                    <p class="min-w-0 flex-1 break-words text-slate-100">
                                        {{ it.text }}
                                    </p>
                                </div>

                                <div
                                    v-if="selectedId === it.id"
                                    class="mt-2 flex items-center justify-end gap-1.5 border-t border-white/5 pt-2"
                                    @click.stop
                                >
                                    <button
                                        v-for="action in MOVE_ACTIONS[q.id]"
                                        :key="action.to"
                                        type="button"
                                        class="action-btn flex h-9 w-9 items-center justify-center rounded-full border border-white/10 transition active:scale-90 sm:h-8 sm:w-8"
                                        :style="{
                                            color: quadrantById(action.to).color,
                                            backgroundColor: 'rgba(255,255,255,0.02)',
                                        }"
                                        :title="`Mover a ${quadrantById(action.to).title}`"
                                        :aria-label="`Mover a ${quadrantById(action.to).title}`"
                                        @click="moveItem(q.id, it.id, action.to)"
                                    >
                                        <component
                                            :is="MOVE_ICONS[action.dir]"
                                            class="h-4 w-4 sm:h-3.5 sm:w-3.5"
                                            :stroke-width="2.5"
                                        />
                                    </button>
                                    <button
                                        type="button"
                                        class="action-btn flex h-9 w-9 items-center justify-center rounded-full border border-rose-500/30 bg-rose-500/10 text-rose-300 transition active:scale-90 sm:h-8 sm:w-8"
                                        title="Eliminar"
                                        aria-label="Eliminar"
                                        @click="removeItem(q.id, it.id)"
                                    >
                                        <Trash2 class="h-4 w-4 sm:h-3.5 sm:w-3.5" :stroke-width="2.5" />
                                    </button>
                                </div>
                            </div>
                        </li>
                    </ul>

                    <p
                        v-if="items[q.id].length === 0"
                        class="px-2 pt-3 pb-2 text-center text-[11px] italic text-slate-600 sm:text-xs"
                    >
                        vacío
                    </p>
                </div>

                <form
                    class="flex items-center gap-1.5 border-t border-white/5 bg-[#0c1017]/70 px-1.5 py-1.5 sm:px-2 sm:py-2"
                    @submit.prevent="commitAdd(q.id)"
                    @click.stop
                >
                    <input
                        :ref="setInputRef(q.id)"
                        v-model="drafts[q.id]"
                        type="text"
                        :placeholder="`+ ${q.title.toLowerCase()}…`"
                        :aria-label="`Añadir a ${q.title}`"
                        autocomplete="off"
                        autocapitalize="sentences"
                        enterkeyhint="done"
                        maxlength="240"
                        class="quadrant-input min-w-0 flex-1 rounded-md bg-transparent px-2 py-1.5 text-base text-slate-100 placeholder:text-slate-600 focus:outline-none"
                        :style="{ caretColor: q.color }"
                        @keydown.escape="drafts[q.id] = ''"
                    />
                    <button
                        v-if="drafts[q.id].trim()"
                        type="submit"
                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md transition active:scale-90"
                        :style="{
                            backgroundColor: q.color,
                            color: '#0a0d12',
                        }"
                        :aria-label="`Añadir a ${q.title}`"
                    >
                        <Plus class="h-4 w-4" :stroke-width="3" />
                    </button>
                </form>
            </section>
        </main>
    </div>
</template>

<style scoped>
.eisenhower-root {
    -webkit-tap-highlight-color: transparent;
    overscroll-behavior: contain;
    padding-bottom: env(safe-area-inset-bottom, 0);
}

.quadrant {
    backdrop-filter: blur(6px);
}

.quadrant.is-drop-target {
    transform: scale(1.005);
}

.item-card {
    transition: border-color 120ms ease, box-shadow 120ms ease, transform 120ms ease,
        background-color 120ms ease;
}

.item-card.is-dragging {
    opacity: 0.45;
}

.quadrant-input {
    -webkit-appearance: none;
    appearance: none;
    border: 0;
}

@media (hover: hover) {
    .item-card:hover {
        background-color: rgba(255, 255, 255, 0.04);
    }
    .action-btn:hover {
        background-color: rgba(255, 255, 255, 0.08) !important;
    }
}
</style>
