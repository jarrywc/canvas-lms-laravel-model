<?php

namespace JarredCain\CanvasLms\Exceptions;

class MissingContextException extends CanvasException
{
    public static function forModel(string $modelClass): static
    {
        return new static(
            "Model [{$modelClass}] requires a parent context. " .
            'Use forCourse(), forUser(), forSection(), or another context method before querying.'
        );
    }
}
