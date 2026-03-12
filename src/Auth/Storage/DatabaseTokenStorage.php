<?php

namespace JarredCain\CanvasLms\Auth\Storage;

use Illuminate\Support\Facades\DB;

class DatabaseTokenStorage implements TokenStorageInterface
{
    private string $table;

    public function __construct(string $table = 'canvas_oauth_tokens')
    {
        $this->table = $table;
    }

    public function store(string $key, array $tokenData): void
    {
        DB::table($this->table)->upsert(
            [
                'key'           => $key,
                'access_token'  => $tokenData['access_token'],
                'refresh_token' => $tokenData['refresh_token'] ?? null,
                'expires_at'    => $tokenData['expires_at'],
                'token_type'    => $tokenData['token_type'] ?? 'Bearer',
                'updated_at'    => now(),
            ],
            ['key'],
            ['access_token', 'refresh_token', 'expires_at', 'token_type', 'updated_at']
        );
    }

    public function retrieve(string $key): ?array
    {
        $record = DB::table($this->table)->where('key', $key)->first();

        if (!$record) {
            return null;
        }

        return [
            'access_token'  => $record->access_token,
            'refresh_token' => $record->refresh_token,
            'expires_at'    => $record->expires_at,
            'token_type'    => $record->token_type,
        ];
    }

    public function forget(string $key): void
    {
        DB::table($this->table)->where('key', $key)->delete();
    }
}
