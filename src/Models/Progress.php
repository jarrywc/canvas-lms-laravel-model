<?php

namespace JarredCain\CanvasLms\Models;

use JarredCain\CanvasLms\Exceptions\CanvasException;
use JarredCain\CanvasLms\Http\CanvasClient;

/**
 * Represents a Canvas async Progress object returned by long-running operations
 * such as bulk grading, content migrations, and SIS imports.
 *
 * @property string      $id
 * @property string      $workflow_state   queued|running|completed|failed
 * @property int         $completion       0–100 percentage
 * @property string|null $message
 * @property array|null  $results          available when completed
 * @property string|null $url              polling endpoint
 * @property string|null $context_id
 * @property string|null $context_type
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 */
class Progress extends CanvasModel
{
    protected static string $endpoint = 'progress';

    protected static bool $requiresContext = false;

    protected array $casts = [
        'id'          => 'string',
        'context_id'  => 'string',
        'completion'  => 'int',
        'results'     => 'array',
        'created_at'  => 'datetime',
        'updated_at'  => 'datetime',
    ];

    public function isComplete(): bool
    {
        return $this->workflow_state === 'completed';
    }

    public function isFailed(): bool
    {
        return $this->workflow_state === 'failed';
    }

    public function isPending(): bool
    {
        return in_array($this->workflow_state, ['queued', 'running'], true);
    }

    /**
     * Re-fetch the latest state from Canvas.
     */
    public function refresh(): static
    {
        $client   = app(CanvasClient::class);
        $response = $client->get($this->buildUrl($this->id));
        return $this->fill($response->json());
    }

    /**
     * Poll Canvas until the operation completes, fails, or the timeout is reached.
     *
     * @param int $maxSeconds      Maximum seconds to wait before throwing
     * @param int $pollIntervalMs  Milliseconds between polls (default 1000ms)
     * @throws CanvasException     If the operation fails or times out
     */
    public function wait(int $maxSeconds = 60, int $pollIntervalMs = 1000): static
    {
        $deadline = microtime(true) + $maxSeconds;

        while ($this->isPending()) {
            if (microtime(true) >= $deadline) {
                throw new CanvasException(
                    "Canvas Progress [{$this->id}] did not complete within {$maxSeconds} seconds. " .
                    "Current state: {$this->workflow_state}, completion: {$this->completion}%."
                );
            }

            usleep($pollIntervalMs * 1000);
            $this->refresh();
        }

        if ($this->isFailed()) {
            throw new CanvasException(
                "Canvas Progress [{$this->id}] failed. Message: {$this->message}"
            );
        }

        return $this;
    }

    private function buildUrl(string $id): string
    {
        return 'api/v1/progress/' . $id;
    }
}
