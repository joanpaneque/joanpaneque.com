<?php

namespace Database\Seeders;

use App\Models\PhoneAwayRecord;
use App\Models\TeethCleaning;
use App\Services\TelegramBotService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use RuntimeException;
use Throwable;

/**
 * Registros históricos según a.txt (30 mar–6 abr 2026).
 * Ejecutar: php artisan db:seed --class=DentalHistoryFromNotesSeeder
 *
 * Tras insertar, envía siempre los textos a Telegram (mismo formato que al pulsar Sí/No).
 * Requiere TELEGRAM_CHAT_ID y token del bot en .env para la API.
 *
 * Antes de insertar, vacía por completo teeth_cleanings y phone_away_records (historial).
 */
class DentalHistoryFromNotesSeeder extends Seeder
{
    private const TZ = 'Europe/Madrid';

    private const TELEGRAM_SEED_USER_ID = 6212914707;

    /** Segundos entre cada sendMessage (evita 429 Too Many Requests). */
    private const TELEGRAM_GAP_SECONDS = 1;

    public function run(): void
    {
        $userId = self::TELEGRAM_SEED_USER_ID;

        $chatId = config('services.telegram.chat_id');
        if ($chatId === null || $chatId === '') {
            throw new RuntimeException('Configura TELEGRAM_CHAT_ID.');
        }
        $chatId = (string) $chatId;

        TeethCleaning::query()->delete();
        PhoneAwayRecord::query()->delete();

        foreach ($this->daySpecs() as $spec) {
            $day = Carbon::parse($spec['date'], self::TZ)->startOfDay();

            foreach (['morning', 'midday', 'night'] as $phase) {
                $teeth = $spec['teeth'][$phase] ?? null;
                if ($teeth === null) {
                    continue;
                }
                $prompt = $this->promptTime($day, $phase);
                $grace = $this->graceMinutes($phase);
                $delayed = (bool) $teeth['delayed'];
                $answered = $this->answeredAt($prompt, $grace, $delayed);

                TeethCleaning::query()->create([
                    'telegram_user_id' => $userId,
                    'telegram_chat_id' => $chatId,
                    'prompt_sent_at' => $prompt,
                    'answered_at' => $answered,
                    'grace_period_minutes' => $grace,
                    'delayed' => $delayed,
                ]);
            }

            $phone = $spec['phone'] ?? null;
            if ($phone !== null) {
                $prompt = $this->promptTime($day, 'phone');
                $grace = $this->graceMinutes('phone');
                $delayed = (bool) $phone['delayed'];
                $answered = $this->answeredAt($prompt, $grace, $delayed);

                PhoneAwayRecord::query()->create([
                    'telegram_user_id' => $userId,
                    'telegram_chat_id' => $chatId,
                    'prompt_sent_at' => $prompt,
                    'answered_at' => $answered,
                    'grace_period_minutes' => $grace,
                    'delayed' => $delayed,
                ]);
            }
        }

        $this->sendTelegramMessages();
    }

    /**
     * Envía mensajes con el mismo texto que ToothFairy / PhoneAwayFairy tras confirmar o negar.
     */
    private function sendTelegramMessages(): void
    {
        foreach ($this->daySpecs() as $spec) {
            $day = Carbon::parse($spec['date'], self::TZ)->startOfDay();

            foreach (['morning', 'midday', 'night'] as $phase) {
                $teeth = $spec['teeth'][$phase] ?? null;
                if ($teeth === null) {
                    if ($phase === 'midday' && ($spec['date'] ?? '') === '2026-04-04') {
                        $this->telegramSend($this->teethNoLine($day, 'midday'));
                        $this->sleepBetweenTelegramMessages();
                    }

                    continue;
                }
                $this->telegramSend($this->teethConfirmationLine($day, $phase, (bool) $teeth['delayed']));
                $this->sleepBetweenTelegramMessages();
            }

            $phone = $spec['phone'] ?? null;
            if ($phone !== null) {
                $this->telegramSend($this->phoneConfirmationLine($day, (bool) $phone['delayed']));
                $this->sleepBetweenTelegramMessages();
            }
        }
    }

    private function sleepBetweenTelegramMessages(): void
    {
        sleep(self::TELEGRAM_GAP_SECONDS);
    }

    private function telegramSend(string $text): void
    {
        $maxAttempts = 8;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                TelegramBotService::sendMessage($text);

                return;
            } catch (Throwable $e) {
                $msg = $e->getMessage();
                $retryAfter = 40;
                if (preg_match('/retry after (\d+)/i', $msg, $m)) {
                    $retryAfter = max(1, (int) $m[1]);
                }

                $isRateLimit = str_contains($msg, 'Too Many Requests') || str_contains($msg, '429');
                if ($isRateLimit && $attempt < $maxAttempts) {
                    sleep($retryAfter);

                    continue;
                }

                throw new RuntimeException('Telegram sendMessage falló: '.$msg, 0, $e);
            }
        }
    }

    /** Misma línea que ToothFairy al confirmar Sí. */
    private function teethConfirmationLine(Carbon $day, string $phase, bool $delayed): string
    {
        $dateStr = $day->format('d/m/Y');
        $suffix = $this->phaseSuffix($phase);
        if ($delayed) {
            return '⏰ '.$dateStr.' te has lavado los dientes'.$suffix.' (con retraso) 🦷';
        }

        return '✅ '.$dateStr.' te has lavado los dientes'.$suffix.' 🦷';
    }

    /** Misma línea que ToothFairy al pulsar No. */
    private function teethNoLine(Carbon $day, string $phase): string
    {
        $dateStr = $day->format('d/m/Y');

        return '❌ '.$dateStr.' no te has lavado los dientes'.$this->phaseSuffix($phase);
    }

    private function phaseSuffix(string $phase): string
    {
        return match ($phase) {
            'morning' => ' por la mañana',
            'midday' => ' al mediodía',
            'night' => ' por la noche',
            default => '',
        };
    }

    /** Misma línea que PhoneAwayFairy al confirmar Sí. */
    private function phoneConfirmationLine(Carbon $day, bool $delayed): string
    {
        $dateStr = $day->format('d/m/Y');
        if ($delayed) {
            return '⏰ '.$dateStr.' confirmado: móvil lejos (con retraso) 📵';
        }

        return '✅ '.$dateStr.' confirmado: móvil lejos 📵';
    }

    /**
     * Horarios alineados con routes/console.php (07:45, 13:00, 20:00; móvil a las 20:00).
     */
    private function promptTime(Carbon $day, string $phase): Carbon
    {
        $d = $day->copy()->timezone(self::TZ);

        return match ($phase) {
            'morning' => $d->copy()->setTime(7, 45, 0),
            'midday' => $d->copy()->setTime(13, 0, 0),
            'night' => $d->copy()->setTime(20, 0, 0),
            'phone' => $d->copy()->setTime(20, 0, 1),
            default => throw new RuntimeException('Fase inválida: '.$phase),
        };
    }

    private function graceMinutes(string $phase): int
    {
        return $phase === 'morning' ? 20 : 4 * 60;
    }

    private function answeredAt(Carbon $promptSent, int $grace, bool $delayed): Carbon
    {
        if ($delayed) {
            return $promptSent->copy()->addMinutes($grace)->addMinutes(35);
        }

        return $promptSent->copy()->addMinutes(5);
    }

    /**
     * Interpretación de a.txt (30 mar–6 abr 2026).
     *
     * @return list<array{date: string, teeth: array{morning?: array{delayed: bool}|null, midday?: array{delayed: bool}|null, night?: array{delayed: bool}|null}, phone: array{delayed: bool}|null}>
     */
    private function daySpecs(): array
    {
        return [
            [
                'date' => '2026-03-30',
                'teeth' => [
                    'morning' => null,
                    'midday' => null,
                    'night' => ['delayed' => false],
                ],
                'phone' => ['delayed' => false],
            ],
            [
                'date' => '2026-03-31',
                'teeth' => [
                    'morning' => ['delayed' => false],
                    'midday' => ['delayed' => false],
                    'night' => ['delayed' => false],
                ],
                'phone' => ['delayed' => false],
            ],
            [
                'date' => '2026-04-01',
                'teeth' => [
                    'morning' => ['delayed' => false],
                    'midday' => ['delayed' => true],
                    'night' => ['delayed' => true],
                ],
                'phone' => ['delayed' => true],
            ],
            [
                'date' => '2026-04-02',
                'teeth' => [
                    'morning' => ['delayed' => false],
                    'midday' => ['delayed' => false],
                    'night' => ['delayed' => false],
                ],
                'phone' => ['delayed' => false],
            ],
            [
                'date' => '2026-04-03',
                'teeth' => [
                    'morning' => ['delayed' => true],
                    'midday' => ['delayed' => true],
                    'night' => ['delayed' => false],
                ],
                'phone' => ['delayed' => false],
            ],
            [
                'date' => '2026-04-04',
                'teeth' => [
                    'morning' => ['delayed' => true],
                    'midday' => null,
                    'night' => ['delayed' => true],
                ],
                'phone' => ['delayed' => true],
            ],
            [
                'date' => '2026-04-05',
                'teeth' => [
                    'morning' => ['delayed' => false],
                    'midday' => ['delayed' => true],
                    'night' => ['delayed' => true],
                ],
                'phone' => ['delayed' => true],
            ],
            [
                'date' => '2026-04-06',
                'teeth' => [
                    'morning' => ['delayed' => false],
                    'midday' => ['delayed' => true],
                    'night' => ['delayed' => false],
                ],
                'phone' => ['delayed' => false],
            ],
        ];
    }
}
