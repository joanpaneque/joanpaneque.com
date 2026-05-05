<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import MarkdownIt from 'markdown-it';

const props = defineProps({
    weekStart: {
        type: String,
        required: true,
    },
    weekEnd: {
        type: String,
        required: true,
    },
    events: {
        type: Array,
        default: () => [],
    },
});

function prettyJson(value) {
    return JSON.stringify(value ?? {}, null, 2);
}

const chatMarkdown = new MarkdownIt({
    html: false,
    breaks: true,
    linkify: true,
});

function renderPlainChatText(text) {
    return String(text ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;')
        .replace(/\n/g, '<br>');
}

function renderChatMessageHtml(role, text) {
    if (role === 'assistant') {
        return chatMarkdown.render(String(text ?? ''));
    }

    return renderPlainChatText(text);
}

function makeChatMessage(id, role, text) {
    const normalizedText = String(text ?? '');

    return {
        id,
        role,
        text: normalizedText,
        html: renderChatMessageHtml(role, normalizedText),
        hasCalendarChanges: false,
        calendarChangesRevertedAt: null,
        calendarRevertResult: null,
    };
}

function nextChatMessage(role, text) {
    return makeChatMessage(`local-${nextChatMessageId++}`, role, text);
}

const DAY_START_HOUR = 0;
const DAY_END_HOUR = 24;
/** Primera hora visible al cargar (línea de las 07:00 pegada al borde inferior del encabezado sticky). */
const INITIAL_SCROLL_HOUR = 7;
const HOUR_HEIGHT = 72;
const SNAP_MINUTES = 15;
const gridHeight = (DAY_END_HOUR - DAY_START_HOUR) * HOUR_HEIGHT;
const EVENTS_STORAGE_PREFIX = 'calendar.events';
const COLORS_STORAGE_KEY = 'calendar.colors';
const CHAT_ASIDE_WIDTH_STORAGE_KEY = 'calendar.chatAsideWidth';
const CHAT_ACTIVE_STORAGE_KEY = 'calendar.activeChatId';
const CHAT_ASIDE_MIN_WIDTH = 240;
const CHAT_ASIDE_MAX_WIDTH = 650;
const POLL_MS = 10000;
const localEvents = ref(readStoredEvents() ?? [...props.events]);
const loadedEventColors = ref(readStoredColors()?.event_colors ?? {});
const defaultEventColor = ref(readStoredColors()?.default_event_color ?? { background: '#4285f4', foreground: '#ffffff' });
const debugOpen = ref({});
const contextMenu = ref(null);
const recurringPrompt = ref(null);
const draggedEvent = ref(null);
const dropPreview = ref(null);
const dragOffsetMinutes = ref(0);
let pendingPointer = null;
let dragFrame = null;
const resizingEvent = ref(null);
const resizePreview = ref(null);
let pendingResizePointer = null;
let resizeFrame = null;
let resizeDay = null;
let resizeDayElement = null;
const savingEventId = ref(null);
const savingEventIds = ref({});
const savedEventId = ref(null);
const savedEventIds = ref({});
const createSelection = ref(null);
const createModal = ref(false);
const chatSessions = ref([]);
const activeChatId = ref(readStoredActiveChatId());
const chatMessages = ref([]);
const chatDraft = ref('');
const chatSending = ref(false);
const chatAgentStatus = ref(null);
const chatTraceSteps = ref([]);
const chatTraceOpen = ref(true);
const loadingChats = ref(false);
const loadingChatMessages = ref(false);
const creatingChat = ref(false);
const deletingChatId = ref(null);
const revertingMessageId = ref(null);
const editingMessageId = ref(null);
const editingMessageText = ref('');
const submittingEditedMessageId = ref(null);
const chatAsideWidth = ref(readStoredChatAsideWidth());
const isResizingChatAside = ref(false);
const chatHistoryOpen = ref(false);
const CHAT_SCROLL_STICK_THRESHOLD_PX = 80;
const chatMessagesScrollEl = ref(null);
/** Si true, los nuevos tokens mantienen el scroll pegado al final; el usuario lo desactiva al subir. */
const chatStickToBottom = ref(true);
const MUTATING_CALENDAR_TOOLS = new Set(['create_event', 'update_event', 'delete_event', 'move_event']);
let chatEventSource = null;
let streamingAssistantMessageId = null;
let pendingAssistantTokenText = '';
let assistantTokenFrame = null;
let assistantTokenDonePending = false;
let pendingAgentMove = null;
let chatAsideResizeStartX = 0;
let chatAsideResizeStartWidth = 0;
let nextChatMessageId = 1;
let nextChatTraceStepId = 1;
let finalResponseTraceAdded = false;
const activeChat = computed(() => {
    return chatSessions.value.find((chat) => chat.id === activeChatId.value) ?? null;
});
const chatTimelineItems = computed(() => chatMessages.value);
const WEEKDAY_OPTIONS = [
    { key: 'MO', label: 'Lun' },
    { key: 'TU', label: 'Mar' },
    { key: 'WE', label: 'Mié' },
    { key: 'TH', label: 'Jue' },
    { key: 'FR', label: 'Vie' },
    { key: 'SA', label: 'Sáb' },
    { key: 'SU', label: 'Dom' },
];

const RECURRENCE_FREQ_PRESETS = [
    { value: 'none', label: 'No' },
    { value: 'daily', label: 'Día' },
    { value: 'weekly', label: 'Semana' },
    { value: 'monthly', label: 'Mes' },
    { value: 'yearly', label: 'Año' },
];

function weekdayKeysFromDatetimeInput(startIso) {
    const d = new Date(startIso);
    if (Number.isNaN(d.getTime())) {
        return ['MO'];
    }

    const map = ['SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA'];

    return [map[d.getDay()]];
}

function defaultCreateRecurrence(startIso) {
    return {
        freq: 'none',
        interval: 1,
        by_day: weekdayKeysFromDatetimeInput(startIso),
        ends: 'never',
        until: '',
        count: 10,
    };
}

function recurrenceIntervalSuffix(freq, interval) {
    const n = interval || 1;
    switch (freq) {
        case 'daily':
            return n === 1 ? 'día' : 'días';
        case 'weekly':
            return n === 1 ? 'semana' : 'semanas';
        case 'monthly':
            return n === 1 ? 'mes' : 'meses';
        case 'yearly':
            return n === 1 ? 'año' : 'años';
        default:
            return '';
    }
}

function recurrenceSummaryLine(rec) {
    if (!rec || rec.freq === 'none') {
        return 'No se repite';
    }

    const iv = Math.min(999, Math.max(1, Number(rec.interval) || 1));
    let base = '';

    switch (rec.freq) {
        case 'daily':
            base = iv === 1 ? 'Cada día' : `Cada ${iv} días`;
            break;
        case 'weekly':
            base = iv === 1 ? 'Cada semana' : `Cada ${iv} semanas`;
            if (rec.by_day?.length) {
                const labels = WEEKDAY_OPTIONS.filter((o) => rec.by_day.includes(o.key)).map((o) => o.label).join(', ');
                if (labels) {
                    base += ` · ${labels}`;
                }
            }
            break;
        case 'monthly':
            base = iv === 1 ? 'Cada mes' : `Cada ${iv} meses`;
            break;
        case 'yearly':
            base = iv === 1 ? 'Cada año' : `Cada ${iv} años`;
            break;
        default:
            base = 'Personalizada';
    }

    if (rec.ends === 'count') {
        const c = Math.min(999, Math.max(1, Number(rec.count) || 1));
        base += ` · ${c} ${c === 1 ? 'vez' : 'veces'}`;
    } else if (rec.ends === 'until' && rec.until) {
        base += ` · hasta ${rec.until}`;
    }

    return base;
}

function normalizeRecurrenceForSubmit(rec) {
    if (!rec || rec.freq === 'none') {
        return { freq: 'none' };
    }

    const out = {
        freq: rec.freq,
        interval: Math.min(999, Math.max(1, Number(rec.interval) || 1)),
        by_day: rec.freq === 'weekly' ? [...(rec.by_day || [])] : [],
        ends: rec.ends === 'until' || rec.ends === 'count' ? rec.ends : 'never',
        until: null,
        count: null,
    };

    if (out.ends === 'until') {
        out.until = rec.until || null;
    }

    if (out.ends === 'count') {
        out.count = Math.min(999, Math.max(1, Number(rec.count) || 1));
    }

    return out;
}

function setCreateRecurrenceFreq(freq) {
    const cur = createForm.value.recurrence;

    const next = {
        ...cur,
        freq,
        interval: freq === 'none' ? 1 : Math.min(999, Math.max(1, Number(cur.interval) || 1)),
        ends: freq === 'none' ? 'never' : cur.ends,
    };

    if (freq === 'none') {
        createForm.value.recurrence = defaultCreateRecurrence(createForm.value.start);
        return;
    }

    if (freq === 'weekly' && (!next.by_day || next.by_day.length === 0)) {
        next.by_day = [...weekdayKeysFromDatetimeInput(createForm.value.start)];
    }

    createForm.value.recurrence = next;
}

function toggleCreateRecurrenceWeekday(key) {
    const cur = [...(createForm.value.recurrence.by_day || [])];
    const i = cur.indexOf(key);
    if (i >= 0) {
        cur.splice(i, 1);
    } else {
        cur.push(key);
    }

    const order = WEEKDAY_OPTIONS.map((o) => o.key);
    cur.sort((a, b) => order.indexOf(a) - order.indexOf(b));
    createForm.value.recurrence = {
        ...createForm.value.recurrence,
        by_day: cur,
    };
}

function clearWeeklyDaysForGoogleDefault() {
    createForm.value.recurrence = {
        ...createForm.value.recurrence,
        by_day: [],
    };
}

function clampChatAsideWidth(width) {
    return Math.min(CHAT_ASIDE_MAX_WIDTH, Math.max(CHAT_ASIDE_MIN_WIDTH, Math.round(width)));
}

function readStoredChatAsideWidth() {
    const stored = Number(readJsonLocalStorage(CHAT_ASIDE_WIDTH_STORAGE_KEY));

    if (Number.isFinite(stored)) {
        return clampChatAsideWidth(stored);
    }

    return 320;
}

function readStoredActiveChatId() {
    const stored = Number(readJsonLocalStorage(CHAT_ACTIVE_STORAGE_KEY));

    return Number.isFinite(stored) && stored > 0 ? stored : null;
}

function startChatAsideResize(nativeEvent) {
    if (nativeEvent.button !== 0) {
        return;
    }

    chatAsideResizeStartX = nativeEvent.clientX;
    chatAsideResizeStartWidth = chatAsideWidth.value;
    isResizingChatAside.value = true;
    document.body.style.cursor = 'col-resize';
    document.body.style.userSelect = 'none';

    nativeEvent.preventDefault();
    nativeEvent.stopPropagation();

    window.addEventListener('pointermove', moveChatAsideResize);
    window.addEventListener('pointerup', finishChatAsideResize, { once: true });
    window.addEventListener('pointercancel', finishChatAsideResize, { once: true });
}

function moveChatAsideResize(nativeEvent) {
    if (!isResizingChatAside.value) {
        return;
    }

    const delta = nativeEvent.clientX - chatAsideResizeStartX;
    chatAsideWidth.value = clampChatAsideWidth(chatAsideResizeStartWidth + delta);
    nativeEvent.preventDefault();
}

function finishChatAsideResize() {
    if (!isResizingChatAside.value) {
        return;
    }

    isResizingChatAside.value = false;
    document.body.style.cursor = '';
    document.body.style.userSelect = '';
    writeJsonLocalStorage(CHAT_ASIDE_WIDTH_STORAGE_KEY, chatAsideWidth.value);

    window.removeEventListener('pointermove', moveChatAsideResize);
    window.removeEventListener('pointerup', finishChatAsideResize);
    window.removeEventListener('pointercancel', finishChatAsideResize);
}

function chatSessionFromBackend(chat) {
    const latestMessage = chat.latest_message ?? null;

    return {
        id: Number(chat.id),
        title: chat.title || 'Nuevo chat',
        lastMessageAt: chat.last_message_at || chat.updated_at || chat.created_at || null,
        latestMessageContent: latestMessage?.content || '',
        messagesCount: Number(chat.messages_count ?? 0),
    };
}

function sortedChatSessions(sessions) {
    return [...sessions].sort((a, b) => {
        const aTime = a.lastMessageAt ? new Date(a.lastMessageAt).getTime() : 0;
        const bTime = b.lastMessageAt ? new Date(b.lastMessageAt).getTime() : 0;

        return bTime - aTime || b.id - a.id;
    });
}

function upsertChatSession(chat) {
    const session = chatSessionFromBackend(chat);
    const index = chatSessions.value.findIndex((item) => item.id === session.id);
    const nextSessions = [...chatSessions.value];

    if (index === -1) {
        nextSessions.push(session);
    } else {
        nextSessions[index] = {
            ...nextSessions[index],
            ...session,
        };
    }

    chatSessions.value = sortedChatSessions(nextSessions);

    return session;
}

function setActiveChatId(chatId) {
    activeChatId.value = chatId;
    if (chatId) {
        writeJsonLocalStorage(CHAT_ACTIVE_STORAGE_KEY, chatId);
    }
}

function chatPreview(chat) {
    if (chat.latestMessageContent) {
        return chat.latestMessageContent;
    }

    return chat.messagesCount ? `${chat.messagesCount} mensajes` : 'Chat vacío';
}

function formatChatSessionTime(value) {
    if (!value) {
        return '';
    }

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return '';
    }

    return date.toLocaleDateString('es-ES', {
        day: '2-digit',
        month: 'short',
    });
}

function toggleChatHistoryDropdown() {
    chatHistoryOpen.value = !chatHistoryOpen.value;
}

function closeChatHistoryDropdown() {
    chatHistoryOpen.value = false;
}

function chatScrollDistanceFromBottom() {
    const el = chatMessagesScrollEl.value;
    if (!el) {
        return 0;
    }

    return el.scrollHeight - el.scrollTop - el.clientHeight;
}

function updateChatStickToBottomFromScroll() {
    chatStickToBottom.value = chatScrollDistanceFromBottom() <= CHAT_SCROLL_STICK_THRESHOLD_PX;
}

function scrollChatToBottomIfStuck() {
    if (!chatStickToBottom.value) {
        return;
    }

    nextTick(() => {
        if (!chatStickToBottom.value) {
            return;
        }

        const el = chatMessagesScrollEl.value;
        if (!el) {
            return;
        }

        el.scrollTop = el.scrollHeight;
    });
}

function canRevertMessage(message) {
    return message.role === 'user'
        && message.backendId
        && message.hasCalendarChanges
        && !message.calendarChangesRevertedAt;
}

function applyBackendMessage(payloadMessage) {
    const nextMessage = chatMessageFromBackend(payloadMessage);
    let replaced = false;
    chatMessages.value = chatMessages.value.map((message) => {
        if (message.backendId === nextMessage.backendId || message.id === nextMessage.id) {
            replaced = true;
            return nextMessage;
        }

        if (
            !replaced
            && !message.backendId
            && message.role === nextMessage.role
            && message.text === nextMessage.text
        ) {
            replaced = true;
            return nextMessage;
        }

        if (
            !replaced
            && nextMessage.role === 'user'
            && !message.backendId
            && message.role === 'user'
        ) {
            replaced = true;
            return nextMessage;
        }

        if (
            !replaced
            && nextMessage.role === 'assistant'
            && streamingAssistantMessageId
            && message.id === streamingAssistantMessageId
        ) {
            replaced = true;
            return nextMessage;
        }

        return message;
    });

    if (!replaced) {
        chatMessages.value = [...chatMessages.value, nextMessage];
    }
}

function markUserMessageAsRevertible(backendId = null) {
    if (backendId) {
        const nextMessages = chatMessages.value.map((message) => {
            if (message.role === 'user' && message.backendId === Number(backendId)) {
                return {
                    ...message,
                    hasCalendarChanges: true,
                    calendarChangesRevertedAt: null,
                };
            }

            return message;
        });
        chatMessages.value = nextMessages;

        if (nextMessages.some((message) => message.role === 'user' && message.backendId === Number(backendId) && message.hasCalendarChanges)) {
            return;
        }
    }

    for (let index = chatMessages.value.length - 1; index >= 0; index -= 1) {
        const message = chatMessages.value[index];
        if (message.role !== 'user') {
            continue;
        }

        const nextMessages = [...chatMessages.value];
        nextMessages[index] = {
            ...message,
            backendId: message.backendId ?? (backendId ? Number(backendId) : message.backendId),
            hasCalendarChanges: true,
            calendarChangesRevertedAt: null,
        };
        chatMessages.value = nextMessages;
        return;
    }
}

function markUserMessagesAsReverted(messageIds, fallbackMessageId = null) {
    const ids = new Set(
        messageIds
            .map((id) => Number(id))
            .filter((id) => Number.isFinite(id))
    );

    if (fallbackMessageId) {
        ids.add(Number(fallbackMessageId));
    }

    if (!ids.size) {
        return;
    }

    const revertedAt = new Date().toISOString();
    chatMessages.value = chatMessages.value.map((message) => {
        if (message.role === 'user' && ids.has(Number(message.backendId))) {
            return {
                ...message,
                hasCalendarChanges: true,
                calendarChangesRevertedAt: message.calendarChangesRevertedAt ?? revertedAt,
            };
        }

        return message;
    });
}

function revertMessageChanges(message) {
    const chatId = activeChatId.value;
    if (!chatId || !canRevertMessage(message) || revertingMessageId.value !== null) {
        return;
    }

    revertingMessageId.value = message.id;
    chatAgentStatus.value = {
        type: 'reverting',
        text: 'Revirtiendo cambios...',
    };

    jsonFetch(`/calendar/chats/${encodeURIComponent(chatId)}/messages/${encodeURIComponent(message.backendId)}/revert`, {
        method: 'POST',
        body: JSON.stringify({}),
    })
        .then((payload) => {
            if (Array.isArray(payload.messages)) {
                chatMessages.value = payload.messages.map(chatMessageFromBackend);
            } else if (Array.isArray(payload.user_messages)) {
                payload.user_messages.forEach((payloadMessage) => applyBackendMessage(payloadMessage));
            } else if (payload.user_message) {
                applyBackendMessage(payload.user_message);
            } else {
                markUserMessagesAsReverted(
                    (payload.results ?? []).map((result) => result.message_id),
                    message.backendId
                );
            }

            if (payload.chat) {
                upsertChatSession(payload.chat);
            }

            const actionResults = (payload.results ?? []).flatMap((result) => result.results ?? [result]);
            const failures = actionResults.filter((result) => !result.ok);
            const revertedMessages = payload.user_messages?.length ?? (payload.user_message ? 1 : (payload.messages ? 1 : 0));
            chatAgentStatus.value = {
                type: failures.length ? 'revert_warning' : 'reverted',
                text: failures.length
                    ? `Reversión completada con ${failures.length} cambio(s) fallido(s).`
                    : `Cambios revertidos${revertedMessages > 1 ? ` en ${revertedMessages} mensajes` : ''}.`,
            };
            loadEvents();
        })
        .catch(() => {
            chatAgentStatus.value = {
                type: 'revert_error',
                text: 'No se han podido revertir los cambios.',
            };
        })
        .finally(() => {
            revertingMessageId.value = null;
            window.setTimeout(() => {
                if (chatAgentStatus.value?.type?.startsWith('revert')) {
                    chatAgentStatus.value = null;
                }
            }, 3500);
        });
}

function startEditingMessage(message) {
    if (message.role !== 'user' || chatSending.value || submittingEditedMessageId.value !== null) {
        return;
    }

    editingMessageId.value = message.id;
    editingMessageText.value = message.text;
    nextTick(() => {
        const el = document.querySelector(`[data-chat-editable-message="${message.id}"]`);
        if (el) {
            el.focus();
        }
    });
}

function cancelEditingMessage() {
    editingMessageId.value = null;
    editingMessageText.value = '';
}

function updateEditingMessageText(nativeEvent) {
    editingMessageText.value = nativeEvent.currentTarget?.innerText ?? '';
}

function submitEditedMessage(message) {
    const chatId = activeChatId.value;
    const nextText = editingMessageText.value.trim();
    if (!chatId || !message.backendId || !nextText || submittingEditedMessageId.value !== null) {
        return;
    }

    if (nextText === message.text.trim()) {
        cancelEditingMessage();
        return;
    }

    cancelActiveChatStream();
    submittingEditedMessageId.value = message.id;
    chatSending.value = true;
    chatAgentStatus.value = {
        type: 'editing',
        text: 'Revirtiendo y reenviando...',
    };

    jsonFetch(`/calendar/chats/${encodeURIComponent(chatId)}/messages/${encodeURIComponent(message.backendId)}/edit`, {
        method: 'POST',
        body: JSON.stringify({ message: nextText }),
    })
        .then((payload) => {
            if (Array.isArray(payload.messages)) {
                chatMessages.value = payload.messages.map(chatMessageFromBackend);
            }

            if (payload.chat) {
                upsertChatSession(payload.chat);
            }

            cancelEditingMessage();
            chatStickToBottom.value = true;
            scrollChatToBottomIfStuck();
            loadEvents();
            chatAgentStatus.value = null;
        })
        .catch(() => {
            chatAgentStatus.value = {
                type: 'edit_error',
                text: 'No se ha podido reenviar el mensaje editado.',
            };
        })
        .finally(() => {
            submittingEditedMessageId.value = null;
            chatSending.value = false;
        });
}

function chatMessageFromBackend(message) {
    if (message.role === 'trace') {
        const traceStep = message.trace_step ?? JSON.parse(message.content || '{}');

        return {
            id: `trace-db-${message.id}`,
            backendId: Number(message.id),
            role: 'trace',
            traceStep: {
                id: traceStep.id ?? Number(message.id),
                type: traceStep.type ?? 'trace',
                title: traceStep.title ?? 'Actividad',
                details: traceStep.details ?? '',
                status: traceStep.status ?? 'done',
            },
        };
    }

    return {
        ...makeChatMessage(`db-${message.id}`, message.role, message.content),
        backendId: Number(message.id),
        hasCalendarChanges: Boolean(message.has_calendar_changes),
        calendarChangesRevertedAt: message.calendar_changes_reverted_at ?? null,
        calendarRevertResult: message.calendar_revert_result ?? null,
    };
}

function formattedCalendarDate(value) {
    if (!value) {
        return '';
    }

    const [year, month, day] = String(value).split('-');
    if (!year || !month || !day) {
        return String(value);
    }

    return `${day}/${month}/${year}`;
}

function toolCallStatusText(payload) {
    if (payload?.tool === 'get_calendar_events') {
        const params = payload.params ?? {};
        const days = Number(params.num_days || 1);
        return `Consultando calendario del ${formattedCalendarDate(params.start_date)} · ${days} ${days === 1 ? 'día' : 'días'}`;
    }

    return `Ejecutando ${payload?.tool || 'herramienta'}...`;
}

function toolTraceTitle(payload) {
    const names = {
        get_calendar_events: 'Consulta calendario',
        create_event: 'Crea evento',
        update_event: 'Modifica evento',
        delete_event: 'Elimina evento',
        move_event: 'Mueve evento',
    };

    return names[payload?.tool] ?? `Ejecuta ${payload?.tool || 'herramienta'}`;
}

function resetChatTrace() {
    chatTraceSteps.value = [];
    chatTraceOpen.value = true;
    finalResponseTraceAdded = false;
}

function addChatTraceStep({ type, title, details = '', status = 'done' }) {
    const step = {
        id: nextChatTraceStepId++,
        type,
        title,
        details,
        status,
    };
    chatTraceSteps.value = [
        ...chatTraceSteps.value,
        step,
    ];
    chatMessages.value = [
        ...chatMessages.value,
        {
            id: `trace-${step.id}`,
            role: 'trace',
            traceStep: step,
        },
    ];
    scrollChatToBottomIfStuck();
}

function traceParams(params) {
    if (!params || Object.keys(params).length === 0) {
        return '';
    }

    return prettyJson(params);
}

function cancelActiveChatStream() {
    chatEventSource?.close();
    chatEventSource = null;

    if (assistantTokenFrame !== null) {
        window.cancelAnimationFrame(assistantTokenFrame);
        assistantTokenFrame = null;
    }

    pendingAssistantTokenText = '';
    assistantTokenDonePending = false;
    streamingAssistantMessageId = null;
    chatAgentStatus.value = null;
    chatSending.value = false;
}

function loadChatSessions() {
    loadingChats.value = true;

    return jsonFetch('/calendar/chats', { method: 'GET' })
        .then((payload) => {
            chatSessions.value = sortedChatSessions((payload.chats ?? []).map(chatSessionFromBackend));
            const storedChatId = activeChatId.value;
            const nextActiveChat = chatSessions.value.find((chat) => chat.id === storedChatId)
                ?? chatSessions.value[0]
                ?? null;

            if (nextActiveChat) {
                setActiveChatId(nextActiveChat.id);

                return loadChatMessages(nextActiveChat.id);
            }

            return createChat();
        })
        .finally(() => {
            loadingChats.value = false;
        });
}

function createChat() {
    if (creatingChat.value) {
        return Promise.resolve();
    }

    closeChatHistoryDropdown();
    cancelActiveChatStream();
    resetChatTrace();
    creatingChat.value = true;

    return jsonFetch('/calendar/chats', {
        method: 'POST',
        body: JSON.stringify({}),
    })
        .then((payload) => {
            if (payload.chat) {
                const session = upsertChatSession(payload.chat);
                setActiveChatId(session.id);
                chatMessages.value = (payload.messages ?? []).map(chatMessageFromBackend);
                chatStickToBottom.value = true;
                scrollChatToBottomIfStuck();
            }
        })
        .finally(() => {
            creatingChat.value = false;
        });
}

function loadChatMessages(chatId) {
    if (!chatId) {
        chatMessages.value = [];

        return Promise.resolve();
    }

    loadingChatMessages.value = true;

    return jsonFetch(`/calendar/chats/${encodeURIComponent(chatId)}`, { method: 'GET' })
        .then((payload) => {
            if (activeChatId.value !== chatId) {
                return;
            }

            if (payload.chat) {
                upsertChatSession(payload.chat);
            }

            chatMessages.value = (payload.messages ?? []).map(chatMessageFromBackend);
            chatStickToBottom.value = true;
            scrollChatToBottomIfStuck();
        })
        .finally(() => {
            if (activeChatId.value === chatId) {
                loadingChatMessages.value = false;
            }
        });
}

function selectChat(chatId) {
    if (!chatId || chatId === activeChatId.value) {
        closeChatHistoryDropdown();
        return;
    }

    closeChatHistoryDropdown();
    cancelActiveChatStream();
    setActiveChatId(chatId);
    chatStickToBottom.value = true;
    chatMessages.value = [];
    resetChatTrace();
    loadChatMessages(chatId);
}

function deleteChat(chatId) {
    if (!chatId || deletingChatId.value !== null) {
        return;
    }

    if (!window.confirm('¿Eliminar este chat?')) {
        return;
    }

    if (chatId === activeChatId.value) {
        cancelActiveChatStream();
    }

    deletingChatId.value = chatId;
    jsonFetch(`/calendar/chats/${encodeURIComponent(chatId)}`, { method: 'DELETE' })
        .then(() => {
            const remainingChats = chatSessions.value.filter((chat) => chat.id !== chatId);
            chatSessions.value = remainingChats;

            if (activeChatId.value !== chatId) {
                return;
            }

            const nextChat = remainingChats[0] ?? null;
            if (nextChat) {
                setActiveChatId(nextChat.id);
                chatMessages.value = [];
                loadChatMessages(nextChat.id);
                return;
            }

            setActiveChatId(null);
            chatMessages.value = [];
            createChat();
        })
        .finally(() => {
            deletingChatId.value = null;
        });
}

function fallbackChatRequest(chatId, text) {
    jsonFetch(`/calendar/chats/${encodeURIComponent(chatId)}/messages`, {
        method: 'POST',
        body: JSON.stringify({ message: text }),
    })
        .then((payload) => {
            if (Array.isArray(payload.messages)) {
                chatMessages.value = payload.messages.map(chatMessageFromBackend);
            }

            if (payload.chat) {
                upsertChatSession(payload.chat);
            }

            if (!Array.isArray(payload.messages)) {
                chatMessages.value = [
                    ...chatMessages.value,
                    nextChatMessage('assistant', payload.reply || 'No he podido generar una respuesta.'),
                ];
            }
            loadEvents();
        })
        .catch(() => {
            chatMessages.value = [
                ...chatMessages.value,
                nextChatMessage('assistant', 'Lo he intentado varias veces, pero la IA sigue fallando. Prueba de nuevo en unos segundos.'),
            ];
        })
        .finally(() => {
            chatSending.value = false;
            chatAgentStatus.value = null;
        });
}

function ensureStreamingAssistantMessage() {
    if (streamingAssistantMessageId === null) {
        streamingAssistantMessageId = `local-${nextChatMessageId++}`;
        chatAgentStatus.value = null;
        chatMessages.value = [
            ...chatMessages.value,
            makeChatMessage(streamingAssistantMessageId, 'assistant', ''),
        ];
    }
}

function appendTextToStreamingAssistantMessage(text) {
    ensureStreamingAssistantMessage();
    const messages = chatMessages.value;
    const id = streamingAssistantMessageId;
    let index = messages.length - 1;
    if (index < 0 || messages[index].id !== id) {
        index = messages.findIndex((message) => message.id === id);
    }
    if (index === -1) {
        return;
    }

    const previous = messages[index];
    const nextText = `${previous.text}${text}`;
    messages[index] = {
        ...previous,
        text: nextText,
        html: renderChatMessageHtml(previous.role, nextText),
    };
}

function flushAssistantTokenFrame() {
    assistantTokenFrame = null;

    if (pendingAssistantTokenText) {
        const chunk = pendingAssistantTokenText;
        pendingAssistantTokenText = '';
        appendTextToStreamingAssistantMessage(chunk);
    }

    if (pendingAssistantTokenText) {
        assistantTokenFrame = window.requestAnimationFrame(flushAssistantTokenFrame);
        return;
    }

    if (assistantTokenDonePending) {
        finishAssistantTokenStream({ waitForBuffer: false });
    }
}

function scheduleAssistantTokenFlush() {
    if (assistantTokenFrame === null) {
        assistantTokenFrame = window.requestAnimationFrame(flushAssistantTokenFrame);
    }
}

function appendAssistantToken(token) {
    ensureStreamingAssistantMessage();
    pendingAssistantTokenText += token;
    scheduleAssistantTokenFlush();
}

function finishAssistantTokenStream({ waitForBuffer = true } = {}) {
    if (waitForBuffer && pendingAssistantTokenText) {
        assistantTokenDonePending = true;
        scheduleAssistantTokenFlush();
        return;
    }

    if (assistantTokenFrame !== null) {
        window.cancelAnimationFrame(assistantTokenFrame);
        assistantTokenFrame = null;
    }

    if (pendingAssistantTokenText) {
        appendTextToStreamingAssistantMessage(pendingAssistantTokenText);
    }

    pendingAssistantTokenText = '';
    assistantTokenDonePending = false;
    streamingAssistantMessageId = null;
    chatAgentStatus.value = null;
    chatSending.value = false;
    chatEventSource?.close();
    chatEventSource = null;
}

function startChatStream(chatId, text) {
    resetChatTrace();
    if (!('EventSource' in window) || text.length > 1800) {
        addChatTraceStep({
            type: 'fallback',
            title: 'Envío sin streaming',
            details: text.length > 1800 ? 'Mensaje largo: usando petición normal.' : 'EventSource no disponible.',
            status: 'running',
        });
        fallbackChatRequest(chatId, text);
        return;
    }

    chatEventSource?.close();
    let completed = false;
    chatEventSource = new EventSource(`/calendar/chats/${encodeURIComponent(chatId)}/stream?message=${encodeURIComponent(text)}`);

    chatEventSource.addEventListener('thinking', () => {
        chatAgentStatus.value = {
            type: 'thinking',
            text: 'Pensando...',
        };
        addChatTraceStep({
            type: 'thinking',
            title: chatTraceSteps.value.length ? 'Evalúa siguiente paso' : 'Preparando respuesta',
            details: chatTraceSteps.value.length
                ? 'Revisando el resultado anterior para decidir si necesita otra herramienta o responder.'
                : 'Analizando la petición y decidiendo si necesita usar herramientas.',
            status: 'running',
        });
    });

    chatEventSource.addEventListener('retry', (event) => {
        const payload = JSON.parse(event.data || '{}');
        const message = payload.message || 'La IA ha fallado temporalmente. Reintentando...';
        chatAgentStatus.value = {
            type: 'retry',
            text: message,
        };
        addChatTraceStep({
            type: 'retry',
            title: 'Reintentando IA',
            details: message,
            status: 'running',
        });
    });

    chatEventSource.addEventListener('tool_call', (event) => {
        const payload = JSON.parse(event.data || '{}');
        chatAgentStatus.value = {
            type: 'tool_call',
            text: toolCallStatusText(payload),
        };
        if (MUTATING_CALENDAR_TOOLS.has(payload.tool)) {
            markUserMessageAsRevertible(payload.message_id);
        }
        applyAgentMoveToolCall(payload);
        addChatTraceStep({
            type: 'tool_call',
            title: toolTraceTitle(payload),
            details: traceParams(payload.params ?? {}),
            status: 'running',
        });
    });

    chatEventSource.addEventListener('tool_result', (event) => {
        const payload = JSON.parse(event.data || '{}');
        if (payload.tool === 'move_event') {
            finishAgentMoveToolResult(payload);
        }
        chatAgentStatus.value = {
            type: 'tool_result',
            text: payload.message || 'Herramienta ejecutada.',
        };
        addChatTraceStep({
            type: 'tool_result',
            title: 'Resultado de herramienta',
            details: payload.message || 'Herramienta ejecutada.',
            status: 'done',
        });
    });

    chatEventSource.addEventListener('token', (event) => {
        const payload = JSON.parse(event.data || '{}');
        if (payload.text) {
            if (!finalResponseTraceAdded) {
                finalResponseTraceAdded = true;
                addChatTraceStep({
                    type: 'final_response',
                    title: 'Redactando respuesta final',
                    details: 'Mostrando la respuesta en streaming.',
                    status: 'running',
                });
            }
            appendAssistantToken(payload.text);
        }
    });

    chatEventSource.addEventListener('user_message', (event) => {
        const payload = JSON.parse(event.data || '{}');
        if (payload.message) {
            applyBackendMessage(payload.message);
        }
    });

    chatEventSource.addEventListener('assistant_message', (event) => {
        const payload = JSON.parse(event.data || '{}');
        if (payload.message) {
            applyBackendMessage(payload.message);
        }
    });

    chatEventSource.addEventListener('done', () => {
        completed = true;
        addChatTraceStep({
            type: 'done',
            title: 'Respuesta completada',
            details: 'La ejecución del agente ha terminado.',
            status: 'done',
        });
        finishAssistantTokenStream();
        loadChatMessages(chatId);
        loadEvents();
    });

    chatEventSource.addEventListener('chat', (event) => {
        const payload = JSON.parse(event.data || '{}');
        if (payload.chat) {
            upsertChatSession(payload.chat);
        }
        if (payload.user_message) {
            applyBackendMessage(payload.user_message);
        }
    });

    chatEventSource.addEventListener('final_response', (event) => {
        const payload = JSON.parse(event.data || '{}');
        completed = true;
        appendAssistantToken(payload.text || 'No he podido generar una respuesta.');
        addChatTraceStep({
            type: 'final_response',
            title: 'Respuesta final recibida',
            details: 'El modelo devolvió una respuesta final sin más herramientas.',
            status: 'done',
        });
        finishAssistantTokenStream();
        loadChatMessages(chatId);
        loadEvents();
    });

    chatEventSource.onerror = () => {
        chatEventSource?.close();
        chatEventSource = null;
        if (!completed) {
            pendingAssistantTokenText = '';
            assistantTokenDonePending = false;
            if (assistantTokenFrame !== null) {
                window.cancelAnimationFrame(assistantTokenFrame);
                assistantTokenFrame = null;
            }
            streamingAssistantMessageId = null;
            fallbackChatRequest(chatId, text);
        }
    };
}

function sendChatMessage() {
    const text = chatDraft.value.trim();
    const chatId = activeChatId.value;
    if (!text || chatSending.value || !chatId) {
        return;
    }

    chatMessages.value = [
        ...chatMessages.value,
        nextChatMessage('user', text),
    ];
    chatDraft.value = '';
    chatSending.value = true;
    chatAgentStatus.value = {
        type: 'thinking',
        text: 'Pensando...',
    };

    startChatStream(chatId, text);
}

watch(chatMessages, () => scrollChatToBottomIfStuck(), { deep: true, flush: 'post' });
watch(chatAgentStatus, () => scrollChatToBottomIfStuck(), { flush: 'post' });

const createForm = ref({
    title: '',
    start: '',
    end: '',
    all_day: false,
    recurrence: defaultCreateRecurrence(''),
    color_id: '9',
});
const creatingEvent = ref(false);
let savedTickTimeout = null;
let pollInterval = null;
let createDay = null;
let createDayElement = null;

const calendarScrollEl = ref(null);
const calendarStickyHeaderEl = ref(null);
const calendarHourScrollAnchorEl = ref(null);
let calendarHeaderResizeObs = null;
let resizeAlignRaf = null;

/**
 * Coloca la hora inicial (INITIAL_SCROLL_HOUR) justo debajo del header sticky real,
 * midiendo posiciones en viewport (incluye bordes, altura variable, eventos todo el día).
 */
function scrollCalendarHourIntoView() {
    const scrollEl = calendarScrollEl.value;
    const stickyEl = calendarStickyHeaderEl.value;
    const anchorEl = calendarHourScrollAnchorEl.value;

    if (!scrollEl || !stickyEl || !anchorEl) {
        return;
    }

    const stickyBottom = stickyEl.getBoundingClientRect().bottom;
    const anchorTop = anchorEl.getBoundingClientRect().top;
    const delta = anchorTop - stickyBottom;

    if (Math.abs(delta) < 0.5) {
        return;
    }

    const next = scrollEl.scrollTop + delta;
    const maxScroll = Math.max(0, scrollEl.scrollHeight - scrollEl.clientHeight);

    scrollEl.scrollTop = Math.min(Math.max(0, next), maxScroll);
}

function scheduleScrollCalendarHourResizeAlign() {
    if (resizeAlignRaf !== null) {
        window.cancelAnimationFrame(resizeAlignRaf);
    }

    resizeAlignRaf = window.requestAnimationFrame(() => {
        resizeAlignRaf = null;
        scrollCalendarHourIntoView();
    });
}

const fallbackEventColors = {
    1: { background: '#a4bdfc', foreground: '#1d1d1d' },
    2: { background: '#7ae7bf', foreground: '#1d1d1d' },
    3: { background: '#dbadff', foreground: '#1d1d1d' },
    4: { background: '#ff887c', foreground: '#1d1d1d' },
    5: { background: '#fbd75b', foreground: '#1d1d1d' },
    6: { background: '#ffb878', foreground: '#1d1d1d' },
    7: { background: '#46d6db', foreground: '#1d1d1d' },
    8: { background: '#e1e1e1', foreground: '#1d1d1d' },
    9: { background: '#5484ed', foreground: '#ffffff' },
    10: { background: '#51b749', foreground: '#ffffff' },
    11: { background: '#dc2127', foreground: '#ffffff' },
};

watch(() => props.events, (events) => {
    localEvents.value = readStoredEvents() ?? [...events];
});

watch(localEvents, (events) => {
    writeJsonLocalStorage(eventsStorageKey(), events);
}, { deep: true });

function toDate(value) {
    if (!value) {
        return null;
    }

    if (/^\d{4}-\d{2}-\d{2}$/.test(value)) {
        return new Date(`${value}T00:00:00`);
    }

    return new Date(value);
}

function formatLocalDate(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${year}-${month}-${day}`;
}

function formatLocalDateTime(date) {
    const hours = String(date.getHours()).padStart(2, '0');
    const minutes = String(date.getMinutes()).padStart(2, '0');
    const seconds = String(date.getSeconds()).padStart(2, '0');

    return `${formatLocalDate(date)}T${hours}:${minutes}:${seconds}`;
}

function formatInputDateTime(date) {
    const hours = String(date.getHours()).padStart(2, '0');
    const minutes = String(date.getMinutes()).padStart(2, '0');

    return `${formatLocalDate(date)}T${hours}:${minutes}`;
}

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

function readJsonLocalStorage(key) {
    try {
        const value = window.localStorage.getItem(key);
        return value ? JSON.parse(value) : null;
    } catch {
        return null;
    }
}

function writeJsonLocalStorage(key, value) {
    try {
        window.localStorage.setItem(key, JSON.stringify(value));
    } catch {
        // Ignore storage quota/private mode failures; live fetches still work.
    }
}

function eventsStorageKey() {
    return `${EVENTS_STORAGE_PREFIX}.${props.weekStart}`;
}

function readStoredEvents() {
    return readJsonLocalStorage(eventsStorageKey());
}

function readStoredColors() {
    return readJsonLocalStorage(COLORS_STORAGE_KEY);
}

function startOfWeek(date) {
    const d = new Date(date);
    d.setHours(0, 0, 0, 0);
    const day = (d.getDay() + 6) % 7;
    d.setDate(d.getDate() - day);
    return d;
}

const weekStart = computed(() => {
    return toDate(props.weekStart) ?? startOfWeek(new Date());
});

const weekEnd = computed(() => {
    const fromProps = toDate(props.weekEnd);
    if (fromProps) {
        fromProps.setHours(23, 59, 59, 999);
        return fromProps;
    }

    const fallback = new Date(weekStart.value);
    fallback.setDate(fallback.getDate() + 6);
    fallback.setHours(23, 59, 59, 999);
    return fallback;
});

const weekLabel = computed(() => {
    const start = weekStart.value.toLocaleDateString('es-ES', { day: '2-digit', month: 'short', year: 'numeric' });
    const end = weekEnd.value.toLocaleDateString('es-ES', { day: '2-digit', month: 'short', year: 'numeric' });
    return `${start} - ${end}`;
});

const weekDays = computed(() => {
    return Array.from({ length: 7 }, (_, index) => {
        const d = new Date(weekStart.value);
        d.setDate(d.getDate() + index);
        d.setHours(0, 0, 0, 0);
        return {
            date: d,
            key: formatLocalDate(d),
            weekday: d.toLocaleDateString('es-ES', { weekday: 'short' }),
            dayNumber: d.toLocaleDateString('es-ES', { day: '2-digit' }),
            monthNumber: d.toLocaleDateString('es-ES', { month: '2-digit' }),
        };
    });
});

const hourSlots = computed(() => {
    return Array.from({ length: DAY_END_HOUR - DAY_START_HOUR }, (_, index) => {
        const hour = DAY_START_HOUR + index;

        return {
            hour,
            label: `${String(hour).padStart(2, '0')}:00`,
        };
    });
});

const normalizedEvents = computed(() => {
    return localEvents.value.map((event) => {
        const startDate = toDate(event.start);
        const endDate = toDate(event.end) ?? startDate;
        const visualEndDate = event.is_all_day && endDate
            ? new Date(endDate.getTime() - 1)
            : endDate;

        return {
            ...event,
            startDate,
            endDate,
            visualEndDate,
        };
    }).filter((event) => event.startDate instanceof Date && !Number.isNaN(event.startDate.getTime()));
});

const weeklyEventsByDay = computed(() => {
    return weekDays.value.map((day) => {
        const dayStart = new Date(day.date);
        dayStart.setHours(0, 0, 0, 0);
        const dayEnd = new Date(day.date);
        dayEnd.setHours(23, 59, 59, 999);

        const events = normalizedEvents.value
            .filter((event) => {
                const eventEnd = event.visualEndDate ?? event.endDate ?? event.startDate;
                return event.startDate <= dayEnd && eventEnd >= dayStart;
            })
            .sort((a, b) => a.startDate - b.startDate);

        return {
            ...day,
            allDayEvents: events.filter((event) => event.is_all_day),
            timedEvents: events.filter((event) => !event.is_all_day),
        };
    });
});

const eventColorOptions = computed(() => {
    const colors = Object.keys(loadedEventColors.value).length ? loadedEventColors.value : fallbackEventColors;

    return Object.entries(colors)
        .sort(([a], [b]) => Number(a) - Number(b))
        .map(([id, color]) => ({
            id,
            background: color.background,
            foreground: color.foreground,
        }));
});

function prevWeek() {
    goToWeek(-7);
}

function nextWeek() {
    goToWeek(7);
}

function currentWeek() {
    router.get('/calendar', {}, { preserveScroll: true });
}

function goToWeek(dayDelta) {
    const next = new Date(weekStart.value);
    next.setDate(next.getDate() + dayDelta);
    router.get('/calendar', { week_start: formatLocalDate(next) }, { preserveScroll: true });
}

function eventTimeLabel(event) {
    if (!event.startDate) {
        return '';
    }

    const timeOptions = { hour: '2-digit', minute: '2-digit', hour12: false };
    const start = event.startDate.toLocaleTimeString('es-ES', timeOptions);
    const end = event.endDate
        ? event.endDate.toLocaleTimeString('es-ES', timeOptions)
        : '';

    return end ? `${start} - ${end}` : start;
}

function eventDisplayTimeLabel(event) {
    if (resizePreview.value?.eventId !== event.id) {
        return eventTimeLabel(event);
    }

    const previewEvent = {
        ...event,
        endDate: resizePreview.value.end,
    };

    return eventTimeLabel(previewEvent);
}

function timedEventStyle(event, day) {
    const dayStart = new Date(day.date);
    dayStart.setHours(DAY_START_HOUR, 0, 0, 0);
    const dayEnd = new Date(day.date);
    dayEnd.setHours(DAY_END_HOUR, 0, 0, 0);

    const start = new Date(Math.max(event.startDate.getTime(), dayStart.getTime()));
    const end = new Date(Math.min((event.endDate ?? event.startDate).getTime(), dayEnd.getTime()));
    const startMinutes = Math.max(0, (start - dayStart) / 60000);
    const durationMinutes = Math.max(30, (end - start) / 60000);

    return {
        top: `${(startMinutes / 60) * HOUR_HEIGHT}px`,
        height: `${(durationMinutes / 60) * HOUR_HEIGHT}px`,
    };
}

function googleColor(event, fallbackBackground = '#4285f4', fallbackForeground = '#ffffff') {
    if (event.color?.background) {
        return {
            background: event.color.background,
            foreground: event.color.foreground || fallbackForeground,
        };
    }

    if (event.color_id && loadedEventColors.value[event.color_id]) {
        return loadedEventColors.value[event.color_id];
    }

    return {
        background: defaultEventColor.value.background || fallbackBackground,
        foreground: defaultEventColor.value.foreground || fallbackForeground,
    };
}

function calendarEventStyle(event, day) {
    const color = googleColor(event);
    const style = timedEventStyle(event, day);

    if (resizePreview.value?.eventId === event.id && resizePreview.value.dayKey === day.key) {
        style.height = `${resizePreview.value.height}px`;
    }

    return {
        ...style,
        backgroundColor: color.background,
        borderColor: color.background,
        color: color.foreground,
        boxShadow: `0 12px 30px ${color.background}33`,
    };
}

function draggedEventDurationMinutes(event) {
    if (! event?.startDate || ! event?.endDate) {
        return 30;
    }

    return Math.max(30, Math.round((event.endDate - event.startDate) / 60000));
}

function minutesFromPointer(event, dayElement) {
    const rect = dayElement.getBoundingClientRect();
    const y = Math.min(Math.max(event.clientY - rect.top, 0), gridHeight);
    const rawMinutes = (y / HOUR_HEIGHT) * 60;

    return Math.min(Math.round(rawMinutes), (DAY_END_HOUR - DAY_START_HOUR) * 60);
}

function snapMinutes(minutes, shouldSnap = true) {
    if (!shouldSnap) {
        return minutes;
    }

    return Math.round(minutes / SNAP_MINUTES) * SNAP_MINUTES;
}

function dateAtMinutes(day, minutes) {
    const date = new Date(day.date);
    date.setHours(DAY_START_HOUR, 0, 0, 0);
    date.setMinutes(date.getMinutes() + minutes);

    return date;
}

function dragPreviewStyle(day) {
    if (! dropPreview.value || dropPreview.value.dayKey !== day.key) {
        return { display: 'none' };
    }

    return {
        top: `${dropPreview.value.top}px`,
        height: `${dropPreview.value.height}px`,
    };
}

function dropPreviewLabel() {
    if (! dropPreview.value || ! draggedEvent.value) {
        return '';
    }

    const start = dropPreview.value.start;
    const end = new Date(start);
    end.setMinutes(end.getMinutes() + draggedEventDurationMinutes(draggedEvent.value));

    const timeOptions = { hour: '2-digit', minute: '2-digit', hour12: false };
    return `${start.toLocaleTimeString('es-ES', timeOptions)} - ${end.toLocaleTimeString('es-ES', timeOptions)}`;
}

function dropPreviewColor() {
    return googleColor(draggedEvent.value ?? {});
}

function replaceLocalEvent(eventId, changes) {
    localEvents.value = localEvents.value.map((event) => {
        if (event.id !== eventId) {
            return event;
        }

        return {
            ...event,
            ...changes,
        };
    });
}

function replaceLocalRecurringEvent(event, scope, changes) {
    const baseStart = toDate(event.start);
    const nextStart = changes.start ? toDate(changes.start) : null;
    const nextEnd = changes.end ? toDate(changes.end) : null;
    const startDeltaMs = baseStart && nextStart ? nextStart.getTime() - baseStart.getTime() : 0;
    const nextDurationMs = nextStart && nextEnd ? nextEnd.getTime() - nextStart.getTime() : null;

    localEvents.value = localEvents.value.map((item) => {
        if (!isEventAffectedByScope(item, event, scope)) {
            return item;
        }

        if ((changes.start || changes.end) && item.start) {
            const itemStart = toDate(item.start);
            if (itemStart && nextDurationMs !== null) {
                const shiftedStart = new Date(itemStart.getTime() + startDeltaMs);
                const shiftedEnd = new Date(shiftedStart.getTime() + nextDurationMs);

                return {
                    ...item,
                    ...changes,
                    start: formatLocalDateTime(shiftedStart),
                    end: formatLocalDateTime(shiftedEnd),
                };
            }
        }

        return {
            ...item,
            ...changes,
        };
    });
}

function removeLocalEvent(eventId) {
    localEvents.value = localEvents.value.filter((event) => event.id !== eventId);
}

function removeLocalRecurringEvent(event, scope) {
    localEvents.value = localEvents.value.filter((item) => {
        return !isEventAffectedByScope(item, event, scope);
    });
}

function eventOccurrenceDate(event) {
    return toDate(event.original_start ?? event.start);
}

function isEventAffectedByScope(item, sourceEvent, scope) {
    if (item.id === sourceEvent.id || scope === 'this') {
        return item.id === sourceEvent.id;
    }

    if (!sourceEvent.recurring_event_id || item.recurring_event_id !== sourceEvent.recurring_event_id) {
        return false;
    }

    if (scope === 'all') {
        return true;
    }

    if (scope === 'this_and_following') {
        const itemDate = eventOccurrenceDate(item);
        const sourceDate = eventOccurrenceDate(sourceEvent);

        return Boolean(itemDate && sourceDate && itemDate >= sourceDate);
    }

    return false;
}

function affectedEventIds(event, scope) {
    return localEvents.value
        .filter((item) => isEventAffectedByScope(item, event, scope))
        .map((item) => item.id);
}

function setSavingEvents(event, scope) {
    const ids = affectedEventIds(event, scope);
    savingEventIds.value = ids.reduce((acc, id) => ({ ...acc, [id]: true }), {});
    savingEventId.value = event.id;
}

function clearSavingEvents() {
    savingEventIds.value = {};
    savingEventId.value = null;
}

function isEventSaving(event) {
    return Boolean(savingEventIds.value[event.id] || savingEventId.value === event.id);
}

function applyAgentMoveToolCall(payload) {
    if (payload?.tool !== 'move_event') {
        return;
    }

    const eventId = String(payload.params?.event_id ?? '');
    const newStartRaw = String(payload.params?.new_start ?? '');
    const event = localEvents.value.find((item) => item.id === eventId);
    const currentStart = toDate(event?.start);
    const currentEnd = toDate(event?.end);
    const nextStart = toDate(newStartRaw);

    if (!event || !currentStart || !currentEnd || !nextStart) {
        pendingAgentMove = null;
        return;
    }

    const durationMs = Math.max(60000, currentEnd.getTime() - currentStart.getTime());
    const nextEnd = new Date(nextStart.getTime() + durationMs);

    pendingAgentMove = {
        eventId,
        previousStart: event.start,
        previousEnd: event.end,
    };

    replaceLocalEvent(eventId, {
        start: formatLocalDateTime(nextStart),
        end: formatLocalDateTime(nextEnd),
        original_start: formatLocalDateTime(nextStart),
    });
    setSavingEvents({ ...event, id: eventId }, 'this');
    savedEventId.value = null;
}

function finishAgentMoveToolResult(payload) {
    if (!pendingAgentMove) {
        return;
    }

    const move = pendingAgentMove;
    pendingAgentMove = null;
    clearSavingEvents();

    if (payload?.ok !== false) {
        markSaved(move.eventId, 'this');
        return;
    }

    replaceLocalEvent(move.eventId, {
        start: move.previousStart,
        end: move.previousEnd,
        original_start: move.previousStart,
    });
}

function eventColorById(colorId) {
    return loadedEventColors.value[colorId] ?? fallbackEventColors[colorId] ?? googleColor({});
}

function showContextMenu(event, nativeEvent) {
    nativeEvent.preventDefault();
    nativeEvent.stopPropagation();

    contextMenu.value = {
        event,
        x: nativeEvent.clientX,
        y: nativeEvent.clientY,
    };
}

function closeContextMenu() {
    contextMenu.value = null;
}

function withRecurringScope(event, apply) {
    if (!event.is_recurring || !event.recurring_event_id) {
        apply('this');
        return;
    }

    recurringPrompt.value = { event, apply };
}

function chooseRecurringScope(scope) {
    const prompt = recurringPrompt.value;
    recurringPrompt.value = null;

    if (prompt) {
        prompt.apply(scope);
    }
}

function closeRecurringPrompt() {
    recurringPrompt.value = null;
}

function markSaved(eventId, scope = 'this') {
    const event = localEvents.value.find((item) => item.id === eventId);
    const ids = event ? affectedEventIds(event, scope) : [eventId];

    savedEventId.value = eventId;
    savedEventIds.value = ids.reduce((acc, id) => ({ ...acc, [id]: true }), {});

    if (savedTickTimeout) {
        window.clearTimeout(savedTickTimeout);
    }

    savedTickTimeout = window.setTimeout(() => {
        savedEventId.value = null;
        savedEventIds.value = {};
        savedTickTimeout = null;
    }, 700);
}

function isEventSaved(event) {
    return Boolean(savedEventIds.value[event.id] || savedEventId.value === event.id);
}

function jsonFetch(url, options = {}) {
    return fetch(url, {
        credentials: 'same-origin',
        ...options,
        headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
            ...(options.headers ?? {}),
        },
    }).then((response) => {
        if (!response.ok) {
            throw new Error('No se pudo completar la accion.');
        }

        return response.json();
    });
}

function loadEventColors() {
    jsonFetch('/calendar/colors', { method: 'GET' })
        .then((payload) => {
            loadedEventColors.value = payload.event_colors ?? {};
            defaultEventColor.value = payload.default_event_color ?? defaultEventColor.value;
            writeJsonLocalStorage(COLORS_STORAGE_KEY, {
                event_colors: loadedEventColors.value,
                default_event_color: defaultEventColor.value,
            });
        })
        .catch(() => {
            loadedEventColors.value = fallbackEventColors;
        });
}

function loadEvents() {
    if (draggedEvent.value || resizingEvent.value || savingEventId.value) {
        return;
    }

    jsonFetch(`/calendar/events?week_start=${encodeURIComponent(props.weekStart)}`, { method: 'GET' })
        .then((payload) => {
            if (Array.isArray(payload.events)) {
                localEvents.value = payload.events;
                writeJsonLocalStorage(eventsStorageKey(), payload.events);
            }
        })
        .catch(() => {
            // Keep localStorage/optimistic state if Google is temporarily unavailable.
        });
}

onMounted(() => {
    writeJsonLocalStorage(eventsStorageKey(), localEvents.value);
    loadEventColors();
    loadEvents();
    loadChatSessions();
    pollInterval = window.setInterval(() => {
        loadEvents();
        loadEventColors();
    }, POLL_MS);

    nextTick(() => scrollCalendarAfterLayout());
});

function scrollCalendarAfterLayout() {
    scrollCalendarHourIntoView();

    window.requestAnimationFrame(() => {
        scrollCalendarHourIntoView();

        window.requestAnimationFrame(() => {
            calendarHeaderResizeObs?.disconnect();
            calendarHeaderResizeObs = null;

            const headerEl = calendarStickyHeaderEl.value;
            if (typeof ResizeObserver !== 'undefined' && headerEl) {
                calendarHeaderResizeObs = new ResizeObserver(() => scheduleScrollCalendarHourResizeAlign());
                calendarHeaderResizeObs.observe(headerEl);
            }
        });
    });
}

function persistEventTime(event, previousStart, previousEnd, nextStart, nextEnd, recurringScope = 'this') {
    setSavingEvents(event, recurringScope);
    savedEventId.value = null;

    jsonFetch(`/calendar/events/${encodeURIComponent(event.id)}`, {
        method: 'PATCH',
        body: JSON.stringify({
            start: nextStart,
            end: nextEnd,
            week_start: props.weekStart,
            recurring_scope: recurringScope,
            recurring_event_id: event.recurring_event_id,
            original_start: event.original_start ?? event.start,
        }),
    })
        .then(() => {
            markSaved(event.id, recurringScope);
        })
        .catch(() => {
            replaceLocalRecurringEvent(event, recurringScope, {
                start: previousStart,
                end: previousEnd,
            });
        })
        .finally(() => {
            clearSavingEvents();
        });
}

function setEventColor(event, colorId) {
    closeContextMenu();
    withRecurringScope(event, (recurringScope) => {
        const previousEvents = [...localEvents.value];
        const nextColor = eventColorById(colorId);

        replaceLocalRecurringEvent(event, recurringScope, {
            color_id: colorId,
            color: nextColor,
        });
        setSavingEvents(event, recurringScope);
        savedEventId.value = null;

        jsonFetch(`/calendar/events/${encodeURIComponent(event.id)}/color`, {
            method: 'PATCH',
            body: JSON.stringify({
                color_id: colorId,
                recurring_scope: recurringScope,
                recurring_event_id: event.recurring_event_id,
                start: event.start,
                end: event.end,
                original_start: event.original_start ?? event.start,
            }),
        })
            .then(() => {
            markSaved(event.id, recurringScope);
            })
            .catch(() => {
                localEvents.value = previousEvents;
            })
            .finally(() => {
                clearSavingEvents();
            });
    });
}

function deleteEvent(event) {
    closeContextMenu();
    withRecurringScope(event, (recurringScope) => {
        const previousEvents = [...localEvents.value];

        removeLocalRecurringEvent(event, recurringScope);

        jsonFetch(`/calendar/events/${encodeURIComponent(event.id)}`, {
            method: 'DELETE',
            body: JSON.stringify({
                recurring_scope: recurringScope,
                recurring_event_id: event.recurring_event_id,
                original_start: event.original_start ?? event.start,
            }),
        }).catch(() => {
            localEvents.value = previousEvents;
        });
    });
}

function dayTargetFromPointer(event) {
    const dayElement = document
        .elementsFromPoint(event.clientX, event.clientY)
        .find((element) => element.dataset?.calendarDay);

    if (!dayElement) {
        return null;
    }

    const day = weeklyEventsByDay.value.find((item) => item.key === dayElement.dataset.calendarDay);
    if (!day) {
        return null;
    }

    return { day, dayElement };
}

function updateDropPreviewFromPoint(point, day, dayElement) {
    if (! draggedEvent.value) {
        return;
    }

    const durationMinutes = draggedEventDurationMinutes(draggedEvent.value);
    const maxStartMinutes = Math.max(0, ((DAY_END_HOUR - DAY_START_HOUR) * 60) - durationMinutes);
    const rawStartMinutes = Math.max(minutesFromPointer(point, dayElement) - dragOffsetMinutes.value, 0);
    const startMinutes = Math.min(snapMinutes(rawStartMinutes, !point.shiftKey), maxStartMinutes);

    dropPreview.value = {
        dayKey: day.key,
        top: `${(startMinutes / 60) * HOUR_HEIGHT}`,
        height: `${(durationMinutes / 60) * HOUR_HEIGHT}`,
        start: dateAtMinutes(day, startMinutes),
    };
}

function flushPointerDrag() {
    dragFrame = null;

    if (! pendingPointer || ! draggedEvent.value) {
        return;
    }

    const target = dayTargetFromPointer(pendingPointer);
    if (!target) {
        return;
    }

    updateDropPreviewFromPoint(pendingPointer, target.day, target.dayElement);
}

function schedulePointerDrag(nativeEvent) {
    pendingPointer = {
        clientX: nativeEvent.clientX,
        clientY: nativeEvent.clientY,
        shiftKey: nativeEvent.shiftKey,
    };

    if (dragFrame !== null) {
        return;
    }

    dragFrame = window.requestAnimationFrame(flushPointerDrag);
}

function startPointerDrag(event, nativeEvent) {
    if (
        event.is_all_day
        || savingEventId.value
        || nativeEvent.button !== 0
        || nativeEvent.target.closest('a, button, pre')
    ) {
        nativeEvent.preventDefault();
        return;
    }

    const rect = nativeEvent.currentTarget.getBoundingClientRect();
    const y = Math.min(Math.max(nativeEvent.clientY - rect.top, 0), rect.height);

    draggedEvent.value = event;
    dragOffsetMinutes.value = (y / HOUR_HEIGHT) * 60;
    nativeEvent.currentTarget.setPointerCapture?.(nativeEvent.pointerId);
    nativeEvent.preventDefault();

    window.addEventListener('pointermove', movePointerDrag);
    window.addEventListener('pointerup', finishPointerDrag, { once: true });
    window.addEventListener('pointercancel', cancelPointerDrag, { once: true });

    schedulePointerDrag(nativeEvent);
}

function movePointerDrag(nativeEvent) {
    if (! draggedEvent.value) {
        return;
    }

    nativeEvent.preventDefault();
    schedulePointerDrag(nativeEvent);
}

function finishPointerDrag(nativeEvent) {
    window.removeEventListener('pointermove', movePointerDrag);
    window.removeEventListener('pointercancel', cancelPointerDrag);

    if (nativeEvent) {
        pendingPointer = {
            clientX: nativeEvent.clientX,
            clientY: nativeEvent.clientY,
            shiftKey: nativeEvent.shiftKey,
        };
        flushPointerDrag();
    }

    if (! draggedEvent.value) {
        cancelPointerDrag();
        return;
    }

    const event = draggedEvent.value;
    const start = dropPreview.value?.start;
    if (! start) {
        cancelPointerDrag();
        return;
    }

    const end = new Date(start);
    end.setMinutes(end.getMinutes() + draggedEventDurationMinutes(event));
    const previousStart = event.start;
    const previousEnd = event.end;
    const nextStart = formatLocalDateTime(start);
    const nextEnd = formatLocalDateTime(end);

    cancelPointerDrag();
    withRecurringScope(event, (recurringScope) => {
        replaceLocalRecurringEvent(event, recurringScope, {
            start: nextStart,
            end: nextEnd,
        });
        persistEventTime(event, previousStart, previousEnd, nextStart, nextEnd, recurringScope);
    });
}

function cancelPointerDrag() {
    window.removeEventListener('pointermove', movePointerDrag);
    window.removeEventListener('pointerup', finishPointerDrag);
    window.removeEventListener('pointercancel', cancelPointerDrag);

    if (dragFrame !== null) {
        window.cancelAnimationFrame(dragFrame);
        dragFrame = null;
    }

    pendingPointer = null;
    draggedEvent.value = null;
    dropPreview.value = null;
    dragOffsetMinutes.value = 0;
}

function resizePointerToEnd(point) {
    if (!resizingEvent.value || !resizeDay || !resizeDayElement) {
        return;
    }

    const dayStart = new Date(resizeDay.date);
    dayStart.setHours(DAY_START_HOUR, 0, 0, 0);
    const startMinutes = Math.max(0, Math.round((resizingEvent.value.startDate - dayStart) / 60000));
    const rawEndMinutes = minutesFromPointer(point, resizeDayElement);
    const minEndMinutes = startMinutes + SNAP_MINUTES;
    const maxEndMinutes = (DAY_END_HOUR - DAY_START_HOUR) * 60;
    const endMinutes = Math.min(
        Math.max(snapMinutes(rawEndMinutes, !point.shiftKey), minEndMinutes),
        maxEndMinutes,
    );

    resizePreview.value = {
        eventId: resizingEvent.value.id,
        dayKey: resizeDay.key,
        height: ((endMinutes - startMinutes) / 60) * HOUR_HEIGHT,
        end: dateAtMinutes(resizeDay, endMinutes),
    };
}

function flushResize() {
    resizeFrame = null;

    if (!pendingResizePointer || !resizingEvent.value) {
        return;
    }

    resizePointerToEnd(pendingResizePointer);
}

function scheduleResize(nativeEvent) {
    pendingResizePointer = {
        clientX: nativeEvent.clientX,
        clientY: nativeEvent.clientY,
        shiftKey: nativeEvent.shiftKey,
    };

    if (resizeFrame !== null) {
        return;
    }

    resizeFrame = window.requestAnimationFrame(flushResize);
}

function startResize(event, day, nativeEvent) {
    if (event.is_all_day || savingEventId.value || nativeEvent.button !== 0) {
        nativeEvent.preventDefault();
        return;
    }

    resizingEvent.value = event;
    resizeDay = day;
    resizeDayElement = nativeEvent.currentTarget.closest('[data-calendar-day]');
    nativeEvent.currentTarget.setPointerCapture?.(nativeEvent.pointerId);
    nativeEvent.preventDefault();
    nativeEvent.stopPropagation();

    window.addEventListener('pointermove', moveResize);
    window.addEventListener('pointerup', finishResize, { once: true });
    window.addEventListener('pointercancel', cancelResize, { once: true });

    scheduleResize(nativeEvent);
}

function moveResize(nativeEvent) {
    if (!resizingEvent.value) {
        return;
    }

    nativeEvent.preventDefault();
    scheduleResize(nativeEvent);
}

function finishResize(nativeEvent) {
    window.removeEventListener('pointermove', moveResize);
    window.removeEventListener('pointercancel', cancelResize);

    if (nativeEvent) {
        pendingResizePointer = {
            clientX: nativeEvent.clientX,
            clientY: nativeEvent.clientY,
            shiftKey: nativeEvent.shiftKey,
        };
        flushResize();
    }

    if (!resizingEvent.value || !resizePreview.value?.end) {
        cancelResize();
        return;
    }

    const event = resizingEvent.value;
    const previousStart = event.start;
    const previousEnd = event.end;
    const nextStart = formatLocalDateTime(event.startDate);
    const nextEnd = formatLocalDateTime(resizePreview.value.end);

    cancelResize();
    withRecurringScope(event, (recurringScope) => {
        replaceLocalRecurringEvent(event, recurringScope, {
            start: nextStart,
            end: nextEnd,
        });
        persistEventTime(event, previousStart, previousEnd, nextStart, nextEnd, recurringScope);
    });
}

function cancelResize() {
    window.removeEventListener('pointermove', moveResize);
    window.removeEventListener('pointerup', finishResize);
    window.removeEventListener('pointercancel', cancelResize);

    if (resizeFrame !== null) {
        window.cancelAnimationFrame(resizeFrame);
        resizeFrame = null;
    }

    pendingResizePointer = null;
    resizingEvent.value = null;
    resizePreview.value = null;
    resizeDay = null;
    resizeDayElement = null;
}

function createSelectionStyle(day) {
    if (!createSelection.value || createSelection.value.dayKey !== day.key) {
        return { display: 'none' };
    }

    const top = Math.min(createSelection.value.startTop, createSelection.value.endTop);
    const height = Math.max(Math.abs(createSelection.value.endTop - createSelection.value.startTop), (SNAP_MINUTES / 60) * HOUR_HEIGHT);

    return {
        top: `${top}px`,
        height: `${height}px`,
    };
}

function startCreateSelection(day, nativeEvent) {
    if (
        nativeEvent.button !== 0
        || nativeEvent.target.closest('[data-calendar-event], button, a, pre')
        || draggedEvent.value
        || resizingEvent.value
    ) {
        return;
    }

    createDay = day;
    createDayElement = nativeEvent.currentTarget;
    const minutes = snapMinutes(minutesFromPointer(nativeEvent, createDayElement), true);
    const top = (minutes / 60) * HOUR_HEIGHT;

    createSelection.value = {
        dayKey: day.key,
        startMinutes: minutes,
        endMinutes: minutes + SNAP_MINUTES,
        startTop: top,
        endTop: top + ((SNAP_MINUTES / 60) * HOUR_HEIGHT),
    };

    nativeEvent.preventDefault();
    window.addEventListener('pointermove', moveCreateSelection);
    window.addEventListener('pointerup', finishCreateSelection, { once: true });
    window.addEventListener('pointercancel', cancelCreateSelection, { once: true });
}

function moveCreateSelection(nativeEvent) {
    if (!createSelection.value || !createDayElement) {
        return;
    }

    nativeEvent.preventDefault();
    const minutes = snapMinutes(minutesFromPointer(nativeEvent, createDayElement), !nativeEvent.shiftKey);
    createSelection.value = {
        ...createSelection.value,
        endMinutes: minutes,
        endTop: (minutes / 60) * HOUR_HEIGHT,
    };
}

function finishCreateSelection() {
    window.removeEventListener('pointermove', moveCreateSelection);
    window.removeEventListener('pointercancel', cancelCreateSelection);

    if (!createSelection.value || !createDay) {
        cancelCreateSelection();
        return;
    }

    const startMinutes = Math.min(createSelection.value.startMinutes, createSelection.value.endMinutes);
    const endMinutes = Math.max(createSelection.value.startMinutes, createSelection.value.endMinutes);
    const start = dateAtMinutes(createDay, startMinutes);
    const end = dateAtMinutes(createDay, Math.max(endMinutes, startMinutes + SNAP_MINUTES));

    createForm.value = {
        title: '',
        start: formatInputDateTime(start),
        end: formatInputDateTime(end),
        all_day: false,
        recurrence: defaultCreateRecurrence(formatInputDateTime(start)),
        color_id: '9',
    };
    createModal.value = true;
    createSelection.value = null;
    createDay = null;
    createDayElement = null;
}

function cancelCreateSelection() {
    window.removeEventListener('pointermove', moveCreateSelection);
    window.removeEventListener('pointerup', finishCreateSelection);
    window.removeEventListener('pointercancel', cancelCreateSelection);
    createSelection.value = null;
    createDay = null;
    createDayElement = null;
}

function closeCreateModal() {
    if (!creatingEvent.value) {
        createModal.value = false;
    }
}

function submitCreateEvent() {
    if (!createForm.value.title.trim()) {
        return;
    }

    const rec = normalizeRecurrenceForSubmit(createForm.value.recurrence);
    if (rec.freq !== 'none') {
        if (rec.ends === 'until' && !rec.until) {
            return;
        }

        if (rec.ends === 'count' && (!rec.count || rec.count < 1)) {
            return;
        }
    }

    creatingEvent.value = true;
    jsonFetch('/calendar/events', {
        method: 'POST',
        body: JSON.stringify({
            title: createForm.value.title,
            start: createForm.value.start,
            end: createForm.value.end,
            all_day: createForm.value.all_day,
            recurrence: rec,
            color_id: createForm.value.color_id,
        }),
    })
        .then((payload) => {
            if (payload.event) {
                localEvents.value = [...localEvents.value, {
                    ...payload.event,
                    color: eventColorById(payload.event.color_id),
                }];
                markSaved(payload.event.id);
            } else {
                loadEvents();
            }
            createModal.value = false;
        })
        .finally(() => {
            creatingEvent.value = false;
        });
}

onBeforeUnmount(() => {
    finishChatAsideResize();
    cancelActiveChatStream();

    if (resizeAlignRaf !== null) {
        window.cancelAnimationFrame(resizeAlignRaf);
        resizeAlignRaf = null;
    }

    calendarHeaderResizeObs?.disconnect();
    calendarHeaderResizeObs = null;

    cancelPointerDrag();
    cancelResize();
    cancelCreateSelection();
    closeContextMenu();
    closeRecurringPrompt();
    if (pollInterval) {
        window.clearInterval(pollInterval);
    }
    if (savedTickTimeout) {
        window.clearTimeout(savedTickTimeout);
    }
    savedEventIds.value = {};
});

function allDayEventStyle(event) {
    const color = googleColor(event, '#fbbc04', '#111827');

    return {
        backgroundColor: color.background,
        borderColor: color.background,
        color: color.foreground,
    };
}

function toggleDebug(key) {
    debugOpen.value = {
        ...debugOpen.value,
        [key]: !debugOpen.value[key],
    };
}
</script>

<template>
    <Head title="Calendario" />

    <div class="h-screen w-full overflow-hidden bg-[#0b0f19] text-neutral-100" @click="closeContextMenu(); closeChatHistoryDropdown()">
        <div class="flex h-screen overflow-hidden bg-[#111827]">
            <aside
                class="relative flex h-screen shrink-0 flex-col border-r border-white/10 bg-[#0b1220]"
                :class="{ 'select-none': isResizingChatAside }"
                :style="{ width: `${chatAsideWidth}px` }"
            >
                <div class="flex min-h-[65px] items-center justify-between gap-3 border-b border-white/10 px-4 py-2.5 sm:px-5 sm:py-3">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-white">Chat</p>
                        <p class="truncate text-xs text-neutral-400">{{ activeChat?.title || 'Selecciona una conversación' }}</p>
                    </div>
                    <div class="relative flex shrink-0 items-center gap-2" @click.stop>
                        <button
                            type="button"
                            class="inline-flex h-9 w-9 items-center justify-center rounded-xl border border-white/10 text-neutral-300 hover:bg-white/10 hover:text-white disabled:opacity-40"
                            title="Historial de chats"
                            :disabled="loadingChats"
                            @click="toggleChatHistoryDropdown"
                        >
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <path d="M12 8v5l3 2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                                <path d="M4 12a8 8 0 1 0 2.34-5.66L4 8.68" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                                <path d="M4 4v4.68h4.68" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </button>
                        <button
                            type="button"
                            class="shrink-0 rounded-xl bg-white px-3 py-2 text-xs font-medium text-neutral-950 hover:bg-neutral-200 disabled:opacity-40"
                            :disabled="creatingChat"
                            @click="createChat"
                        >
                            Nuevo
                        </button>

                        <div
                            v-if="chatHistoryOpen"
                            class="absolute right-0 top-full z-40 mt-2 w-80 max-w-[calc(100vw-2rem)] overflow-hidden rounded-2xl border border-white/10 bg-[#0f172a] shadow-2xl shadow-black/60"
                        >
                            <div class="border-b border-white/10 px-3 py-2">
                                <p class="text-xs font-semibold text-white">Historial</p>
                            </div>
                            <div class="chat-scrollbar max-h-80 overflow-y-auto p-2">
                                <p v-if="loadingChats" class="px-2 py-3 text-xs text-neutral-500">Cargando chats...</p>
                                <p v-else-if="!chatSessions.length" class="px-2 py-3 text-xs text-neutral-500">Aún no hay chats.</p>
                                <div v-else class="space-y-1">
                                    <div
                                        v-for="chat in chatSessions"
                                        :key="chat.id"
                                        class="group flex min-w-0 items-center gap-2 rounded-xl transition"
                                        :class="chat.id === activeChatId ? 'bg-white/10 text-white' : 'text-neutral-300 hover:bg-white/[0.06]'"
                                    >
                                        <button
                                            type="button"
                                            class="min-w-0 flex-1 px-2.5 py-2 text-left text-xs"
                                            @click="selectChat(chat.id)"
                                        >
                                            <span class="flex min-w-0 items-center gap-2">
                                                <span class="block min-w-0 flex-1 truncate font-medium">{{ chat.title }}</span>
                                                <span class="shrink-0 text-[10px] text-neutral-500">{{ formatChatSessionTime(chat.lastMessageAt) }}</span>
                                            </span>
                                            <span class="mt-0.5 block truncate text-[11px] text-neutral-500">{{ chatPreview(chat) }}</span>
                                        </button>
                                        <button
                                            type="button"
                                            class="mr-1 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-neutral-500 opacity-70 hover:bg-red-500/10 hover:text-red-200 group-hover:opacity-100 disabled:opacity-30"
                                            title="Borrar chat"
                                            :disabled="deletingChatId === chat.id"
                                            @click.stop="deleteChat(chat.id)"
                                        >
                                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                                <path d="M4 7h16" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
                                                <path d="M10 11v6M14 11v6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
                                                <path d="M6 7l1 13h10l1-13" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                                                <path d="M9 7V4h6v3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                                            </svg>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div
                    ref="chatMessagesScrollEl"
                    class="chat-scrollbar min-h-0 flex-1 space-y-3 overflow-y-auto px-3 py-4"
                    @scroll.passive="updateChatStickToBottomFromScroll"
                >
                    <p v-if="loadingChatMessages" class="text-xs text-neutral-500">Cargando conversación...</p>
                    <p v-else-if="!chatMessages.length" class="text-sm text-neutral-500">Empieza un chat nuevo sobre tu calendario.</p>
                    <div
                        v-for="message in chatTimelineItems"
                        :key="message.id"
                        v-memo="[message.id, message.html, message.traceStep?.status, message.hasCalendarChanges, message.calendarChangesRevertedAt, revertingMessageId === message.id]"
                        class="flex"
                        :class="message.role === 'user' ? 'w-full min-w-0 justify-end' : 'w-full min-w-0 justify-start'"
                    >
                        <div
                            v-if="message.role === 'user'"
                            class="w-full min-w-0 rounded-2xl bg-white px-3 py-2 text-sm leading-snug text-neutral-950 shadow-sm"
                        >
                            <div class="mb-1 flex items-center justify-between gap-2">
                                <p class="text-[10px] font-medium uppercase tracking-[0.14em] opacity-60">
                                    Tú
                                </p>
                                <button
                                    v-if="canRevertMessage(message)"
                                    type="button"
                                    class="inline-flex h-6 items-center gap-1 rounded-full bg-neutral-950/10 px-2 text-[10px] font-medium text-neutral-700 hover:bg-neutral-950/20 disabled:opacity-40"
                                    title="Deshacer cambios de este mensaje"
                                    :disabled="revertingMessageId === message.id"
                                    @click="revertMessageChanges(message)"
                                >
                                    <span
                                        v-if="revertingMessageId === message.id"
                                        class="h-3 w-3 animate-spin rounded-full border border-current border-t-transparent"
                                        aria-hidden="true"
                                    />
                                    <span v-else aria-hidden="true">↩</span>
                                    <span>{{ revertingMessageId === message.id ? 'Revirtiendo' : 'Deshacer' }}</span>
                                </button>
                                <span
                                    v-else-if="message.hasCalendarChanges && message.calendarChangesRevertedAt"
                                    class="rounded-full bg-emerald-500/15 px-2 py-1 text-[10px] font-medium text-emerald-700"
                                >
                                    revertido
                                </span>
                            </div>
                            <div
                                v-if="editingMessageId === message.id"
                                :data-chat-editable-message="message.id"
                                contenteditable="true"
                                class="min-h-8 rounded-xl border border-neutral-950/10 bg-neutral-950/[0.04] px-2 py-1 outline-none ring-2 ring-transparent focus:ring-sky-400/40 whitespace-pre-wrap break-words"
                                @input="updateEditingMessageText"
                                @keydown.enter.meta.prevent="submitEditedMessage(message)"
                                @keydown.enter.ctrl.prevent="submitEditedMessage(message)"
                            >{{ editingMessageText }}</div>
                            <div
                                v-else
                                class="chat-message-markdown chat-message-markdown--user cursor-text break-words"
                                title="Click para editar y reenviar desde aquí"
                                @click="startEditingMessage(message)"
                                v-html="message.html"
                            />
                            <div v-if="editingMessageId === message.id" class="mt-2 flex justify-end gap-2">
                                <button
                                    type="button"
                                    class="rounded-lg px-2 py-1 text-xs font-medium text-neutral-600 hover:bg-neutral-950/10"
                                    :disabled="submittingEditedMessageId === message.id"
                                    @click="cancelEditingMessage"
                                >
                                    Cancelar
                                </button>
                                <button
                                    type="button"
                                    class="rounded-lg bg-neutral-950 px-2 py-1 text-xs font-medium text-white hover:bg-neutral-800 disabled:opacity-40"
                                    :disabled="submittingEditedMessageId === message.id || !editingMessageText.trim()"
                                    @click="submitEditedMessage(message)"
                                >
                                    {{ submittingEditedMessageId === message.id ? 'Enviando...' : 'Enviar editado' }}
                                </button>
                            </div>
                        </div>
                        <div
                            v-else-if="message.role === 'trace'"
                            class="w-full min-w-0 border-y border-white/10 py-1.5 text-xs text-neutral-400"
                        >
                            <div class="grid grid-cols-[12px_minmax(0,1fr)] gap-2">
                                <span
                                    class="mt-1.5 h-1.5 w-1.5 rounded-full"
                                    :class="message.traceStep.status === 'running' ? 'animate-pulse bg-neutral-300' : 'bg-neutral-600'"
                                />
                                <div class="min-w-0">
                                    <p class="truncate text-[11px] leading-5 text-neutral-300">{{ message.traceStep.title }}</p>
                                    <pre
                                        v-if="message.traceStep.details"
                                        class="chat-scrollbar max-h-24 overflow-auto whitespace-pre-wrap break-words text-[10px] leading-relaxed text-neutral-500"
                                    >{{ message.traceStep.details }}</pre>
                                </div>
                            </div>
                        </div>
                        <div
                            v-else
                            class="w-full min-w-0 text-sm leading-relaxed text-neutral-200"
                        >
                            <div class="chat-message-markdown chat-message-markdown--assistant break-words" v-html="message.html" />
                        </div>
                    </div>

                    <div
                        v-if="chatAgentStatus && !chatTraceSteps.length"
                        class="border-y border-white/10 py-2 text-xs text-neutral-400"
                    >
                        <span class="flex min-w-0 items-center gap-2">
                            <span class="h-1.5 w-1.5 shrink-0 animate-pulse rounded-full bg-neutral-400" />
                            <span class="truncate">{{ chatAgentStatus.text }}</span>
                        </span>
                    </div>
                </div>

                <form class="border-t border-white/10 p-3" @submit.prevent="sendChatMessage">
                    <div class="flex items-end gap-2 rounded-2xl border border-white/10 bg-black/25 p-2">
                        <textarea
                            v-model="chatDraft"
                            rows="1"
                            class="chat-scrollbar max-h-28 min-h-9 flex-1 resize-none bg-transparent px-2 py-2 text-sm text-white outline-none placeholder:text-neutral-500"
                            :placeholder="activeChatId ? 'Escribe un mensaje...' : 'Crea un chat para empezar...'"
                            :disabled="!activeChatId || loadingChatMessages"
                            @keydown.enter.exact.prevent="sendChatMessage"
                        />
                        <button
                            type="submit"
                            class="rounded-xl bg-white px-3 py-2 text-xs font-medium text-neutral-950 hover:bg-neutral-200 disabled:opacity-40"
                            :disabled="!chatDraft.trim() || chatSending || !activeChatId || loadingChatMessages"
                        >
                            Enviar
                        </button>
                    </div>
                </form>

                <div
                    class="absolute inset-y-0 -right-1 z-30 w-2 cursor-col-resize"
                    title="Arrastra para cambiar el ancho del chat"
                    @pointerdown="startChatAsideResize"
                >
                    <div
                        class="mx-auto h-full w-px bg-transparent transition"
                        :class="isResizingChatAside ? 'bg-sky-300/80' : 'hover:bg-white/30'"
                    />
                </div>
            </aside>

            <section class="flex h-screen min-w-0 flex-1 flex-col overflow-hidden bg-[#111827]">
            <div class="flex min-h-[65px] shrink-0 flex-wrap items-center gap-x-4 gap-y-2 border-b border-white/10 bg-white/[0.03] px-4 py-2.5 sm:px-5 sm:py-3">
                <div class="mr-auto min-w-0 space-y-0.5 pr-2">
                    <h2 class="text-lg font-semibold leading-snug text-white">{{ weekLabel }}</h2>
                    <p class="text-xs leading-snug text-neutral-400">Europe/Madrid · lunes a domingo</p>
                </div>
                <button type="button" class="rounded-full border border-white/10 px-4 py-2 text-sm hover:bg-white/10" @click="prevWeek">
                    Semana anterior
                </button>
                <button type="button" class="rounded-full bg-white px-4 py-2 text-sm font-medium text-neutral-950 hover:bg-neutral-200" @click="currentWeek">
                    Semana actual
                </button>
                <button type="button" class="rounded-full border border-white/10 px-4 py-2 text-sm hover:bg-white/10" @click="nextWeek">
                    Semana siguiente
                </button>
            </div>

            <div ref="calendarScrollEl" class="min-h-0 flex-1 overflow-auto">
                <div class="min-w-[1180px]">
                    <div ref="calendarStickyHeaderEl" class="sticky top-0 z-20 grid grid-cols-[72px_repeat(7,minmax(0,1fr))] border-b border-white/10 bg-[#111827]/95 backdrop-blur">
                            <div class="border-r border-white/10 px-3 py-3 text-xs leading-snug text-neutral-500 sm:px-4 sm:py-3.5">Madrid</div>
                            <div
                                v-for="day in weeklyEventsByDay"
                                :key="day.key"
                                class="border-r border-white/10 px-3 py-3 text-center last:border-r-0 sm:px-4 sm:py-3.5"
                            >
                                <p class="text-xs uppercase tracking-[0.2em] text-neutral-400">{{ day.weekday }}</p>
                                <p class="mt-1 text-2xl font-semibold leading-none text-white">{{ day.dayNumber }}</p>
                                <p class="mt-0.5 text-xs leading-none text-neutral-500">/{{ day.monthNumber }}</p>
                                <div v-if="day.allDayEvents.length" class="mt-3 space-y-1">
                                    <div
                                        v-for="event in day.allDayEvents"
                                        :key="`${day.key}-all-day-${event.id}`"
                                        class="rounded-md border px-1.5 py-1 text-left text-[10px]"
                                        :style="allDayEventStyle(event)"
                                        @contextmenu="showContextMenu(event, $event)"
                                    >
                                        <p class="truncate">{{ event.title || '(Sin titulo)' }}</p>
                                        <button
                                            type="button"
                                            class="text-[9px] underline-offset-2 opacity-80 hover:underline"
                                            @click="toggleDebug(`${day.key}-all-day-${event.id}`)"
                                        >
                                            {{ debugOpen[`${day.key}-all-day-${event.id}`] ? 'Ocultar JSON' : 'Ver JSON' }}
                                        </button>
                                        <pre
                                            v-if="debugOpen[`${day.key}-all-day-${event.id}`]"
                                            class="mt-1 max-h-32 overflow-auto rounded bg-black/50 p-2 text-[10px] text-neutral-200"
                                        >{{ prettyJson(event.raw) }}</pre>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-[72px_repeat(7,minmax(0,1fr))]">
                            <div class="relative border-r border-white/10 bg-[#0f172a]" :style="{ height: `${gridHeight}px` }">
                                <div
                                    ref="calendarHourScrollAnchorEl"
                                    class="pointer-events-none absolute left-0 right-0 z-0 h-px opacity-0"
                                    aria-hidden="true"
                                    :style="{ top: `${(INITIAL_SCROLL_HOUR - DAY_START_HOUR) * HOUR_HEIGHT}px` }"
                                />
                                <div
                                    v-for="slot in hourSlots"
                                    :key="slot.hour"
                                    class="absolute left-0 right-0 border-t border-white/10 pr-2 text-right text-[11px] text-neutral-500"
                                    :style="{ top: `${(slot.hour - DAY_START_HOUR) * HOUR_HEIGHT}px`, height: `${HOUR_HEIGHT}px` }"
                                >
                                    <span class="-translate-y-1/2 bg-[#0f172a] px-1">{{ slot.label }}</span>
                                </div>
                            </div>

                            <div
                                v-for="day in weeklyEventsByDay"
                                :key="`${day.key}-grid`"
                                :data-calendar-day="day.key"
                                class="relative border-r border-white/10 bg-[#0b1220] last:border-r-0"
                                :class="{ 'bg-white/[0.04]': dropPreview?.dayKey === day.key }"
                                :style="{ height: `${gridHeight}px` }"
                                @pointerdown="startCreateSelection(day, $event)"
                            >
                                <div
                                    v-for="slot in hourSlots"
                                    :key="`${day.key}-${slot.hour}`"
                                    class="absolute left-0 right-0 border-t border-white/10"
                                    :style="{ top: `${(slot.hour - DAY_START_HOUR) * HOUR_HEIGHT}px`, height: `${HOUR_HEIGHT}px` }"
                                />

                                <div
                                    v-if="dropPreview?.dayKey === day.key"
                                    class="pointer-events-none absolute left-1 right-1 z-30 rounded-lg border-2 border-dashed p-1.5 text-[10px] font-semibold shadow-2xl backdrop-blur"
                                    :style="{
                                        ...dragPreviewStyle(day),
                                        borderColor: dropPreviewColor().background,
                                        backgroundColor: `${dropPreviewColor().background}44`,
                                        color: dropPreviewColor().foreground,
                                    }"
                                >
                                    <p class="truncate">{{ draggedEvent?.title || '(Sin titulo)' }}</p>
                                    <p class="truncate text-[9px] opacity-80">{{ dropPreviewLabel() }}</p>
                                </div>

                                <div
                                    v-if="createSelection?.dayKey === day.key"
                                    class="pointer-events-none absolute left-1 right-1 z-20 rounded-lg border border-dashed border-white/50 bg-white/10 p-1.5 text-[10px] text-white shadow-xl backdrop-blur"
                                    :style="createSelectionStyle(day)"
                                >
                                    Nuevo evento
                                </div>

                                <article
                                    v-for="event in day.timedEvents"
                                    :key="`${day.key}-${event.id}`"
                                    data-calendar-event
                                    class="absolute left-1 right-1 z-10 touch-none select-none overflow-hidden rounded-lg border p-1.5 shadow-lg hover:z-20"
                                    :class="{
                                        'cursor-grab active:cursor-grabbing': !savingEventId,
                                        'opacity-40 ring-2 ring-white/60': draggedEvent?.id === event.id || resizingEvent?.id === event.id,
                                    }"
                                    :style="calendarEventStyle(event, day)"
                                    @pointerdown="startPointerDrag(event, $event)"
                                    @contextmenu="showContextMenu(event, $event)"
                                >
                                    <div class="flex items-start justify-between gap-2">
                                        <div class="min-w-0">
                                            <p class="truncate text-[10px] font-semibold">{{ event.title || '(Sin titulo)' }}</p>
                                            <p class="truncate text-[9px] opacity-80">{{ eventDisplayTimeLabel(event) }}</p>
                                        </div>
                                        <div class="flex h-4 w-4 shrink-0 items-center justify-center">
                                            <div
                                                v-if="isEventSaving(event)"
                                                class="h-3 w-3 animate-spin rounded-full border border-current border-t-transparent opacity-90"
                                                aria-label="Guardando"
                                            />
                                            <div
                                                v-else-if="isEventSaved(event)"
                                                class="calendar-save-tick flex h-3.5 w-3.5 items-center justify-center rounded-full bg-white/20 text-[10px] font-bold leading-none"
                                                aria-label="Guardado"
                                            >
                                                ✓
                                            </div>
                                        </div>
                                    </div>

                                    <button
                                        type="button"
                                        class="mt-1 text-[9px] underline-offset-2 opacity-80 hover:underline"
                                        @click="toggleDebug(`${day.key}-${event.id}`)"
                                    >
                                        {{ debugOpen[`${day.key}-${event.id}`] ? 'Ocultar JSON' : 'Ver JSON' }}
                                    </button>
                                    <pre
                                        v-if="debugOpen[`${day.key}-${event.id}`]"
                                        class="mt-2 max-h-48 overflow-auto rounded border border-white/10 bg-black/60 p-2 text-[10px] text-neutral-200"
                                    >{{ prettyJson(event.raw) }}</pre>
                                    <div
                                        class="absolute inset-x-1 bottom-0 z-20 flex h-3 cursor-ns-resize items-end justify-center rounded-b-lg opacity-70 hover:opacity-100"
                                        title="Arrastra para alargar o acortar"
                                        @pointerdown.stop="startResize(event, day, $event)"
                                    >
                                        <div class="mb-0.5 h-0.5 w-8 rounded-full bg-current opacity-80" />
                                    </div>
                                </article>
                            </div>
                        </div>
                    </div>
                </div>
            <div
                v-if="createModal"
                class="fixed inset-0 z-[70] flex items-center justify-center bg-black/50 p-4 backdrop-blur-sm"
                @click.self="closeCreateModal"
            >
                <form
                    class="w-full max-w-md space-y-4 rounded-2xl border border-white/10 bg-[#111827] p-5 text-white shadow-2xl shadow-black/70"
                    @submit.prevent="submitCreateEvent"
                >
                    <div>
                        <h3 class="text-base font-semibold">Nuevo evento</h3>
                        <p class="text-xs text-neutral-400">Ajusta los detalles antes de crearlo en Google Calendar.</p>
                    </div>

                    <label class="block text-xs text-neutral-300">
                        Título
                        <input
                            v-model="createForm.title"
                            type="text"
                            class="mt-1 w-full rounded-lg border border-white/10 bg-black/30 px-3 py-2 text-sm outline-none focus:border-sky-400"
                            placeholder="Título del evento"
                            autofocus
                        >
                    </label>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="block text-xs text-neutral-300">
                            Inicio
                            <input
                                v-model="createForm.start"
                                type="datetime-local"
                                class="mt-1 w-full rounded-lg border border-white/10 bg-black/30 px-3 py-2 text-sm outline-none focus:border-sky-400"
                            >
                        </label>
                        <label class="block text-xs text-neutral-300">
                            Fin
                            <input
                                v-model="createForm.end"
                                type="datetime-local"
                                class="mt-1 w-full rounded-lg border border-white/10 bg-black/30 px-3 py-2 text-sm outline-none focus:border-sky-400"
                            >
                        </label>
                    </div>

                    <label class="flex items-center gap-2 text-xs text-neutral-300">
                        <input v-model="createForm.all_day" type="checkbox" class="rounded border-white/20 bg-black/30">
                        Todo el día
                    </label>

                    <div class="space-y-3">
                        <div>
                            <p class="text-xs font-medium text-neutral-300">Repetición</p>
                            <p class="mt-1 text-[11px] leading-snug text-neutral-500">
                                {{ recurrenceSummaryLine(createForm.recurrence) }}
                            </p>
                        </div>

                        <div
                            class="flex flex-wrap gap-1 rounded-xl border border-white/10 bg-black/25 p-1"
                            role="group"
                            aria-label="Frecuencia de repetición"
                        >
                            <button
                                v-for="preset in RECURRENCE_FREQ_PRESETS"
                                :key="preset.value"
                                type="button"
                                class="min-h-[34px] flex-1 rounded-lg px-2 py-1.5 text-center text-[11px] font-medium transition sm:text-xs"
                                :class="createForm.recurrence.freq === preset.value
                                    ? 'bg-white text-neutral-950 shadow-sm'
                                    : 'text-neutral-400 hover:bg-white/10 hover:text-white'"
                                @click="setCreateRecurrenceFreq(preset.value)"
                            >
                                {{ preset.label }}
                            </button>
                        </div>

                        <div
                            v-if="createForm.recurrence.freq !== 'none'"
                            class="space-y-4 rounded-xl border border-white/10 bg-black/20 p-3"
                        >
                            <label class="flex flex-wrap items-center gap-2 text-xs text-neutral-300">
                                <span class="shrink-0">Cada</span>
                                <input
                                    v-model.number="createForm.recurrence.interval"
                                    type="number"
                                    min="1"
                                    max="999"
                                    class="w-16 rounded-lg border border-white/10 bg-black/40 px-2 py-1.5 text-center text-sm outline-none focus:border-sky-400"
                                >
                                <span class="text-neutral-400">
                                    {{ recurrenceIntervalSuffix(createForm.recurrence.freq, createForm.recurrence.interval) }}
                                </span>
                            </label>

                            <div v-if="createForm.recurrence.freq === 'weekly'" class="space-y-2">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <p class="text-xs text-neutral-300">Días de la semana</p>
                                    <button
                                        type="button"
                                        class="text-[10px] text-sky-300 underline-offset-2 hover:underline"
                                        @click="clearWeeklyDaysForGoogleDefault"
                                    >
                                        Solo día de inicio (Google)
                                    </button>
                                </div>
                                <div class="flex flex-wrap gap-1.5">
                                    <button
                                        v-for="d in WEEKDAY_OPTIONS"
                                        :key="d.key"
                                        type="button"
                                        class="min-w-[2.5rem] rounded-lg border px-2 py-1.5 text-[11px] font-medium transition"
                                        :class="createForm.recurrence.by_day?.includes(d.key)
                                            ? 'border-white/30 bg-white text-neutral-950'
                                            : 'border-white/10 bg-black/30 text-neutral-400 hover:border-white/20 hover:text-white'"
                                        @click="toggleCreateRecurrenceWeekday(d.key)"
                                    >
                                        {{ d.label }}
                                    </button>
                                </div>
                                <p class="text-[10px] text-neutral-500">
                                    Si no eliges ningún día, Google repetirá el mismo día de la semana que la fecha de inicio.
                                </p>
                            </div>

                            <fieldset class="space-y-2 border-0 p-0">
                                <legend class="text-xs font-medium text-neutral-300">Finaliza</legend>
                                <label class="flex cursor-pointer items-center gap-2 text-[11px] text-neutral-400 hover:text-neutral-200">
                                    <input
                                        v-model="createForm.recurrence.ends"
                                        type="radio"
                                        value="never"
                                        class="border-white/30 bg-black/40 text-sky-500"
                                    >
                                    Sin fecha de fin
                                </label>
                                <label class="flex cursor-pointer flex-wrap items-center gap-2 text-[11px] text-neutral-400 hover:text-neutral-200">
                                    <input
                                        v-model="createForm.recurrence.ends"
                                        type="radio"
                                        value="until"
                                        class="border-white/30 bg-black/40 text-sky-500"
                                    >
                                    <span class="shrink-0">El</span>
                                    <input
                                        v-model="createForm.recurrence.until"
                                        type="date"
                                        class="rounded-lg border border-white/10 bg-black/40 px-2 py-1 text-xs outline-none focus:border-sky-400 disabled:opacity-40"
                                        :disabled="createForm.recurrence.ends !== 'until'"
                                    >
                                </label>
                                <label class="flex cursor-pointer flex-wrap items-center gap-2 text-[11px] text-neutral-400 hover:text-neutral-200">
                                    <input
                                        v-model="createForm.recurrence.ends"
                                        type="radio"
                                        value="count"
                                        class="border-white/30 bg-black/40 text-sky-500"
                                    >
                                    <span class="shrink-0">Después de</span>
                                    <input
                                        v-model.number="createForm.recurrence.count"
                                        type="number"
                                        min="1"
                                        max="999"
                                        class="w-16 rounded-lg border border-white/10 bg-black/40 px-2 py-1 text-center text-xs outline-none focus:border-sky-400 disabled:opacity-40"
                                        :disabled="createForm.recurrence.ends !== 'count'"
                                    >
                                    <span>ocurrencias</span>
                                </label>
                            </fieldset>
                        </div>
                    </div>

                    <div>
                        <p class="mb-2 text-xs text-neutral-300">Color</p>
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="color in eventColorOptions"
                                :key="`create-${color.id}`"
                                type="button"
                                class="h-7 w-7 rounded-full border border-white/20 transition hover:scale-110"
                                :class="{ 'ring-2 ring-white ring-offset-2 ring-offset-[#111827]': createForm.color_id === color.id }"
                                :style="{ backgroundColor: color.background }"
                                @click="createForm.color_id = color.id"
                            />
                        </div>
                    </div>

                    <div class="flex justify-end gap-2 border-t border-white/10 pt-4">
                        <button
                            type="button"
                            class="rounded-lg px-4 py-2 text-sm text-neutral-300 hover:bg-white/10"
                            :disabled="creatingEvent"
                            @click="closeCreateModal"
                        >
                            Cancelar
                        </button>
                        <button
                            type="submit"
                            class="rounded-lg bg-white px-4 py-2 text-sm font-medium text-neutral-950 hover:bg-neutral-200 disabled:opacity-60"
                            :disabled="creatingEvent || !createForm.title.trim()"
                        >
                            {{ creatingEvent ? 'Creando...' : 'Crear evento' }}
                        </button>
                    </div>
                </form>
            </div>

            <div
                v-if="contextMenu"
                class="fixed z-50 w-56 overflow-hidden rounded-xl border border-white/10 bg-[#111827]/95 text-sm text-white shadow-2xl shadow-black/60 backdrop-blur"
                :style="{ left: `${contextMenu.x}px`, top: `${contextMenu.y}px` }"
                @click.stop
                @contextmenu.prevent
            >
                <div class="border-b border-white/10 px-3 py-2">
                    <p class="truncate text-xs font-semibold">{{ contextMenu.event.title || '(Sin titulo)' }}</p>
                    <p class="text-[10px] text-neutral-400">Color del evento</p>
                </div>

                <div class="grid grid-cols-6 gap-2 p-3">
                    <button
                        v-for="color in eventColorOptions"
                        :key="color.id"
                        type="button"
                        class="h-6 w-6 rounded-full border border-white/20 shadow-sm transition hover:scale-110"
                        :class="{ 'ring-2 ring-white ring-offset-2 ring-offset-[#111827]': contextMenu.event.color_id === color.id }"
                        :style="{ backgroundColor: color.background }"
                        :title="`Color ${color.id}`"
                        @click="setEventColor(contextMenu.event, color.id)"
                    />
                </div>

                <button
                    type="button"
                    class="flex w-full items-center justify-between border-t border-white/10 px-3 py-2 text-left text-xs text-red-200 hover:bg-red-500/15"
                    @click="deleteEvent(contextMenu.event)"
                >
                    <span>Eliminar evento</span>
                    <span class="text-red-300">⌫</span>
                </button>
            </div>

            <div
                v-if="recurringPrompt"
                class="fixed inset-0 z-[60] flex items-center justify-center bg-black/50 p-4 backdrop-blur-sm"
                @click.self="closeRecurringPrompt"
            >
                <div class="w-full max-w-sm overflow-hidden rounded-2xl border border-white/10 bg-[#111827] text-white shadow-2xl shadow-black/70">
                    <div class="border-b border-white/10 px-5 py-4">
                        <h3 class="text-base font-semibold">Editar evento periódico</h3>
                        <p class="mt-1 truncate text-xs text-neutral-400">
                            {{ recurringPrompt.event.title || '(Sin titulo)' }}
                        </p>
                    </div>

                    <div class="p-2">
                        <button
                            type="button"
                            class="block w-full rounded-xl px-4 py-3 text-left text-sm hover:bg-white/10"
                            @click="chooseRecurringScope('this')"
                        >
                            Este evento
                        </button>
                        <button
                            type="button"
                            class="block w-full rounded-xl px-4 py-3 text-left text-sm hover:bg-white/10"
                            @click="chooseRecurringScope('this_and_following')"
                        >
                            Este evento y los posteriores
                        </button>
                        <button
                            type="button"
                            class="block w-full rounded-xl px-4 py-3 text-left text-sm hover:bg-white/10"
                            @click="chooseRecurringScope('all')"
                        >
                            Todos los eventos
                        </button>
                    </div>

                    <div class="border-t border-white/10 p-2">
                        <button
                            type="button"
                            class="block w-full rounded-xl px-4 py-2 text-center text-xs text-neutral-400 hover:bg-white/10"
                            @click="closeRecurringPrompt"
                        >
                            Cancelar
                        </button>
                    </div>
                </div>
            </div>
        </section>
        </div>
    </div>
</template>

<style scoped>
/**
 * Scrollbars del panel de chat (Firefox + WebKit), tonos slate acordes al aside oscuro.
 */
.chat-scrollbar {
    scrollbar-width: thin;
    scrollbar-color: rgba(148, 163, 184, 0.42) rgba(15, 23, 42, 0.65);
}

.chat-scrollbar::-webkit-scrollbar {
    width: 8px;
    height: 8px;
}

.chat-scrollbar::-webkit-scrollbar-track {
    margin: 4px 0;
    background: rgba(15, 23, 42, 0.55);
    border-radius: 9999px;
}

.chat-scrollbar::-webkit-scrollbar-thumb {
    background: rgba(148, 163, 184, 0.38);
    border-radius: 9999px;
    border: 2px solid transparent;
    background-clip: padding-box;
}

.chat-scrollbar::-webkit-scrollbar-thumb:hover {
    background: rgba(203, 213, 225, 0.48);
    border: 2px solid transparent;
    background-clip: padding-box;
}

.chat-scrollbar::-webkit-scrollbar-corner {
    background: transparent;
}

.chat-message-markdown :deep(p) {
    margin: 0;
}

.chat-message-markdown :deep(p + p) {
    margin-top: 0.65rem;
}

.chat-message-markdown :deep(ul),
.chat-message-markdown :deep(ol) {
    margin: 0.45rem 0 0.65rem;
    padding-left: 1.2rem;
}

.chat-message-markdown :deep(ul) {
    list-style: disc;
}

.chat-message-markdown :deep(ol) {
    list-style: decimal;
}

.chat-message-markdown :deep(li + li) {
    margin-top: 0.2rem;
}

.chat-message-markdown :deep(strong) {
    font-weight: 700;
    color: #ffffff;
}

.chat-message-markdown :deep(code) {
    border-radius: 0.375rem;
    background: rgba(255, 255, 255, 0.1);
    padding: 0.05rem 0.25rem;
    font-size: 0.92em;
}

.chat-message-markdown :deep(a) {
    color: #7dd3fc;
    text-decoration: underline;
    text-underline-offset: 2px;
}

.chat-message-markdown :deep(table) {
    display: block;
    width: 100%;
    max-width: 100%;
    margin: 0.75rem 0;
    overflow-x: auto;
    border-collapse: separate;
    border-spacing: 0;
    border: 1px solid rgba(255, 255, 255, 0.12);
    border-radius: 0.75rem;
    color: #f8fafc;
    background: rgba(15, 23, 42, 0.72);
}

.chat-message-markdown :deep(thead) {
    color: #ffffff;
    background: rgba(255, 255, 255, 0.08);
}

.chat-message-markdown :deep(th),
.chat-message-markdown :deep(td) {
    min-width: 5.5rem;
    border-right: 1px solid rgba(255, 255, 255, 0.1);
    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    padding: 0.45rem 0.6rem;
    color: #f8fafc;
    vertical-align: top;
    white-space: nowrap;
}

.chat-message-markdown :deep(th) {
    font-weight: 700;
}

.chat-message-markdown :deep(tr:last-child td) {
    border-bottom: 0;
}

.chat-message-markdown :deep(th:last-child),
.chat-message-markdown :deep(td:last-child) {
    border-right: 0;
}

.chat-message-markdown :deep(tbody tr:nth-child(even)) {
    background: rgba(255, 255, 255, 0.035);
}

.calendar-save-tick {
    animation: calendar-save-tick 700ms ease-out forwards;
}

@keyframes calendar-save-tick {
    0% {
        opacity: 0;
        transform: scale(0.75);
    }
    18% {
        opacity: 1;
        transform: scale(1.08);
    }
    45% {
        opacity: 1;
        transform: scale(1);
    }
    100% {
        opacity: 0;
        transform: scale(0.9);
    }
}
</style>
