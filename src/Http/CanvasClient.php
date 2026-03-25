<?php

namespace JarredCain\CanvasLms\Http;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use JarredCain\CanvasLms\Exceptions\AuthException;
use JarredCain\CanvasLms\Exceptions\CanvasException;
use JarredCain\CanvasLms\Exceptions\RateLimitException;
use Psr\Log\LoggerInterface;

class CanvasClient
{
    private string $baseUrl;
    private string $token;
    private string $userAgent;
    private ?LoggerInterface $logger;

    public function __construct(string $baseUrl, string $token, string $userAgent = '', ?LoggerInterface $logger = null)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->token = $token;
        $this->userAgent = $userAgent ?: config('canvas.user_agent', 'CanvasLmsLaravel/1.0');
        $this->logger = $logger;
    }

    public function withToken(string $token): static
    {
        $clone = clone $this;
        $clone->token = $token;
        return $clone;
    }

    public function get(string $path, array $query = []): Response
    {
        $url = $this->resolveUrl($path);
        return $this->send('get', $url, ['query' => $query]);
    }

    public function getUrl(string $url, array $query = []): Response
    {
        // Used for opaque pagination URLs — do not prepend base URL
        return $this->send('get', $url, ['query' => $query]);
    }

    public function post(string $path, array $data = []): Response
    {
        $url = $this->resolveUrl($path);
        return $this->send('post', $url, ['json' => $data]);
    }

    public function put(string $path, array $data = []): Response
    {
        $url = $this->resolveUrl($path);
        return $this->send('put', $url, ['json' => $data]);
    }

    public function delete(string $path): Response
    {
        $url = $this->resolveUrl($path);
        return $this->send('delete', $url);
    }

    /**
     * POST a multipart/form-data request (used for SIS imports and file uploads).
     *
     * @param array $fields       Scalar form fields
     * @param array $attachments  Each entry: ['name'=>, 'contents'=>, 'filename'=>, 'mimeType'=>]
     */
    public function postMultipart(string $path, array $fields = [], array $attachments = []): Response
    {
        $url     = $this->resolveUrl($path);

        $this->logRequest('POST', $url, array_merge($fields, [
            '_attachments' => array_map(fn($a) => $a['filename'], $attachments),
        ]));

        $pending = Http::withToken($this->token)
            ->withHeaders(['User-Agent' => $this->userAgent])
            ->acceptJson();

        foreach ($attachments as $attachment) {
            $pending = $pending->attach(
                $attachment['name'],
                $attachment['contents'],
                $attachment['filename'],
            );
        }

        $httpResponse = $pending->post($url, $fields);

        $this->logResponse('POST', $url, $httpResponse);

        return $this->handleResponse($httpResponse);
    }

    private function send(string $method, string $url, array $options = []): Response
    {
        $this->logRequest($method, $url, $options['json'] ?? $options['query'] ?? []);

        $pending = $this->buildRequest();

        $httpResponse = match ($method) {
            'get'    => $pending->get($url, $options['query'] ?? []),
            'post'   => $pending->post($url, $options['json'] ?? []),
            'put'    => $pending->put($url, $options['json'] ?? []),
            'delete' => $pending->delete($url),
        };

        $this->logResponse($method, $url, $httpResponse);

        return $this->handleResponse($httpResponse);
    }

    private function handleResponse(\Illuminate\Http\Client\Response $httpResponse): Response
    {
        if ($httpResponse->status() === 429 ||
            ($httpResponse->status() === 403 && $this->isRateLimitResponse($httpResponse))) {
            $retryAfter = (int) ($httpResponse->header('Retry-After') ?: 60);
            throw new RateLimitException($retryAfter);
        }

        if ($httpResponse->status() === 401) {
            throw new AuthException('Canvas API authentication failed. Check your token or OAuth2 credentials.');
        }

        if ($httpResponse->failed()) {
            throw new CanvasException(
                "Canvas API request failed [{$httpResponse->status()}]: {$httpResponse->body()}"
            );
        }

        return new Response($httpResponse);
    }

    private function buildRequest(): PendingRequest
    {
        return Http::withToken($this->token)
            ->withHeaders(['User-Agent' => $this->userAgent])
            ->acceptJson()
            ->contentType('application/json');
    }

    private function resolveUrl(string $path): string
    {
        // If path is already a full URL, use it as-is
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return $this->baseUrl . '/' . ltrim($path, '/');
    }

    private function isRateLimitResponse(\Illuminate\Http\Client\Response $response): bool
    {
        $remaining = $response->header('X-Rate-Limit-Remaining');
        return $remaining !== null && (float) $remaining <= 0;
    }

    private function logRequest(string $method, string $url, array $payload = []): void
    {
        if (!$this->logger) {
            return;
        }

        $context = ['method' => strtoupper($method), 'url' => $url];
        if (!empty($payload)) {
            $context['payload'] = $payload;
        }

        $this->logger->debug('Canvas API request', $context);
    }

    private function logResponse(string $method, string $url, \Illuminate\Http\Client\Response $response): void
    {
        if (!$this->logger) {
            return;
        }

        $body = $response->body();
        $truncated = mb_strlen($body) > 2000
            ? mb_substr($body, 0, 2000) . '...[truncated]'
            : $body;

        $level = $response->successful() ? 'debug' : 'warning';

        $this->logger->log($level, 'Canvas API response', [
            'method' => strtoupper($method),
            'url'    => $url,
            'status' => $response->status(),
            'body'   => $truncated,
        ]);
    }
}
