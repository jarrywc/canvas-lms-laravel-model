<?php

namespace JarredCain\CanvasLms\Import;

use Illuminate\Support\Collection;
use JarredCain\CanvasLms\Query\Builder;

/**
 * Flexible CSV importer for any Canvas model.
 *
 * Only fields present (and non-empty) in the CSV row are sent to Canvas —
 * a partial CSV is safe: rows don't need to contain every field.
 *
 * Basic usage:
 *   CsvImporter::for(Course::class)->import('/path/to/courses.csv');
 *
 * Nested resources:
 *   CsvImporter::for(Assignment::class)
 *       ->using(Assignment::query()->forCourse(42))
 *       ->import('/path/to/assignments.csv');
 *
 * Column mapping (if CSV headers don't match Canvas field names):
 *   CsvImporter::for(User::class)
 *       ->mapColumns(['Full Name' => 'name', 'Email Address' => 'email'])
 *       ->import('/path/to/users.csv');
 */
class CsvImporter
{
    protected string   $idColumn   = 'id';
    protected bool     $skipEmpty  = true;
    protected bool     $dryRun     = false;
    protected bool     $autoWrap   = true;
    protected ?string  $wrapKey    = null;
    protected ?Builder $builder    = null;
    protected array    $columnMap  = [];

    protected function __construct(
        protected readonly string $modelClass
    ) {
    }

    // -------------------------------------------------------------------------
    // Factory
    // -------------------------------------------------------------------------

    public static function for(string $modelClass): static
    {
        return new static($modelClass);
    }

    // -------------------------------------------------------------------------
    // Configuration
    // -------------------------------------------------------------------------

    /**
     * Provide a pre-configured Builder for models that require context.
     * E.g. Assignment::query()->forCourse(42)
     */
    public function using(Builder $builder): static
    {
        $clone          = clone $this;
        $clone->builder = $builder;
        return $clone;
    }

    /**
     * The CSV column name that holds the Canvas record ID (default: 'id').
     */
    public function idColumn(string $column): static
    {
        $clone           = clone $this;
        $clone->idColumn = $column;
        return $clone;
    }

    /**
     * Map CSV column headers to Canvas field names.
     * Keys = CSV header, values = Canvas field name.
     * E.g. ['Course Name' => 'name', 'SIS ID' => 'sis_course_id']
     */
    public function mapColumns(array $map): static
    {
        $clone            = clone $this;
        $clone->columnMap = $map;
        return $clone;
    }

    /**
     * Wrap payload in the Canvas resource namespace (e.g. course[name]).
     * Auto-derived from model endpoint by default. Pass a key to override.
     */
    public function wrap(string $key): static
    {
        $clone          = clone $this;
        $clone->wrapKey = $key;
        $clone->autoWrap = true;
        return $clone;
    }

    /**
     * Send fields flat — do not wrap in a resource namespace.
     * Use when the Canvas endpoint accepts flat params.
     */
    public function noWrap(): static
    {
        $clone           = clone $this;
        $clone->autoWrap = false;
        return $clone;
    }

    /**
     * Whether to skip CSV cells that are empty strings (default: true).
     * When true, only columns with a value are included in the API payload.
     */
    public function skipEmpty(bool $value = true): static
    {
        $clone            = clone $this;
        $clone->skipEmpty = $value;
        return $clone;
    }

    /**
     * Validate rows and report what would be sent — no API calls made.
     */
    public function dryRun(): static
    {
        $clone         = clone $this;
        $clone->dryRun = true;
        return $clone;
    }

    // -------------------------------------------------------------------------
    // Import
    // -------------------------------------------------------------------------

    /**
     * Import from a file path.
     *
     * @throws \RuntimeException  If the file cannot be opened
     */
    public function import(string $path): ImportResult
    {
        $handle = @fopen($path, 'r');

        if ($handle === false) {
            throw new \RuntimeException("Cannot open CSV file: {$path}");
        }

        try {
            return $this->importHandle($handle);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Import from a CSV string.
     */
    public function importString(string $csv): ImportResult
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $csv);
        rewind($handle);

        try {
            return $this->importHandle($handle);
        } finally {
            fclose($handle);
        }
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    /**
     * @param resource $handle
     */
    private function importHandle($handle): ImportResult
    {
        $headers = fgetcsv($handle);

        if ($headers === false || $headers === null) {
            return new ImportResult(new Collection());
        }

        $headers = array_map('trim', $headers);

        $builder  = $this->resolveBuilder();
        $wrapKey  = $this->resolveWrapKey();
        $results  = [];
        $rowIndex = 1; // 1 = first data row (header was row 0)

        while (($row = fgetcsv($handle)) !== false) {
            $rowIndex++;

            if ($this->isBlankRow($row)) {
                continue;
            }

            $record = $this->buildRecord($headers, $row);
            $id     = $record[$this->idColumn] ?? null;

            if ($id === null || $id === '') {
                $results[] = new ImportRowResult(
                    row:   $rowIndex,
                    id:    '',
                    success: false,
                    error: "Missing ID column '{$this->idColumn}'"
                );
                continue;
            }

            // Remove the ID from the payload — it's the URL parameter, not a field to update
            $fields = $this->filterFields($record);

            if (empty($fields)) {
                $results[] = new ImportRowResult(
                    row:   $rowIndex,
                    id:    (string) $id,
                    success: false,
                    error: 'No fields to update after filtering empty values'
                );
                continue;
            }

            $payload = $wrapKey !== null ? [$wrapKey => $fields] : $fields;

            if ($this->dryRun) {
                $results[] = new ImportRowResult(
                    row:   $rowIndex,
                    id:    (string) $id,
                    success: true,
                );
                continue;
            }

            try {
                $model = (clone $builder)->update($id, $payload);
                $results[] = new ImportRowResult(
                    row:   $rowIndex,
                    id:    (string) $id,
                    success: true,
                    model: $model,
                );
            } catch (\Throwable $e) {
                $results[] = new ImportRowResult(
                    row:   $rowIndex,
                    id:    (string) $id,
                    success: false,
                    error: $e->getMessage(),
                );
            }
        }

        return new ImportResult(new Collection($results));
    }

    private function buildRecord(array $headers, array $row): array
    {
        $record = [];

        foreach ($headers as $i => $header) {
            $canvasField = $this->columnMap[$header] ?? $header;
            $record[$canvasField] = $row[$i] ?? '';
        }

        return $record;
    }

    private function filterFields(array $record): array
    {
        // Always remove the ID column from the payload
        unset($record[$this->idColumn]);

        if (!$this->skipEmpty) {
            return $record;
        }

        return array_filter($record, fn($value) => $value !== '' && $value !== null);
    }

    private function isBlankRow(array $row): bool
    {
        return count($row) === 1 && ($row[0] === null || trim($row[0]) === '');
    }

    private function resolveBuilder(): Builder
    {
        if ($this->builder !== null) {
            return $this->builder;
        }

        return ($this->modelClass)::query();
    }

    private function resolveWrapKey(): ?string
    {
        if (!$this->autoWrap) {
            return null;
        }

        if ($this->wrapKey !== null) {
            return $this->wrapKey;
        }

        return $this->deriveWrapKey(($this->modelClass)::getEndpoint());
    }

    /**
     * Derive the Canvas namespace key from the plural endpoint name.
     *
     * assignment_groups -> assignment_group
     * quizzes           -> quiz
     * courses           -> course
     */
    private function deriveWrapKey(string $endpoint): string
    {
        if (str_ends_with($endpoint, 'zes')) {
            return substr($endpoint, 0, -3) . 'z';
        }

        if (str_ends_with($endpoint, 'ies')) {
            return substr($endpoint, 0, -3) . 'y';
        }

        return rtrim($endpoint, 's');
    }
}
