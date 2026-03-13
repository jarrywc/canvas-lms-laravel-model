<?php

namespace JarredCain\CanvasLms\Models;

use Carbon\Carbon;
use JarredCain\CanvasLms\Http\CanvasClient;
use JarredCain\CanvasLms\Query\Builder;
use JarredCain\CanvasLms\Relations\BelongsTo;
use JarredCain\CanvasLms\Relations\HasMany;

abstract class CanvasModel
{
    /**
     * The Canvas API endpoint for this resource (e.g., 'courses', 'users').
     */
    protected static string $endpoint = '';

    /**
     * Whether this resource requires a parent context to be queried.
     * Set true for nested resources like Enrollment, Assignment, Submission, etc.
     */
    protected static bool $requiresContext = false;

    /**
     * Raw API attributes from the Canvas JSON response.
     */
    protected array $attributes = [];

    /**
     * Attribute type casts. Subclasses should merge in additional casts.
     * Available types: 'string', 'int', 'float', 'bool', 'array', 'datetime'
     */
    protected array $casts = [
        'id'         => 'string',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function __construct(array $attributes = [])
    {
        if (!empty($attributes)) {
            $this->fill($attributes);
        }
    }

    public function fill(array $attributes): static
    {
        $this->attributes = array_merge($this->attributes, $attributes);
        return $this;
    }

    public function __get(string $key): mixed
    {
        return $this->getAttribute($key);
    }

    public function __set(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function __isset(string $key): bool
    {
        return isset($this->attributes[$key]);
    }

    public function getAttribute(string $key): mixed
    {
        $value = $this->attributes[$key] ?? null;

        if ($value === null) {
            return null;
        }

        $cast = $this->casts[$key] ?? null;

        if ($cast === null) {
            return $value;
        }

        return $this->castAttribute($value, $cast);
    }

    public function toArray(): array
    {
        return $this->attributes;
    }

    public function toJson(int $flags = 0): string
    {
        return json_encode($this->toArray(), $flags);
    }

    public function __toString(): string
    {
        return $this->toJson();
    }

    public static function getEndpoint(): string
    {
        return static::$endpoint;
    }

    /**
     * The URL segment used when this model is the PARENT in a HasMany relationship.
     * Defaults to $endpoint. Override in models where the listing endpoint differs
     * from the parent-context segment (e.g. SubAccount lists via 'sub_accounts'
     * but parent-context routes via 'accounts').
     */
    public static function getRelationshipEndpoint(): string
    {
        return static::$endpoint;
    }

    public static function requiresContext(): bool
    {
        return static::$requiresContext;
    }

    /**
     * Return a new query Builder for this model.
     */
    public static function query(): Builder
    {
        return new Builder(static::class);
    }

    /**
     * Fetch a single resource by ID.
     */
    public static function find(int|string $id): static
    {
        return static::query()->find($id);
    }

    /**
     * Create a lazy model instance with only the ID set (no API call).
     * Used for relationship traversal via facade sugar: Canvas::course(1)->enrollments()
     */
    public static function newWithId(int|string $id): static
    {
        $instance = new static();
        $instance->attributes['id'] = (string) $id;
        return $instance;
    }

    /**
     * Define a has-many relationship.
     */
    protected function hasMany(string $relatedClass): HasMany
    {
        return new HasMany($this, $relatedClass);
    }

    /**
     * Define a belongs-to relationship.
     */
    protected function belongsTo(string $relatedClass, string $foreignKey): BelongsTo
    {
        return new BelongsTo($this, $relatedClass, $foreignKey);
    }

    /**
     * Execute an API action that doesn't map to standard CRUD.
     * Used by model lifecycle methods (publish, conclude, grade, etc.)
     *
     * @param string $method  HTTP method: 'get', 'post', 'put', 'delete'
     * @param string $path    Full API path (e.g., 'api/v1/courses/42')
     * @param array  $data    Request body (for POST/PUT)
     * @param array  $query   Query string parameters (for GET/DELETE)
     */
    protected function performAction(string $method, string $path, array $data = [], array $query = []): array
    {
        $client = app(CanvasClient::class);

        $response = match (strtolower($method)) {
            'get'    => $client->get($path, $query),
            'post'   => $client->post($path, $data),
            'put'    => $client->put($path, $data),
            'delete' => $client->delete($path . (!empty($query) ? '?' . http_build_query($query) : '')),
        };

        return $response->json();
    }

    private function castAttribute(mixed $value, string $cast): mixed
    {
        return match ($cast) {
            'string'   => (string) $value,
            'int'      => (int) $value,
            'float'    => (float) $value,
            'bool'     => (bool) $value,
            'array'    => (array) $value,
            'datetime' => $value instanceof Carbon ? $value : Carbon::parse($value),
            default    => $value,
        };
    }
}
