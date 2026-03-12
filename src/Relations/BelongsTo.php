<?php

namespace JarredCain\CanvasLms\Relations;

use JarredCain\CanvasLms\Models\CanvasModel;

class BelongsTo extends Relation
{
    private string $foreignKey;

    public function __construct(CanvasModel $parent, string $relatedClass, string $foreignKey)
    {
        $this->foreignKey = $foreignKey;
        parent::__construct($parent, $relatedClass);
    }

    protected function addConstraints(): void
    {
        // BelongsTo resolves its parent by the foreign key value.
        // No context is pushed — the related resource is fetched at its top-level endpoint.
        // Actual fetch happens in get() which calls find() with the foreign key value.
    }

    /**
     * Fetch the related model by the foreign key value on the parent.
     */
    public function get(): ?CanvasModel
    {
        $foreignId = $this->parent->getAttribute($this->foreignKey);

        if ($foreignId === null) {
            return null;
        }

        return $this->builder->find($foreignId);
    }

    /**
     * BelongsTo first() is the same as get() — there's always at most one.
     */
    public function first(): ?CanvasModel
    {
        return $this->get();
    }
}
