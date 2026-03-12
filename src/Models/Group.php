<?php

namespace JarredCain\CanvasLms\Models;

use JarredCain\CanvasLms\Relations\BelongsTo;
use JarredCain\CanvasLms\Relations\HasMany;

/**
 * @property string      $id
 * @property string      $name
 * @property string|null $description
 * @property bool        $is_public
 * @property bool        $followed_by_user
 * @property string      $join_level
 * @property int         $members_count
 * @property string|null $avatar_url
 * @property string|null $context_type
 * @property string|null $course_id
 * @property string|null $account_id
 * @property string|null $role
 * @property string|null $group_category_id
 * @property string|null $sis_group_id
 * @property string|null $sis_import_id
 * @property int|null    $storage_quota_mb
 * @property bool        $has_submission
 * @property array|null  $permissions
 * @property array|null  $users
 */
class Group extends CanvasModel
{
    protected static string $endpoint = 'groups';

    protected static bool $requiresContext = false;

    protected array $casts = [
        'id'                => 'string',
        'course_id'         => 'string',
        'account_id'        => 'string',
        'group_category_id' => 'string',
        'members_count'     => 'int',
        'storage_quota_mb'  => 'int',
        'is_public'         => 'bool',
        'followed_by_user'  => 'bool',
        'has_submission'    => 'bool',
        'permissions'       => 'array',
        'users'             => 'array',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
