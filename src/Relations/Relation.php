<?php

namespace JarredCain\CanvasLms\Relations;

use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use JarredCain\CanvasLms\Http\PaginatedResponse;
use JarredCain\CanvasLms\Models\CanvasModel;
use JarredCain\CanvasLms\Query\Builder;

abstract class Relation
{
    protected CanvasModel $parent;
    protected string $relatedClass;
    protected Builder $builder;

    public function __construct(CanvasModel $parent, string $relatedClass)
    {
        $this->parent       = $parent;
        $this->relatedClass = $relatedClass;
        $this->builder      = $relatedClass::query();

        $this->addConstraints();
    }

    /**
     * Each relation subclass wires the parent context onto the builder.
     */
    abstract protected function addConstraints(): void;

    public function get(): PaginatedResponse
    {
        return $this->builder->get();
    }

    public function first(): ?CanvasModel
    {
        return $this->builder->first();
    }

    public function all(): Collection
    {
        return $this->builder->all();
    }

    public function lazy(): LazyCollection
    {
        return $this->builder->lazy();
    }

    public function find(int|string $id): CanvasModel
    {
        return $this->builder->find($id);
    }

    public function create(array $data): CanvasModel
    {
        return $this->builder->create($data);
    }

    public function update(int|string $id, array $data): CanvasModel
    {
        return $this->builder->update($id, $data);
    }

    public function delete(int|string $id): bool
    {
        return $this->builder->delete($id);
    }

    /**
     * Delegate all unknown calls to the builder for fluent chaining.
     * Returns $this if the builder returned itself (chaining), otherwise the result.
     */
    public function __call(string $name, array $arguments): mixed
    {
        $result = $this->builder->$name(...$arguments);

        if ($result instanceof Builder) {
            return $this;
        }

        return $result;
    }
}
