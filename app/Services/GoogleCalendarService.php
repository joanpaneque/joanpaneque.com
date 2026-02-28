<?php

namespace App\Services;

use App\Models\GoogleToken;
use Google\Client;
use Google\Service\Calendar;
use Google\Service\Calendar\Event;
use Google\Service\Calendar\EventDateTime;
use Google\Service\Calendar\EventAttendee;
use Google\Service\Calendar\FreeBusyRequest;
use Google\Service\Calendar\FreeBusyRequestItem;
use Illuminate\Support\Carbon;

class GoogleCalendarService
{
    private const TIMEZONE = 'Europe/Madrid';
    private const SLOT_DURATION_MINUTES = 30;
    private const WORK_START = '10:00';
    private const WORK_END = '18:00';
    private const WORK_DAYS = [1, 2, 3, 4, 5]; // L-V

    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $redirectUri,
        private readonly string $calendarId
    ) {}

    public function getClient(): Client
    {
        $client = new Client();
        $client->setClientId($this->clientId);
        $client->setClientSecret($this->clientSecret);
        $client->setRedirectUri($this->redirectUri);
        $client->addScope('https://www.googleapis.com/auth/calendar');
        $client->setAccessType('offline');
        $client->setPrompt('consent');

        $refreshToken = GoogleToken::getRefreshToken();
        if ($refreshToken) {
            $client->fetchAccessTokenWithRefreshToken($refreshToken);
        }

        return $client;
    }

    /**
     * @return array<array{start: string, end: string}>
     */
    public function getBusyIntervals(string $date): array
    {
        $tz = new \DateTimeZone(self::TIMEZONE);
        $start = Carbon::parse($date . ' 00:00:00', $tz);
        $end = Carbon::parse($date . ' 23:59:59', $tz);

        $client = $this->getClient();
        $accessToken = $client->getAccessToken();
        if (empty($accessToken['access_token'])) {
            return [];
        }

        $calendar = new Calendar($client);
        $item = new FreeBusyRequestItem();
        $item->setId($this->calendarId);

        $request = new FreeBusyRequest();
        $request->setTimeMin($start->toRfc3339String());
        $request->setTimeMax($end->toRfc3339String());
        $request->setTimeZone(self::TIMEZONE);
        $request->setItems([$item]);

        $response = $calendar->freebusy->query($request);
        $calendars = $response->getCalendars();
        $calendarData = $calendars[$this->calendarId] ?? null;

        if (!$calendarData || !$calendarData->getBusy()) {
            return [];
        }

        $busy = [];
        foreach ($calendarData->getBusy() as $period) {
            $busy[] = [
                'start' => $period->getStart(),
                'end' => $period->getEnd(),
            ];
        }

        return $busy;
    }

    /**
     * @return array<string>
     */
    public function getAvailableSlots(string $date): array
    {
        $dayOfWeek = (int) Carbon::parse($date)->format('N');
        if (!in_array($dayOfWeek, self::WORK_DAYS)) {
            return [];
        }

        $tz = new \DateTimeZone(self::TIMEZONE);
        $busy = $this->getBusyIntervals($date);

        $slots = [];
        $slotStart = Carbon::parse($date . ' ' . self::WORK_START, $tz);
        $workEnd = Carbon::parse($date . ' ' . self::WORK_END, $tz);

        while ($slotStart->copy()->addMinutes(self::SLOT_DURATION_MINUTES)->lte($workEnd)) {
            $slotEnd = $slotStart->copy()->addMinutes(self::SLOT_DURATION_MINUTES);
            $slotStartRfc = $slotStart->toRfc3339String();
            $slotEndRfc = $slotEnd->toRfc3339String();

            $isFree = true;
            foreach ($busy as $interval) {
                $busyStart = Carbon::parse($interval['start']);
                $busyEnd = Carbon::parse($interval['end']);
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

    public function createEvent(
        string $startRfc3339,
        string $name,
        ?string $email = null,
        ?string $notes = null
    ): ?string {
        $client = $this->getClient();
        $accessToken = $client->getAccessToken();
        if (empty($accessToken['access_token'])) {
            return null;
        }

        $start = Carbon::parse($startRfc3339);
        $end = $start->copy()->addMinutes(self::SLOT_DURATION_MINUTES);

        $event = new Event();
        $event->setSummary($name);

        $startDt = new EventDateTime();
        $startDt->setDateTime($start->toRfc3339String());
        $startDt->setTimeZone(self::TIMEZONE);
        $event->setStart($startDt);

        $endDt = new EventDateTime();
        $endDt->setDateTime($end->toRfc3339String());
        $endDt->setTimeZone(self::TIMEZONE);
        $event->setEnd($endDt);

        if ($notes) {
            $event->setDescription($notes);
        }

        if ($email) {
            $attendee = new EventAttendee();
            $attendee->setEmail($email);
            $event->setAttendees([$attendee]);
        }

        $calendar = new Calendar($client);
        $created = $calendar->events->insert(
            $this->calendarId,
            $event,
            ['sendUpdates' => 'all']
        );

        return $created->getId();
    }

    public function isSlotAvailable(string $startRfc3339): bool
    {
        $start = Carbon::parse($startRfc3339);
        $date = $start->format('Y-m-d');
        $slots = $this->getAvailableSlots($date);

        return in_array($startRfc3339, $slots);
    }
}
