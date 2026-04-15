<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class InstagramWebhookController extends Controller
{
    /**
     * Meta Instagram webhooks: GET verifies the callback URL; POST delivers events.
     *
     * @see https://developers.facebook.com/docs/graph-api/webhooks/getting-started
     */
    public function __invoke(Request $request): SymfonyResponse
    {
        if ($request->isMethod('GET')) {
            return $this->verifySubscription($request);
        }

        return $this->handleEvent($request);
    }

    private function verifySubscription(Request $request): SymfonyResponse
    {
        $mode = $request->query('hub.mode') ?? $request->query('hub_mode');
        $token = $request->query('hub.verify_token') ?? $request->query('hub_verify_token');
        $challenge = $request->query('hub.challenge') ?? $request->query('hub_challenge');

        $expected = config('services.instagram.webhook_verify_token');

        if ($mode === 'subscribe'
            && is_string($expected) && $expected !== ''
            && is_string($token) && hash_equals($expected, $token)
            && is_string($challenge) && $challenge !== '') {
            return response($challenge, Response::HTTP_OK)->header('Content-Type', 'text/plain');
        }

        Log::warning('Instagram webhook: verification failed or misconfigured', [
            'hub_mode' => $mode,
            'has_challenge' => is_string($challenge) && $challenge !== '',
        ]);

        return response('Forbidden', Response::HTTP_FORBIDDEN);
    }

    private function handleEvent(Request $request): SymfonyResponse
    {
        $secret = config('services.instagram.app_secret');
        if (is_string($secret) && $secret !== '') {
            $signature = $request->header('X-Hub-Signature-256');
            if (! is_string($signature) || ! $this->signatureValid($request->getContent(), $secret, $signature)) {
                Log::warning('Instagram webhook: invalid signature');

                return response('Forbidden', Response::HTTP_FORBIDDEN);
            }
        }

        $payload = $request->all();

        if (config('services.instagram.log_payload')) {
            Log::info('Instagram webhook: payload', ['payload' => $payload]);
        }

        // Acknowledge immediately; process async later if needed.
        return response()->noContent();
    }

    private function signatureValid(string $rawBody, string $appSecret, string $headerValue): bool
    {
        $expected = 'sha256='.hash_hmac('sha256', $rawBody, $appSecret);

        return hash_equals($expected, $headerValue);
    }
}
