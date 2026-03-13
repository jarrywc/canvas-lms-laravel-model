<?php

namespace JarredCain\CanvasLms\Models;

use JarredCain\CanvasLms\Relations\BelongsTo;
use JarredCain\CanvasLms\Relations\HasMany;

/**
 * @property string      $id
 * @property string      $course_id
 * @property string      $name
 * @property string|null $description
 * @property string|null $html_url
 * @property string|null $assignment_group_id
 * @property float|null  $points_possible
 * @property string      $grading_type
 * @property array|null  $submission_types
 * @property array|null  $allowed_extensions
 * @property bool        $published
 * @property bool        $peer_reviews
 * @property bool        $anonymous_grading
 * @property bool        $moderated_grading
 * @property string|null $group_category_id
 * @property string|null $integration_id
 * @property string|null $grading_standard_id
 * @property int         $position
 * @property int|null    $allowed_attempts
 * @property \Carbon\Carbon|null $due_at
 * @property \Carbon\Carbon|null $lock_at
 * @property \Carbon\Carbon|null $unlock_at
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 */
class Assignment extends CanvasModel
{
    protected static string $endpoint = 'assignments';

    protected static bool $requiresContext = true;

    protected array $casts = [
        'id'                  => 'string',
        'course_id'           => 'string',
        'assignment_group_id' => 'string',
        'group_category_id'   => 'string',
        'grading_standard_id' => 'string',
        'points_possible'     => 'float',
        'position'            => 'int',
        'allowed_attempts'    => 'int',
        'published'           => 'bool',
        'peer_reviews'        => 'bool',
        'anonymous_grading'   => 'bool',
        'moderated_grading'   => 'bool',
        'submission_types'    => 'array',
        'allowed_extensions'  => 'array',
        'due_at'              => 'datetime',
        'lock_at'             => 'datetime',
        'unlock_at'           => 'datetime',
        'created_at'          => 'datetime',
        'updated_at'          => 'datetime',
    ];

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class);
    }

    // -------------------------------------------------------------------------
    // Bulk grading — async Canvas operation returning a Progress object
    // Canvas endpoint: POST /courses/:course_id/assignments/:id/submissions/update_grades
    // -------------------------------------------------------------------------

    /**
     * Grade multiple student submissions in bulk. Returns a Progress model for polling.
     *
     * @param array $grades  Keyed by user_id. Values can be a scalar grade or an array
     *                       with 'score' and optional 'comment' keys.
     *                       Examples:
     *                         [101 => 85, 102 => 92]
     *                         [101 => ['score' => 85, 'comment' => 'Late submission']]
     *                         [101 => '88%', 102 => 'A']
     *
     * @return Progress  Poll this to track completion: $progress->wait(120)
     */
    public function bulkGrade(array $grades): Progress
    {
        $gradeData = [];

        foreach ($grades as $userId => $value) {
            if (is_array($value)) {
                $entry = ['posted_grade' => $value['score'] ?? $value['posted_grade'] ?? null];
                if (isset($value['comment'])) {
                    $entry['text_comment'] = $value['comment'];
                }
                $gradeData[$userId] = $entry;
            } else {
                $gradeData[$userId] = ['posted_grade' => $value];
            }
        }

        $path = "api/v1/courses/{$this->course_id}/assignments/{$this->id}/submissions/update_grades";
        $data = $this->performAction('post', $path, ['grade_data' => $gradeData]);

        return (new Progress())->fill($data);
    }
}
