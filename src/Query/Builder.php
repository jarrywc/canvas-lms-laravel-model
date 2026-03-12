<?php

namespace JarredCain\CanvasLms\Query;

use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use JarredCain\CanvasLms\Exceptions\MissingContextException;
use JarredCain\CanvasLms\Http\CanvasClient;
use JarredCain\CanvasLms\Http\PaginatedResponse;
use JarredCain\CanvasLms\Models\CanvasModel;

class Builder
{
    /**
     * Ordered context segments that build the URL prefix.
     * Each entry is [endpoint, id], e.g. [['courses', 42], ['sections', 7]]
     */
    protected array $context = [];

    /**
     * Scalar query parameters (mapped 1:1 to Canvas query string params).
     */
    protected array $parameters = [];

    /**
     * include[] accumulator — serialized as include[]=value1&include[]=value2
     */
    protected array $includes = [];

    /**
     * Other array parameters that use Canvas bracket notation (e.g., type[], state[]).
     * Format: ['type' => ['StudentEnrollment', 'TeacherEnrollment']]
     */
    protected array $arrayParams = [];

    /**
     * Retry configuration for rate-limited requests.
     */
    protected int $retryTimes = 0;
    protected int $retrySleepMs = 500;

    public function __construct(
        protected readonly string $modelClass,
        protected ?CanvasClient $client = null
    ) {
    }

    // -------------------------------------------------------------------------
    // Context setters — build the nested URL prefix
    // -------------------------------------------------------------------------

    public function pushContext(string $endpoint, int|string $id): static
    {
        $this->context[] = [$endpoint, $id];
        return $this;
    }

    public function forCourse(int|string $id): static
    {
        return $this->pushContext('courses', $id);
    }

    public function forUser(int|string $id): static
    {
        return $this->pushContext('users', $id);
    }

    public function forAccount(int|string $id): static
    {
        return $this->pushContext('accounts', $id);
    }

    public function forSection(int|string $id): static
    {
        return $this->pushContext('sections', $id);
    }

    public function forGroup(int|string $id): static
    {
        return $this->pushContext('groups', $id);
    }

    public function forAssignment(int|string $id): static
    {
        return $this->pushContext('assignments', $id);
    }

    public function forModule(int|string $id): static
    {
        return $this->pushContext('modules', $id);
    }

    // -------------------------------------------------------------------------
    // Fluent filter methods
    // -------------------------------------------------------------------------

    public function where(string $field, mixed $value): static
    {
        $this->parameters[$field] = $value;
        return $this;
    }

    public function whereIn(string $field, array $values): static
    {
        $this->arrayParams[$field] = $values;
        return $this;
    }

    public function include(string|array $includes): static
    {
        $this->includes = array_unique(
            array_merge($this->includes, (array) $includes)
        );
        return $this;
    }

    public function search(string $term): static
    {
        return $this->where('search_term', $term);
    }

    public function perPage(int $count): static
    {
        return $this->where('per_page', $count);
    }

    public function page(int $number): static
    {
        return $this->where('page', $number);
    }

    public function orderBy(string $field, string $direction = 'asc'): static
    {
        $this->where('sort', $field);
        $this->where('order', strtolower($direction));
        return $this;
    }

    public function withRetry(int $times, int $sleepMs = 500): static
    {
        $this->retryTimes = $times;
        $this->retrySleepMs = $sleepMs;
        return $this;
    }

    // -------------------------------------------------------------------------
    // Execution methods
    // -------------------------------------------------------------------------

    /**
     * Execute a GET list request and return a paginated response.
     */
    public function get(): PaginatedResponse
    {
        $client = $this->resolveClient();
        $url    = $this->buildUrl();
        $params = $this->buildQueryParameters();

        $response = $this->executeWithRetry(fn() => $client->get($url, $params));

        return PaginatedResponse::fromResponse($response, $this->modelClass, $client);
    }

    /**
     * Get only the first result.
     */
    public function first(): ?CanvasModel
    {
        return $this->perPage(1)->get()->first();
    }

    /**
     * Fetch a single resource by ID.
     */
    public function find(int|string $id): CanvasModel
    {
        $client   = $this->resolveClient();
        $url      = $this->buildUrl($id);
        $response = $this->executeWithRetry(fn() => $client->get($url));

        $data = $response->json();
        return (new $this->modelClass)->fill($data);
    }

    /**
     * Auto-follow all pagination pages and return a flat Collection.
     * WARNING: loads all records into memory. For large datasets use lazy() instead.
     */
    public function all(): Collection
    {
        return $this->lazy()->collect();
    }

    /**
     * Lazily stream all pages using a generator. Memory-efficient for large datasets.
     */
    public function lazy(): LazyCollection
    {
        $builder = $this;

        return LazyCollection::make(function () use ($builder) {
            $page = $builder->get();

            foreach ($page->items() as $item) {
                yield $item;
            }

            while ($page->hasNextPage()) {
                $page = $page->next();
                foreach ($page->items() as $item) {
                    yield $item;
                }
            }
        });
    }

    /**
     * Create a new resource via POST.
     */
    public function create(array $data): CanvasModel
    {
        $client   = $this->resolveClient();
        $url      = $this->buildUrl();
        $response = $client->post($url, $data);

        return (new $this->modelClass)->fill($response->json());
    }

    /**
     * Update a resource via PUT.
     */
    public function update(int|string $id, array $data): CanvasModel
    {
        $client   = $this->resolveClient();
        $url      = $this->buildUrl($id);
        $response = $client->put($url, $data);

        return (new $this->modelClass)->fill($response->json());
    }

    /**
     * Delete a resource by ID.
     */
    public function delete(int|string $id): bool
    {
        $client = $this->resolveClient();
        $url    = $this->buildUrl($id);
        $client->delete($url);
        return true;
    }

    // -------------------------------------------------------------------------
    // URL and parameter construction
    // -------------------------------------------------------------------------

    public function buildUrl(int|string|null $resourceId = null): string
    {
        /** @var CanvasModel $modelClass */
        $modelClass = $this->modelClass;

        if ($modelClass::requiresContext() && empty($this->context)) {
            throw MissingContextException::forModel($this->modelClass);
        }

        $segments = ['api', 'v1'];

        foreach ($this->context as [$resource, $id]) {
            $segments[] = $resource;
            $segments[] = $id;
        }

        $segments[] = $modelClass::getEndpoint();

        if ($resourceId !== null) {
            $segments[] = $resourceId;
        }

        return implode('/', $segments);
    }

    public function buildQueryParameters(): array
    {
        $params = $this->parameters;

        if (!empty($this->includes)) {
            $params['include'] = $this->includes;
        }

        foreach ($this->arrayParams as $key => $values) {
            $params[$key] = $values;
        }

        return $params;
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    public function setClient(CanvasClient $client): static
    {
        $this->client = $client;
        return $this;
    }

    private function resolveClient(): CanvasClient
    {
        if ($this->client) {
            return $this->client;
        }

        // Resolve from container when used outside of test context
        return app(CanvasClient::class);
    }

    private function executeWithRetry(callable $fn): mixed
    {
        $attempts = 0;
        $maxAttempts = $this->retryTimes + 1;

        while (true) {
            try {
                return $fn();
            } catch (\JarredCain\CanvasLms\Exceptions\RateLimitException $e) {
                $attempts++;
                if ($attempts >= $maxAttempts) {
                    throw $e;
                }
                usleep($this->retrySleepMs * 1000 * $attempts);
            }
        }
    }
}
