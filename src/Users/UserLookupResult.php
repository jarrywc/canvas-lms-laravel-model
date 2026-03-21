<?php

namespace JarredCain\CanvasLms\Users;

/**
 * Represents the result of a single email lookup against Canvas.
 *
 * @property-read string      $email      The email address that was searched
 * @property-read bool        $found      Whether a user with this email was found
 * @property-read string|null $id         Canvas user ID (null if not found)
 * @property-read string|null $sisUserId  SIS user ID (null if not found or unset)
 * @property-read string|null $name       User's full name (null if not found)
 * @property-read string|null $status     'active'|'suspended' when withStatus() was used; null otherwise
 */
readonly class UserLookupResult
{
    public function __construct(
        public string  $email,
        public bool    $found,
        public ?string $id        = null,
        public ?string $sisUserId = null,
        public ?string $name      = null,
        /** null = not looked up or user not found; 'active' | 'suspended' otherwise */
        public ?string $status    = null,
    ) {
    }

    public function toArray(): array
    {
        return [
            'email'       => $this->email,
            'id'          => $this->id,
            'sis_user_id' => $this->sisUserId,
            'name'        => $this->name,
            'status'      => $this->status,
            'found'       => $this->found,
        ];
    }
}