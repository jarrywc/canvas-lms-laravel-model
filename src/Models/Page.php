<?php

namespace JarredCain\CanvasLms\Models;

use JarredCain\CanvasLms\Relations\BelongsTo;

/**
 * @property string      $page_id   (Canvas uses page_id, not id, for pages)
 * @property string      $url       (the URL-friendly slug, used as the identifier)
 * @property string      $title
 * @property string|null $body
 * @property string|null $editing_roles
 * @property array|null  $last_edited_by
 * @property bool        $published
 * @property bool        $front_page
 * @property bool        $hide_from_students
 * @property bool        $locked_for_user
 * @property int|null    $revision_id
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 * @property \Carbon\Carbon|null $publish_at
 */
class Page extends CanvasModel
{
    protected static string $endpoint = 'pages';

    protected static bool $requiresContext = true;

    protected array $casts = [
        'page_id'           => 'string',
        'revision_id'       => 'int',
        'published'         => 'bool',
        'front_page'        => 'bool',
        'hide_from_students'=> 'bool',
        'locked_for_user'   => 'bool',
        'last_edited_by'    => 'array',
        'created_at'        => 'datetime',
        'updated_at'        => 'datetime',
        'publish_at'        => 'datetime',
    ];

    /**
     * Pages use `url` as their identifier rather than `id`.
     * Override getAttribute to return page_id when 'id' is requested.
     */
    public function getAttribute(string $key): mixed
    {
        if ($key === 'id') {
            return parent::getAttribute('page_id') ?? parent::getAttribute('url');
        }

        return parent::getAttribute($key);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id');
    }
}
