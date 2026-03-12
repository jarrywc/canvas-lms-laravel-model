<?php

namespace JarredCain\CanvasLms\Relations;

class HasMany extends Relation
{
    protected function addConstraints(): void
    {
        // Push the parent's endpoint and ID onto the builder context.
        // e.g. Course (endpoint='courses', id=42) produces context: [['courses', 42]]
        // Builder then builds URL: api/v1/courses/42/{related_endpoint}
        $this->builder->pushContext(
            $this->parent::getEndpoint(),
            $this->parent->id
        );
    }
}
