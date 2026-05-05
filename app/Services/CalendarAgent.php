<?php

namespace App\Services;

use App\Models\CalendarChatMessage;
use App\Models\CalendarMessageChangeLog;
use App\Models\User;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

final class CalendarAgent
{
    private const MAX_TOOL_LOOPS = 8;
    private const MAX_OPENROUTER_ATTEMPTS = 3;

    /**
     * @param  list<array{role: 'user'|'assistant', content: string}>  $conversation
     * @param  null|callable(string, array<string, mixed>): void  $emit
     */
    public function respond(User $user, array $conversation, ?callable $emit = null, ?CalendarChatMessage $changeMessage = null): string
    {
        $messages = array_merge([
            ['role' => 'system', 'content' => $this->systemPrompt()],
        ], $conversation);

        for ($i = 0; $i < self::MAX_TOOL_LOOPS; $i++) {
            $this->emit($emit, 'thinking', []);

            $message = $this->chatCompletionMessageWithRetry(
                $messages,
                [
                    'tools' => $this->tools(),
                    'tool_choice' => 'auto',
                    'temperature' => 0.2,
                ],
                $emit,
            );

            $toolCalls = $message['tool_calls'] ?? [];
            if (is_array($toolCalls) && $toolCalls !== []) {
                $messages[] = $this->assistantToolCallMessage($message);

                foreach ($toolCalls as $toolCall) {
                    if (! is_array($toolCall)) {
                        continue;
                    }

                    $toolCallId = (string) ($toolCall['id'] ?? '');
                    $function = $toolCall['function'] ?? [];
                    $toolName = is_array($function) ? (string) ($function['name'] ?? '') : '';
                    $arguments = is_array($function)
                        ? $this->decodeToolArguments($function['arguments'] ?? '{}')
                        : [];

                    $this->emit($emit, 'tool_call', [
                        'tool' => $toolName,
                        'params' => $arguments,
                    ]);

                    try {
                        $result = $this->executeTool($user, $toolName, $arguments, $changeMessage);
                    } catch (Throwable $e) {
                        report($e);

                        $result = [
                            'error' => $e->getMessage(),
                            'message' => 'La herramienta ha fallado temporalmente. Revisa los datos y vuelve a intentarlo con otra estrategia.',
                        ];
                    }

                    $this->emit($emit, 'tool_result', [
                        'tool' => $toolName,
                        'message' => $this->toolResultMessage($result),
                        'ok' => ! array_key_exists('error', $result),
                        'error' => $result['error'] ?? null,
                    ]);

                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $toolCallId,
                        'name' => $toolName,
                        'content' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ];
                }

                continue;
            }

            $content = $message['content'] ?? '';
            if (is_array($content)) {
                $content = collect($content)
                    ->map(fn ($part) => is_array($part) ? ($part['text'] ?? '') : $part)
                    ->filter(fn ($part) => is_string($part) && trim($part) !== '')
                    ->implode("\n");
            }

            if (! is_string($content) || trim($content) === '') {
                throw new RuntimeException('El modelo no devolvió una respuesta de texto.');
            }

            if ($emit) {
                $fallbackContent = trim($content);

                return $this->streamFinalResponse($messages, $emit, $fallbackContent);
            }

            $content = trim($content);
            $this->emit($emit, 'final_response', [
                'text' => $content,
            ]);

            return $content;
        }

        throw new RuntimeException('El agente alcanzó el límite de herramientas sin respuesta final.');
    }

    /**
     * @param  callable(string, array<string, mixed>): void  $emit
     */
    private function streamFinalResponse(array $messages, callable $emit, string $fallbackContent = ''): string
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= self::MAX_OPENROUTER_ATTEMPTS; $attempt++) {
            $streamedContent = '';

            try {
                $content = OpenRouter::streamChatCompletion(
                    $messages,
                    null,
                    [
                        'temperature' => 0.2,
                    ],
                    function (string $token) use ($emit, &$streamedContent): void {
                        $streamedContent .= $token;
                        $this->emit($emit, 'token', ['text' => $token]);
                    },
                );

                if (trim($content) === '' && $fallbackContent !== '') {
                    $content = $fallbackContent;
                    $this->emit($emit, 'token', ['text' => $content]);
                }

                $this->emit($emit, 'done', []);

                return trim($content);
            } catch (Throwable $e) {
                report($e);
                $lastException = $e;

                if (trim($streamedContent) !== '') {
                    $this->emit($emit, 'done', []);

                    return trim($fallbackContent) !== '' ? trim($fallbackContent) : trim($streamedContent);
                }

                if ($attempt < self::MAX_OPENROUTER_ATTEMPTS) {
                    $this->emitRetry($emit, $attempt + 1);
                    $this->pauseBeforeRetry($attempt);
                }
            }
        }

        if (trim($fallbackContent) !== '') {
            $this->emit($emit, 'token', ['text' => $fallbackContent]);
            $this->emit($emit, 'done', []);

            return trim($fallbackContent);
        }

        throw $lastException ?? new RuntimeException('OpenRouter no devolvió una respuesta en streaming.');
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function chatCompletionMessageWithRetry(array $messages, array $options, ?callable $emit): array
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= self::MAX_OPENROUTER_ATTEMPTS; $attempt++) {
            try {
                $response = OpenRouter::chatCompletion($messages, null, $options);
                $message = $response['choices'][0]['message'] ?? null;
                if (! is_array($message)) {
                    throw new RuntimeException('El modelo no devolvió un mensaje válido.');
                }

                return $message;
            } catch (Throwable $e) {
                report($e);
                $lastException = $e;

                if ($attempt < self::MAX_OPENROUTER_ATTEMPTS) {
                    $this->emitRetry($emit, $attempt + 1);
                    $this->pauseBeforeRetry($attempt);
                }
            }
        }

        throw $lastException ?? new RuntimeException('OpenRouter no devolvió un mensaje válido.');
    }

    private function emitRetry(?callable $emit, int $nextAttempt): void
    {
        $this->emit($emit, 'retry', [
            'attempt' => $nextAttempt,
            'max_attempts' => self::MAX_OPENROUTER_ATTEMPTS,
            'message' => "La IA ha fallado temporalmente. Reintentando ({$nextAttempt}/".self::MAX_OPENROUTER_ATTEMPTS.')...',
        ]);
    }

    private function pauseBeforeRetry(int $attempt): void
    {
        usleep($attempt * 350000);
    }

    /**
     * @param  null|callable(string, array<string, mixed>): void  $emit
     * @param  array<string, mixed>  $payload
     */
    private function emit(?callable $emit, string $event, array $payload): void
    {
        if ($emit) {
            $emit($event, $payload);
        }
    }

    private function systemPrompt(): string
    {
        $now = now('Europe/Madrid');

        return <<<PROMPT
Eres un asistente de calendario personal.

Fecha y hora actual: {$now->toDateTimeString()} Europe/Madrid ({$now->toIso8601String()}).

Ayuda al usuario a consultar y gestionar su calendario. Cuando pregunte por sus eventos, disponibilidad, agenda de un día, esta semana o cualquier rango de fechas, usa las herramientas disponibles. También puedes crear, modificar, mover y eliminar eventos cuando el usuario lo pida claramente. No inventes eventos: si no tienes datos, consulta el calendario o dilo claramente. Para búsqueda semántica sobre eventos ya indexados (embeddings) con filtros opcionales de fecha u hora, usa search_calendar_events_index; si un evento no aparece en el índice (p. ej. acaba de crearse), usa get_calendar_events.

Reglas importantes al modificar calendario:
- Evita duplicados. Antes de crear un evento que parece reemplazar, corregir o ampliar una petición anterior, consulta el calendario y limpia o actualiza los eventos relacionados.
- Si el usuario corrige el mensaje anterior con frases como "no", "nono", "me refería", "quiero que..." o "en vez de...", interpreta la nueva petición como una sustitución del cambio anterior, no como una acción adicional independiente.
- En correcciones, identifica los eventos previos por título, hora, duración y rango mencionado en la conversación. Si creaste eventos sueltos y ahora el usuario pide una repetición indefinida, borra primero esos eventos sueltos relacionados y luego crea la serie correcta.
- Prefiere actualizar o borrar eventos existentes relacionados antes que crear otros nuevos encima.
- Para cambiar título, descripción, ubicación, color, fecha u hora de un evento existente, usa update_event. No borres y recrees un evento si el cambio puede hacerse actualizando sus campos.
- Si no puedes identificar con seguridad qué eventos sustituir, pregunta antes de modificar.

Incertidumbre y tono (obligatorio):
- No inventes ni afirmes hechos que no provengan de los datos de las herramientas. Si algo es una inferencia, dilo con cautela o pregunta.
- No actúes con seguridad aparente si no lo estás: antes de mover, borrar o actualizar, si hay duda razonable, pregunta con una sola pregunta concreta (fecha/hora, título exacto o confirmación sí/no).
- Tras search_calendar_events_index: el ranking por embeddings puede devolver eventos cuyo título no coincide con las palabras que dijo el usuario (ej. buscó "gym" y el candidato se llama "Ejercicio"). Si el título del evento no es obviamente el mismo que pidió el usuario (misma expresión, inclusión clara o sinónimo evidente en el mismo idioma), no ejecutes move_event, update_event ni delete_event hasta confirmar; pregunta, por ejemplo: "¿Te refieres al evento «Ejercicio» el [fecha] a las [hora]?".
- Si hay varios candidatos con puntuaciones parecidas o ninguno encaja bien con lo dicho, pregunta cuál es el correcto en lugar de elegir tú solo.

Reglas de interpretación de fechas y búsqueda:
- Para días de la semana sin modificador claro ("el viernes", "el lunes"), no asumas siempre futuro. Interpreta según el contexto conversacional y la fecha actual; si hoy es sábado, "el viernes" puede referirse a ayer.
- Antes de eliminar, mover o actualizar por nombre + día ambiguo, consulta un rango suficiente que incluya la semana actual y días cercanos. Si no encuentras el evento en la primera fecha inferida, itera: busca también el viernes anterior/siguiente o un rango de 7-14 días alrededor antes de decir que no existe.
- Si hay un match claro por título y día cercano (por ejemplo el resumen del evento contiene literalmente lo que dijo el usuario o es inequívoco), puedes actuar y resume con el título real del calendario.
- Pregunta al usuario no solo cuando no hay coincidencias o hay empate entre varias, sino también cuando la coincidencia es solo semántica y el nombre del evento no encaja claramente con lo que pidió.

Responde en español, de forma breve y útil. Si una petición es ambigua, pregunta lo mínimo necesario.
PROMPT;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tools(): array
    {
        return [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_calendar_events',
                    'description' => 'Consulta eventos de Google Calendar para un rango de días consecutivos. Para modificar/eliminar eventos con fechas relativas ambiguas, usa rangos amplios e iterativos antes de concluir que no existen.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'start_date' => [
                                'type' => 'string',
                                'description' => 'Primer día a consultar en formato YYYY-MM-DD.',
                            ],
                            'num_days' => [
                                'type' => 'integer',
                                'description' => 'Número de días a incluir desde start_date. Por defecto 1. Usa 7 para una semana entera.',
                                'minimum' => 1,
                                'maximum' => 31,
                            ],
                        ],
                        'required' => ['start_date'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'search_calendar_events_index',
                    'description' => 'Busca en el índice local de eventos del usuario. Si indicas query_text, se convierte en embedding y se ordena por similitud. start_date/end_date filtran por fecha de inicio del evento (Rango: inicio >= start_date y/o inicio <= end_date). start_time/end_time filtran por hora de inicio (HH:MM o HH:MM:SS); los eventos sin hora (all-day) quedan fuera si usas filtros de hora. Todos los parámetros son opcionales pero debe haber al menos query_text o algún filtro.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'query_text' => [
                                'type' => 'string',
                                'description' => 'Texto libre para búsqueda semántica (vacío si solo filtras por fecha/hora).',
                            ],
                            'start_date' => [
                                'type' => 'string',
                                'description' => 'Fecha mínima de inicio del evento, YYYY-MM-DD.',
                            ],
                            'end_date' => [
                                'type' => 'string',
                                'description' => 'Fecha máxima de inicio del evento, YYYY-MM-DD.',
                            ],
                            'start_time' => [
                                'type' => 'string',
                                'description' => 'Hora mínima de inicio; HH:MM o HH:MM:SS (Europe/Madrid).',
                            ],
                            'end_time' => [
                                'type' => 'string',
                                'description' => 'Hora máxima de inicio; HH:MM o HH:MM:SS (Europe/Madrid).',
                            ],
                            'limit' => [
                                'type' => 'integer',
                                'description' => 'Máximo de resultados (por defecto 15, máximo 50).',
                                'minimum' => 1,
                                'maximum' => 50,
                            ],
                        ],
                        'required' => [],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'create_event',
                    'description' => 'Crea un evento nuevo en Google Calendar. Antes de usarla en una corrección o reemplazo, elimina o actualiza primero eventos relacionados para evitar duplicados. Usa fechas/hora ISO 8601 con zona horaria cuando sea posible.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'start' => ['type' => 'string', 'description' => 'Inicio del evento, preferiblemente ISO 8601.'],
                            'end' => ['type' => 'string', 'description' => 'Fin del evento, preferiblemente ISO 8601.'],
                            'description' => ['type' => 'string'],
                            'location' => ['type' => 'string'],
                            'color' => ['type' => 'string', 'description' => 'Color del evento. Acepta ColorId 1..11 o nombres como azul, rojo, verde, amarillo, naranja, morado, rosa, gris, turquesa.'],
                            'recurrence' => ['type' => 'string', 'description' => 'Regla RRULE, por ejemplo RRULE:FREQ=WEEKLY;BYDAY=MO.'],
                        ],
                        'required' => ['title', 'start', 'end'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'update_event',
                    'description' => 'Modifica campos de un evento existente sin borrarlo. Úsala para cambiar título, descripción, ubicación, color, fecha, hora o recurrencia cuando puedas identificar el event_id.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'event_id' => ['type' => 'string'],
                            'title' => ['type' => 'string'],
                            'start' => ['type' => 'string'],
                            'end' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                            'location' => ['type' => 'string'],
                            'color' => ['type' => 'string', 'description' => 'Color del evento. Acepta ColorId 1..11 o nombres como azul, rojo, verde, amarillo, naranja, morado, rosa, gris, turquesa.'],
                            'recurrence' => ['type' => 'string', 'description' => 'Regla RRULE. Cadena vacía para quitar repetición.'],
                        ],
                        'required' => ['event_id'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'delete_event',
                    'description' => 'Elimina un evento existente. Úsala para limpiar eventos obsoletos cuando una corrección del usuario reemplaza un cambio anterior.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'event_id' => ['type' => 'string'],
                        ],
                        'required' => ['event_id'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'move_event',
                    'description' => 'Mueve un evento manteniendo su duración original.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'event_id' => ['type' => 'string'],
                            'new_start' => ['type' => 'string', 'description' => 'Nuevo inicio, preferiblemente ISO 8601.'],
                        ],
                        'required' => ['event_id', 'new_start'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    private function assistantToolCallMessage(array $message): array
    {
        return [
            'role' => 'assistant',
            'content' => $message['content'] ?? null,
            'tool_calls' => $message['tool_calls'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeToolArguments(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }

        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function executeTool(User $user, string $toolName, array $arguments, ?CalendarChatMessage $changeMessage = null): array
    {
        return match ($toolName) {
            'get_calendar_events' => $this->getCalendarEvents($user, $arguments),
            'search_calendar_events_index' => app(CalendarEventIndexSync::class)->search($user, $arguments),
            'create_event' => $this->createEvent($user, $arguments, $changeMessage),
            'update_event' => $this->updateEvent($user, $arguments, $changeMessage),
            'delete_event' => $this->deleteEvent($user, $arguments, $changeMessage),
            'move_event' => $this->moveEvent($user, $arguments, $changeMessage),
            default => [
                'error' => "Herramienta desconocida: {$toolName}",
            ],
        };
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function toolResultMessage(array $result): string
    {
        return (string) ($result['message'] ?? 'Herramienta ejecutada.');
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function getCalendarEvents(User $user, array $arguments): array
    {
        $startDate = (string) ($arguments['start_date'] ?? '');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
            return [
                'events' => [],
                'message' => 'start_date debe tener formato YYYY-MM-DD.',
            ];
        }

        $numDays = max(1, min(31, (int) ($arguments['num_days'] ?? 1)));
        $timeMin = Carbon::createFromFormat('Y-m-d', $startDate, 'Europe/Madrid')->startOfDay();
        $timeMax = $timeMin->copy()->addDays($numDays);

        $calendar = new GoogleCalendarService($user);
        $events = $calendar->getEvents(timeMin: $timeMin, timeMax: $timeMax)->getItems();

        $payload = collect($events)->map(function ($event): array {
            $start = $event->getStart();
            $end = $event->getEnd();
            $item = [
                'id' => $event->getId(),
                'title' => $event->getSummary() ?: '(Sin título)',
                'start' => $start?->getDateTime() ?: $start?->getDate(),
                'end' => $end?->getDateTime() ?: $end?->getDate(),
            ];

            if ($event->getDescription()) {
                $item['description'] = $event->getDescription();
            }

            if ($event->getLocation()) {
                $item['location'] = $event->getLocation();
            }

            if ($event->getColorId()) {
                $item['color'] = $event->getColorId();
            }

            return $item;
        })->values()->all();

        return [
            'events' => $payload,
            'message' => $payload === []
                ? 'No hay eventos en el rango consultado.'
                : 'Eventos encontrados: '.count($payload).'.',
            'range' => [
                'start' => $timeMin->toDateString(),
                'end_exclusive' => $timeMax->toDateString(),
                'num_days' => $numDays,
            ],
        ];
    }

    private function createEvent(User $user, array $arguments, ?CalendarChatMessage $changeMessage): array
    {
        foreach (['title', 'start', 'end'] as $field) {
            if (trim((string) ($arguments[$field] ?? '')) === '') {
                return ['error' => "{$field} es obligatorio."];
            }
        }

        $calendar = new GoogleCalendarService($user);
        $color = $this->normalizeCalendarColor($arguments['color'] ?? null);
        $event = $calendar->createEvent([
            'title' => (string) $arguments['title'],
            'start' => Carbon::parse((string) $arguments['start'], 'Europe/Madrid')->toRfc3339String(),
            'end' => Carbon::parse((string) $arguments['end'], 'Europe/Madrid')->toRfc3339String(),
            'description' => $arguments['description'] ?? null,
            'location' => $arguments['location'] ?? null,
            'color' => $color,
            'recurrence' => $arguments['recurrence'] ?? null,
        ]);
        $snapshot = $calendar->eventSnapshot($event);

        $this->appendCalendarChange($changeMessage, [
            'action' => 'create_event',
            'event_id' => $event->getId(),
        ]);

        return [
            'event' => $snapshot,
            'message' => "Evento creado: {$snapshot['title']}.",
        ];
    }

    private function updateEvent(User $user, array $arguments, ?CalendarChatMessage $changeMessage): array
    {
        $eventId = trim((string) ($arguments['event_id'] ?? ''));
        if ($eventId === '') {
            return ['error' => 'event_id es obligatorio.'];
        }

        $updates = array_intersect_key($arguments, array_flip([
            'title',
            'start',
            'end',
            'description',
            'location',
            'color',
            'recurrence',
        ]));
        if ($updates === []) {
            return ['error' => 'No hay campos para modificar.'];
        }

        if (array_key_exists('color', $updates)) {
            $updates['color'] = $this->normalizeCalendarColor($updates['color']);
        }

        $calendar = new GoogleCalendarService($user);
        $before = $calendar->eventSnapshot($calendar->getEvent($eventId));
        $event = $calendar->updateEvent($eventId, $this->normalizeEventUpdates($updates));
        $after = $calendar->eventSnapshot($event);

        $this->appendCalendarChange($changeMessage, [
            'action' => 'update_event',
            'event_id' => $eventId,
            'snapshot' => $before,
            'updated_fields' => array_keys($updates),
        ]);

        return [
            'event' => $after,
            'message' => "Evento actualizado: {$after['title']}.",
        ];
    }

    private function deleteEvent(User $user, array $arguments, ?CalendarChatMessage $changeMessage): array
    {
        $eventId = trim((string) ($arguments['event_id'] ?? ''));
        if ($eventId === '') {
            return ['error' => 'event_id es obligatorio.'];
        }

        $calendar = new GoogleCalendarService($user);
        $snapshot = $calendar->eventSnapshot($calendar->getEvent($eventId));
        $calendar->deleteEvent($eventId);

        $this->appendCalendarChange($changeMessage, [
            'action' => 'delete_event',
            'snapshot' => $snapshot,
        ]);

        return [
            'event_id' => $eventId,
            'message' => "Evento eliminado: {$snapshot['title']}.",
        ];
    }

    private function moveEvent(User $user, array $arguments, ?CalendarChatMessage $changeMessage): array
    {
        $eventId = trim((string) ($arguments['event_id'] ?? ''));
        $newStartRaw = trim((string) ($arguments['new_start'] ?? ''));
        if ($eventId === '' || $newStartRaw === '') {
            return ['error' => 'event_id y new_start son obligatorios.'];
        }

        $calendar = new GoogleCalendarService($user);
        $before = $calendar->eventSnapshot($calendar->getEvent($eventId));
        $start = Carbon::parse((string) $before['start'], 'Europe/Madrid');
        $end = Carbon::parse((string) $before['end'], 'Europe/Madrid');
        $durationSeconds = max(60, $end->getTimestamp() - $start->getTimestamp());
        $newStart = Carbon::parse($newStartRaw, 'Europe/Madrid');
        $newEnd = $newStart->copy()->addSeconds($durationSeconds);
        $event = $calendar->moveTimedEvent($eventId, $newStart, $newEnd);
        $after = $calendar->eventSnapshot($event);

        $this->appendCalendarChange($changeMessage, [
            'action' => 'move_event',
            'event_id' => $eventId,
            'snapshot' => [
                'event_id' => $eventId,
                'start' => $before['start'],
                'end' => $before['end'],
            ],
        ]);

        return [
            'event' => $after,
            'message' => "Evento movido: {$after['title']}.",
        ];
    }

    /**
     * @param  array<string, mixed>  $updates
     * @return array<string, mixed>
     */
    private function normalizeEventUpdates(array $updates): array
    {
        if (isset($updates['start'])) {
            $updates['start'] = Carbon::parse((string) $updates['start'], 'Europe/Madrid')->toRfc3339String();
        }

        if (isset($updates['end'])) {
            $updates['end'] = Carbon::parse((string) $updates['end'], 'Europe/Madrid')->toRfc3339String();
        }

        return $updates;
    }

    private function normalizeCalendarColor(mixed $color): ?string
    {
        if ($color === null) {
            return null;
        }

        $value = strtolower(trim((string) $color));
        if ($value === '') {
            return null;
        }

        if (preg_match('/^(?:[1-9]|10|11)$/', $value)) {
            return $value;
        }

        $colors = [
            'lavanda' => '1',
            'lila' => '1',
            'salvia' => '2',
            'uva' => '3',
            'morado' => '3',
            'violeta' => '3',
            'flamenco' => '4',
            'rosa' => '4',
            'banana' => '5',
            'amarillo' => '5',
            'mandarina' => '6',
            'naranja' => '6',
            'pavo real' => '7',
            'turquesa' => '7',
            'cyan' => '7',
            'cian' => '7',
            'grafito' => '8',
            'gris' => '8',
            'arándano' => '9',
            'arandano' => '9',
            'azul' => '9',
            'albahaca' => '10',
            'verde' => '10',
            'tomate' => '11',
            'rojo' => '11',
        ];

        return $colors[$value] ?? $value;
    }

    private function appendCalendarChange(?CalendarChatMessage $message, array $change): void
    {
        if (! $message) {
            return;
        }

        $log = $message->changeLog ?: CalendarMessageChangeLog::create([
            'calendar_chat_message_id' => $message->id,
            'changes' => [],
        ]);

        $changes = $log->changes ?? [];
        $changes[] = $change;
        $log->forceFill([
            'changes' => $changes,
            'reverted_at' => null,
            'revert_result' => null,
        ])->save();
        $message->setRelation('changeLog', $log);
    }
}
