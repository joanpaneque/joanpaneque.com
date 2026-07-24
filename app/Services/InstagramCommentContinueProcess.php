<?php

namespace App\Services;

/**
 * Punto de extensión cuando un comentario no coincide con ninguna keyword (similitud %).
 * Aquí se podrá encadenar otro flujo en el futuro.
 */
final class InstagramCommentContinueProcess
{
    /**
     * @param  array<string, mixed>  $webhookPayload  Payload completo del webhook de Instagram (POST).
     */
    public static function continue_process(array $webhookPayload): void
    {
        //
    }
}
