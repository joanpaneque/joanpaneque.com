<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

class InstagramTestCommand extends Command
{
    protected $signature = 'instagram:test
                            {--ig-user-id= : ID de la cuenta profesional de Instagram (Graph), por defecto INSTAGRAM_BUSINESS_ACCOUNT_ID}
                            {--diagnose : Solo muestra cómo se lee el token desde .env (longitud, prefijo); no llama a Meta}
                            {--send-dm= : IGSID del destinatario; POST graph.instagram.com/.../me/messages (solo token IGAA…)}
                            {--dm-text=Hola : Texto del mensaje de prueba (con --send-dm)}';

    protected $description = 'Prueba INSTAGRAM_ACCESS_TOKEN: graph.instagram.com (IGAA…) o graph.facebook.com (EAA…)';

    public function handle(): int
    {
        $raw = config('services.instagram.access_token');
        if (! is_string($raw) || $raw === '') {
            $this->error('Define INSTAGRAM_ACCESS_TOKEN en .env');

            return self::FAILURE;
        }

        $token = $this->normalizeAccessToken($raw);
        if ($token === '') {
            $this->error('INSTAGRAM_ACCESS_TOKEN quedó vacío tras quitar espacios/comillas; revisa .env');

            return self::FAILURE;
        }

        if ($this->option('diagnose')) {
            $this->printTokenDiagnostics($token, $raw, true);

            return self::SUCCESS;
        }

        $this->maybeWarnTokenShape($token);

        $usesInstagramApi = $this->tokenUsesInstagramGraphHost($token);

        try {
            $me = $this->requestMe($token, $usesInstagramApi);

            if (! $me->successful()) {
                $this->error('GET /me falló (HTTP '.$me->status().') — host: '.($usesInstagramApi ? 'graph.instagram.com' : 'graph.facebook.com'));
                $this->line($me->body());
                $this->hintOnTokenError(is_array($me->json()) ? $me->json() : null, $token, $raw, $usesInstagramApi);

                return self::FAILURE;
            }

            $this->info('GET /me — OK ('.($usesInstagramApi ? 'graph.instagram.com' : 'graph.facebook.com').')');
            $this->line(json_encode($me->json(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            $sendDm = $this->option('send-dm');
            if (is_string($sendDm) && $sendDm !== '') {
                if (! $usesInstagramApi) {
                    $this->error('--send-dm solo aplica a tokens de Instagram API (prefijo IGAA…); usa graph.instagram.com + Bearer.');

                    return self::FAILURE;
                }

                return $this->sendInstagramDm($token, $sendDm, (string) $this->option('dm-text'));
            }

            if ($usesInstagramApi) {
                $this->newLine();
                $this->comment('Opcional: prueba DM con IGSID del usuario: sail artisan instagram:test --send-dm=IGSID_…');

                return self::SUCCESS;
            }

            $igId = $this->option('ig-user-id');
            if (! is_string($igId) || $igId === '') {
                $igId = config('services.instagram.business_account_id');
            }

            if (! is_string($igId) || $igId === '') {
                $this->newLine();
                $this->comment('Opcional: añade INSTAGRAM_BUSINESS_ACCOUNT_ID en .env o --ig-user-id=… para probar el nodo de Instagram en graph.facebook.com.');

                return self::SUCCESS;
            }

            $version = config('services.instagram.graph_api_version', 'v21.0');
            $base = 'https://graph.facebook.com/'.ltrim((string) $version, '/');
            $ig = Http::acceptJson()->get("{$base}/{$igId}", [
                'fields' => 'id,username,media_count,profile_picture_url',
                'access_token' => $token,
            ]);

            $this->newLine();
            if (! $ig->successful()) {
                $this->error("GET /{$igId} (Instagram) falló (HTTP ".$ig->status().')');
                $this->line($ig->body());
                $this->newLine();
                $this->comment('Comprueba que el token incluye permisos de Instagram y que el id es el de la cuenta profesional vinculada a la app.');

                return self::FAILURE;
            }

            $this->info("GET /{$igId} (Instagram) — OK");
            $this->line(json_encode($ig->json(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function tokenUsesInstagramGraphHost(string $token): bool
    {
        return str_starts_with($token, 'IGAA')
            || str_starts_with($token, 'IGQV');
    }

    /**
     * GET /me: Instagram API (Bearer) vs Facebook Graph (access_token query).
     */
    private function requestMe(string $token, bool $instagramHost): \Illuminate\Http\Client\Response
    {
        if ($instagramHost) {
            $version = config('services.instagram.instagram_api_version', 'v21.0');
            $base = 'https://graph.instagram.com/'.ltrim((string) $version, '/');

            return Http::acceptJson()
                ->withToken($token)
                ->get("{$base}/me", [
                    'fields' => 'id,username',
                ]);
        }

        $version = config('services.instagram.graph_api_version', 'v21.0');
        $base = 'https://graph.facebook.com/'.ltrim((string) $version, '/');

        return Http::acceptJson()->get("{$base}/me", [
            'fields' => 'id,name',
            'access_token' => $token,
        ]);
    }

    private function sendInstagramDm(string $token, string $recipientIgsid, string $text): int
    {
        $version = config('services.instagram.instagram_api_version', 'v21.0');
        $url = 'https://graph.instagram.com/'.ltrim((string) $version, '/').'/me/messages';

        $response = Http::acceptJson()
            ->withToken($token)
            ->post($url, [
                'recipient' => ['id' => $recipientIgsid],
                'message' => ['text' => $text],
            ]);

        $this->newLine();
        if (! $response->successful()) {
            $this->error('POST /me/messages falló (HTTP '.$response->status().')');
            $this->line($response->body());
            $this->comment('recipient debe ser el IGSID del usuario (no vacío). A veces hace falta que haya conversación previa o cumplir políticas de mensajería.');

            return self::FAILURE;
        }

        $this->info('POST /me/messages — OK');
        $this->line(json_encode($response->json(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    private function normalizeAccessToken(string $raw): string
    {
        $t = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
        $t = trim($t);
        if (
            (str_starts_with($t, '"') && str_ends_with($t, '"'))
            || (str_starts_with($t, "'") && str_ends_with($t, "'"))
        ) {
            $t = substr($t, 1, -1);
            $t = trim($t);
        }

        // Caracteres invisibles típicos al copiar desde el panel web
        $t = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}\x{00A0}]/u', '', $t) ?? $t;

        // A veces se pega el JSON completo del generador de tokens
        if (str_starts_with($t, '{')) {
            $decoded = json_decode($t, true);
            if (is_array($decoded)) {
                if (isset($decoded['access_token']) && is_string($decoded['access_token'])) {
                    $t = $decoded['access_token'];
                } elseif (isset($decoded['data']['access_token']) && is_string($decoded['data']['access_token'])) {
                    $t = $decoded['data']['access_token'];
                }
            }
        }

        // access_token=... en URL o texto
        if (preg_match('/access_token=([^&\s#]+)/i', $t, $m)) {
            $t = urldecode($m[1]);
        }

        // Los tokens de Graph no llevan espacios; quita saltos pegados por error
        if (preg_match('/\s/', $t)) {
            $t = preg_replace('/\s+/u', '', $t) ?? $t;
        }

        return $t;
    }

    private function maybeWarnTokenShape(string $token): void
    {
        if (preg_match('/\s/u', $token)) {
            $this->warn('El token contiene espacios o saltos de línea; suele romper la petición. Pon el token en una sola línea en .env');
        }
        if (strlen($token) < 32) {
            $this->warn('El token es muy corto; los de Graph suelen ser cadenas largas. ¿Has pegado el token completo y no el id de app/usuario?');
        }
    }

    /**
     * @param  array<string, mixed>|null  $json
     */
    private function hintOnTokenError(?array $json, string $token, string $rawFromEnv, bool $usedInstagramHost): void
    {
        $code = is_array($json['error'] ?? null) ? ($json['error']['code'] ?? null) : null;
        $message = is_array($json['error'] ?? null) ? (string) ($json['error']['message'] ?? '') : '';

        if ($code === 190 || str_contains($message, 'parse access token')) {
            $this->newLine();
            $this->comment('Pistas para "Cannot parse access token":');
            $this->line('  • Tokens IGAA… → graph.instagram.com + Bearer (el comando ya lo hace). Tokens EAA… → graph.facebook.com + access_token en query.');
            if (! $usedInstagramHost && $this->tokenUsesInstagramGraphHost($token)) {
                $this->warn('  • Tu token parece de Instagram API pero la petición usó Facebook Graph; revisa el prefijo tras normalizar.');
            }
            $this->line('  • Una sola línea en .env: INSTAGRAM_ACCESS_TOKEN=… (si lleva $, entre comillas dobles).');
            $this->line('  • Tras cambiar .env: sail artisan config:clear');
            $this->printTokenDiagnostics($token, $rawFromEnv, false);
        }
    }

    private function printTokenDiagnostics(string $normalized, string $rawFromEnv, bool $fromDiagnoseFlag): void
    {
        $this->newLine();
        $this->comment('Diagnóstico del token (sin mostrarlo entero):');
        $len = strlen($normalized);
        $rawLen = strlen($rawFromEnv);
        if ($rawFromEnv !== '' && $rawLen !== $len) {
            $this->line("  • Bytes en .env (raw): {$rawLen} → tras normalizar: {$len}");
        } else {
            $this->line("  • Longitud: {$len} bytes (los de usuario/página suelen ser ~200–400+)");
        }
        if ($len >= 10) {
            $this->line('  • Prefijo: '.substr($normalized, 0, 10).' … sufijo: …'.substr($normalized, -8));
        }
        if ($len > 0 && $len < 50) {
            $this->warn('  • Muy corto para un access_token de Graph; suele ser otro tipo de id.');
        }
        if (preg_match('/[^\x20-\x7E]/', $normalized)) {
            $this->warn('  • Hay caracteres no ASCII; prueba a regenerar el token y pegar de nuevo.');
        }
        if ($fromDiagnoseFlag) {
            $this->line('  • Prefijos habituales: IGAA… (Instagram API) → graph.instagram.com; EAA… (Facebook) → graph.facebook.com.');
        }
    }
}
