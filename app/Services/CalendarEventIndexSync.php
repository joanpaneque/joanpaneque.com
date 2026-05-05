<?php

namespace App\Services;

use App\Models\CalendarEventIndex;
use App\Models\User;
use Google\Service\Calendar\Event;
use Google\Service\Calendar\EventDateTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class CalendarEventIndexSync
{
    private const EMBEDDING_DIMENSIONS = 1024;

    /** @var array<string, Event> */
    private array $recurringMasterCache = [];

    /**
     * @param  iterable<Event>  $events
     */
    public function syncEvents(User $user, iterable $events, ?GoogleCalendarService $calendar = null): void
    {
        foreach ($events as $event) {
            $this->syncEvent($user, $event, $calendar);
        }
    }

    public function syncEvent(User $user, Event $event, ?GoogleCalendarService $calendar = null): CalendarEventIndex
    {
        $attributes = $this->attributesForEvent($user, $event, $calendar);
        $embeddingInput = $this->embeddingInput($attributes);
        $fingerprint = hash('sha256', $embeddingInput);
        $embeddingModel = $this->embeddingModel();

        $index = CalendarEventIndex::query()
            ->where('user_id', $user->id)
            ->where('google_event_id', $attributes['google_event_id'])
            ->first();

        $shouldGenerateEmbedding = ! $index
            || $index->embedding_fingerprint !== $fingerprint
            || $index->embedding_model !== $embeddingModel
            || ! $index->embedding_generated_at;

        $index = CalendarEventIndex::updateOrCreate(
            [
                'user_id' => $user->id,
                'google_event_id' => $attributes['google_event_id'],
            ],
            [
                ...$attributes,
                'embedding_input' => $embeddingInput,
                'embedding_model' => $embeddingModel,
            ],
        );

        if ($shouldGenerateEmbedding) {
            $this->generateAndStoreEmbedding($index, $embeddingInput, $fingerprint);
        }

        return $index;
    }

    public function deleteEvent(User $user, string $eventId): void
    {
        CalendarEventIndex::query()
            ->where('user_id', $user->id)
            ->where('google_event_id', $eventId)
            ->delete();
    }

    /**
     * Búsqueda en el índice local: similitud semántica (embedding) opcional + filtros de fecha/hora opcionales.
     * Requiere al menos query_text o un filtro de fecha o hora.
     *
     * @param  array<string, mixed>  $arguments
     * @return array{events: list<array<string, mixed>>, message: string, error?: string}
     */
    public function search(User $user, array $arguments): array
    {
        $queryText = trim((string) ($arguments['query_text'] ?? ''));
        $limit = max(1, min(50, (int) ($arguments['limit'] ?? 15)));

        $startDate = $this->optionalDateArg($arguments['start_date'] ?? null, 'start_date');
        $endDate = $this->optionalDateArg($arguments['end_date'] ?? null, 'end_date');
        $startTime = $this->optionalTimeArg($arguments['start_time'] ?? null, 'start_time');
        $endTime = $this->optionalTimeArg($arguments['end_time'] ?? null, 'end_time');

        if (isset($startDate['error'])) {
            return $this->finalizeSearch($user, $arguments, ['events' => [], 'message' => $startDate['error'], 'error' => 'fecha_invalida']);
        }
        if (isset($endDate['error'])) {
            return $this->finalizeSearch($user, $arguments, ['events' => [], 'message' => $endDate['error'], 'error' => 'fecha_invalida']);
        }
        if (isset($startTime['error'])) {
            return $this->finalizeSearch($user, $arguments, ['events' => [], 'message' => $startTime['error'], 'error' => 'hora_invalida']);
        }
        if (isset($endTime['error'])) {
            return $this->finalizeSearch($user, $arguments, ['events' => [], 'message' => $endTime['error'], 'error' => 'hora_invalida']);
        }

        $hasText = $queryText !== '';
        $hasFilter = $startDate['value'] !== null || $endDate['value'] !== null
            || $startTime['value'] !== null || $endTime['value'] !== null;

        if (! $hasText && ! $hasFilter) {
            return $this->finalizeSearch($user, $arguments, [
                'events' => [],
                'message' => 'Indica query_text y/o al menos un filtro (start_date, end_date, start_time o end_time).',
                'error' => 'sin_criterios',
            ]);
        }

        $base = CalendarEventIndex::query()->where('user_id', $user->id);

        if ($startDate['value'] !== null) {
            $base->whereDate('start_date', '>=', $startDate['value']);
        }
        if ($endDate['value'] !== null) {
            $base->whereDate('start_date', '<=', $endDate['value']);
        }
        if ($startTime['value'] !== null) {
            $base->whereNotNull('start_time')->whereTime('start_time', '>=', $startTime['value']);
        }
        if ($endTime['value'] !== null) {
            $base->whereNotNull('start_time')->whereTime('start_time', '<=', $endTime['value']);
        }

        if (! $hasText) {
            $rows = (clone $base)
                ->orderBy('start_date')
                ->orderBy('start_time')
                ->limit($limit)
                ->get();

            return $this->finalizeSearch($user, $arguments, [
                'events' => $rows->map(fn (CalendarEventIndex $row) => $this->searchResultPayload($row))->values()->all(),
                'message' => $rows->isEmpty()
                    ? 'Ningún evento en el índice coincide con los filtros.'
                    : 'Eventos encontrados: '.$rows->count().'.',
            ]);
        }

        $vector = OpenRouter::createEmbedding($this->queryEmbeddingInput($queryText));
        if (count($vector) !== self::EMBEDDING_DIMENSIONS) {
            throw new RuntimeException('El embedding de la consulta debe tener '.self::EMBEDDING_DIMENSIONS.' dimensiones; recibido '.count($vector).'.');
        }

        if ($this->usesNativeVectorColumn()) {
            return $this->finalizeSearch($user, $arguments, $this->searchWithPgvector($base, $vector, $limit));
        }

        return $this->finalizeSearch($user, $arguments, $this->searchWithJsonEmbeddings($base, $vector, $limit));
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array{events: list<array<string, mixed>>, message: string, error?: string}  $result
     * @return array{events: list<array<string, mixed>>, message: string, error?: string}
     */
    private function finalizeSearch(User $user, array $arguments, array $result): array
    {
        $this->logSearchResults($user, $arguments, $result);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array{events: list<array<string, mixed>>, message: string, error?: string}  $result
     */
    private function logSearchResults(User $user, array $arguments, array $result): void
    {
        $criteria = array_filter(
            [
                'query_text' => $arguments['query_text'] ?? null,
                'start_date' => $arguments['start_date'] ?? null,
                'end_date' => $arguments['end_date'] ?? null,
                'start_time' => $arguments['start_time'] ?? null,
                'end_time' => $arguments['end_time'] ?? null,
                'limit' => $arguments['limit'] ?? null,
            ],
            fn (mixed $v): bool => $v !== null && $v !== ''
        );

        $payload = [
            'user_id' => $user->id,
            'criteria' => $criteria,
            'message' => $result['message'] ?? '',
            'events_count' => count($result['events'] ?? []),
            'events' => $result['events'] ?? [],
        ];
        if (isset($result['error'])) {
            $payload['error'] = $result['error'];
        }

        $pretty = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($pretty === false) {
            Log::info('[calendar index search] (json_encode falló)', ['user_id' => $user->id]);

            return;
        }

        Log::info("[calendar index search]\n".$pretty);
    }

    /**
     * @return array{value: ?string, error?: string}
     */
    private function optionalDateArg(mixed $raw, string $field): array
    {
        if ($raw === null || $raw === '') {
            return ['value' => null];
        }

        $s = trim((string) $raw);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
            return ['value' => null, 'error' => "{$field} debe ser YYYY-MM-DD."];
        }

        try {
            Carbon::createFromFormat('Y-m-d', $s);
        } catch (Throwable) {
            return ['value' => null, 'error' => "{$field} no es una fecha válida."];
        }

        return ['value' => $s];
    }

    /**
     * @return array{value: ?string, error?: string}
     */
    private function optionalTimeArg(mixed $raw, string $field): array
    {
        if ($raw === null || $raw === '') {
            return ['value' => null];
        }

        $s = trim((string) $raw);
        if (preg_match('/^\d{1,2}:\d{2}$/', $s)) {
            $s .= ':00';
        }
        if (! preg_match('/^\d{1,2}:\d{2}:\d{2}$/', $s)) {
            return ['value' => null, 'error' => "{$field} debe ser HH:MM o HH:MM:SS."];
        }

        try {
            Carbon::parse($s, 'Europe/Madrid')->format('H:i:s');
        } catch (Throwable) {
            return ['value' => null, 'error' => "{$field} no es una hora válida."];
        }

        return ['value' => Carbon::parse($s, 'Europe/Madrid')->format('H:i:s')];
    }

    private function queryEmbeddingInput(string $text): string
    {
        return 'query: '.$text;
    }

    /**
     * @return array{events: list<array<string, mixed>>, message: string}
     */
    private function searchWithPgvector(Builder $base, array $vector, int $limit): array
    {
        $literal = str_replace("'", "''", $this->vectorLiteral($vector));

        $rows = (clone $base)
            ->selectRaw('calendar_event_indexes.*, (embedding <=> \''.$literal.'\'::vector) as embedding_distance')
            ->orderByRaw('embedding <=> \''.$literal.'\'::vector')
            ->limit($limit)
            ->get();

        $events = $rows->map(function (CalendarEventIndex $row): array {
            $distance = (float) $row->getAttribute('embedding_distance');
            $payload = $this->searchResultPayload($row);
            $payload['similarity'] = round(max(0.0, min(1.0, 1.0 - $distance)), 6);

            return $payload;
        })->values()->all();


        return [
            'events' => $events,
            'message' => $events === []
                ? 'Ningún evento en el índice coincide con la búsqueda.'
                : 'Coincidencias por similitud: '.count($events).'.',
        ];
    }

    /**
     * @return array{events: list<array<string, mixed>>, message: string}
     */
    private function searchWithJsonEmbeddings(Builder $base, array $vector, int $limit): array
    {
        $idQuery = (clone $base)->select('calendar_event_indexes.id')->limit(800);
        $ids = $idQuery->pluck('id')->all();
        if ($ids === []) {
            return [
                'events' => [],
                'message' => 'Ningún evento en el índice coincide con los filtros.',
            ];
        }

        $rows = DB::table('calendar_event_indexes')
            ->whereIn('id', $ids)
            ->get();

        $scored = [];
        foreach ($rows as $row) {
            $decoded = json_decode((string) ($row->embedding ?? ''), true);
            if (! is_array($decoded)) {
                continue;
            }
            $vec = [];
            foreach ($decoded as $x) {
                if (is_int($x) || is_float($x)) {
                    $vec[] = (float) $x;
                }
            }
            if (count($vec) !== self::EMBEDDING_DIMENSIONS) {
                continue;
            }
            $scored[] = [
                'id' => (int) $row->id,
                'similarity' => $this->cosineSimilarity($vector, $vec),
            ];
        }

        usort($scored, fn (array $a, array $b): int => $b['similarity'] <=> $a['similarity']);
        $scored = array_slice($scored, 0, $limit);
        $topIds = array_column($scored, 'id');
        if ($topIds === []) {
            return [
                'events' => [],
                'message' => 'No hay embeddings almacenados para rankear; sincroniza el calendario o revisa errores de embedding.',
            ];
        }

        $models = CalendarEventIndex::query()
            ->whereIn('id', $topIds)
            ->get()
            ->keyBy('id');

        $events = [];
        foreach ($scored as $item) {
            $model = $models->get($item['id']);
            if (! $model) {
                continue;
            }
            $payload = $this->searchResultPayload($model);
            $payload['similarity'] = round($item['similarity'], 6);
            $events[] = $payload;
        }

        return [
            'events' => $events,
            'message' => $events === []
                ? 'Ningún evento en el índice coincide con la búsqueda.'
                : 'Coincidencias por similitud: '.count($events).'.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function searchResultPayload(CalendarEventIndex $row): array
    {
        return [
            'google_event_id' => $row->google_event_id,
            'title' => $row->title,
            'start_date' => $row->start_date?->toDateString(),
            'end_date' => $row->end_date?->toDateString(),
            'start_time' => $row->start_time,
            'end_time' => $row->end_time,
            'color' => $row->color,
            'is_all_day' => $row->is_all_day,
            'is_recurring' => $row->is_recurring,
            'recurrence_frequency' => $row->recurrence_frequency,
            'recurrence_interval' => $row->recurrence_interval,
            'recurrence_by_day' => $row->recurrence_by_day,
            'recurrence_until' => $row->recurrence_until?->toIso8601String(),
            'recurrence_count' => $row->recurrence_count,
        ];
    }

    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    private function cosineSimilarity(array $a, array $b): float
    {
        $n = min(count($a), count($b));
        if ($n === 0) {
            return 0.0;
        }

        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $dot += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }

        if ($normA <= 0.0 || $normB <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }

    /**
     * @return array<string, mixed>
     */
    private function attributesForEvent(User $user, Event $event, ?GoogleCalendarService $calendar): array
    {
        $start = $event->getStart();
        $end = $event->getEnd();
        $recurringEventId = $event->getRecurringEventId();
        $recurrence = $this->recurrenceForEvent($event, $calendar);
        $parsedRecurrence = $this->parseRecurrence($recurrence);

        return [
            'user_id' => $user->id,
            'google_event_id' => (string) $event->getId(),
            'google_recurring_event_id' => $recurringEventId ?: null,
            'title' => $event->getSummary() ?: '(Sin título)',
            'start_date' => $this->dateValue($start),
            'end_date' => $this->dateValue($end),
            'start_time' => $this->timeValue($start),
            'end_time' => $this->timeValue($end),
            'color' => $event->getColorId(),
            'is_all_day' => (bool) ($start?->getDate() && ! $start?->getDateTime()),
            'is_recurring' => $recurringEventId !== null || $recurrence !== [],
            'recurrence' => $recurrence ?: null,
            ...$parsedRecurrence,
        ];
    }

    /**
     * @return list<string>
     */
    private function recurrenceForEvent(Event $event, ?GoogleCalendarService $calendar): array
    {
        $recurrence = $event->getRecurrence() ?? [];
        if ($recurrence !== [] || ! $calendar || ! $event->getRecurringEventId()) {
            return $recurrence;
        }

        $masterId = $event->getRecurringEventId();
        if (! isset($this->recurringMasterCache[$masterId])) {
            try {
                $this->recurringMasterCache[$masterId] = $calendar->getEvent($masterId);
            } catch (Throwable $e) {
                report($e);

                return [];
            }
        }

        return $this->recurringMasterCache[$masterId]->getRecurrence() ?? [];
    }

    /**
     * @param  list<string>  $recurrence
     * @return array<string, mixed>
     */
    private function parseRecurrence(array $recurrence): array
    {
        $rule = collect($recurrence)->first(
            fn (string $item): bool => str_starts_with(strtoupper($item), 'RRULE:'),
        );
        if (! is_string($rule)) {
            return [
                'recurrence_rule' => null,
                'recurrence_frequency' => null,
                'recurrence_interval' => null,
                'recurrence_by_day' => null,
                'recurrence_until' => null,
                'recurrence_count' => null,
            ];
        }

        $parts = [];
        foreach (explode(';', substr($rule, strlen('RRULE:'))) as $part) {
            [$key, $value] = array_pad(explode('=', $part, 2), 2, null);
            if (is_string($key) && is_string($value)) {
                $parts[strtoupper($key)] = $value;
            }
        }

        return [
            'recurrence_rule' => $rule,
            'recurrence_frequency' => isset($parts['FREQ']) ? strtolower($parts['FREQ']) : null,
            'recurrence_interval' => isset($parts['INTERVAL']) ? max(1, (int) $parts['INTERVAL']) : null,
            'recurrence_by_day' => isset($parts['BYDAY']) ? array_values(array_filter(explode(',', $parts['BYDAY']))) : null,
            'recurrence_until' => isset($parts['UNTIL']) ? $this->parseUntil($parts['UNTIL']) : null,
            'recurrence_count' => isset($parts['COUNT']) ? max(1, (int) $parts['COUNT']) : null,
        ];
    }

    private function parseUntil(string $until): ?Carbon
    {
        foreach (['Ymd\THis\Z', 'Ymd'] as $format) {
            try {
                $date = Carbon::createFromFormat($format, $until, 'UTC');

                return $date ? $date->setTimezone('Europe/Madrid') : null;
            } catch (Throwable) {
                //
            }
        }

        return null;
    }

    private function dateValue(?EventDateTime $value): ?string
    {
        $raw = $value?->getDateTime() ?: $value?->getDate();
        if (! $raw) {
            return null;
        }

        return Carbon::parse($raw, 'Europe/Madrid')->toDateString();
    }

    private function timeValue(?EventDateTime $value): ?string
    {
        $raw = $value?->getDateTime();
        if (! $raw) {
            return null;
        }

        return Carbon::parse($raw, 'Europe/Madrid')->format('H:i:s');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function embeddingInput(array $attributes): string
    {
        $recurrence = $attributes['is_recurring']
            ? sprintf(
                'si; regla=%s; frecuencia=%s; dias=%s; hasta=%s; count=%s',
                (string) ($attributes['recurrence_rule'] ?? ''),
                (string) ($attributes['recurrence_frequency'] ?? ''),
                implode(',', (array) ($attributes['recurrence_by_day'] ?? [])),
                $attributes['recurrence_until'] instanceof Carbon
                    ? $attributes['recurrence_until']->toDateString()
                    : '',
                (string) ($attributes['recurrence_count'] ?? ''),
            )
            : 'no';

        return implode("\n", [
            'passage: evento de calendario',
            'titulo: '.(string) ($attributes['title'] ?? ''),
            'start_date: '.(string) ($attributes['start_date'] ?? ''),
            'end_date: '.(string) ($attributes['end_date'] ?? ''),
            'start_time: '.(string) ($attributes['start_time'] ?? ''),
            'end_time: '.(string) ($attributes['end_time'] ?? ''),
            'color: '.(string) ($attributes['color'] ?? ''),
            'repetido: '.$recurrence,
        ]);
    }

    private function generateAndStoreEmbedding(CalendarEventIndex $index, string $input, string $fingerprint): void
    {
        try {
            $embedding = OpenRouter::createEmbedding($input);
            if (count($embedding) !== self::EMBEDDING_DIMENSIONS) {
                throw new \RuntimeException('El embedding debe tener '.self::EMBEDDING_DIMENSIONS.' dimensiones; recibido '.count($embedding).'.');
            }

            $payload = [
                'embedding_model' => $this->embeddingModel(),
                'embedding_fingerprint' => $fingerprint,
                'embedding_generated_at' => now(),
            ];

            if ($this->usesNativeVectorColumn()) {
                DB::table('calendar_event_indexes')
                    ->where('id', $index->id)
                    ->update([
                        ...$payload,
                        'embedding' => DB::raw("'".$this->vectorLiteral($embedding)."'::vector"),
                    ]);
            } else {
                DB::table('calendar_event_indexes')
                    ->where('id', $index->id)
                    ->update([
                        ...$payload,
                        'embedding' => json_encode($embedding, JSON_THROW_ON_ERROR),
                    ]);
            }
        } catch (Throwable $e) {
            report($e);
            Log::warning('No se pudo generar embedding del evento de calendario', [
                'calendar_event_index_id' => $index->id,
                'google_event_id' => $index->google_event_id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  list<float>  $embedding
     */
    private function vectorLiteral(array $embedding): string
    {
        return '['.implode(',', array_map(
            fn (float $value): string => rtrim(rtrim(sprintf('%.12F', $value), '0'), '.'),
            $embedding,
        )).']';
    }

    private function embeddingModel(): string
    {
        return (string) config('services.openrouter.embedding_model', 'intfloat/multilingual-e5-large');
    }

    private function usesNativeVectorColumn(): bool
    {
        if (DB::getDriverName() !== 'pgsql') {
            return false;
        }

        $column = DB::selectOne(
            <<<'SQL'
            select udt_name
            from information_schema.columns
            where table_name = 'calendar_event_indexes'
              and column_name = 'embedding'
            SQL,
        );

        return $column !== null && (string) $column->udt_name === 'vector';
    }
}
