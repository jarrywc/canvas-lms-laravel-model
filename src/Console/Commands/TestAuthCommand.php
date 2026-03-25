<?php

namespace JarredCain\CanvasLms\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class TestAuthCommand extends Command
{
    protected $signature = 'canvas:test-auth';

    protected $description = 'Test Canvas LMS authentication using current env/config values';

    public function handle(): int
    {
        $config = config('canvas');

        $this->info('Canvas Auth Tester');
        $this->line(str_repeat('─', 50));

        // ── 1. Config Check ──────────────────────────────

        $this->newLine();
        $this->comment('Configuration');

        $baseUrl   = $config['base_url'] ?? null;
        $driver    = $config['auth_driver'] ?? null;
        $userAgent = $config['user_agent'] ?? 'CanvasLmsLaravel/1.0';

        $this->line("  Base URL:    " . ($baseUrl ?: '<not set>'));
        $this->line("  Auth driver: " . ($driver ?: '<not set>'));
        $this->line("  User-Agent:  " . $userAgent);

        if (empty($baseUrl)) {
            $this->error('CANVAS_URL is not configured. Set it in your .env file.');
            return self::FAILURE;
        }

        if (!in_array($driver, ['token', 'oauth2'], true)) {
            $this->error("Unsupported auth driver [{$driver}]. Supported: token, oauth2.");
            return self::FAILURE;
        }

        // ── 2. Driver-specific checks ────────────────────

        $this->newLine();
        $this->comment('Credential Check');

        if ($driver === 'token') {
            return $this->testTokenAuth($config, $baseUrl, $userAgent);
        }

        return $this->testOAuth2Auth($config, $baseUrl, $userAgent);
    }

    private function testTokenAuth(array $config, string $baseUrl, string $userAgent): int
    {
        $token = $config['token'] ?? null;

        if (empty($token)) {
            $this->error('CANVAS_API_TOKEN is not set. Cannot authenticate.');
            return self::FAILURE;
        }

        $masked = substr($token, 0, 8) . str_repeat('*', max(0, strlen($token) - 12)) . substr($token, -4);
        $this->line("  Token: {$masked}");
        $this->line("  Token length: " . strlen($token) . " chars");

        return $this->callCanvasApi($baseUrl, $token, $userAgent);
    }

    private function testOAuth2Auth(array $config, string $baseUrl, string $userAgent): int
    {
        $clientId     = $config['oauth2']['client_id'] ?? null;
        $clientSecret = $config['oauth2']['client_secret'] ?? null;
        $redirectUri  = $config['oauth2']['redirect_uri'] ?? null;
        $storage      = $config['oauth2']['storage_driver'] ?? 'cache';

        $this->line("  Client ID:      " . ($clientId ?: '<not set>'));
        $this->line("  Client Secret:  " . ($clientSecret ? substr($clientSecret, 0, 4) . '****' : '<not set>'));
        $this->line("  Redirect URI:   " . ($redirectUri ?: '<not set>'));
        $this->line("  Token storage:  " . $storage);

        $missing = [];
        if (empty($clientId))     $missing[] = 'CANVAS_CLIENT_ID';
        if (empty($clientSecret)) $missing[] = 'CANVAS_CLIENT_SECRET';
        if (empty($redirectUri))  $missing[] = 'CANVAS_REDIRECT_URI';

        if (!empty($missing)) {
            $this->error('Missing OAuth2 config: ' . implode(', ', $missing));
            return self::FAILURE;
        }

        // Try to get a stored token and test it
        try {
            $tokenStorage = app(\JarredCain\CanvasLms\Auth\Storage\TokenStorageInterface::class);
            $tokenData    = $tokenStorage->retrieve('default');

            if (!$tokenData) {
                $this->warn('No stored OAuth2 token found for key "default".');
                $this->line('  User must complete the OAuth2 flow first.');
                $this->line('  Authorization URL would be:');
                $this->line("  {$baseUrl}/login/oauth2/auth?client_id={$clientId}&response_type=code&redirect_uri=" . urlencode($redirectUri));
                return self::FAILURE;
            }

            $this->line("  Stored token found (expires: " . ($tokenData['expires_at'] ?? 'unknown') . ")");

            return $this->callCanvasApi($baseUrl, $tokenData['access_token'], $userAgent);
        } catch (\Throwable $e) {
            $this->error("Token storage error: {$e->getMessage()}");
            return self::FAILURE;
        }
    }

    private function callCanvasApi(string $baseUrl, string $token, string $userAgent): int
    {
        $this->newLine();
        $this->comment('API Connection Test');

        $url = rtrim($baseUrl, '/') . '/api/v1/users/self';
        $this->line("  GET {$url}");

        try {
            $response = Http::withToken($token)
                ->withHeaders(['User-Agent' => $userAgent])
                ->acceptJson()
                ->timeout(15)
                ->get($url);
        } catch (\Throwable $e) {
            $this->error("Connection failed: {$e->getMessage()}");
            return self::FAILURE;
        }

        $status = $response->status();
        $this->line("  Status: {$status}");

        if ($status === 401) {
            $this->error('Authentication failed (401). Token is invalid or expired.');
            return self::FAILURE;
        }

        if ($status === 403) {
            $remaining = $response->header('X-Rate-Limit-Remaining');
            if ($remaining !== null && (float) $remaining <= 0) {
                $this->error('Rate limited (403). Try again later.');
                return self::FAILURE;
            }
            $this->error('Forbidden (403). Token may lack required permissions.');
            return self::FAILURE;
        }

        if ($response->failed()) {
            $this->error("Unexpected error ({$status}): " . $response->body());
            return self::FAILURE;
        }

        // ── Success ──────────────────────────────────────

        $user = $response->json();

        $this->newLine();
        $this->info('Authentication successful!');
        $this->line(str_repeat('─', 50));
        $this->line("  User ID:   " . ($user['id'] ?? '—'));
        $this->line("  Name:      " . ($user['name'] ?? '—'));
        $this->line("  Email:     " . ($user['primary_email'] ?? $user['email'] ?? '—'));
        $this->line("  Login ID:  " . ($user['login_id'] ?? '—'));

        // Rate limit info
        $remaining = $response->header('X-Rate-Limit-Remaining');
        if ($remaining !== null) {
            $this->line("  Rate limit remaining: {$remaining}");
        }

        return self::SUCCESS;
    }
}
