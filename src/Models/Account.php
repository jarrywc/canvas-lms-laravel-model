<?php

namespace JarredCain\CanvasLms\Models;

use JarredCain\CanvasLms\Relations\HasMany;

/**
 * @property string      $id
 * @property string      $name
 * @property string|null $uuid
 * @property string|null $parent_account_id
 * @property string|null $root_account_id
 * @property string      $workflow_state
 * @property string|null $default_time_zone
 * @property int|null    $default_storage_quota_mb
 * @property int|null    $default_user_storage_quota_mb
 * @property string|null $sis_account_id
 * @property string|null $integration_id
 * @property \Carbon\Carbon|null $created_at
 */
class Account extends CanvasModel
{
    protected static string $endpoint = 'accounts';

    protected static bool $requiresContext = false;

    protected array $casts = [
        'id'                            => 'string',
        'parent_account_id'             => 'string',
        'root_account_id'               => 'string',
        'default_storage_quota_mb'      => 'int',
        'default_user_storage_quota_mb' => 'int',
        'created_at'                    => 'datetime',
    ];

    public function courses(): HasMany
    {
        return $this->hasMany(Course::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
