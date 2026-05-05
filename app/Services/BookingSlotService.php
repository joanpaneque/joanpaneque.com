<?php

namespace App\Services;

use App\Models\Booking;
use Illuminate\Support\Carbon;

class BookingSlotService
{
    private const TIMEZONE = 'Europe/Madrid';

    private const SLOT_DURATION_MINUTES = 30;

    private const WORK_START = '10:00';

    private const WORK_END = '18:00';

    /** @var array<int> Lun–Vie */
    private const WORK_DAYS = [1, 2, 3, 4, 5];

    /**
     * @return array<array{start: Carbon, end: Carbon}>
     */
    private function busyIntervalsForDate(string $date): array
    {
        $tz = new \DateTimeZone(self::TIMEZONE);
        $rangeStart = Carbon::parse($date.' 00:00:00', $tz);
        $rangeEnd = Carbon::parse($date.' 23:59:59', $tz);

        return Booking::query()
            ->where('start', '<', $rangeEnd)
            ->where('end', '>', $rangeStart)
            ->get(['start', 'end'])
            ->map(fn (Booking $b) => [
                'start' => $b->start->clone()->timezone(self::TIMEZONE),
                'end' => $b->end->clone()->timezone(self::TIMEZONE),
            ])
            ->all();
    }

    /**
     * @return array<string>
     */
    public function getAvailableSlots(string $date): array
    {
        $dayOfWeek = (int) Carbon::parse($date)->format('N');
        if (! in_array($dayOfWeek, self::WORK_DAYS, true)) {
            return [];
        }

        $tz = new \DateTimeZone(self::TIMEZONE);
        $busy = $this->busyIntervalsForDate($date);

        $slots = [];
        $slotStart = Carbon::parse($date.' '.self::WORK_START, $tz);
        $workEnd = Carbon::parse($date.' '.self::WORK_END, $tz);

        while ($slotStart->copy()->addMinutes(self::SLOT_DURATION_MINUTES)->lte($workEnd)) {
            $slotEnd = $slotStart->copy()->addMinutes(self::SLOT_DURATION_MINUTES);
            $slotStartRfc = $slotStart->toRfc3339String();

            $isFree = true;
            foreach ($busy as $interval) {
                /** @var Carbon $busyStart */
                $busyStart = $interval['start'];
                /** @var Carbon $busyEnd */
                $busyEnd = $interval['end'];
                if ($slotStart->lt($busyEnd) && $slotEnd->gt($busyStart)) {
                    $isFree = false;
                    break;
                }
            }

            if ($isFree) {
                $slots[] = $slotStartRfc;
            }

            $slotStart->addMinutes(self::SLOT_DURATION_MINUTES);
        }

        return $slots;
    }

    public function isSlotAvailable(string $startRfc3339): bool
    {
        $start = Carbon::parse($startRfc3339);
        $date = $start->format('Y-m-d');
        $slots = $this->getAvailableSlots($date);

        return in_array($startRfc3339, $slots, true);
    }
}
