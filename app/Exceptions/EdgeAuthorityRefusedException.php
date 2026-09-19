<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * P5C HOME LAB (19 Sep 2026) — the Cloud authority API answered a heartbeat/handback with a refusal. The message keeps
 * the historical shape ("AUTHORITY_REFUSED: HTTP <status> <failure_code>"); the decoded body is exposed so the
 * appliance can act on a SPECIFIC refusal (a STALE_HEARTBEAT that carries the Cloud's sequence) without parsing text.
 */
class EdgeAuthorityRefusedException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status, public readonly array $body)
    {
        parent::__construct($message);
    }

    public function failureCode(): string
    {
        return (string) ($this->body['failure_code'] ?? '');
    }
}
