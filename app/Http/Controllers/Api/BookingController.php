<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Services\GoogleCalendarService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class BookingController extends Controller
{
    private const DATE_FORMAT = 'Y-m-d';
    private const MAX_DAYS_AHEAD = 60;

    public function __construct(
        private readonly GoogleCalendarService $calendar
    ) {}

    public function availability(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date_format:' . self::DATE_FORMAT, 'after_or_equal:today'],
        ]);

        $date = $validated['date'];
        $parsed = Carbon::parse($date);

        if ($parsed->diffInDays(now(), false) > self::MAX_DAYS_AHEAD) {
            return response()->json(['slots' => []]);
        }

        $slots = $this->calendar->getAvailableSlots($date);

        return response()->json(['slots' => $slots]);
    }

    public function book(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start' => ['required', 'string'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $start = $validated['start'];
        $parsed = Carbon::parse($start);

        if ($parsed->isPast()) {
            throw ValidationException::withMessages(['start' => ['El slot ya ha pasado.']]);
        }

        if ($parsed->format('N') > 5) {
            throw ValidationException::withMessages(['start' => ['Solo se puede reservar entre semana.']]);
        }

        $hour = (int) $parsed->format('G');
        $minute = (int) $parsed->format('i');
        if ($hour < 10 || ($hour === 18 && $minute > 0) || $hour >= 18) {
            throw ValidationException::withMessages(['start' => ['Fuera del horario disponible (10:00-18:00).']]);
        }

        if ($minute % 30 !== 0) {
            throw ValidationException::withMessages(['start' => ['Slot no valido.']]);
        }

        if (!$this->calendar->isSlotAvailable($start)) {
            throw ValidationException::withMessages(['start' => ['El slot ya no esta disponible.']]);
        }

        $eventId = $this->calendar->createEvent(
            $start,
            $validated['name'],
            $validated['email'] ?? null,
            $validated['notes'] ?? null
        );

        if (!$eventId) {
            return response()->json(['message' => 'Error al crear la reserva.'], 500);
        }

        $end = $parsed->copy()->addMinutes(30);
        Booking::create([
            'start' => $parsed,
            'end' => $end,
            'email' => $validated['email'] ?? null,
            'event_id' => $eventId,
        ]);

        return response()->json([
            'message' => 'Reserva confirmada.',
            'start' => $parsed->toRfc3339String(),
            'end' => $end->toRfc3339String(),
        ]);
    }
}
