<?php

namespace JarredCain\CanvasLms\Utilities;

use Illuminate\Support\Collection;
use JarredCain\CanvasLms\Http\CanvasClient;
use JarredCain\CanvasLms\Models\User;
use JarredCain\CanvasLms\Query\Builder;

/**
 * Collects unique users (id + name + email) across multiple courses.
 *
 * Usage:
 *   Canvas::courseUserList()->courses([23, 24, 25])->get();
 *   Canvas::courseUserList()->courseRange(23, 25)->studentsOnly()->get();
 *
 * Returns a Collection of ['id' => '...', 'name' => '...', 'email' => '...'] with no duplicate user IDs.
 */
class CourseUserCollector
{
    private array $courseIds = [];

    private array $enrollmentTypes = [];

    public function __construct(private readonly CanvasClient $client) {}

    /**
     * Set the course IDs to collect users from.
     *
     * @param  array<int|string>  $ids
     */
    public function courses(array $ids): static
    {
        $this->courseIds = array_map('strval', $ids);

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

    /**
     * Set courses by a numeric range (inclusive).
     *
     * Canvas::courseUserList()->courseRange(23, 25)->get()
     * is equivalent to ->courses([23, 24, 25])
     */
    public function courseRange(int $from, int $to): static
    {
        return $this->courses(range($from, $to));
    }

    /**
     * Fetch all unique users across the configured courses.
     *
     * Iterates each course, requests all pages, and deduplicates by user ID.
     *
     * @return Collection<int, array{id: string, name: string|null, email: string|null}>
     */
    public function get(): Collection
    {
        $seen    = [];
        $results = [];

        foreach ($this->courseIds as $courseId) {
            $builder = (new Builder(User::class))
                ->setClient($this->client)
                ->forCourse($courseId)
                ->include(['email']);

            if (!empty($this->enrollmentTypes)) {
                $builder = $builder->ofEnrollmentType($this->enrollmentTypes);
            }

            $users = $builder->all();

            foreach ($users as $user) {
                if (!isset($seen[$user->id])) {
                    $seen[$user->id] = true;
                    $results[]       = ['id' => $user->id, 'name' => $user->name, 'email' => $user->email];
                }
            }
        }

        return new Collection($results);
    }
}
