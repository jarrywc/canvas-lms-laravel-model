<?php

namespace JarredCain\CanvasLms\Import;

use Illuminate\Support\Collection;

class ImportResult
{
    public readonly int $total;
    public readonly int $succeeded;
    public readonly int $failed;

    public function __construct(
        public readonly Collection $results
    ) {
        $this->total     = $results->count();
        $this->succeeded = $results->filter(fn(ImportRowResult $r) => $r->success)->count();
        $this->failed    = $results->filter(fn(ImportRowResult $r) => !$r->success)->count();
    }

    /**
     * Rows that succeeded — each has a hydrated model.
     */
    public function succeeded(): Collection
    {
        return $this->results->filter(fn(ImportRowResult $r) => $r->success)->values();
    }

    /**
     * Rows that failed — each has an error message.
     */
    public function failed(): Collection
    {
        return $this->results->filter(fn(ImportRowResult $r) => !$r->success)->values();
    }

    /**
     * Whether every row succeeded.
     */
    public function allSucceeded(): bool
    {
        return $this->failed === 0;
    }
}
