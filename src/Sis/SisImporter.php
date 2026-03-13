<?php

namespace JarredCain\CanvasLms\Sis;

use JarredCain\CanvasLms\Http\CanvasClient;
use JarredCain\CanvasLms\Models\SisImport;

/**
 * Fluent builder for Canvas SIS CSV imports.
 *
 * Basic usage:
 *   Canvas::sisImport()->fromFile('/tmp/users.csv')->submit();
 *
 * With options:
 *   Canvas::sisImport()
 *       ->fromFile('/tmp/users.csv')
 *       ->batchMode(termId: 3)
 *       ->changeThreshold(20)
 *       ->submit()
 *       ->wait(120);
 *
 * From a CSV string:
 *   Canvas::sisImport()->fromCsv($csvString)->submit();
 *
 * Explicit account:
 *   Canvas::sisImport(accountId: 5)->fromFile('/tmp/users.csv')->submit();
 */
class SisImporter
{
    protected ?string    $fileContents = null;
    protected string     $filename     = 'import.csv';
    protected string     $mimeType     = 'text/csv';

    protected bool            $batchMode           = false;
    protected string|int|null $batchModeTermId     = null;
    protected ?string         $diffingIdentifier   = null;
    protected ?string         $diffingDropStatus   = null;
    protected bool            $skipDeletes         = false;
    protected bool            $overrideStickiness  = false;
    protected bool            $addStickiness       = false;
    protected bool            $clearStickiness     = false;
    protected ?int            $changeThreshold     = null;

    public function __construct(
        private readonly CanvasClient $client,
        private readonly string|int   $accountId,
    ) {
    }

    // -------------------------------------------------------------------------
    // Input sources
    // -------------------------------------------------------------------------

    /**
     * Load the import from a local file path.
     * Zip files are automatically detected by extension.
     */
    public function fromFile(string $path): static
    {
        if (!file_exists($path)) {
            throw new \RuntimeException("SIS import file not found: {$path}");
        }

        $clone               = clone $this;
        $clone->fileContents = file_get_contents($path);
        $clone->filename     = basename($path);
        $clone->mimeType     = str_ends_with($path, '.zip') ? 'application/zip' : 'text/csv';
        return $clone;
    }

    /**
     * Load the import from a raw CSV string.
     */
    public function fromCsv(string $csv, string $filename = 'import.csv'): static
    {
        $clone               = clone $this;
        $clone->fileContents = $csv;
        $clone->filename     = $filename;
        $clone->mimeType     = 'text/csv';
        return $clone;
    }

    /**
     * Load the import from a zip file path containing multiple SIS CSV files.
     */
    public function fromZip(string $path): static
    {
        if (!file_exists($path)) {
            throw new \RuntimeException("SIS import zip not found: {$path}");
        }

        $clone               = clone $this;
        $clone->fileContents = file_get_contents($path);
        $clone->filename     = basename($path);
        $clone->mimeType     = 'application/zip';
        return $clone;
    }

    // -------------------------------------------------------------------------
    // Options
    // -------------------------------------------------------------------------

    /**
     * Enable batch mode — Canvas will remove records from the given term that
     * are not present in this import file.
     *
     * @param string|int $termId  Canvas enrollment term ID to scope deletions to
     */
    public function batchMode(string|int $termId): static
    {
        $clone                  = clone $this;
        $clone->batchMode       = true;
        $clone->batchModeTermId = $termId;
        return $clone;
    }

    /**
     * Enable diffing — Canvas only processes rows that changed since the last
     * import with the same identifier. Greatly reduces processing time for
     * large nightly imports.
     *
     * @param string      $identifier  Arbitrary key to group related imports (e.g. 'users-nightly')
     * @param string|null $dropStatus  Status for records dropped from the diff: 'deleted'|'inactive'|'completed'
     */
    public function diffing(string $identifier, ?string $dropStatus = null): static
    {
        $clone                    = clone $this;
        $clone->diffingIdentifier = $identifier;
        $clone->diffingDropStatus = $dropStatus;
        return $clone;
    }

    /**
     * Do not delete any records — only create and update.
     */
    public function skipDeletes(): static
    {
        $clone              = clone $this;
        $clone->skipDeletes = true;
        return $clone;
    }

    /**
     * Abort the import if more than $threshold percent of records would be
     * deleted. Protects against accidentally wiping data with a bad file.
     *
     * @param int $threshold  0–100
     */
    public function changeThreshold(int $threshold): static
    {
        $clone                  = clone $this;
        $clone->changeThreshold = $threshold;
        return $clone;
    }

    /**
     * Allow this import to overwrite SIS-sticky fields.
     */
    public function overrideSisStickiness(): static
    {
        $clone                   = clone $this;
        $clone->overrideStickiness = true;
        return $clone;
    }

    /**
     * Mark all fields in this import as SIS-sticky after writing.
     */
    public function addSisStickiness(): static
    {
        $clone               = clone $this;
        $clone->addStickiness = true;
        return $clone;
    }

    /**
     * Clear the SIS-sticky flag from all fields touched by this import.
     */
    public function clearSisStickiness(): static
    {
        $clone                 = clone $this;
        $clone->clearStickiness = true;
        return $clone;
    }

    // -------------------------------------------------------------------------
    // Submit
    // -------------------------------------------------------------------------

    /**
     * Upload the CSV to Canvas and return the resulting SisImport.
     * Chain ->wait() to block until Canvas finishes processing.
     *
     * @throws \RuntimeException  If no file has been provided
     */
    public function submit(): SisImport
    {
        if ($this->fileContents === null) {
            throw new \RuntimeException(
                'No file provided. Call fromFile(), fromCsv(), or fromZip() before submit().'
            );
        }

        $fields = array_filter([
            'import_type'                 => 'instructure_csv',
            'batch_mode'                  => $this->batchMode ?: null,
            'batch_mode_term_id'          => $this->batchModeTermId,
            'diffing_data_set_identifier' => $this->diffingIdentifier,
            'diffing_drop_status'         => $this->diffingDropStatus,
            'skip_deletes'                => $this->skipDeletes ?: null,
            'override_sis_stickiness'     => $this->overrideStickiness ?: null,
            'add_sis_stickiness'          => $this->addStickiness ?: null,
            'clear_sis_stickiness'        => $this->clearStickiness ?: null,
            'change_threshold'            => $this->changeThreshold,
        ], fn($v) => $v !== null);

        $path     = "api/v1/accounts/{$this->accountId}/sis_imports";
        $response = $this->client->postMultipart($path, $fields, [
            [
                'name'     => 'attachment',
                'contents' => $this->fileContents,
                'filename' => $this->filename,
                'mimeType' => $this->mimeType,
            ],
        ]);

        return SisImport::fromResponse($response->json(), $this->accountId);
    }
}
