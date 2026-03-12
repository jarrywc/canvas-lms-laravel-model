<?php

namespace JarredCain\CanvasLms\Models;

use JarredCain\CanvasLms\Relations\BelongsTo;
use JarredCain\CanvasLms\Relations\HasMany;

/**
 * @property string      $id
 * @property string      $name
 * @property string|null $course_code
 * @property string|null $uuid
 * @property string|null $sis_course_id
 * @property string|null $integration_id
 * @property string|null $account_id
 * @property string|null $root_account_id
 * @property string|null $enrollment_term_id
 * @property string|null $grading_standard_id
 * @property string      $workflow_state
 * @property string|null $default_view
 * @property string|null $syllabus_body
 * @property bool        $is_public
 * @property bool        $public_syllabus
 * @property bool        $hide_final_grades
 * @property bool        $blueprint
 * @property string|null $time_zone
 * @property string|null $license
 * @property int|null    $storage_quota_mb
 * @property \Carbon\Carbon|null $start_at
 * @property \Carbon\Carbon|null $end_at
 * @property \Carbon\Carbon|null $created_at
 */
class Course extends CanvasModel
{
    protected static string $endpoint = 'courses';

    protected static bool $requiresContext = false;

    protected array $casts = [
        'id'                 => 'string',
        'account_id'         => 'string',
        'root_account_id'    => 'string',
        'enrollment_term_id' => 'string',
        'grading_standard_id'=> 'string',
        'storage_quota_mb'   => 'int',
        'is_public'          => 'bool',
        'public_syllabus'    => 'bool',
        'hide_final_grades'  => 'bool',
        'blueprint'          => 'bool',
        'start_at'           => 'datetime',
        'end_at'             => 'datetime',
        'created_at'         => 'datetime',
        'updated_at'         => 'datetime',
    ];

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function sections(): HasMany
    {
        return $this->hasMany(Section::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class);
    }

    public function quizzes(): HasMany
    {
        return $this->hasMany(Quiz::class);
    }

    public function modules(): HasMany
    {
        return $this->hasMany(Module::class);
    }

    public function pages(): HasMany
    {
        return $this->hasMany(Page::class);
    }

    public function groups(): HasMany
    {
        return $this->hasMany(Group::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }
}
