<?php

namespace JarredCain\CanvasLms\Models;

use JarredCain\CanvasLms\Relations\BelongsTo;

/**
 * @property string      $id
 * @property string      $user_id
 * @property string      $course_id
 * @property string|null $course_section_id
 * @property string|null $root_account_id
 * @property string      $type
 * @property string      $role
 * @property string|null $role_id
 * @property string      $enrollment_state
 * @property string|null $html_url
 * @property array|null  $grades
 * @property array|null  $user
 * @property string|null $sis_account_id
 * @property string|null $sis_course_id
 * @property string|null $sis_section_id
 * @property string|null $sis_user_id
 * @property string|null $integration_id
 * @property int|null    $total_activity_time
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 * @property \Carbon\Carbon|null $start_at
 * @property \Carbon\Carbon|null $end_at
 * @property \Carbon\Carbon|null $last_activity_at
 * @property \Carbon\Carbon|null $last_attended_at
 */
class Enrollment extends CanvasModel
{
    protected static string $endpoint = 'enrollments';

    protected static bool $requiresContext = true;

    protected array $casts = [
        'id'                  => 'string',
        'user_id'             => 'string',
        'course_id'           => 'string',
        'course_section_id'   => 'string',
        'root_account_id'     => 'string',
        'role_id'             => 'string',
        'total_activity_time' => 'int',
        'grades'              => 'array',
        'created_at'          => 'datetime',
        'updated_at'          => 'datetime',
        'start_at'            => 'datetime',
        'end_at'              => 'datetime',
        'last_activity_at'    => 'datetime',
        'last_attended_at'    => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class, 'course_section_id');
    }
}
