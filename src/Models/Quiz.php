<?php

namespace JarredCain\CanvasLms\Models;

use JarredCain\CanvasLms\Relations\BelongsTo;

/**
 * @property string      $id
 * @property string      $course_id (via context, not always in response)
 * @property string      $title
 * @property string|null $description
 * @property string      $quiz_type
 * @property string|null $assignment_group_id
 * @property int|null    $time_limit
 * @property bool        $shuffle_answers
 * @property string|null $hide_results
 * @property bool        $show_correct_answers
 * @property bool        $one_time_results
 * @property string      $scoring_policy
 * @property int         $allowed_attempts
 * @property bool        $one_question_at_a_time
 * @property int         $question_count
 * @property float|null  $points_possible
 * @property bool        $cant_go_back
 * @property string|null $access_code
 * @property string|null $ip_filter
 * @property bool        $published
 * @property bool        $unpublishable
 * @property string|null $html_url
 * @property string|null $speedgrader_url
 * @property int         $version_number
 * @property \Carbon\Carbon|null $due_at
 * @property \Carbon\Carbon|null $lock_at
 * @property \Carbon\Carbon|null $unlock_at
 * @property \Carbon\Carbon|null $show_correct_answers_at
 * @property \Carbon\Carbon|null $hide_correct_answers_at
 */
class Quiz extends CanvasModel
{
    protected static string $endpoint = 'quizzes';

    protected static bool $requiresContext = true;

    protected array $casts = [
        'id'                   => 'string',
        'assignment_group_id'  => 'string',
        'time_limit'           => 'int',
        'allowed_attempts'     => 'int',
        'question_count'       => 'int',
        'version_number'       => 'int',
        'points_possible'      => 'float',
        'shuffle_answers'      => 'bool',
        'show_correct_answers' => 'bool',
        'one_time_results'     => 'bool',
        'one_question_at_a_time' => 'bool',
        'cant_go_back'         => 'bool',
        'published'            => 'bool',
        'unpublishable'        => 'bool',
        'due_at'               => 'datetime',
        'lock_at'              => 'datetime',
        'unlock_at'            => 'datetime',
        'show_correct_answers_at'  => 'datetime',
        'hide_correct_answers_at'  => 'datetime',
        'created_at'           => 'datetime',
        'updated_at'           => 'datetime',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id');
    }
}
