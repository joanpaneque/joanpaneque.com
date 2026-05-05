<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Laravel\Socialite\Contracts\Provider as SocialiteProvider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use LogicException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class GoogleAuthController extends Controller
{
    public function redirect(Request $request): RedirectResponse|SymfonyResponse
    {
        $provider = $this->googleProvider();

        $redirect = $provider
            ->scopes(['https://www.googleapis.com/auth/calendar'])
            ->with([
                'access_type' => 'offline',
                'prompt' => 'consent',
            ])
            ->redirect();

        if ($request->header('X-Inertia')) {
            return Inertia::location($redirect->getTargetUrl());
        }

        return $redirect;
    }

    public function callback(): RedirectResponse
    {
        $provider = $this->socialiteProvider();
        $googleUser = call_user_func([$provider, 'user']);

        $user = Auth::user();
        if (! $user instanceof User) {
            return redirect()->route('personal.login');
        }

        $user->update([
            'google_token' => $googleUser->token,
            'google_refresh_token' => $googleUser->refreshToken,
            'google_token_expires_at' => now()->addSeconds($googleUser->expiresIn),
        ]);

        return redirect()->route('calendar.index');
    }

    private function googleProvider(): GoogleProvider
    {
        $provider = Socialite::driver('google');
        if (! $provider instanceof GoogleProvider) {
            throw new LogicException('Google provider is not configured correctly.');
        }

        return $provider;
    }

    private function socialiteProvider(): SocialiteProvider
    {
        $provider = Socialite::driver('google');
        if (! $provider instanceof SocialiteProvider) {
            throw new LogicException('Socialite provider is not configured correctly.');
        }

        return $provider;
    }
}
