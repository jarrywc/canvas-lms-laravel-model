<?php

namespace JarredCain\CanvasLms\Relations;

class HasMany extends Relation
{
    protected function addConstraints(): void
    {
        // Push the parent's relationship endpoint and ID onto the builder context.
        // e.g. Course (endpoint='courses', id=42) produces context: [['courses', 42]]
        // Builder then builds URL: api/v1/courses/42/{related_endpoint}
        // Uses getRelationshipEndpoint() so models like SubAccount can route via
        // 'accounts' in relationships while still listing via 'sub_accounts'.
        $this->builder->pushContext(
            $this->parent::getRelationshipEndpoint(),
            $this->parent->id
        );
    }
}
