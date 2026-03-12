<?php

namespace JarredCain\CanvasLms\Auth;

class TokenAuth
{
    public function __construct(private readonly string $token)
    {
    }

    public function getToken(): string
    {
        return $this->token;
    }
}
