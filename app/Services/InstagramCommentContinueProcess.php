<?php

namespace App\Services;

/**
 * Punto de extensión cuando un comentario no pasa el clasificador de intención (embeddings + LLM).
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
