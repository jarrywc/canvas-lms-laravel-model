<?php

namespace JarredCain\CanvasLms\Sis;

use JarredCain\CanvasLms\Models\SisImport;
use JarredCain\CanvasLms\Models\User;

/**
 * Fluent builder for Canvas SIS user-status CSV files.
 *
 * Produces a minimal two-column CSV (user_id, status) accepted by the
 * Canvas SIS import endpoint to suspend, activate, or delete users in bulk.
 *
 * user_id = the SIS user ID (sis_user_id on the User model), NOT the Canvas numeric ID.
 * status  = active | suspended | deleted
 *
 * Basic usage:
 *   SisUserCsvBuilder::make()
 *       ->suspend('sis_u1')
 *       ->suspend('sis_u2')
 *       ->activate('sis_u3')
 *       ->submitVia(Canvas::sisImport())
 *       ->wait();
 *
 * From User model instances:
 *   SisUserCsvBuilder::make()
 *       ->suspendUsers(Canvas::users()->all())
 *       ->submitVia(Canvas::sisImport(5));
 *
 * Inspect before submitting:
 *   SisUserCsvBuilder::make()->suspend('u1')->toFile('/tmp/suspend.csv');
 */
class SisUserCsvBuilder
{
    /** @var array<int, array{user_id: string, status: string}> */
    protected array $rows = [];

    protected function __construct()
    {
    }

    public static function make(): static
    {
        return new static();
    }

    // -------------------------------------------------------------------------
    // Row builders
    // -------------------------------------------------------------------------

    /**
     * Add a row that will suspend the given SIS user.
     */
    public function suspend(string $sisUserId): static
    {
        return $this->addRow($sisUserId, 'suspended');
    }

    /**
     * Add a row that will activate (unsuspend) the given SIS user.
     */
    public function activate(string $sisUserId): static
    {
        return $this->addRow($sisUserId, 'active');
    }

    /**
     * Add a row that will delete the given SIS user.
     */
    public function delete(string $sisUserId): static
    {
        return $this->addRow($sisUserId, 'deleted');
    }

    /**
     * Bulk-add suspend rows from an iterable of User model instances.
     * Reads the sis_user_id attribute on each model.
     *
     * @param iterable<User> $users
     * @throws \InvalidArgumentException  If a user has no sis_user_id set
     */
    public function suspendUsers(iterable $users): static
    {
        return $this->applyStatusToUsers($users, 'suspended');
    }

    /**
     * Bulk-add activate rows from an iterable of User model instances.
     *
     * @param iterable<User> $users
     * @throws \InvalidArgumentException  If a user has no sis_user_id set
     */
    public function activateUsers(iterable $users): static
    {
        return $this->applyStatusToUsers($users, 'active');
    }

    /**
     * Add a raw row with an explicit status value.
     * status must be one of: active, suspended, deleted.
     *
     * @throws \InvalidArgumentException  On unrecognised status
     */
    public function addRow(string $sisUserId, string $status): static
    {
        if (!in_array($status, ['active', 'suspended', 'deleted'], true)) {
            throw new \InvalidArgumentException(
                "Invalid SIS user status '{$status}'. Must be one of: active, suspended, deleted."
            );
        }

        $clone         = clone $this;
        $clone->rows[] = ['user_id' => $sisUserId, 'status' => $status];
        return $clone;
    }

    // -------------------------------------------------------------------------
    // Output
    // -------------------------------------------------------------------------

    /**
     * Return the SIS CSV as a string.
     */
    public function toCsv(): string
    {
        $handle = fopen('php://temp', 'r+');

        $this->write($handle);

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * Write the SIS CSV to a file.
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
     * Submit this CSV via the given SisImporter and return the resulting SisImport.
     * Chain ->wait() to block until Canvas finishes processing.
     *
     * @throws \RuntimeException  If there are no rows to submit
     */
    public function submitVia(SisImporter $importer): SisImport
    {
        if (empty($this->rows)) {
            throw new \RuntimeException('No rows to submit. Add rows with suspend(), activate(), or delete() first.');
        }

        return $importer->fromCsv($this->toCsv(), 'users.csv')->submit();
    }

    /**
     * Returns the number of rows queued for import.
     */
    public function count(): int
    {
        return count($this->rows);
    }

    public function isEmpty(): bool
    {
        return empty($this->rows);
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    /**
     * @param resource $handle
     */
    private function write($handle): void
    {
        fputcsv($handle, ['user_id', 'status'], ',', '"', '\\');

        foreach ($this->rows as $row) {
            fputcsv($handle, [$row['user_id'], $row['status']], ',', '"', '\\');
        }
    }

    /**
     * @param iterable<User> $users
     */
    private function applyStatusToUsers(iterable $users, string $status): static
    {
        $clone = clone $this;

        foreach ($users as $user) {
            $sisId = $user->sis_user_id ?? null;

            if ($sisId === null || $sisId === '') {
                throw new \InvalidArgumentException(
                    "User with Canvas ID [{$user->id}] has no sis_user_id. " .
                    "Only users provisioned via SIS can be managed through the SIS CSV import."
                );
            }

            $clone->rows[] = ['user_id' => $sisId, 'status' => $status];
        }

        return $clone;
    }
}
