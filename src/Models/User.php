<?php

namespace JarredCain\CanvasLms\Models;

use JarredCain\CanvasLms\Relations\HasMany;

/**
 * @property string      $id
 * @property string      $name
 * @property string|null $sortable_name
 * @property string|null $short_name
 * @property string|null $login_id
 * @property string|null $email
 * @property string|null $sis_user_id
 * @property string|null $integration_id
 * @property string|null $avatar_url
 * @property string|null $pronouns
 * @property string|null $time_zone
 * @property string|null $locale
 * @property string|null $bio
 * @property string|null $effective_locale
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $last_login
 */
class User extends CanvasModel
{
    protected static string $endpoint = 'users';

    protected static bool $requiresContext = false;

    protected array $casts = [
        'id'         => 'string',
        'created_at' => 'datetime',
        'last_login' => 'datetime',
    ];

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function courses(): HasMany
    {
        return $this->hasMany(Course::class);
    }

    public function groups(): HasMany
    {
        return $this->hasMany(Group::class);
    }
}
