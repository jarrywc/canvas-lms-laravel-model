<?php

namespace JarredCain\CanvasLms\Models;

use JarredCain\CanvasLms\Relations\BelongsTo;
use JarredCain\CanvasLms\Relations\HasMany;

/**
 * @property string      $id
 * @property string      $name
 * @property int         $position
 * @property float|null  $group_weight
 * @property array|null  $assignments
 * @property array|null  $rules
 * @property array|null  $integration_data
 * @property string|null $sis_source_id
 */
class AssignmentGroup extends CanvasModel
{
    protected static string $endpoint = 'assignment_groups';

    protected static bool $requiresContext = true;

    protected array $casts = [
        'id'               => 'string',
        'position'         => 'int',
        'group_weight'     => 'float',
        'assignments'      => 'array',
        'rules'            => 'array',
        'integration_data' => 'array',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class);
    }
}
