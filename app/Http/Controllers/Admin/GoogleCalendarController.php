<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GoogleToken;
use App\Services\GoogleCalendarService;
use Google\Client;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GoogleCalendarController extends Controller
{
    public function connect(Request $request): RedirectResponse
    {
        $redirectUri = $this->redirectUri($request);

        $client = new Client();
        $client->setClientId(config('services.google.client_id'));
        $client->setClientSecret(config('services.google.client_secret'));
        $client->setRedirectUri($redirectUri);
        $client->addScope('https://www.googleapis.com/auth/calendar');
        $client->setAccessType('offline');
        $client->setPrompt('consent');

        return redirect()->away($client->createAuthUrl());
    }

    public function callback(Request $request): RedirectResponse|View
    {
        $code = $request->query('code');
        if (!$code) {
            return view('admin.oauth-error', [
                'message' => 'Google no devolvio codigo de autorizacion.',
            ]);
        }

        $redirectUri = $this->redirectUri($request);

        $client = new Client();
        $client->setClientId(config('services.google.client_id'));
        $client->setClientSecret(config('services.google.client_secret'));
        $client->setRedirectUri($redirectUri);

        $token = $client->fetchAccessTokenWithAuthCode($code);

        if (isset($token['error'])) {
            return view('admin.oauth-error', [
                'message' => 'Error al intercambiar codigo: ' . ($token['error_description'] ?? $token['error']),
            ]);
        }

        $refreshToken = $token['refresh_token'] ?? null;
        if (!$refreshToken) {
            return view('admin.oauth-error', [
                'message' => 'Google no devolvio refresh_token. Si ya habias autorizado antes, revoca el acceso en https://myaccount.google.com/permissions y vuelve a conectar para forzar consentimiento.',
            ]);
        }

        GoogleToken::query()->delete();
        GoogleToken::create(['refresh_token' => $refreshToken]);

        return redirect()->route('admin.google-calendar.connect')
            ->with('success', 'Calendario conectado correctamente.');
    }

    public function index(): View
    {
        $connected = GoogleToken::getRefreshToken() !== null;

        return view('admin.google-calendar', compact('connected'));
    }

    public function debug(Request $request): View
    {
        $explicit = config('services.google.redirect_uri');
        $dynamic = $request->getSchemeAndHttpHost() . '/admin/google-calendar/callback';
        $used = $this->redirectUri($request);

        return view('admin.oauth-debug', [
            'explicit' => $explicit ?: '(no definido en .env)',
            'dynamic' => $dynamic,
            'used' => $used,
            'scheme' => $request->getScheme(),
            'host' => $request->getHost(),
            'url' => $request->fullUrl(),
        ]);
    }

    private function redirectUri(Request $request): string
    {
        $explicit = config('services.google.redirect_uri');
        if (!empty($explicit)) {
            return rtrim($explicit, '/');
        }

        return $request->getSchemeAndHttpHost() . '/admin/google-calendar/callback';
    }
}
