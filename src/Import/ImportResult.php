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

    /**
     * Export the import results as a CSV string.
     * Columns: row, id, success, error
     */
    public function toCsv(): string
    {
        $handle = fopen('php://temp', 'r+');
        $this->writeCsv($handle);
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);
        return $csv;
    }

    /**
     * Write the import results CSV to a file.
     *
     * @throws \RuntimeException  If the file cannot be opened for writing
     */
    public function exportCsv(string $path): void
    {
        $handle = @fopen($path, 'w');

        if ($handle === false) {
            throw new \RuntimeException("Cannot write to file: {$path}");
        }

        try {
            $this->writeCsv($handle);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param resource $handle
     */
    private function writeCsv($handle): void
    {
        fputcsv($handle, ['row', 'id', 'success', 'error']);

        foreach ($this->results as $result) {
            fputcsv($handle, [
                $result->row,
                $result->id,
                $result->success ? 'true' : 'false',
                $result->error ?? '',
            ]);
        }
    }
}
