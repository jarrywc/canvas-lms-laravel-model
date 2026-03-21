<?php

namespace JarredCain\CanvasLms\Utilities;

use Illuminate\Support\Collection;
use JarredCain\CanvasLms\Http\CanvasClient;
use JarredCain\CanvasLms\Models\User;
use JarredCain\CanvasLms\Query\Builder;

/**
 * Collects unique users (id + email) across multiple courses.
 *
 * Usage:
 *   Canvas::courseUserList()->courses([23, 24, 25])->get();
 *   Canvas::courseUserList()->courseRange(23, 25)->get();
 *
 * Returns a Collection of ['id' => '...', 'email' => '...'] with no duplicate user IDs.
 */
class CourseUserCollector
{
    private array $courseIds = [];

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
     * @return Collection<int, array{id: string, email: string|null}>
     */
    public function get(): Collection
    {
        $seen    = [];
        $results = [];

        foreach ($this->courseIds as $courseId) {
            $users = (new Builder(User::class))
                ->setClient($this->client)
                ->forCourse($courseId)
                ->include(['email'])
                ->all();

            foreach ($users as $user) {
                if (!isset($seen[$user->id])) {
                    $seen[$user->id] = true;
                    $results[]       = ['id' => $user->id, 'email' => $user->email];
                }
            }
        }

        return new Collection($results);
    }
}
