<?php

namespace App\Http\Controllers;

use App\Models\PhoneAwayRecord;
use App\Models\TeethCleaning;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PersonalController extends Controller
{
    private const TZ = 'Europe/Madrid';

    public function showLogin(Request $request): Response|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('personal.dashboard');
        }

        return Inertia::render('Personal/Login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => __('Credenciales incorrectas.'),
            ]);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('personal.dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('personal.login');
    }

    public function dashboard(): Response
    {
        Carbon::setLocale('es');

        return Inertia::render('Personal/Dashboard', [
            'monthlyRows' => $this->monthlyStats()->values()->all(),
            'teethPunctualityStreak' => $this->teethPunctualityStreak(),
            'teethPunctualityStreakMax' => $this->teethPunctualityStreakMax(),
            'phonePunctualityStreak' => $this->phonePunctualityStreak(),
            'phonePunctualityStreakMax' => $this->phonePunctualityStreakMax(),
        ]);
    }

    /**
     * Mayor racha histórica de lavados seguidos a tiempo (orden cronológico).
     */
    private function teethPunctualityStreakMax(): int
    {
        $max = 0;
        $current = 0;

        foreach (TeethCleaning::query()->where('completed', true)->orderBy('answered_at')->cursor() as $row) {
            if ($row->delayed) {
                $current = 0;
            } else {
                $current++;
                if ($current > $max) {
                    $max = $current;
                }
            }
        }

        return $max;
    }

    /**
     * Respuestas seguidas a tiempo (sin retraso), desde la más reciente hacia atrás.
     */
    private function teethPunctualityStreak(): int
    {
        $streak = 0;

        foreach (TeethCleaning::query()->where('completed', true)->orderByDesc('answered_at')->cursor() as $row) {
            if ($row->delayed) {
                break;
            }
            $streak++;
        }

        return $streak;
    }

    /**
     * Mayor racha histórica de móvil lejos seguidos a tiempo.
     */
    private function phonePunctualityStreakMax(): int
    {
        $max = 0;
        $current = 0;

        foreach (PhoneAwayRecord::query()->orderBy('answered_at')->cursor() as $row) {
            if ($row->delayed) {
                $current = 0;
            } else {
                $current++;
                if ($current > $max) {
                    $max = $current;
                }
            }
        }

        return $max;
    }

    /**
     * Respuestas seguidas a tiempo (móvil lejos), desde la más reciente hacia atrás.
     */
    private function phonePunctualityStreak(): int
    {
        $streak = 0;

        foreach (PhoneAwayRecord::query()->orderByDesc('answered_at')->cursor() as $row) {
            if ($row->delayed) {
                break;
            }
            $streak++;
        }

        return $streak;
    }

    /**
     * @return Collection<int, array{month_key: string, label: string, teeth_total: int, teeth_on_time: int, teeth_delayed: int, phone_total: int, phone_on_time: int, phone_delayed: int}>
     */
    private function monthlyStats(): Collection
    {
        $tz = self::TZ;
        /** @var array<string, array{label: string, teeth_total: int, teeth_on_time: int, teeth_delayed: int, phone_total: int, phone_on_time: int, phone_delayed: int}> $months */
        $months = [];

        foreach (TeethCleaning::query()->where('completed', true)->orderBy('answered_at')->cursor() as $row) {
            $key = $row->answered_at->timezone($tz)->format('Y-m');
            if (! isset($months[$key])) {
                $months[$key] = $this->emptyMonthRow($key);
            }
            $months[$key]['teeth_total']++;
            if ($row->delayed) {
                $months[$key]['teeth_delayed']++;
            } else {
                $months[$key]['teeth_on_time']++;
            }
        }

        foreach (PhoneAwayRecord::query()->orderBy('answered_at')->cursor() as $row) {
            $key = $row->answered_at->timezone($tz)->format('Y-m');
            if (! isset($months[$key])) {
                $months[$key] = $this->emptyMonthRow($key);
            }
            $months[$key]['phone_total']++;
            if ($row->delayed) {
                $months[$key]['phone_delayed']++;
            } else {
                $months[$key]['phone_on_time']++;
            }
        }

        krsort($months);

        return collect($months)->map(function (array $row, string $monthKey) {
            $row['month_key'] = $monthKey;

            return $row;
        })->values();
    }

    /**
     * @return array{label: string, teeth_total: int, teeth_on_time: int, teeth_delayed: int, phone_total: int, phone_on_time: int, phone_delayed: int}
     */
    private function emptyMonthRow(string $yMonth): array
    {
        $label = Carbon::createFromFormat('Y-m', $yMonth)->timezone(self::TZ)->translatedFormat('F Y');

        return [
            'label' => Str::ucfirst($label),
            'teeth_total' => 0,
            'teeth_on_time' => 0,
            'teeth_delayed' => 0,
            'phone_total' => 0,
            'phone_on_time' => 0,
            'phone_delayed' => 0,
        ];
    }
}
