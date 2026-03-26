<?php

namespace JarredCain\CanvasLms\Utilities;

use Illuminate\Support\Collection;
use JarredCain\CanvasLms\Exceptions\CanvasException;
use JarredCain\CanvasLms\Http\CanvasClient;
use JarredCain\CanvasLms\Models\Course;
use JarredCain\CanvasLms\Models\Enrollment;
use JarredCain\CanvasLms\Models\User;
use JarredCain\CanvasLms\Query\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Collects users in an account who are NOT enrolled in any course.
 *
 * This is the inverse of CourseUserCollector — it returns account users whose
 * IDs do not appear in any course enrollment.
 *
 * Usage:
 *   Canvas::unenrolledUsers()->get();
 *   Canvas::unenrolledUsers()->studentsOnly()->toCsv();
 *   Canvas::unenrolledUsers(5)->courses([23, 24])->toResponse('unenrolled.csv');
 *
 * Returns a Collection of ['id' => '...', 'name' => '...', 'email' => '...'].
 */
class UnenrolledUserCollector
{
    private array $courseIds = [];

    private array $enrollmentTypes = [];

    private int|string|null $accountId = null;

    /** @var array<string, string> Course ID => error message from the last get() call */
    private array $errors = [];

    public function __construct(private readonly CanvasClient $client) {}

    /**
     * Set the course IDs to check enrollment against.
     * Users enrolled in ANY of these courses are excluded.
     *
     * @param  array<int|string>  $ids
     */
    public function courses(array $ids): static
    {
        $this->courseIds = array_map('strval', $ids);

        return $this;
    }

    /**
     * Filter by enrollment type(s) when checking enrollments.
     * Valid values: student, teacher, ta, observer, designer
     *
     * A user is considered "enrolled" only if they have an enrollment of this type.
     *
     * @param  string|array<string>  $types
     */
    public function enrollmentType(string|array $types): static
    {
        $this->enrollmentTypes = (array) $types;

        return $this;
    }

    /**
     * Convenience: only consider student enrollments.
     * Users enrolled as teachers but not students will be returned.
     */
    public function studentsOnly(): static
    {
        return $this->enrollmentType('student');
    }

    /**
     * Set courses by a numeric range (inclusive).
     *
     * Canvas::unenrolledUsers()->courseRange(23, 25)->get()
     * is equivalent to ->courses([23, 24, 25])
     */
    public function courseRange(int $from, int $to): static
    {
        return $this->courses(range($from, $to));
    }

    /**
     * Discover courses from a Canvas account instead of specifying IDs.
     * If courses() is also called, explicit IDs take priority.
     *
     * Defaults to the account_id from config/canvas.php when null is passed.
     *
     * @param int|string|null $accountId  Override the configured account ID
     */
    public function forAccount(int|string|null $accountId = null): static
    {
        $this->accountId = $accountId ?? config('canvas.account_id', 1);

        return $this;
    }

    // -------------------------------------------------------------------------
    // Output
    // -------------------------------------------------------------------------

    /**
     * Fetch all account users who are NOT enrolled in any of the configured courses.
     *
     * @return Collection<int, array{id: string, name: string|null, email: string|null}>
     */
    public function get(): Collection
    {
        $this->errors = [];

        if (empty($this->courseIds) && $this->accountId !== null) {
            try {
                $courses = (new Builder(Course::class))
                    ->setClient($this->client)
                    ->forAccount($this->accountId)
                    ->all();

                $this->courseIds = $courses->pluck('id')->map(fn ($id) => (string) $id)->all();
            } catch (CanvasException $e) {
                $this->errors['_account'] = $e->getMessage();

                return new Collection();
            }
        }

        // Fetch all account users — gives us names + emails
        $userMap = $this->fetchAccountUsers();

        if (empty($userMap)) {
            return new Collection();
        }

        // No courses to check — all account users are unenrolled
        if (empty($this->courseIds)) {
            return new Collection(array_map(
                fn (int|string $userId, array $data) => [
                    'id'    => (string) $userId,
                    'name'  => $data['name'],
                    'email' => $data['email'],
                ],
                array_keys($userMap),
                array_values($userMap)
            ));
        }

        // Build a set of enrolled user IDs across all courses
        $enrolledIds = [];

        foreach ($this->courseIds as $courseId) {
            try {
                $builder = (new Builder(Enrollment::class))
                    ->setClient($this->client)
                    ->forCourse($courseId);

                if (! empty($this->enrollmentTypes)) {
                    $canvasTypes = array_map(
                        fn (string $t) => ucfirst($t) . 'Enrollment',
                        $this->enrollmentTypes
                    );
                    $builder = $builder->whereIn('type', $canvasTypes);
                }

                foreach ($builder->all() as $enrollment) {
                    $enrolledIds[(string) $enrollment->user_id] = true;
                }
            } catch (CanvasException $e) {
                $this->errors[$courseId] = $e->getMessage();
            }
        }

        // Return account users whose IDs are NOT in the enrolled set
        $results = [];

        foreach ($userMap as $userId => $data) {
            if (! isset($enrolledIds[(string) $userId])) {
                $results[] = [
                    'id'    => (string) $userId,
                    'name'  => $data['name'],
                    'email' => $data['email'],
                ];
            }
        }

        return new Collection($results);
    }

    /**
     * Execute and return results as a CSV string.
     */
    public function toCsv(): string
    {
        $handle = fopen('php://temp', 'r+');
        $this->writeCsv($handle, $this->get());
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * Execute and write results to a CSV file.
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
            $this->writeCsv($handle, $this->get());
        } finally {
            fclose($handle);
        }
    }

    /**
     * Execute and return a StreamedResponse for HTTP download.
     */
    public function toResponse(string $filename): StreamedResponse
    {
        $results = $this->get();

        return new StreamedResponse(
            function () use ($results) {
                $handle = fopen('php://output', 'w');
                $this->writeCsv($handle, $results);
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

    /**
     * Get errors from the last get() call, keyed by course ID.
     *
     * @return array<string, string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    /**
     * Fetch all users for the account and build a userId => {name, email} map.
     *
     * @return array<string, array{name: string|null, email: string|null}>
     */
    private function fetchAccountUsers(): array
    {
        $accountId = $this->accountId ?? config('canvas.account_id', 1);
        $map = [];

        try {
            $users = (new Builder(User::class))
                ->setClient($this->client)
                ->forAccount($accountId)
                ->include(['email'])
                ->all();

            foreach ($users as $user) {
                $map[(string) $user->id] = [
                    'name'  => $user->name,
                    'email' => $user->email,
                ];
            }
        } catch (CanvasException $e) {
            $this->errors['_users'] = $e->getMessage();
        }

        return $map;
    }

    /**
     * @param  resource    $handle
     * @param  Collection  $results
     */
    private function writeCsv($handle, Collection $results): void
    {
        fputcsv($handle, ['user_id', 'user_name', 'user_email'], ',', '"', '\\');

        foreach ($results as $row) {
            fputcsv($handle, [
                $row['id'],
                $row['name']  ?? '',
                $row['email'] ?? '',
            ], ',', '"', '\\');
        }
    }
}
