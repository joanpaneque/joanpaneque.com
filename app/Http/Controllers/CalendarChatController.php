<?php

namespace App\Http\Controllers;

use App\Models\CalendarChat;
use App\Models\CalendarChatMessage;
use App\Services\CalendarAgent;
use App\Services\GoogleCalendarService;
use Google\Service\Exception as GoogleServiceException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class CalendarChatController extends Controller
{
    public function index(): JsonResponse
    {
        $user = Auth::user();
        abort_unless($user, 403);

        $chats = CalendarChat::query()
            ->whereBelongsTo($user)
            ->with('latestMessage')
            ->withCount('messages')
            ->orderByDesc('last_message_at')
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (CalendarChat $chat) => $this->chatPayload($chat))
            ->values();

        return response()->json([
            'chats' => $chats,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = Auth::user();
        abort_unless($user, 403);

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:100'],
        ]);

        $chat = CalendarChat::create([
            'user_id' => $user->id,
            'title' => trim((string) ($validated['title'] ?? '')) ?: 'Nuevo chat',
        ]);

        return response()->json([
            'chat' => $this->chatPayload($chat),
            'messages' => [],
        ], 201);
    }

    public function show(CalendarChat $chat): JsonResponse
    {
        $user = Auth::user();
        abort_unless($user, 403);
        $this->authorizeChat($chat, $user->id);

        $chat->load('latestMessage');
        $messages = $chat->messages()
            ->with('changeLog')
            ->oldest()
            ->get()
            ->map(fn (CalendarChatMessage $message) => $this->messagePayload($message))
            ->values();

        return response()->json([
            'chat' => $this->chatPayload($chat),
            'messages' => $messages,
        ]);
    }

    public function destroy(CalendarChat $chat): JsonResponse
    {
        $user = Auth::user();
        abort_unless($user, 403);
        $this->authorizeChat($chat, $user->id);

        $chat->delete();

        return response()->json([
            'ok' => true,
        ]);
    }

    public function revert(CalendarChat $chat, CalendarChatMessage $message): JsonResponse
    {
        $user = Auth::user();
        abort_unless($user, 403);
        $this->authorizeChat($chat, $user->id);
        abort_unless((int) $message->calendar_chat_id === (int) $chat->id, 404);

        [$results, $messagesToRevert] = $this->revertPendingMessagesFrom($chat, $message, new GoogleCalendarService($user));
        if ($messagesToRevert->isEmpty()) {
            return response()->json([
                'ok' => false,
                'message' => 'Este mensaje no tiene cambios pendientes de revertir.',
            ], 422);
        }

        $flatResults = collect($results)->flatMap(fn (array $item) => $item['results'] ?? []);
        $chat->messages()
            ->where('id', '>', $message->id)
            ->delete();
        $chat->refresh()->load('latestMessage');

        return response()->json([
            'ok' => ! $flatResults->contains(fn (array $result) => ! ($result['ok'] ?? false)),
            'message' => 'Reversión encadenada completada.',
            'results' => $results,
            'chat' => $this->chatPayload($chat),
            'messages' => $this->messagesPayload($chat),
        ]);
    }

    public function edit(Request $request, CalendarChat $chat, CalendarChatMessage $message, CalendarAgent $agent): JsonResponse
    {
        $user = Auth::user();
        abort_unless($user, 403);
        $this->authorizeChat($chat, $user->id);
        abort_unless((int) $message->calendar_chat_id === (int) $chat->id && $message->role === 'user', 404);

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:4000'],
        ]);

        $calendar = new GoogleCalendarService($user);
        [$revertResults] = $this->revertPendingMessagesFrom($chat, $message, $calendar);

        $chat->messages()
            ->where('id', '>', $message->id)
            ->delete();

        $message->changeLog()?->delete();
        $message->forceFill([
            'content' => $validated['message'],
        ])->save();
        $message->unsetRelation('changeLog');

        $history = $this->conversationHistoryUntil($chat, $message);

        try {
            $reply = $agent->respond($user, $history, null, $message);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Lo he intentado varias veces, pero la IA sigue fallando. Prueba de nuevo en unos segundos.',
            ], 500);
        }

        $this->createMessage($chat, 'assistant', $reply);
        $chat->refresh()->load('latestMessage');

        return response()->json([
            'reply' => $reply,
            'chat' => $this->chatPayload($chat),
            'messages' => $this->messagesPayload($chat),
            'revert_results' => $revertResults,
        ]);
    }

    public function message(Request $request, CalendarChat $chat, CalendarAgent $agent): JsonResponse
    {
        $user = Auth::user();
        abort_unless($user, 403);
        $this->authorizeChat($chat, $user->id);

        if (! $user->google_token) {
            return response()->json([
                'message' => 'Conecta Google Calendar antes de usar el asistente.',
            ], 422);
        }

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:4000'],
        ]);

        $userMessage = $this->createMessage($chat, 'user', $validated['message']);
        $history = $this->conversationHistory($chat);

        try {
            $reply = $agent->respond($user, $history, null, $userMessage);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Lo he intentado varias veces, pero la IA sigue fallando. Prueba de nuevo en unos segundos.',
            ], 500);
        }

        $assistantMessage = $this->createMessage($chat, 'assistant', $reply);
        $chat->refresh()->load('latestMessage');

        return response()->json([
            'reply' => $reply,
            'chat' => $this->chatPayload($chat),
            'user_message' => $this->messagePayload($userMessage),
            'assistant_message' => $this->messagePayload($assistantMessage),
            'messages' => $this->messagesPayload($chat),
        ]);
    }

    public function stream(Request $request, CalendarChat $chat, CalendarAgent $agent): StreamedResponse|JsonResponse
    {
        $user = Auth::user();
        abort_unless($user, 403);
        $this->authorizeChat($chat, $user->id);

        if (! $user->google_token) {
            return response()->json([
                'message' => 'Conecta Google Calendar antes de usar el asistente.',
            ], 422);
        }

        $message = trim((string) $request->query('message', ''));
        if ($message === '' || mb_strlen($message) > 4000) {
            return response()->json([
                'message' => 'El mensaje es obligatorio y no puede superar 4000 caracteres.',
            ], 422);
        }

        $userMessage = $this->createMessage($chat, 'user', $message);
        $history = $this->conversationHistory($chat);

        return response()->stream(function () use ($agent, $chat, $history, $user, $userMessage): void {
            $traceCount = 0;
            $finalResponseTracePersisted = false;
            $emit = function (string $event, array $payload) use ($chat, &$traceCount, &$finalResponseTracePersisted): void {
                $this->persistTraceEvent($chat, $event, $payload, $traceCount, $finalResponseTracePersisted);
                echo "event: {$event}\n";
                echo 'data: '.json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\n";
                @ob_flush();
                flush();
            };
            $emit('user_message', [
                'message' => $this->messagePayload($userMessage),
            ]);

            $donePayload = null;
            $agentEmit = function (string $event, array $payload) use ($emit, &$donePayload, $userMessage): void {
                if ($event === 'done') {
                    $donePayload = $payload;

                    return;
                }

                if ($event === 'tool_call' && in_array((string) ($payload['tool'] ?? ''), ['create_event', 'update_event', 'delete_event', 'move_event'], true)) {
                    $payload['message_id'] = $userMessage->id;
                }

                $emit($event, $payload);
            };

            try {
                $reply = $agent->respond($user, $history, $agentEmit, $userMessage);

                $assistantMessage = $this->createMessage($chat, 'assistant', $reply);
                $chat->refresh()->load('latestMessage');

                $userMessage->load('changeLog');
                $emit('user_message', [
                    'message' => $this->messagePayload($userMessage),
                ]);
                $emit('assistant_message', [
                    'message' => $this->messagePayload($assistantMessage),
                ]);
                $emit('chat', [
                    'chat' => $this->chatPayload($chat),
                    'user_message' => $this->messagePayload($userMessage),
                ]);
                $emit('done', $donePayload ?? []);
            } catch (Throwable $e) {
                report($e);

                $emit('token', [
                    'text' => 'Lo he intentado varias veces, pero la IA sigue fallando. Prueba de nuevo en unos segundos.',
                ]);

                $assistantMessage = $this->createMessage($chat, 'assistant', 'Lo he intentado varias veces, pero la IA sigue fallando. Prueba de nuevo en unos segundos.');
                $chat->refresh()->load('latestMessage');

                $emit('assistant_message', [
                    'message' => $this->messagePayload($assistantMessage),
                ]);
                $emit('chat', [
                    'chat' => $this->chatPayload($chat),
                ]);
                $emit('done', []);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * @return list<array{role: 'user'|'assistant', content: string}>
     */
    private function conversationHistory(CalendarChat $chat): array
    {
        return $chat->messages()
            ->oldest()
            ->get()
            ->filter(fn (CalendarChatMessage $message) => in_array($message->role, ['user', 'assistant'], true))
            ->map(fn (CalendarChatMessage $message) => [
                'role' => $message->role,
                'content' => $message->content,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{role: 'user'|'assistant', content: string}>
     */
    private function conversationHistoryUntil(CalendarChat $chat, CalendarChatMessage $message): array
    {
        return $chat->messages()
            ->where('id', '<=', $message->id)
            ->oldest()
            ->get()
            ->filter(fn (CalendarChatMessage $item) => in_array($item->role, ['user', 'assistant'], true))
            ->map(fn (CalendarChatMessage $item) => [
                'role' => $item->role,
                'content' => $item->content,
            ])
            ->values()
            ->all();
    }

    private function authorizeChat(CalendarChat $chat, int $userId): void
    {
        abort_unless((int) $chat->user_id === $userId, 404);
    }

    private function createMessage(CalendarChat $chat, string $role, string $content): CalendarChatMessage
    {
        $message = $chat->messages()->create([
            'role' => $role,
            'content' => $content,
        ]);

        $updates = [
            'last_message_at' => $message->created_at,
        ];

        if ($role === 'user' && $chat->messages()->count() === 1) {
            $updates['title'] = Str::limit(trim($content), 60, '...');
        }

        $chat->forceFill($updates)->save();

        return $message;
    }

    private function createTraceMessage(CalendarChat $chat, array $step): void
    {
        $chat->messages()->create([
            'role' => 'trace',
            'content' => json_encode($step, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    private function persistTraceEvent(CalendarChat $chat, string $event, array $payload, int &$traceCount, bool &$finalResponseTracePersisted): void
    {
        $step = null;

        if ($event === 'thinking') {
            $step = [
                'type' => 'thinking',
                'title' => $traceCount > 0 ? 'Evalúa siguiente paso' : 'Preparando respuesta',
                'details' => $traceCount > 0
                    ? 'Revisando el resultado anterior para decidir si necesita otra herramienta o responder.'
                    : 'Analizando la petición y decidiendo si necesita usar herramientas.',
                'status' => 'running',
            ];
        } elseif ($event === 'tool_call') {
            $step = [
                'type' => 'tool_call',
                'title' => $this->traceToolTitle($payload),
                'details' => isset($payload['params']) && is_array($payload['params']) && $payload['params'] !== []
                    ? json_encode($payload['params'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : '',
                'status' => 'running',
            ];
        } elseif ($event === 'retry') {
            $step = [
                'type' => 'retry',
                'title' => 'Reintentando IA',
                'details' => (string) ($payload['message'] ?? 'La IA ha fallado temporalmente. Reintentando...'),
                'status' => 'running',
            ];
        } elseif ($event === 'tool_result') {
            $step = [
                'type' => 'tool_result',
                'title' => 'Resultado de herramienta',
                'details' => (string) ($payload['message'] ?? 'Herramienta ejecutada.'),
                'status' => 'done',
            ];
        } elseif (($event === 'token' || $event === 'final_response') && ! $finalResponseTracePersisted) {
            $finalResponseTracePersisted = true;
            $step = [
                'type' => 'final_response',
                'title' => $event === 'final_response' ? 'Respuesta final recibida' : 'Redactando respuesta final',
                'details' => $event === 'final_response'
                    ? 'El modelo devolvió una respuesta final sin más herramientas.'
                    : 'Mostrando la respuesta en streaming.',
                'status' => $event === 'final_response' ? 'done' : 'running',
            ];
        }

        if ($step === null) {
            return;
        }

        $traceCount++;
        $this->createTraceMessage($chat, [
            'id' => $traceCount,
            ...$step,
        ]);
    }

    private function traceToolTitle(array $payload): string
    {
        return match ((string) ($payload['tool'] ?? '')) {
            'get_calendar_events' => 'Consulta calendario',
            'search_calendar_events_index' => 'Busca en índice (embeddings)',
            'create_event' => 'Crea evento',
            'update_event' => 'Modifica evento',
            'delete_event' => 'Elimina evento',
            'move_event' => 'Mueve evento',
            default => 'Ejecuta herramienta',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function chatPayload(CalendarChat $chat): array
    {
        $chat->loadMissing('latestMessage');
        $latestMessage = $chat->latestMessage;

        return [
            'id' => $chat->id,
            'title' => $chat->title,
            'last_message_at' => $chat->last_message_at?->toIso8601String(),
            'created_at' => $chat->created_at?->toIso8601String(),
            'updated_at' => $chat->updated_at?->toIso8601String(),
            'messages_count' => $chat->messages_count ?? $chat->messages()->count(),
            'latest_message' => $latestMessage ? $this->messagePayload($latestMessage) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function messagePayload(CalendarChatMessage $message): array
    {
        if ($message->role === 'trace') {
            $traceStep = json_decode($message->content, true);

            return [
                'id' => $message->id,
                'role' => 'trace',
                'content' => $message->content,
                'trace_step' => is_array($traceStep) ? $traceStep : null,
                'created_at' => $message->created_at?->toIso8601String(),
            ];
        }

        $message->loadMissing('changeLog');
        $changeLog = $message->changeLog;

        return [
            'id' => $message->id,
            'role' => $message->role,
            'content' => $message->content,
            'created_at' => $message->created_at?->toIso8601String(),
            'has_calendar_changes' => (bool) ($changeLog && ($changeLog->changes ?? []) !== []),
            'calendar_changes_reverted_at' => $changeLog?->reverted_at?->toIso8601String(),
            'calendar_revert_result' => $changeLog?->revert_result,
        ];
    }

    private function messagesPayload(CalendarChat $chat): array
    {
        return $chat->messages()
            ->with('changeLog')
            ->oldest()
            ->get()
            ->map(fn (CalendarChatMessage $message) => $this->messagePayload($message))
            ->values()
            ->all();
    }

    private function revertPendingMessagesFrom(CalendarChat $chat, CalendarChatMessage $message, GoogleCalendarService $calendar): array
    {
        $messagesToRevert = $chat->messages()
            ->with('changeLog')
            ->where('id', '>=', $message->id)
            ->orderByDesc('id')
            ->get()
            ->filter(fn (CalendarChatMessage $item) => $item->changeLog
                && ! $item->changeLog->reverted_at
                && ($item->changeLog->changes ?? []) !== [])
            ->values();

        $results = [];

        foreach ($messagesToRevert as $messageToRevert) {
            $log = $messageToRevert->changeLog;
            $messageResults = [];

            foreach (array_reverse($log->changes ?? []) as $change) {
                try {
                    $messageResults[] = $this->revertCalendarChange($calendar, $change);
                } catch (Throwable $e) {
                    report($e);
                    $messageResults[] = [
                        'ok' => false,
                        'action' => $change['action'] ?? 'unknown',
                        'event_id' => $change['event_id'] ?? ($change['snapshot']['event_id'] ?? null),
                        'message' => $e->getMessage(),
                    ];
                }
            }

            $log->forceFill([
                'reverted_at' => now(),
                'revert_result' => $messageResults,
            ])->save();
            $messageToRevert->load('changeLog');

            $results[] = [
                'message_id' => $messageToRevert->id,
                'results' => $messageResults,
            ];
        }

        return [$results, $messagesToRevert];
    }

    /**
     * @param  array<string, mixed>  $change
     * @return array<string, mixed>
     */
    private function revertCalendarChange(GoogleCalendarService $calendar, array $change): array
    {
        $action = (string) ($change['action'] ?? '');

        return match ($action) {
            'create_event' => $this->revertCreatedEvent($calendar, $change),
            'delete_event' => $this->revertDeletedEvent($calendar, $change),
            'update_event' => $this->revertUpdatedEvent($calendar, $change),
            'move_event' => $this->revertMovedEvent($calendar, $change),
            default => [
                'ok' => false,
                'action' => $action,
                'message' => 'Acción desconocida.',
            ],
        };
    }

    private function revertCreatedEvent(GoogleCalendarService $calendar, array $change): array
    {
        $eventId = (string) ($change['event_id'] ?? '');
        if ($eventId === '') {
            return ['ok' => false, 'action' => 'create_event', 'message' => 'Falta event_id.'];
        }

        try {
            $calendar->deleteEvent($eventId);
        } catch (GoogleServiceException $e) {
            if (! in_array((int) $e->getCode(), [404, 410], true)) {
                throw $e;
            }

            return ['ok' => true, 'action' => 'create_event', 'event_id' => $eventId, 'message' => 'El evento creado ya no existía.'];
        }

        return ['ok' => true, 'action' => 'create_event', 'event_id' => $eventId, 'message' => 'Evento creado eliminado.'];
    }

    private function revertDeletedEvent(GoogleCalendarService $calendar, array $change): array
    {
        $snapshot = $change['snapshot'] ?? null;
        if (! is_array($snapshot)) {
            return ['ok' => false, 'action' => 'delete_event', 'message' => 'Falta snapshot.'];
        }

        $event = $calendar->restoreEventSnapshot($snapshot);

        return ['ok' => true, 'action' => 'delete_event', 'event_id' => $event->getId(), 'message' => 'Evento eliminado recreado.'];
    }

    private function revertUpdatedEvent(GoogleCalendarService $calendar, array $change): array
    {
        $snapshot = $change['snapshot'] ?? null;
        $eventId = (string) ($change['event_id'] ?? ($snapshot['event_id'] ?? ''));
        if ($eventId === '' || ! is_array($snapshot)) {
            return ['ok' => false, 'action' => 'update_event', 'message' => 'Falta snapshot o event_id.'];
        }

        $calendar->updateEvent($eventId, $this->eventSnapshotUpdatePayload($snapshot));

        return ['ok' => true, 'action' => 'update_event', 'event_id' => $eventId, 'message' => 'Evento actualizado restaurado.'];
    }

    private function revertMovedEvent(GoogleCalendarService $calendar, array $change): array
    {
        $snapshot = $change['snapshot'] ?? [];
        $eventId = (string) ($change['event_id'] ?? ($snapshot['event_id'] ?? ''));
        if ($eventId === '' || ! is_array($snapshot) || empty($snapshot['start']) || empty($snapshot['end'])) {
            return ['ok' => false, 'action' => 'move_event', 'message' => 'Falta start/end original.'];
        }

        $calendar->updateEvent($eventId, [
            'start' => $snapshot['start'],
            'end' => $snapshot['end'],
        ]);

        return ['ok' => true, 'action' => 'move_event', 'event_id' => $eventId, 'message' => 'Evento movido devuelto a su hora original.'];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function eventSnapshotUpdatePayload(array $snapshot): array
    {
        return [
            'title' => $snapshot['title'] ?? '(Sin título)',
            'start' => $snapshot['start'] ?? null,
            'end' => $snapshot['end'] ?? null,
            'description' => $snapshot['description'] ?? null,
            'location' => $snapshot['location'] ?? null,
            'color' => $snapshot['color'] ?? null,
            'recurrence' => is_array($snapshot['recurrence'] ?? null) && ($snapshot['recurrence'] ?? []) !== []
                ? $snapshot['recurrence'][0]
                : '',
        ];
    }
}
