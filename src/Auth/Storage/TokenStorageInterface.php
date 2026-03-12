<?php

namespace JarredCain\CanvasLms\Auth\Storage;

interface TokenStorageInterface
{
    /**
     * Store OAuth2 token data keyed by an identifier (e.g., "user:42").
     *
     * @param string $key
     * @param array{access_token: string, refresh_token: string, expires_at: string, token_type: string} $tokenData
     */
    public function store(string $key, array $tokenData): void;

    /**
     * Retrieve stored token data by key. Returns null if not found.
     *
     * @param string $key
     * @return array{access_token: string, refresh_token: string, expires_at: string, token_type: string}|null
     */
    public function retrieve(string $key): ?array;

    /**
     * Remove stored token data by key.
     */
    public function forget(string $key): void;
}
