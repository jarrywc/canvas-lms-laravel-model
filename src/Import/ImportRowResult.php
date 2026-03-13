<?php

namespace JarredCain\CanvasLms\Import;

use JarredCain\CanvasLms\Models\CanvasModel;

class ImportRowResult
{
    public function __construct(
        public readonly int         $row,
        public readonly string      $id,
        public readonly bool        $success,
        public readonly ?CanvasModel $model = null,
        public readonly ?string     $error = null,
    ) {
    }
}
