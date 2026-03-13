<?php

namespace JarredCain\CanvasLms\Export;

use Illuminate\Support\LazyCollection;
use JarredCain\CanvasLms\Models\CanvasModel;
use JarredCain\CanvasLms\Query\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Export any collection of Canvas models to CSV.
 *
 * Basic usage:
 *   CsvExporter::from(Canvas::accountCourses()->all())->toString();
 *
 * Stream all pages without loading into memory:
 *   CsvExporter::fromBuilder(Canvas::accountCourses())->toFile('/tmp/courses.csv');
 *
 * Explicit columns + header labels:
 *   CsvExporter::from($courses)
 *       ->columns(['id', 'name', 'course_code', 'workflow_state'])
 *       ->mapHeaders(['workflow_state' => 'Status', 'course_code' => 'Code'])
 *       ->toFile('/tmp/courses.csv');
 *
 * HTTP download response:
 *   return CsvExporter::fromBuilder(Canvas::accountCourses())
 *       ->toResponse('courses.csv');
 */
class CsvExporter
{
    protected ?array  $columns    = null;
    protected array   $headerMap  = [];

    protected function __construct(
        protected readonly iterable $items
    ) {
    }

    // -------------------------------------------------------------------------
    // Factories
    // -------------------------------------------------------------------------

    /**
     * Export from any iterable of CanvasModel instances (Collection, array, LazyCollection, etc.).
     */
    public static function from(iterable $items): static
    {
        return new static($items);
    }

    /**
     * Export by streaming all pages of a Builder query via lazy().
     * Memory-efficient for large datasets.
     */
    public static function fromBuilder(Builder $builder): static
    {
        return new static($builder->lazy());
    }

    // -------------------------------------------------------------------------
    // Configuration
    // -------------------------------------------------------------------------

    /**
     * Specify which model attributes to include as columns, in order.
     * Defaults to all keys present on the first row.
     */
    public function columns(array $columns): static
    {
        $clone          = clone $this;
        $clone->columns = $columns;
        return $clone;
    }

    /**
     * Map model attribute names to custom CSV header labels.
     * E.g. ['workflow_state' => 'Status', 'sis_course_id' => 'SIS ID']
     */
    public function mapHeaders(array $map): static
    {
        $clone            = clone $this;
        $clone->headerMap = $map;
        return $clone;
    }

    // -------------------------------------------------------------------------
    // Output
    // -------------------------------------------------------------------------

    /**
     * Return the CSV as a string.
     */
    public function toString(): string
    {
        $handle = fopen('php://temp', 'r+');

        $this->write($handle);

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * Write the CSV to a file path.
     *
     * @throws \RuntimeException  If the file cannot be opened for writing
     */
    public function toFile(string $path): void
    {
        $handle = @fopen($path, 'w');

        if ($handle === false) {
            throw new \RuntimeException("Cannot write to file: {$path}");
        }

        try {
            $this->write($handle);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Return a StreamedResponse for direct HTTP download.
     * Use in a Laravel controller: return CsvExporter::fromBuilder(...)->toResponse('export.csv');
     */
    public function toResponse(string $filename): StreamedResponse
    {
        $exporter = $this;

        return new StreamedResponse(
            function () use ($exporter) {
                $handle = fopen('php://output', 'w');
                $exporter->write($handle);
                fclose($handle);
            },
            200,
            [
                'Content-Type'        => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                'Cache-Control'       => 'no-store, no-cache',
                'Pragma'              => 'no-cache',
            ]
        );
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    /**
     * @param resource $handle
     */
    public function write($handle): void
    {
        $columns     = $this->columns;
        $headerWritten = false;

        foreach ($this->items as $model) {
            $row = $model instanceof CanvasModel ? $model->toArray() : (array) $model;

            // Derive columns from first row if not specified
            if ($columns === null) {
                $columns = array_keys($row);
            }

            // Write header row once
            if (!$headerWritten) {
                $headers = array_map(
                    fn(string $col) => $this->headerMap[$col] ?? $col,
                    $columns
                );
                fputcsv($handle, $headers, ',', '"', '\\');
                $headerWritten = true;
            }

            fputcsv($handle, $this->extractValues($row, $columns), ',', '"', '\\');
        }
    }

    private function extractValues(array $row, array $columns): array
    {
        return array_map(function (string $col) use ($row) {
            $value = $row[$col] ?? '';

            // Flatten non-scalar values
            if (is_array($value)) {
                return implode('|', array_map(
                    fn($v) => is_scalar($v) ? $v : json_encode($v),
                    $value
                ));
            }

            if ($value instanceof \DateTimeInterface) {
                return $value->format(\DateTime::RFC3339);
            }

            // Carbon instances
            if (is_object($value) && method_exists($value, 'toIso8601String')) {
                return $value->toIso8601String();
            }

            return $value ?? '';
        }, $columns);
    }
}
