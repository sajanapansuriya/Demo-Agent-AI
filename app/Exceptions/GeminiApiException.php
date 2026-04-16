<?php

namespace App\Exceptions;

use RuntimeException;

class GeminiApiException extends RuntimeException
{
    public function __construct(
        string $message,
        protected int $status = 500,
        protected ?int $retryAfterSeconds = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $status, $previous);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function retryAfterSeconds(): ?int
    {
        return $this->retryAfterSeconds;
    }
}
