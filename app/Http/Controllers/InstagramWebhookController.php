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
    public function webhook(Request $request): SymfonyResponse
    {
        $this->logIncomingOverview($request);

        if ($request->isMethod('GET')) {
            return $this->verifySubscription($request);
        }

        return $this->handleEvent($request);
    }

    private function logIncomingOverview(Request $request): void
    {
        if (! config('services.instagram.log_requests')) {
            return;
        }

        $base = [
            'method' => $request->method(),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent() !== null
                ? mb_substr($request->userAgent(), 0, 200)
                : null,
        ];

        if ($request->isMethod('GET')) {
            $mode = $request->query('hub.mode') ?? $request->query('hub_mode');
            $token = $request->query('hub.verify_token') ?? $request->query('hub_verify_token');
            $challenge = $request->query('hub.challenge') ?? $request->query('hub_challenge');

            Log::info('Instagram webhook: incoming request', $base + [
                'kind' => 'subscription_verify',
                'hub_mode' => is_scalar($mode) ? (string) $mode : null,
                'has_verify_token' => is_string($token) && $token !== '',
                'has_challenge' => is_string($challenge) && $challenge !== '',
            ]);

            return;
        }

        Log::info('Instagram webhook: incoming request', $base + [
            'kind' => 'event_delivery',
            'content_length' => strlen($request->getContent()),
            'has_x_hub_signature_256' => is_string($request->header('X-Hub-Signature-256')),
            'content_type' => $request->header('Content-Type'),
        ]);
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
            if (config('services.instagram.log_requests')) {
                Log::info('Instagram webhook: verification succeeded (challenge returned)');
            }

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
                Log::warning('Instagram webhook: invalid signature', [
                    'content_length' => strlen($request->getContent()),
                ]);

                return response('Forbidden', Response::HTTP_FORBIDDEN);
            }
        }

        $payload = $request->all();

        if (config('services.instagram.log_requests')) {
            $entries = $payload['entry'] ?? null;
            $entryCount = is_array($entries) ? count($entries) : 0;
            Log::info('Instagram webhook: event payload summary', [
                'object' => $payload['object'] ?? null,
                'entry_count' => $entryCount,
                'top_level_keys' => array_keys(is_array($payload) ? $payload : []),
            ]);
        }

        if (config('services.instagram.log_payload')) {
            Log::info('Instagram webhook: full payload', ['payload' => $payload]);
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
