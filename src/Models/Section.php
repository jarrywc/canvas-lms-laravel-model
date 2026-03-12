<?php

namespace JarredCain\CanvasLms\Models;

use JarredCain\CanvasLms\Relations\BelongsTo;
use JarredCain\CanvasLms\Relations\HasMany;

/**
 * @property string      $id
 * @property string      $course_id
 * @property string      $name
 * @property string|null $sis_section_id
 * @property string|null $integration_id
 * @property string|null $sis_import_id
 * @property bool|null   $restrict_enrollments_to_section_dates
 * @property string|null $nonxlist_course_id
 * @property int|null    $total_students
 * @property \Carbon\Carbon|null $start_at
 * @property \Carbon\Carbon|null $end_at
 * @property \Carbon\Carbon|null $created_at
 */
class Section extends CanvasModel
{
    protected static string $endpoint = 'sections';

    protected static bool $requiresContext = true;

    protected array $casts = [
        'id'                                    => 'string',
        'course_id'                             => 'string',
        'nonxlist_course_id'                    => 'string',
        'total_students'                        => 'int',
        'restrict_enrollments_to_section_dates' => 'bool',
        'start_at'                              => 'datetime',
        'end_at'                                => 'datetime',
        'created_at'                            => 'datetime',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }
}
