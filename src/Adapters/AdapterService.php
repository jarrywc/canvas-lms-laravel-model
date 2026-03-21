<?php

namespace JarredCain\CanvasLms\Adapters;

use InvalidArgumentException;
use JarredCain\CanvasLms\Canvas;
use JarredCain\CanvasLms\Models\CanvasModel;
use JarredCain\CanvasLms\Query\Builder;

/**
 * Service for translating external system payloads into Canvas and applying mutations.
 *
 * Combines ResourceMapper (loaded from config) with Canvas write operations.
 * The resource name must match a key in config('canvas.adapters') and a supported
 * Canvas resource type ('user', 'course', 'group', 'section', 'assignment').
 *
 * Register via the service container for injection:
 *   app(AdapterService::class)->push('user', 42, 'salesforce', $payload);
 *
 * Or use the Canvas facade if you prefer procedural style:
 *   Canvas::adapter()->push('course', $id, 'salesforce', $data);
 */
class AdapterService
{
    public function __construct(private readonly Canvas $canvas)
    {
    }

    /**
     * Translate $data (from the given system) into Canvas field names using the named config mapper.
     *
     * This is a pure translation step — no Canvas API call is made.
     * Useful in observers where you want to inspect the translated payload before deciding to push.
     *
     * @param  string  $resource    Config adapter key (e.g. 'user', 'course')
     * @param  string  $fromSystem  The source system name (e.g. 'salesforce', 'sql')
     * @param  array   $data        Key-value data in the source system's field names
     * @return array<string, mixed> Data mapped to Canvas field names
     */
    public function translate(string $resource, string $fromSystem, array $data): array
    {
        return ResourceMapper::fromConfig($resource)->from($fromSystem, $data)->to('canvas');
    }

    /**
     * Translate $data from the given system and update the Canvas resource via the API.
     *
     * The $data array should use the source system's field names — translation to Canvas
     * field names happens automatically via the named config mapper.
     *
     * Returns the updated Canvas model as returned by the API.
     *
     * @param  string      $resource    Config adapter key matching a supported Canvas resource type
     * @param  int|string  $canvasId    The Canvas resource ID to update
     * @param  string      $fromSystem  The source system name (e.g. 'salesforce', 'sql')
     * @param  array       $data        Key-value data in the source system's field names
     *
     * @throws InvalidArgumentException if $resource is not a supported Canvas resource type
     */
    public function push(string $resource, int|string $canvasId, string $fromSystem, array $data): CanvasModel
    {
        $canvasPayload = $this->translate($resource, $fromSystem, $data);

        return $this->builderForResource($resource)->update($canvasId, $canvasPayload);
    }

    /**
     * Resolve a Builder for the given Canvas resource type.
     *
     * Supported resource types mirror the Canvas service's top-level builder methods.
     * Extend this method in a subclass to add support for additional resource types.
     *
     * @throws InvalidArgumentException
     */
    protected function builderForResource(string $resource): Builder
    {
        return match ($resource) {
            'user'       => $this->canvas->users(),
            'course'     => $this->canvas->courses(),
            'group'      => $this->canvas->groups(),
            'enrollment' => $this->canvas->enrollments(),
            'account'    => $this->canvas->accounts(),
            default      => throw new InvalidArgumentException(
                "Unsupported Canvas resource type: '{$resource}'. " .
                "Supported types: user, course, group, enrollment, account. " .
                "Override builderForResource() in a subclass to add more."
            ),
        };
    }
}
