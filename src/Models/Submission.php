<?php

namespace JarredCain\CanvasLms\Models;

use JarredCain\CanvasLms\Relations\BelongsTo;

/**
 * @property string      $id
 * @property string      $assignment_id
 * @property string      $user_id
 * @property string|null $course_id
 * @property string|null $grader_id
 * @property string|null $anonymous_id
 * @property int         $attempt
 * @property string|null $body
 * @property string|null $grade
 * @property float|null  $score
 * @property float|null  $points_deducted
 * @property int|null    $seconds_late
 * @property int|null    $extra_attempts
 * @property string      $workflow_state
 * @property string|null $submission_type
 * @property string|null $url
 * @property string|null $html_url
 * @property string|null $preview_url
 * @property bool        $late
 * @property bool        $missing
 * @property bool        $excused
 * @property bool|null   $grade_matches_current_submission
 * @property string|null $late_policy_status
 * @property string|null $read_status
 * @property \Carbon\Carbon|null $submitted_at
 * @property \Carbon\Carbon|null $graded_at
 * @property \Carbon\Carbon|null $posted_at
 */
class Submission extends CanvasModel
{
    protected static string $endpoint = 'submissions';

    protected static bool $requiresContext = true;

    protected array $casts = [
        'id'                               => 'string',
        'assignment_id'                    => 'string',
        'user_id'                          => 'string',
        'course_id'                        => 'string',
        'grader_id'                        => 'string',
        'attempt'                          => 'int',
        'score'                            => 'float',
        'points_deducted'                  => 'float',
        'seconds_late'                     => 'int',
        'extra_attempts'                   => 'int',
        'late'                             => 'bool',
        'missing'                          => 'bool',
        'excused'                          => 'bool',
        'grade_matches_current_submission' => 'bool',
        'submitted_at'                     => 'datetime',
        'graded_at'                        => 'datetime',
        'posted_at'                        => 'datetime',
    ];

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class, 'assignment_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id');
    }
}
