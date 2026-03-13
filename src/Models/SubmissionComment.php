<?php

namespace JarredCain\CanvasLms\Models;

/**
 * A comment on a Canvas submission. Not a top-level API resource — returned
 * as a nested array when fetching submissions with include[]=submission_comments.
 *
 * @property string      $id
 * @property string      $author_id
 * @property string      $author_name
 * @property string      $comment
 * @property array|null  $media_comment
 * @property array|null  $attachments
 * @property \Carbon\Carbon|null $created_at
 */
class SubmissionComment extends CanvasModel
{
    protected static string $endpoint = '';

    protected static bool $requiresContext = false;

    protected array $casts = [
        'id'            => 'string',
        'author_id'     => 'string',
        'media_comment' => 'array',
        'attachments'   => 'array',
        'created_at'    => 'datetime',
    ];
}
