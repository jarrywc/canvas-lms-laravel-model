<?php

namespace JarredCain\CanvasLms\Exceptions;

class RateLimitException extends CanvasException
{
    public function __construct(
        private readonly int $retryAfter = 60,
        string $message = 'Canvas API rate limit exceeded.'
    ) {
        parent::__construct($message);
    }

    public function getRetryAfter(): int
    {
        return $this->retryAfter;
    }
}
