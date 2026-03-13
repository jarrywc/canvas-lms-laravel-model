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

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

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

    // -------------------------------------------------------------------------
    // Grading action methods
    // Canvas endpoint: PUT /courses/:course_id/assignments/:assignment_id/submissions/:user_id
    // -------------------------------------------------------------------------

    /**
     * Grade this submission.
     *
     * @param int|float|string $score  Points, percentage ("85%"), letter grade, or pass/fail
     * @param string|null      $comment  Optional inline comment for the student
     */
    public function grade(int|float|string $score, ?string $comment = null): static
    {
        $body = ['submission' => ['posted_grade' => $score]];

        if ($comment !== null) {
            $body['comment'] = ['text_comment' => $comment];
        }

        $data = $this->performAction('put', $this->submissionPath(), $body);
        return $this->fill($data);
    }

    /**
     * Mark this submission as excused.
     */
    public function excuse(): static
    {
        $data = $this->performAction('put', $this->submissionPath(), [
            'submission' => ['excuse' => true],
        ]);
        return $this->fill($data);
    }

    /**
     * Add a text comment to this submission without changing the grade.
     */
    public function addComment(string $text): static
    {
        $data = $this->performAction('put', $this->submissionPath(), [
            'comment' => ['text_comment' => $text],
        ]);
        return $this->fill($data);
    }

    /**
     * Grade using a rubric. Provide an array of [criterion_id => points] pairs.
     *
     * @param array $criteria  e.g., ['_1234' => 3, '_5678' => 5]
     */
    public function gradeWithRubric(array $criteria): static
    {
        $rubricAssessment = [];
        foreach ($criteria as $criterionId => $points) {
            $rubricAssessment[$criterionId] = ['points' => $points];
        }

        $data = $this->performAction('put', $this->submissionPath(), [
            'rubric_assessment' => $rubricAssessment,
        ]);
        return $this->fill($data);
    }

    private function submissionPath(): string
    {
        return "api/v1/courses/{$this->course_id}/assignments/{$this->assignment_id}/submissions/{$this->user_id}";
    }
}
