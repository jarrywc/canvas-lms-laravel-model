<?php

namespace JarredCain\CanvasLms\Auth\OAuth2;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use JarredCain\CanvasLms\Auth\Storage\TokenStorageInterface;
use JarredCain\CanvasLms\Exceptions\AuthException;
use Psr\Log\LoggerInterface;

class OAuth2Handler
{
    private string $baseUrl;
    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;

    public function __construct(
        private readonly TokenStorageInterface $storage,
        array $config,
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->baseUrl      = rtrim($config['base_url'], '/');
        $this->clientId     = $config['oauth2']['client_id'];
        $this->clientSecret = $config['oauth2']['client_secret'];
        $this->redirectUri  = $config['oauth2']['redirect_uri'];
    }

    /**
     * Build the Canvas authorization URL and store a CSRF state token in session.
     */
    public function buildAuthorizationUrl(array $scopes = []): string
    {
        $state = Str::random(40);
        Session::put('canvas_oauth_state', $state);

        $params = [
            'client_id'     => $this->clientId,
            'response_type' => 'code',
            'redirect_uri'  => $this->redirectUri,
            'state'         => $state,
        ];

        if (!empty($scopes)) {
            $params['scope'] = implode(' ', $scopes);
        }

        return $this->baseUrl . '/login/oauth2/auth?' . http_build_query($params);
    }

    /**
     * Handle the OAuth2 callback: verify state, exchange code for tokens, store them.
     *
     * @param Request $request
     * @param string  $storageKey  Key to store the token under (e.g., "user:42")
     * @return array  The stored token data
     * @throws AuthException
     */
    public function handleCallback(Request $request, string $storageKey): array
    {
        $this->verifyState($request);

        $code = $request->input('code');

        if (empty($code)) {
            throw new AuthException('No authorization code received from Canvas.');
        }

        $tokenData = $this->exchangeCode($code);
        $this->storage->store($storageKey, $tokenData);

        Session::forget('canvas_oauth_state');

        return $tokenData;
    }

    /**
     * Get a valid access token, refreshing if necessary.
     *
     * @throws AuthException
     */
    public function getValidToken(string $storageKey): string
    {
        $tokenData = $this->storage->retrieve($storageKey);

        if (!$tokenData) {
            throw new AuthException(
                "No Canvas OAuth2 token found for key [{$storageKey}]. " .
                "The user must complete the OAuth2 authorization flow first."
            );
        }

        if ($this->isExpired($tokenData)) {
            if (empty($tokenData['refresh_token'])) {
                throw new AuthException(
                    "Canvas access token for [{$storageKey}] has expired and no refresh token is available."
                );
            }

            $tokenData = $this->refresh($tokenData['refresh_token'], $storageKey);
        }

        return $tokenData['access_token'];
    }

    /**
     * Revoke the stored token and remove it from storage.
     */
    public function revokeToken(string $storageKey): void
    {
        $tokenData = $this->storage->retrieve($storageKey);

        if ($tokenData) {
            $url = $this->baseUrl . '/login/oauth2/token';
            $payload = ['token' => $tokenData['access_token']];

            $this->logOAuthRequest('revoke', $url, $payload);

            $response = Http::withHeaders(['User-Agent' => config('canvas.user_agent', 'CanvasLmsLaravel/1.0')])
                ->post($url, $payload);

            $this->logOAuthResponse('revoke', $response);
        }

        $this->storage->forget($storageKey);
    }

    private function exchangeCode(string $code): array
    {
        $url = $this->baseUrl . '/login/oauth2/token';
        $payload = [
            'grant_type'    => 'authorization_code',
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri'  => $this->redirectUri,
            'code'          => $code,
        ];

        $this->logOAuthRequest('token_exchange', $url, $payload);

        $response = Http::withHeaders(['User-Agent' => config('canvas.user_agent', 'CanvasLmsLaravel/1.0')])
            ->post($url, $payload);

        $this->logOAuthResponse('token_exchange', $response);

        if ($response->failed()) {
            throw new AuthException('Canvas token exchange failed: ' . $response->body());
        }

        return $this->normalizeTokenResponse($response->json());
    }

    private function refresh(string $refreshToken, string $storageKey): array
    {
        $url = $this->baseUrl . '/login/oauth2/token';
        $payload = [
            'grant_type'    => 'refresh_token',
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $refreshToken,
        ];

        $this->logOAuthRequest('token_refresh', $url, $payload);

        $response = Http::withHeaders(['User-Agent' => config('canvas.user_agent', 'CanvasLmsLaravel/1.0')])
            ->post($url, $payload);

        $this->logOAuthResponse('token_refresh', $response);

        if ($response->failed()) {
            // Refresh token is no longer valid — clear stored token
            $this->storage->forget($storageKey);
            throw new AuthException(
                "Canvas token refresh failed for [{$storageKey}]. " .
                "User must re-authorize: " . $response->body()
            );
        }

        $tokenData = $this->normalizeTokenResponse($response->json());

        // Canvas may not return a new refresh token — preserve the old one
        if (empty($tokenData['refresh_token'])) {
            $tokenData['refresh_token'] = $refreshToken;
        }

        $this->storage->store($storageKey, $tokenData);

        return $tokenData;
    }

    private function normalizeTokenResponse(array $response): array
    {
        $expiresIn = $response['expires_in'] ?? 3600;

        return [
            'access_token'  => $response['access_token'],
            'refresh_token' => $response['refresh_token'] ?? null,
            'token_type'    => $response['token_type'] ?? 'Bearer',
            'expires_at'    => Carbon::now()->addSeconds($expiresIn)->toIso8601String(),
        ];
    }

    private function isExpired(array $tokenData): bool
    {
        // Refresh 60 seconds before actual expiry to avoid edge-case failures
        return Carbon::parse($tokenData['expires_at'])->subMinutes(1)->isPast();
    }

    private function verifyState(Request $request): void
    {
        $sessionState  = Session::get('canvas_oauth_state');
        $requestState  = $request->input('state');

        if (empty($sessionState) || $sessionState !== $requestState) {
            throw new AuthException('OAuth2 state mismatch. Possible CSRF attack.');
        }
    }

    private function logOAuthRequest(string $action, string $url, array $payload): void
    {
        if (!$this->logger) {
            return;
        }

        $safe = $payload;
        foreach (['client_secret', 'code', 'refresh_token', 'token'] as $key) {
            if (isset($safe[$key])) {
                $safe[$key] = '********';
            }
        }

        $this->logger->debug("Canvas OAuth2 {$action}", ['url' => $url, 'payload' => $safe]);
    }

    private function logOAuthResponse(string $action, \Illuminate\Http\Client\Response $response): void
    {
        if (!$this->logger) {
            return;
        }

        $level = $response->successful() ? 'debug' : 'warning';
        $this->logger->log($level, "Canvas OAuth2 {$action} response", [
            'status' => $response->status(),
        ]);
    }
}
