<?php

namespace JarredCain\CanvasLms\Utilities;

use Illuminate\Support\Collection;
use JarredCain\CanvasLms\Exceptions\CanvasException;
use JarredCain\CanvasLms\Http\CanvasClient;
use JarredCain\CanvasLms\Models\Course;
use JarredCain\CanvasLms\Models\User;
use JarredCain\CanvasLms\Query\Builder;

/**
 * Generates a user report organized by course (cohort) for an account.
 *
 * Unlike CourseUserCollector which deduplicates, this preserves the per-course
 * grouping — a user enrolled in multiple courses appears once per course.
 *
 * Usage:
 *   Canvas::accountUserReport()->forAccount()->studentsOnly()->toCsv();
 *   Canvas::accountUserReport()->forAccount(5)->toFile('/tmp/students.csv');
 *   Canvas::accountUserReport()->courses([23, 24])->studentsOnly()->get();
 *
 * CSV columns: course_id, course_name, user_id, user_name, user_email
 */
class AccountUserReport
{
    private array $courseIds = [];

    private array $enrollmentTypes = [];

    private int|string|null $accountId = null;

    /** @var array<string, string> */
    private array $errors = [];

    public function __construct(private readonly CanvasClient $client) {}

    /**
     * Set the course IDs to report on.
     *
     * @param  array<int|string>  $ids
     */
    public function courses(array $ids): static
    {
        $this->courseIds = array_map('strval', $ids);

        return $this;
    }

    /**
     * Set courses by a numeric range (inclusive).
     */
    public function courseRange(int $from, int $to): static
    {
        return $this->courses(range($from, $to));
    }

    /**
     * Discover courses from a Canvas account.
     * Defaults to the account_id from config/canvas.php when null is passed.
     * If courses() is also called, explicit IDs take priority.
     */
    public function forAccount(int|string|null $accountId = null): static
    {
        $this->accountId = $accountId ?? config('canvas.account_id', 1);

        return $this;
    }

    /**
     * Filter users by enrollment type(s).
     * Valid values: student, teacher, ta, observer, designer
     *
     * @param  string|array<string>  $types
     */
    public function enrollmentType(string|array $types): static
    {
        $this->enrollmentTypes = (array) $types;

        return $this;
    }

    /**
     * Convenience: only return students.
     */
    public function studentsOnly(): static
    {
        return $this->enrollmentType('student');
    }

    // -------------------------------------------------------------------------
    // Output
    // -------------------------------------------------------------------------

    /**
     * Fetch users organized by course.
     *
     * @return Collection<int, array{course_id: string, course_name: string, user_id: string, user_name: string|null, user_email: string|null}>
     */
    public function get(): Collection
    {
        $results      = [];
        $this->errors = [];

        $courseMap = $this->resolveCourses();

        foreach ($courseMap as $courseId => $courseName) {
            try {
                $builder = (new Builder(User::class))
                    ->setClient($this->client)
                    ->forCourse($courseId)
                    ->include(['email']);

                if (!empty($this->enrollmentTypes)) {
                    $builder = $builder->ofEnrollmentType($this->enrollmentTypes);
                }

                foreach ($builder->all() as $user) {
                    $results[] = [
                        'course_id'  => (string) $courseId,
                        'course_name' => $courseName,
                        'user_id'    => $user->id,
                        'user_name'  => $user->name,
                        'user_email' => $user->email,
                    ];
                }
            } catch (CanvasException $e) {
                $this->errors[$courseId] = $e->getMessage();
            }
        }

        return new Collection($results);
    }

    /**
     * Execute the report and return results as a CSV string.
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
     * Execute the report and write results to a CSV file.
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
     * Resolve course IDs to an id => name map.
     * If explicit IDs were set, fetches each course's name.
     * If forAccount() was used, discovers courses from the account.
     *
     * @return array<string, string>
     */
    private function resolveCourses(): array
    {
        if (!empty($this->courseIds)) {
            return $this->fetchCourseNames($this->courseIds);
        }

        if ($this->accountId !== null) {
            try {
                $courses = (new Builder(Course::class))
                    ->setClient($this->client)
                    ->forAccount($this->accountId)
                    ->all();

                $map = [];
                foreach ($courses as $course) {
                    $map[(string) $course->id] = $course->name ?? '';
                }

                return $map;
            } catch (CanvasException $e) {
                $this->errors['_account'] = $e->getMessage();

                return [];
            }
        }

        return [];
    }

    /**
     * Fetch course names for explicit course IDs.
     *
     * @param  array<string>  $courseIds
     * @return array<string, string>
     */
    private function fetchCourseNames(array $courseIds): array
    {
        $map = [];

        foreach ($courseIds as $id) {
            try {
                $course  = (new Builder(Course::class))
                    ->setClient($this->client)
                    ->find($id);

                $map[(string) $id] = $course->name ?? '';
            } catch (CanvasException $e) {
                $this->errors[$id] = $e->getMessage();
            }
        }

        return $map;
    }

    /**
     * @param resource   $handle
     * @param Collection $results
     */
    private function writeCsv($handle, Collection $results): void
    {
        fputcsv($handle, ['course_id', 'course_name', 'user_id', 'user_name', 'user_email'], ',', '"', '\\');

        foreach ($results as $row) {
            fputcsv($handle, [
                $row['course_id'],
                $row['course_name'],
                $row['user_id'],
                $row['user_name']  ?? '',
                $row['user_email'] ?? '',
            ], ',', '"', '\\');
        }
    }
}
