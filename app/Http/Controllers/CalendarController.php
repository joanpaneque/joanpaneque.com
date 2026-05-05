<?php

namespace App\Http\Controllers;

use App\Services\GoogleCalendarService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CalendarController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        $user = Auth::user();
        if (! $user) {
            return redirect()->route('personal.login');
        }

        if (! $user->google_token) {
            return redirect()->route('google.auth.redirect');
        }

        [$weekStart, $weekEnd] = $this->weekRange($request);

        return Inertia::render('Calendar/Index', [
            'weekStart' => $weekStart->toDateString(),
            'weekEnd' => $weekEnd->toDateString(),
            'events' => [],
        ]);
    }

    public function events(Request $request): JsonResponse
    {
        $user = Auth::user();
        abort_unless($user, 403);

        [$weekStart, $weekEnd] = $this->weekRange($request);

        $calendar = new GoogleCalendarService($user);
        $events = $calendar->getEvents(timeMin: $weekStart, timeMax: $weekEnd);

        return response()->json([
            'week_start' => $weekStart->toDateString(),
            'week_end' => $weekEnd->toDateString(),
            'events' => $this->eventPayload($events->getItems()),
        ]);
    }

    public function colors(): JsonResponse
    {
        $user = Auth::user();
        abort_unless($user, 403);

        $calendar = new GoogleCalendarService($user);
        $colorPalette = $calendar->getColorPalette();
        $calendarListEntry = $calendar->getCalendarListEntry();

        return response()->json([
            'event_colors' => collect($colorPalette->getEvent() ?? [])->map(fn ($color) => [
                'background' => $color->getBackground(),
                'foreground' => $color->getForeground(),
            ]),
            'default_event_color' => [
                'background' => $calendarListEntry->getBackgroundColor() ?: '#4285f4',
                'foreground' => $calendarListEntry->getForegroundColor() ?: '#ffffff',
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $user = Auth::user();
        if (! $user) {
            return redirect()->route('personal.login');
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'start' => ['required', 'date'],
            'end' => ['required', 'date', 'after:start'],
            'all_day' => ['nullable', 'boolean'],
            'recurrence' => ['nullable', 'array'],
            'recurrence.freq' => ['nullable', 'in:none,daily,weekly,monthly,yearly'],
            'recurrence.interval' => ['nullable', 'integer', 'min:1', 'max:999'],
            'recurrence.by_day' => ['nullable', 'array', 'max:7'],
            'recurrence.by_day.*' => ['string', 'in:SU,MO,TU,WE,TH,FR,SA'],
            'recurrence.ends' => ['nullable', 'in:never,until,count'],
            'recurrence.until' => [
                'nullable',
                'date',
                Rule::requiredIf(fn () => ($request->input('recurrence.ends') ?? 'never') === 'until'
                    && ($request->input('recurrence.freq') ?? 'none') !== 'none'),
            ],
            'recurrence.count' => [
                'nullable',
                'integer',
                'min:1',
                'max:999',
                Rule::requiredIf(fn () => ($request->input('recurrence.ends') ?? 'never') === 'count'
                    && ($request->input('recurrence.freq') ?? 'none') !== 'none'),
            ],
            'color_id' => ['nullable', 'string', 'in:1,2,3,4,5,6,7,8,9,10,11'],
        ]);

        $recurrence = array_merge([
            'freq' => 'none',
            'interval' => 1,
            'by_day' => [],
            'ends' => 'never',
            'until' => null,
            'count' => null,
        ], $validated['recurrence'] ?? []);

        if (($recurrence['freq'] ?? 'none') === 'none') {
            $recurrence = ['freq' => 'none'];
        }

        $calendar = new GoogleCalendarService($user);
        $event = $calendar->createEvent([
            'title' => $validated['title'],
            'start' => Carbon::parse($validated['start'], 'Europe/Madrid')->toRfc3339String(),
            'end' => Carbon::parse($validated['end'], 'Europe/Madrid')->toRfc3339String(),
            'all_day' => $request->boolean('all_day'),
            'recurrence' => $recurrence,
            'color_id' => $validated['color_id'] ?? null,
        ]);

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'event' => $this->eventPayload([$event])[0] ?? null,
            ]);
        }

        return back();
    }

    public function update(Request $request, string $eventId): RedirectResponse|JsonResponse
    {
        $user = Auth::user();
        if (! $user) {
            return redirect()->route('personal.login');
        }

        $validated = $request->validate([
            'start' => ['required', 'date'],
            'end' => ['required', 'date', 'after:start'],
            'week_start' => ['nullable', 'date'],
            'recurring_scope' => ['nullable', 'string', 'in:this,this_and_following,all'],
            'recurring_event_id' => ['nullable', 'string'],
            'original_start' => ['nullable', 'date'],
        ]);

        $calendar = new GoogleCalendarService($user);
        $scope = $validated['recurring_scope'] ?? 'this';
        if ($scope === 'this_and_following' && isset($validated['recurring_event_id'], $validated['original_start'])) {
            $calendar->moveRecurringEventAndFollowing(
                $validated['recurring_event_id'],
                Carbon::parse($validated['original_start'], 'Europe/Madrid'),
                Carbon::parse($validated['start'], 'Europe/Madrid'),
                Carbon::parse($validated['end'], 'Europe/Madrid'),
            );

            return response()->json([
                'ok' => true,
                'event_id' => $eventId,
                'start' => $validated['start'],
                'end' => $validated['end'],
                'recurring_scope' => $scope,
            ]);
        }

        if ($scope === 'all' && isset($validated['recurring_event_id'], $validated['original_start'])) {
            $calendar->moveRecurringEventAll(
                $validated['recurring_event_id'],
                Carbon::parse($validated['original_start'], 'Europe/Madrid'),
                Carbon::parse($validated['start'], 'Europe/Madrid'),
                Carbon::parse($validated['end'], 'Europe/Madrid'),
            );

            return response()->json([
                'ok' => true,
                'event_id' => $validated['recurring_event_id'],
                'start' => $validated['start'],
                'end' => $validated['end'],
                'recurring_scope' => $scope,
            ]);
        }

        $targetEventId = $scope === 'all'
            ? ($validated['recurring_event_id'] ?? $eventId)
            : $eventId;

        $calendar->moveTimedEvent(
            $targetEventId,
            Carbon::parse($validated['start'], 'Europe/Madrid'),
            Carbon::parse($validated['end'], 'Europe/Madrid'),
        );

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'event_id' => $targetEventId,
                'start' => $validated['start'],
                'end' => $validated['end'],
            ]);
        }

        return redirect()->route('calendar.index', array_filter([
            'week_start' => $validated['week_start'] ?? null,
        ]));
    }

    public function updateColor(Request $request, string $eventId): JsonResponse
    {
        $user = Auth::user();
        abort_unless($user, 403);

        $validated = $request->validate([
            'color_id' => ['required', 'string', 'in:1,2,3,4,5,6,7,8,9,10,11'],
            'recurring_scope' => ['nullable', 'string', 'in:this,this_and_following,all'],
            'recurring_event_id' => ['nullable', 'string'],
            'start' => ['nullable', 'date'],
            'end' => ['nullable', 'date', 'after:start'],
            'original_start' => ['nullable', 'date'],
        ]);

        $calendar = new GoogleCalendarService($user);
        $scope = $validated['recurring_scope'] ?? 'this';
        if (
            $scope === 'this_and_following'
            && isset($validated['recurring_event_id'], $validated['original_start'], $validated['start'], $validated['end'])
        ) {
            $calendar->updateRecurringColorAndFollowing(
                $validated['recurring_event_id'],
                Carbon::parse($validated['original_start'], 'Europe/Madrid'),
                Carbon::parse($validated['start'], 'Europe/Madrid'),
                Carbon::parse($validated['end'], 'Europe/Madrid'),
                $validated['color_id'],
            );

            return response()->json([
                'ok' => true,
                'event_id' => $eventId,
                'color_id' => $validated['color_id'],
                'recurring_scope' => $scope,
            ]);
        }

        $targetEventId = $scope === 'all'
            ? ($validated['recurring_event_id'] ?? $eventId)
            : $eventId;

        $calendar->updateEventColor($targetEventId, $validated['color_id']);

        return response()->json([
            'ok' => true,
            'event_id' => $targetEventId,
            'color_id' => $validated['color_id'],
        ]);
    }

    public function destroy(Request $request, string $eventId): JsonResponse
    {
        $user = Auth::user();
        abort_unless($user, 403);

        $validated = $request->validate([
            'recurring_scope' => ['nullable', 'string', 'in:this,this_and_following,all'],
            'recurring_event_id' => ['nullable', 'string'],
            'original_start' => ['nullable', 'date'],
        ]);

        $calendar = new GoogleCalendarService($user);
        $scope = $validated['recurring_scope'] ?? 'this';
        if ($scope === 'this_and_following' && isset($validated['recurring_event_id'], $validated['original_start'])) {
            $calendar->deleteRecurringAndFollowing(
                $validated['recurring_event_id'],
                Carbon::parse($validated['original_start'], 'Europe/Madrid'),
            );

            return response()->json([
                'ok' => true,
                'event_id' => $eventId,
                'recurring_scope' => $scope,
            ]);
        }

        $targetEventId = $scope === 'all'
            ? ($validated['recurring_event_id'] ?? $eventId)
            : $eventId;

        $calendar->deleteEvent($targetEventId);

        return response()->json([
            'ok' => true,
            'event_id' => $targetEventId,
        ]);
    }

    /**
     * @return array{Carbon, Carbon}
     */
    private function weekRange(Request $request): array
    {
        $weekStart = $request->filled('week_start')
            ? Carbon::parse($request->query('week_start'), 'Europe/Madrid')->startOfWeek()
            : now('Europe/Madrid')->startOfWeek();

        return [$weekStart, $weekStart->copy()->endOfWeek()];
    }

    private function eventPayload(iterable $events): array
    {
        return collect($events)->map(function ($event) {
            $start = $event->getStart();
            $end = $event->getEnd();

            return [
                'id' => $event->getId(),
                'title' => $event->getSummary(),
                'description' => $event->getDescription(),
                'start' => $start?->getDateTime() ?: $start?->getDate(),
                'end' => $end?->getDateTime() ?: $end?->getDate(),
                'is_all_day' => (bool) $start?->getDate(),
                'color_id' => $event->getColorId(),
                'recurring_event_id' => $event->getRecurringEventId(),
                'is_recurring' => (bool) $event->getRecurringEventId(),
                'original_start' => $event->getOriginalStartTime()?->getDateTime()
                    ?: $event->getOriginalStartTime()?->getDate()
                    ?: $start?->getDateTime()
                    ?: $start?->getDate(),
                'html_link' => $event->getHtmlLink(),
                'raw' => $event->toSimpleObject(),
            ];
        })->values()->all();
    }
}
