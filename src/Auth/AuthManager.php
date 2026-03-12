<?php

namespace JarredCain\CanvasLms\Auth;

use JarredCain\CanvasLms\Auth\OAuth2\OAuth2Handler;
use JarredCain\CanvasLms\Exceptions\AuthException;

class AuthManager
{
    private ?string $oauthStorageKey = null;

    public function __construct(
        private readonly array $config,
        private readonly ?OAuth2Handler $oauth2Handler = null
    ) {
    }

    public function isOAuth2(): bool
    {
        return $this->config['auth_driver'] === 'oauth2';
    }

    public function isToken(): bool
    {
        return $this->config['auth_driver'] === 'token';
    }

    public function setOAuthStorageKey(string $key): void
    {
        $this->oauthStorageKey = $key;
    }

    public function getToken(): string
    {
        if ($this->isToken()) {
            $token = $this->config['token'] ?? null;

            if (empty($token)) {
                throw new AuthException(
                    'Canvas API token is not configured. Set CANVAS_API_TOKEN in your environment.'
                );
            }

            return $token;
        }

        if ($this->isOAuth2()) {
            if (!$this->oauth2Handler) {
                throw new AuthException('OAuth2 handler is not configured.');
            }

            $key = $this->oauthStorageKey ?? 'default';
            return $this->oauth2Handler->getValidToken($key);
        }

        throw new AuthException(
            "Unsupported Canvas auth driver [{$this->config['auth_driver']}]. Supported: token, oauth2."
        );
    }
}
