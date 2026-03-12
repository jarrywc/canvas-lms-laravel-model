<?php

namespace JarredCain\CanvasLms\Http;

use Illuminate\Http\Client\Response as HttpResponse;

class Response
{
    public function __construct(protected HttpResponse $response)
    {
    }

    public function json(): array
    {
        return $this->response->json() ?? [];
    }

    public function statusCode(): int
    {
        return $this->response->status();
    }

    public function header(string $name): string
    {
        return $this->response->header($name);
    }

    public function successful(): bool
    {
        return $this->response->successful();
    }

    public function raw(): HttpResponse
    {
        return $this->response;
    }
}
