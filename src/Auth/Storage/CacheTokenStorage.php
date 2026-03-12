<?php

namespace JarredCain\CanvasLms\Auth\Storage;

use Illuminate\Support\Facades\Cache;

class CacheTokenStorage implements TokenStorageInterface
{
    private string $prefix;

    public function __construct(string $prefix = 'canvas_oauth_token')
    {
        $this->prefix = $prefix;
    }

    public function store(string $key, array $tokenData): void
    {
        // Store indefinitely — expiry is managed by OAuth2Handler via expires_at field
        Cache::forever($this->cacheKey($key), $tokenData);
    }

    public function retrieve(string $key): ?array
    {
        return Cache::get($this->cacheKey($key));
    }

    public function forget(string $key): void
    {
        Cache::forget($this->cacheKey($key));
    }

    private function cacheKey(string $key): string
    {
        return "{$this->prefix}:{$key}";
    }
}
