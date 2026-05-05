<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Services\BookingSlotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BookingController extends Controller
{
    private const DATE_FORMAT = 'Y-m-d';

    private const MAX_DAYS_AHEAD = 60;

    public function __construct(
        private readonly BookingSlotService $slots
    ) {}

    public function availability(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date_format:'.self::DATE_FORMAT, 'after_or_equal:today'],
        ]);

        $date = $validated['date'];
        $parsed = Carbon::parse($date);

        if ($parsed->diffInDays(now(), false) > self::MAX_DAYS_AHEAD) {
            return response()->json(['slots' => []]);
        }

        return response()->json(['slots' => $this->slots->getAvailableSlots($date)]);
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

        if (! $this->slots->isSlotAvailable($start)) {
            throw ValidationException::withMessages(['start' => ['El slot ya no esta disponible.']]);
        }

        $end = $parsed->copy()->addMinutes(30);
        Booking::create([
            'start' => $parsed,
            'end' => $end,
            'email' => $validated['email'] ?? null,
            'event_id' => (string) Str::uuid(),
        ]);

        return response()->json([
            'message' => 'Reserva confirmada.',
            'start' => $parsed->toRfc3339String(),
            'end' => $end->toRfc3339String(),
        ]);
    }
}
