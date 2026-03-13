<?php

namespace JarredCain\CanvasLms\Models;

use JarredCain\CanvasLms\Exceptions\CanvasException;
use JarredCain\CanvasLms\Http\CanvasClient;

/**
 * Represents a Canvas SIS Import object returned by POST /api/v1/accounts/:id/sis_imports.
 *
 * @property string      $id
 * @property string      $workflow_state   initializing|created|importing|cleanup_batch|imported|imported_with_messages|aborted|failed|failed_with_messages|restoring|partially_restored|restored
 * @property int         $progress         0–100
 * @property array|null  $data             { import_type, supplied_batches, counts }
 * @property array|null  $statistics
 * @property array|null  $processing_errors    [[file, message], ...]
 * @property array|null  $processing_warnings  [[file, message], ...]
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $started_at
 * @property \Carbon\Carbon|null $ended_at
 * @property \Carbon\Carbon|null $updated_at
 */
class SisImport extends CanvasModel
{
    protected static string $endpoint = 'sis_imports';

    protected static bool $requiresContext = true;

    protected array $casts = [
        'id'                  => 'string',
        'progress'            => 'int',
        'data'                => 'array',
        'statistics'          => 'array',
        'processing_errors'   => 'array',
        'processing_warnings' => 'array',
        'created_at'          => 'datetime',
        'started_at'          => 'datetime',
        'ended_at'            => 'datetime',
        'updated_at'          => 'datetime',
    ];

    private string $accountId = '1';

    public static function fromResponse(array $data, string|int $accountId): static
    {
        $instance            = new static($data);
        $instance->accountId = (string) $accountId;
        return $instance;
    }

    // -------------------------------------------------------------------------
    // State helpers
    // -------------------------------------------------------------------------

    public function isComplete(): bool
    {
        return in_array($this->workflow_state, ['imported', 'imported_with_messages'], true);
    }

    public function isFailed(): bool
    {
        return in_array($this->workflow_state, ['failed', 'failed_with_messages', 'aborted'], true);
    }

    public function isPending(): bool
    {
        return in_array($this->workflow_state, [
            'initializing', 'created', 'importing', 'cleanup_batch', 'restoring',
        ], true);
    }

    public function hasErrors(): bool
    {
        return !empty($this->processing_errors);
    }

    public function hasWarnings(): bool
    {
        return !empty($this->processing_warnings);
    }

    /** @return array<int, array{0: string, 1: string}> */
    public function errors(): array
    {
        return $this->processing_errors ?? [];
    }

    /** @return array<int, array{0: string, 1: string}> */
    public function warnings(): array
    {
        return $this->processing_warnings ?? [];
    }

    // -------------------------------------------------------------------------
    // Polling
    // -------------------------------------------------------------------------

    /**
     * Re-fetch the latest state from Canvas.
     */
    public function refresh(): static
    {
        $client   = app(CanvasClient::class);
        $response = $client->get("api/v1/accounts/{$this->accountId}/sis_imports/{$this->id}");
        return $this->fill($response->json());
    }

    /**
     * Poll Canvas until the import finishes, fails, or the timeout is reached.
     *
     * @param int $maxSeconds      Maximum seconds to wait before throwing
     * @param int $pollIntervalMs  Milliseconds between polls (default 2000ms)
     * @throws CanvasException     On failure or timeout
     */
    public function wait(int $maxSeconds = 120, int $pollIntervalMs = 2000): static
    {
        $deadline = microtime(true) + $maxSeconds;

        while ($this->isPending()) {
            if (microtime(true) >= $deadline) {
                throw new CanvasException(
                    "SIS Import [{$this->id}] did not complete within {$maxSeconds} seconds. " .
                    "State: {$this->workflow_state}, progress: {$this->progress}%."
                );
            }

            usleep($pollIntervalMs * 1000);
            $this->refresh();
        }

        if ($this->isFailed()) {
            throw new CanvasException(
                "SIS Import [{$this->id}] failed. State: {$this->workflow_state}"
            );
        }

        return $this;
    }
}
