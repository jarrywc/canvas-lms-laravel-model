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
 * @property string|null $public_description
 * @property string|null $course_image_url
 * @property string|null $banner_image_url
 * @property bool        $is_public
 * @property bool        $public_syllabus
 * @property bool        $hide_final_grades
 * @property bool        $blueprint
 * @property bool|null   $concluded
 * @property string|null $time_zone
 * @property string|null $license
 * @property int|null    $storage_quota_mb
 * @property int|null    $total_students
 * @property array|null  $blueprint_restrictions
 * @property \Carbon\Carbon|null $start_at
 * @property \Carbon\Carbon|null $end_at
 * @property \Carbon\Carbon|null $created_at
 */
class Course extends CanvasModel
{
    protected static string $endpoint = 'courses';

    protected static bool $requiresContext = false;

    protected array $casts = [
        'id'                    => 'string',
        'account_id'            => 'string',
        'root_account_id'       => 'string',
        'enrollment_term_id'    => 'string',
        'grading_standard_id'   => 'string',
        'storage_quota_mb'      => 'int',
        'total_students'        => 'int',
        'is_public'             => 'bool',
        'public_syllabus'       => 'bool',
        'hide_final_grades'     => 'bool',
        'blueprint'             => 'bool',
        'concluded'             => 'bool',
        'blueprint_restrictions'=> 'array',
        'start_at'              => 'datetime',
        'end_at'                => 'datetime',
        'created_at'            => 'datetime',
        'updated_at'            => 'datetime',
    ];

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

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

    public function assignmentGroups(): HasMany
    {
        return $this->hasMany(AssignmentGroup::class);
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

    public function gradingPeriods(): HasMany
    {
        return $this->hasMany(GradingPeriod::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    // -------------------------------------------------------------------------
    // Lifecycle action methods
    // Canvas endpoint: PUT /api/v1/courses/:id with course[event]=<action>
    // -------------------------------------------------------------------------

    /**
     * Publish the course — makes it visible to students.
     * Canvas event: offer
     */
    public function publish(): static
    {
        return $this->triggerEvent('offer');
    }

    /**
     * Unpublish / hide the course from students.
     * Canvas event: claim
     */
    public function hide(): static
    {
        return $this->triggerEvent('claim');
    }

    /**
     * Conclude the course — locks it as read-only. Students can still view but not participate.
     * Canvas event: conclude
     */
    public function conclude(): static
    {
        return $this->triggerEvent('conclude');
    }

    /**
     * Restore a deleted or concluded course.
     * Canvas event: undelete
     */
    public function restore(): static
    {
        return $this->triggerEvent('undelete');
    }

    private function triggerEvent(string $event): static
    {
        $data = $this->performAction(
            'put',
            'api/v1/courses/' . $this->id,
            ['course' => ['event' => $event]]
        );

        return $this->fill($data);
    }
}
