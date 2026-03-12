<?php

namespace JarredCain\CanvasLms\Models;

use JarredCain\CanvasLms\Relations\BelongsTo;
use JarredCain\CanvasLms\Relations\HasMany;

/**
 * @property string      $id
 * @property string      $workflow_state
 * @property int         $position
 * @property string      $name
 * @property bool        $require_sequential_progress
 * @property array|null  $prerequisite_module_ids
 * @property int         $items_count
 * @property string|null $items_url
 * @property array|null  $items
 * @property string|null $state
 * @property bool        $published
 * @property bool|null   $publish_final_grade
 * @property \Carbon\Carbon|null $unlock_at
 * @property \Carbon\Carbon|null $completed_at
 */
class Module extends CanvasModel
{
    protected static string $endpoint = 'modules';

    protected static bool $requiresContext = true;

    protected array $casts = [
        'id'                            => 'string',
        'position'                      => 'int',
        'items_count'                   => 'int',
        'require_sequential_progress'   => 'bool',
        'published'                     => 'bool',
        'publish_final_grade'           => 'bool',
        'prerequisite_module_ids'       => 'array',
        'items'                         => 'array',
        'unlock_at'                     => 'datetime',
        'completed_at'                  => 'datetime',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ModuleItem::class);
    }
}
