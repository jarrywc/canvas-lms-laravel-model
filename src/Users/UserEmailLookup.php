<?php

namespace JarredCain\CanvasLms\Users;

use Illuminate\Support\Collection;
use JarredCain\CanvasLms\Http\CanvasClient;

/**
 * Look up Canvas users by email address, returning their Canvas ID, SIS ID,
 * name, and optionally their account status (active or suspended).
 *
 * Basic usage — from an array of emails:
 *   Canvas::userEmailLookup()
 *       ->fromEmails(['alice@example.com', 'bob@example.com'])
 *       ->withStatus()
 *       ->lookup();
 *
 * From a CSV file:
 *   Canvas::userEmailLookup()
 *       ->fromCsv('/path/to/users.csv')           // reads 'email' column
 *       ->fromCsv('/path/to/users.csv', 'Email')  // custom column header
 *       ->withStatus()
 *       ->toCsv();
 *
 * Notes:
 *   - Searches GET /api/v1/accounts/:id/users?search_term={email}&include[]=email
 *     and filters for an exact, case-insensitive email match.
 *   - withStatus() adds one extra API call per found user (GET /api/v1/users/:id/logins)
 *     to check whether any login is suspended. Not used for not-found users.
 *   - Deleted users are excluded from Canvas search results by default; they
 *     will appear as not-found.
 */
class UserEmailLookup
{
    /** @var string[] */
    protected array $emails = [];

    protected bool $statusEnabled = false;

    public function __construct(
        private readonly CanvasClient $client,
        private readonly string|int   $accountId,
    ) {
    }

    // -------------------------------------------------------------------------
    // Input sources
    // -------------------------------------------------------------------------

    /**
     * Provide a plain array of email addresses to look up.
     *
     * @param string[] $emails
     */
    public function fromEmails(array $emails): static
    {
        $clone         = clone $this;
        $clone->emails = array_values(array_unique(array_map('strtolower', array_filter($emails))));
        return $clone;
    }

    /**
     * Read email addresses from a column in a CSV file.
     *
     * @param string $path    Path to the CSV file
     * @param string $column  Header name of the email column (case-insensitive, default: 'email')
     * @throws \InvalidArgumentException  If the file is not found or the column is missing
     */
    public function fromCsv(string $path, string $column = 'email'): static
    {
        if (!file_exists($path)) {
            throw new \InvalidArgumentException("CSV file not found: {$path}");
        }

        $csv = file_get_contents($path);
        return $this->fromCsvString($csv, $column);
    }

    /**
     * Read email addresses from a CSV string.
     *
     * @param string $csv     Raw CSV content
     * @param string $column  Header name of the email column (case-insensitive, default: 'email')
     * @throws \InvalidArgumentException  If the column is not found in the CSV headers
     */
    public function fromCsvString(string $csv, string $column = 'email'): static
    {
        $clone         = clone $this;
        $clone->emails = $this->extractEmailsFromCsv($csv, $column);
        return $clone;
    }

    // -------------------------------------------------------------------------
    // Options
    // -------------------------------------------------------------------------

    /**
     * Also fetch each found user's login status (active or suspended).
     * Requires one additional API call per found user.
     */
    public function withStatus(): static
    {
        $clone                = clone $this;
        $clone->statusEnabled = true;
        return $clone;
    }

    // -------------------------------------------------------------------------
    // Output
    // -------------------------------------------------------------------------

    /**
     * Execute the lookup and return a Collection of UserLookupResult objects —
     * one per email address in input order.
     *
     * @return Collection<int, UserLookupResult>
     * @throws \RuntimeException  If no emails have been provided
     */
    public function lookup(): Collection
    {
        if (empty($this->emails)) {
            throw new \RuntimeException(
                'No emails to look up. Call fromEmails(), fromCsv(), or fromCsvString() first.'
            );
        }

        return collect($this->emails)->map(fn(string $email) => $this->lookupSingle($email));
    }

    /**
     * Execute the lookup and return the results as a CSV string.
     * Columns: email, id, sis_user_id, name, [status,] found
     */
    public function toCsv(): string
    {
        $handle = fopen('php://temp', 'r+');
        $this->writeCsv($handle, $this->lookup());
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);
        return $csv;
    }

    /**
     * Execute the lookup and write the results to a CSV file.
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
            $this->writeCsv($handle, $this->lookup());
        } finally {
            fclose($handle);
        }
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    private function lookupSingle(string $email): UserLookupResult
    {
        $response = $this->client->get(
            "api/v1/accounts/{$this->accountId}/users",
            ['search_term' => $email, 'include' => ['email']]
        );

        $users = $response->json() ?? [];

        // Canvas search is fuzzy — find the exact email match
        $match = collect($users)->first(
            fn($u) => strtolower($u['email'] ?? '') === $email
        );

        if (!$match) {
            return new UserLookupResult(email: $email, found: false);
        }

        $status = $this->statusEnabled
            ? $this->resolveLoginStatus((string) $match['id'])
            : null;

        return new UserLookupResult(
            email:     $email,
            found:     true,
            id:        (string) $match['id'],
            sisUserId: $match['sis_user_id'] ?? null,
            name:      $match['name']        ?? null,
            status:    $status,
        );
    }

    /**
     * Check the user's logins to determine if they are suspended.
     * Returns 'suspended' if any login has workflow_state=suspended, 'active' otherwise.
     */
    private function resolveLoginStatus(string $userId): string
    {
        $response = $this->client->get("api/v1/users/{$userId}/logins");
        $logins   = $response->json() ?? [];

        $isSuspended = collect($logins)->contains(
            fn($login) => ($login['workflow_state'] ?? '') === 'suspended'
        );

        return $isSuspended ? 'suspended' : 'active';
    }

    /**
     * @param resource                   $handle
     * @param Collection<UserLookupResult> $results
     */
    private function writeCsv($handle, Collection $results): void
    {
        $headers = ['email', 'id', 'sis_user_id', 'name'];
        if ($this->statusEnabled) {
            $headers[] = 'status';
        }
        $headers[] = 'found';

        fputcsv($handle, $headers, ',', '"', '\\');

        foreach ($results as $result) {
            $row = [
                $result->email,
                $result->id        ?? '',
                $result->sisUserId ?? '',
                $result->name      ?? '',
            ];

            if ($this->statusEnabled) {
                $row[] = $result->status ?? '';
            }

            $row[] = $result->found ? 'true' : 'false';

            fputcsv($handle, $row, ',', '"', '\\');
        }
    }

    /**
     * @return string[]
     * @throws \InvalidArgumentException
     */
    private function extractEmailsFromCsv(string $csv, string $column): array
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $csv);
        rewind($handle);

        $headers = fgetcsv($handle, 0, ',', '"', '');

        if ($headers === false || $headers === null) {
            fclose($handle);
            return [];
        }

        // Case-insensitive column match
        $normalised = array_map('strtolower', array_map('trim', $headers));
        $index      = array_search(strtolower(trim($column)), $normalised, true);

        if ($index === false) {
            fclose($handle);
            throw new \InvalidArgumentException(
                "Column '{$column}' not found in CSV. Available columns: " . implode(', ', $headers)
            );
        }

        $emails = [];
        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $value = trim($row[$index] ?? '');
            if ($value !== '') {
                $emails[] = strtolower($value);
            }
        }

        fclose($handle);

        return array_values(array_unique($emails));
    }
}