<?php

namespace JarredCain\CanvasLms\Models;

use JarredCain\CanvasLms\Relations\BelongsTo;

/**
 * @property string      $id
 * @property string      $module_id
 * @property int         $position
 * @property string      $title
 * @property int         $indent
 * @property string      $type
 * @property string|null $content_id
 * @property string|null $html_url
 * @property string|null $url
 * @property string|null $page_url
 * @property string|null $external_url
 * @property bool|null   $new_tab
 * @property array|null  $completion_requirement
 * @property array|null  $content_details
 * @property bool        $published
 */
class ModuleItem extends CanvasModel
{
    protected static string $endpoint = 'items';

    protected static bool $requiresContext = true;

    protected array $casts = [
        'id'                    => 'string',
        'module_id'             => 'string',
        'content_id'            => 'string',
        'position'              => 'int',
        'indent'                => 'int',
        'new_tab'               => 'bool',
        'published'             => 'bool',
        'completion_requirement'=> 'array',
        'content_details'       => 'array',
    ];

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class, 'module_id');
    }
}
