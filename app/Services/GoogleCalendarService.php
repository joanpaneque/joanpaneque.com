<?php

namespace App\Services;

use App\Models\User;
use Google\Client;
use Google\Service\Calendar;
use Google\Service\Calendar\Event;
use Google\Service\Calendar\EventDateTime;
use Google\Service\Exception as GoogleServiceException;
use Illuminate\Support\Carbon;

class GoogleCalendarService
{
    protected Calendar $service;

    public function __construct(protected User $user)
    {
        $client = new Client;
        $client->setClientId(config('services.google.client_id'));
        $client->setClientSecret(config('services.google.client_secret'));
        $client->setAccessType('offline');

        $expiresIn = $this->secondsUntilTokenExpiry($user->google_token_expires_at);
        $client->setAccessToken([
            'access_token' => $user->google_token,
            'refresh_token' => $user->google_refresh_token,
            'expires_in' => $expiresIn,
            'created' => now()->subSeconds(max($expiresIn, 0))->timestamp,
        ]);

        if ($client->isAccessTokenExpired() && $user->google_refresh_token) {
            $newToken = $client->fetchAccessTokenWithRefreshToken($user->google_refresh_token);

            if (! isset($newToken['error'])) {
                $this->persistFreshToken($newToken);
            }
        }

        $this->service = new Calendar($client);
    }

    public function getEvents(string $calendarId = 'primary', ?Carbon $timeMin = null, ?Carbon $timeMax = null)
    {
        $options = [
            'maxResults' => 20,
            'orderBy' => 'startTime',
            'singleEvents' => true,
            'timeMin' => ($timeMin ?? now('UTC'))->copy()->utc()->toIso8601String(),
        ];

        if ($timeMax) {
            $options['timeMax'] = $timeMax->copy()->utc()->toIso8601String();
            $options['maxResults'] = 250;
        }

        $events = $this->service->events->listEvents($calendarId, $options);
        $this->calendarEventIndexSync()->syncEvents($this->user, $events->getItems(), $this);

        return $events;
    }

    public function getEvent(string $eventId, string $calendarId = 'primary'): Event
    {
        return $this->service->events->get($calendarId, $eventId);
    }

    public function createEvent(array $data, string $calendarId = 'primary')
    {
        $eventData = [
            'summary' => $data['title'],
        ];

        if (! empty($data['event_id'])) {
            $eventData['id'] = $data['event_id'];
        } elseif (! empty($data['id'])) {
            $eventData['id'] = $data['id'];
        }

        if (! empty($data['all_day'])) {
            $eventData['start'] = ['date' => Carbon::parse($data['start'], 'Europe/Madrid')->toDateString()];
            $eventData['end'] = ['date' => Carbon::parse($data['end'], 'Europe/Madrid')->addDay()->toDateString()];
        } else {
            $eventData['start'] = [
                'dateTime' => $data['start'],
                'timeZone' => 'Europe/Madrid',
            ];
            $eventData['end'] = [
                'dateTime' => $data['end'],
                'timeZone' => 'Europe/Madrid',
            ];
        }

        if (! empty($data['description'])) {
            $eventData['description'] = $data['description'];
        }

        if (! empty($data['location'])) {
            $eventData['location'] = $data['location'];
        }

        $colorId = $data['color_id'] ?? $data['color'] ?? null;
        if (! empty($colorId)) {
            $eventData['colorId'] = $colorId;
        }

        if (is_string($data['recurrence'] ?? null) && trim($data['recurrence']) !== '') {
            $eventData['recurrence'] = [$this->normalizeRrule($data['recurrence'])];
        } else {
            $rrule = $this->buildCreateRecurrenceRrule(
                is_array($data['recurrence'] ?? null) ? $data['recurrence'] : [],
                ! empty($data['all_day']),
            );
            if ($rrule !== null) {
                $eventData['recurrence'] = [$rrule];
            }
        }

        $event = new Event($eventData);

        $createdEvent = $this->service->events->insert($calendarId, $event);
        $this->calendarEventIndexSync()->syncEvent($this->user, $createdEvent, $this);

        return $createdEvent;
    }

    public function updateEvent(string $eventId, array $data, string $calendarId = 'primary')
    {
        $eventData = [];

        if (array_key_exists('title', $data)) {
            $eventData['summary'] = $data['title'];
        }

        if (array_key_exists('start', $data)) {
            $eventData['start'] = $this->eventDateTimeFromInput($data['start']);
        }

        if (array_key_exists('end', $data)) {
            $eventData['end'] = $this->eventDateTimeFromInput($data['end']);
        }

        if (array_key_exists('description', $data)) {
            $eventData['description'] = $data['description'];
        }

        if (array_key_exists('location', $data)) {
            $eventData['location'] = $data['location'];
        }

        if (array_key_exists('color', $data)) {
            $eventData['colorId'] = $data['color'];
        } elseif (array_key_exists('color_id', $data)) {
            $eventData['colorId'] = $data['color_id'];
        }

        if (array_key_exists('recurrence', $data)) {
            $recurrence = $data['recurrence'];
            $eventData['recurrence'] = is_string($recurrence) && trim($recurrence) !== ''
                ? [$this->normalizeRrule($recurrence)]
                : null;
        }

        $event = $this->service->events->patch($calendarId, $eventId, new Event($eventData));
        $this->calendarEventIndexSync()->syncEvent($this->user, $event, $this);

        return $event;
    }

    public function eventSnapshot(Event $event): array
    {
        $start = $event->getStart();
        $end = $event->getEnd();

        return [
            'event_id' => $event->getId(),
            'title' => $event->getSummary() ?: '(Sin título)',
            'start' => $start?->getDateTime() ?: $start?->getDate(),
            'end' => $end?->getDateTime() ?: $end?->getDate(),
            'all_day' => (bool) ($start?->getDate() && ! $start?->getDateTime()),
            'description' => $event->getDescription(),
            'location' => $event->getLocation(),
            'color' => $event->getColorId(),
            'recurrence' => $event->getRecurrence(),
        ];
    }

    public function restoreEventSnapshot(array $snapshot, string $calendarId = 'primary')
    {
        $data = [
            'event_id' => $snapshot['event_id'] ?? null,
            'title' => $snapshot['title'] ?? '(Sin título)',
            'start' => $snapshot['start'] ?? null,
            'end' => ! empty($snapshot['all_day']) && ! empty($snapshot['end'])
                ? Carbon::parse($snapshot['end'], 'Europe/Madrid')->subDay()->toDateString()
                : ($snapshot['end'] ?? null),
            'all_day' => (bool) ($snapshot['all_day'] ?? false),
            'description' => $snapshot['description'] ?? null,
            'location' => $snapshot['location'] ?? null,
            'color' => $snapshot['color'] ?? null,
        ];

        if (is_array($snapshot['recurrence'] ?? null) && ($snapshot['recurrence'] ?? []) !== []) {
            $data['recurrence'] = $snapshot['recurrence'][0];
        }

        try {
            return $this->createEvent($data, $calendarId);
        } catch (GoogleServiceException $e) {
            if (! $this->isDuplicateIdentifierException($e)) {
                throw $e;
            }

            $eventId = (string) ($data['event_id'] ?? '');
            if ($eventId !== '') {
                try {
                    $existing = $this->getEvent($eventId, $calendarId);
                    if ($existing->getStatus() !== 'cancelled') {
                        $this->calendarEventIndexSync()->syncEvent($this->user, $existing, $this);

                        return $existing;
                    }
                } catch (GoogleServiceException) {
                    // Fall through and recreate with a fresh Google-generated id.
                }
            }

            unset($data['event_id']);

            return $this->createEvent($data, $calendarId);
        }
    }

    public function moveTimedEvent(string $eventId, Carbon $start, Carbon $end, string $calendarId = 'primary')
    {
        $event = new Event([
            'start' => new EventDateTime([
                'dateTime' => $start->toRfc3339String(),
                'timeZone' => 'Europe/Madrid',
            ]),
            'end' => new EventDateTime([
                'dateTime' => $end->toRfc3339String(),
                'timeZone' => 'Europe/Madrid',
            ]),
        ]);

        $movedEvent = $this->service->events->patch($calendarId, $eventId, $event);
        $this->calendarEventIndexSync()->syncEvent($this->user, $movedEvent, $this);

        return $movedEvent;
    }

    public function moveRecurringEventAll(
        string $recurringEventId,
        Carbon $originalInstanceStart,
        Carbon $newInstanceStart,
        Carbon $newInstanceEnd,
        string $calendarId = 'primary'
    ) {
        $master = $this->service->events->get($calendarId, $recurringEventId);
        $masterStart = $this->carbonFromEventDateTime($master->getStart());
        $masterEnd = $this->carbonFromEventDateTime($master->getEnd());
        $startDeltaSeconds = $newInstanceStart->getTimestamp() - $originalInstanceStart->getTimestamp();
        $newDurationSeconds = max(60, $newInstanceEnd->getTimestamp() - $newInstanceStart->getTimestamp());
        $masterDurationSeconds = max(60, $masterEnd->getTimestamp() - $masterStart->getTimestamp());

        $newMasterStart = $masterStart->copy()->addSeconds($startDeltaSeconds);
        $newMasterEnd = $newMasterStart->copy()->addSeconds($newDurationSeconds ?: $masterDurationSeconds);

        return $this->moveTimedEvent($recurringEventId, $newMasterStart, $newMasterEnd, $calendarId);
    }

    public function updateEventColor(string $eventId, string $colorId, string $calendarId = 'primary')
    {
        $event = $this->service->events->patch($calendarId, $eventId, new Event([
            'colorId' => $colorId,
        ]));
        $this->calendarEventIndexSync()->syncEvent($this->user, $event, $this);

        return $event;
    }

    public function deleteEvent(string $eventId, string $calendarId = 'primary'): void
    {
        $this->service->events->delete($calendarId, $eventId);
        $this->calendarEventIndexSync()->deleteEvent($this->user, $eventId);
    }

    public function moveRecurringEventAndFollowing(
        string $recurringEventId,
        Carbon $originalStart,
        Carbon $newStart,
        Carbon $newEnd,
        string $calendarId = 'primary'
    ) {
        $master = $this->service->events->get($calendarId, $recurringEventId);
        $this->trimRecurringEvent($recurringEventId, $originalStart, $calendarId);

        $event = $this->service->events->insert($calendarId, $this->newSeriesFromMaster($master, [
            'start' => $this->eventDateTime($newStart),
            'end' => $this->eventDateTime($newEnd),
        ]));
        $this->calendarEventIndexSync()->syncEvent($this->user, $event, $this);

        return $event;
    }

    public function updateRecurringColorAndFollowing(
        string $recurringEventId,
        Carbon $originalStart,
        Carbon $instanceStart,
        Carbon $instanceEnd,
        string $colorId,
        string $calendarId = 'primary'
    ) {
        $master = $this->service->events->get($calendarId, $recurringEventId);
        $this->trimRecurringEvent($recurringEventId, $originalStart, $calendarId);

        $event = $this->service->events->insert($calendarId, $this->newSeriesFromMaster($master, [
            'start' => $this->eventDateTime($instanceStart),
            'end' => $this->eventDateTime($instanceEnd),
            'colorId' => $colorId,
        ]));
        $this->calendarEventIndexSync()->syncEvent($this->user, $event, $this);

        return $event;
    }

    public function deleteRecurringAndFollowing(string $recurringEventId, Carbon $originalStart, string $calendarId = 'primary'): void
    {
        $this->trimRecurringEvent($recurringEventId, $originalStart, $calendarId);
    }

    public function getColorPalette()
    {
        return $this->service->colors->get();
    }

    public function getCalendarListEntry(string $calendarId = 'primary')
    {
        return $this->service->calendarList->get($calendarId);
    }

    /**
     * @param  array<string, mixed>  $recurrence
     */
    private function buildCreateRecurrenceRrule(array $recurrence, bool $allDay): ?string
    {
        $freq = strtolower((string) ($recurrence['freq'] ?? 'none'));
        if ($freq === 'none' || $freq === '') {
            return null;
        }

        $allowedFreq = ['daily', 'weekly', 'monthly', 'yearly'];
        if (! in_array($freq, $allowedFreq, true)) {
            return null;
        }

        $parts = ['FREQ='.strtoupper($freq)];
        $interval = max(1, min(999, (int) ($recurrence['interval'] ?? 1)));
        if ($interval > 1) {
            $parts[] = 'INTERVAL='.$interval;
        }

        if ($freq === 'weekly') {
            $days = $recurrence['by_day'] ?? [];
            $allowedDays = ['SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA'];
            if (is_array($days) && $days !== []) {
                $normalized = [];
                foreach ($days as $day) {
                    $u = strtoupper((string) $day);
                    if (in_array($u, $allowedDays, true)) {
                        $normalized[$u] = $u;
                    }
                }
                if ($normalized !== []) {
                    $order = array_flip($allowedDays);
                    uasort($normalized, fn ($a, $b) => ($order[$a] ?? 0) <=> ($order[$b] ?? 0));
                    $parts[] = 'BYDAY='.implode(',', array_values($normalized));
                }
            }
        }

        $ends = $recurrence['ends'] ?? 'never';
        if ($ends === 'count') {
            $count = max(1, min(999, (int) ($recurrence['count'] ?? 1)));
            $parts[] = 'COUNT='.$count;
        } elseif ($ends === 'until' && ! empty($recurrence['until'])) {
            try {
                $until = Carbon::parse($recurrence['until'], 'Europe/Madrid');
                if ($allDay) {
                    $parts[] = 'UNTIL='.$until->copy()->utc()->format('Ymd');
                } else {
                    $parts[] = 'UNTIL='.$until->copy()->endOfDay()->utc()->format('Ymd\THis\Z');
                }
            } catch (\Throwable) {
                // Omit invalid UNTIL; series still repeats without end.
            }
        }

        return 'RRULE:'.implode(';', $parts);
    }

    private function secondsUntilTokenExpiry(mixed $expiresAt): int
    {
        if (! $expiresAt) {
            return 0;
        }

        $expires = $expiresAt instanceof Carbon
            ? $expiresAt
            : Carbon::parse($expiresAt);

        return now()->diffInSeconds($expires, false);
    }

    private function trimRecurringEvent(string $recurringEventId, Carbon $originalStart, string $calendarId = 'primary'): void
    {
        $until = $originalStart->copy()->utc()->subSecond();
        $master = $this->service->events->get($calendarId, $recurringEventId);

        $this->cancelOriginalInstance($recurringEventId, $originalStart, $calendarId);

        $event = $this->service->events->patch($calendarId, $recurringEventId, new Event([
            'recurrence' => $this->recurrenceUntil(
                $master->getRecurrence() ?? [],
                $until,
            ),
        ]));
        $this->calendarEventIndexSync()->syncEvent($this->user, $event, $this);
    }

    private function cancelOriginalInstance(string $recurringEventId, Carbon $originalStart, string $calendarId = 'primary'): void
    {
        try {
            $instances = $this->service->events->instances($calendarId, $recurringEventId, [
                'timeMin' => $originalStart->copy()->subDay()->toRfc3339String(),
                'timeMax' => $originalStart->copy()->addDay()->toRfc3339String(),
                'showDeleted' => false,
            ])->getItems();

            foreach ($instances as $instance) {
                $instanceOriginalStart = $instance->getOriginalStartTime();
                $instanceOriginalStartValue = $instanceOriginalStart?->getDateTime() ?: $instanceOriginalStart?->getDate();

                if (! $instanceOriginalStartValue) {
                    continue;
                }

                if (Carbon::parse($instanceOriginalStartValue)->equalTo($originalStart)) {
                    $this->service->events->delete($calendarId, $instance->getId());
                    $this->calendarEventIndexSync()->deleteEvent($this->user, $instance->getId());
                    break;
                }
            }
        } catch (\Throwable) {
            // If the instance lookup fails, the UNTIL trim below is still the source of truth.
        }
    }

    private function newSeriesFromMaster(Event $master, array $overrides): Event
    {
        return new Event(array_merge([
            'summary' => $master->getSummary(),
            'description' => $master->getDescription(),
            'location' => $master->getLocation(),
            'colorId' => $master->getColorId(),
            'recurrence' => $this->recurrenceForNewSeries($master->getRecurrence() ?? []),
            'reminders' => $master->getReminders(),
            'transparency' => $master->getTransparency(),
            'visibility' => $master->getVisibility(),
        ], $overrides));
    }

    private function eventDateTime(Carbon $date): EventDateTime
    {
        return new EventDateTime([
            'dateTime' => $date->toRfc3339String(),
            'timeZone' => 'Europe/Madrid',
        ]);
    }

    private function eventDateTimeFromInput(mixed $value): array
    {
        $raw = (string) $value;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return ['date' => $raw];
        }

        return [
            'dateTime' => Carbon::parse($raw, 'Europe/Madrid')->toRfc3339String(),
            'timeZone' => 'Europe/Madrid',
        ];
    }

    private function normalizeRrule(string $rrule): string
    {
        $rrule = trim($rrule);

        return str_starts_with(strtoupper($rrule), 'RRULE:') ? $rrule : 'RRULE:'.$rrule;
    }

    private function isDuplicateIdentifierException(GoogleServiceException $e): bool
    {
        return (int) $e->getCode() === 409
            && str_contains(strtolower($e->getMessage()), 'duplicate');
    }

    private function carbonFromEventDateTime(EventDateTime $eventDateTime): Carbon
    {
        return Carbon::parse($eventDateTime->getDateTime() ?: $eventDateTime->getDate(), 'Europe/Madrid');
    }

    /**
     * @param  array<int, string>  $recurrence
     * @return array<int, string>
     */
    private function recurrenceUntil(array $recurrence, Carbon $until): array
    {
        return collect($recurrence)->map(function (string $rule) use ($until): string {
            if (! str_starts_with($rule, 'RRULE:')) {
                return $rule;
            }

            return $this->replaceRRulePart(
                $this->removeRRulePart($rule, 'COUNT'),
                'UNTIL',
                $until->format('Ymd\THis\Z'),
            );
        })->all();
    }

    /**
     * @param  array<int, string>  $recurrence
     * @return array<int, string>
     */
    private function recurrenceForNewSeries(array $recurrence): array
    {
        return collect($recurrence)->map(function (string $rule): string {
            if (! str_starts_with($rule, 'RRULE:')) {
                return $rule;
            }

            return $this->removeRRulePart($rule, 'COUNT');
        })->all();
    }

    private function replaceRRulePart(string $rule, string $key, string $value): string
    {
        $body = substr($rule, strlen('RRULE:'));
        $parts = collect(explode(';', $body))
            ->reject(fn (string $part) => str_starts_with($part, $key.'='))
            ->push($key.'='.$value)
            ->values()
            ->all();

        return 'RRULE:'.implode(';', $parts);
    }

    private function removeRRulePart(string $rule, string $key): string
    {
        $body = substr($rule, strlen('RRULE:'));
        $parts = collect(explode(';', $body))
            ->reject(fn (string $part) => str_starts_with($part, $key.'='))
            ->values()
            ->all();

        return 'RRULE:'.implode(';', $parts);
    }

    private function persistFreshToken(array $tokenPayload): void
    {
        $accessToken = $tokenPayload['access_token'] ?? $this->user->google_token;
        $refreshToken = $tokenPayload['refresh_token'] ?? $this->user->google_refresh_token;
        $expiresIn = (int) ($tokenPayload['expires_in'] ?? 0);

        $this->user->update([
            'google_token' => $accessToken,
            'google_refresh_token' => $refreshToken,
            'google_token_expires_at' => $expiresIn > 0 ? now()->addSeconds($expiresIn) : null,
        ]);
    }

    private function calendarEventIndexSync(): CalendarEventIndexSync
    {
        return app(CalendarEventIndexSync::class);
    }
}
