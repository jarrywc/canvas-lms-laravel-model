<?php

namespace JarredCain\CanvasLms\Adapters;

use InvalidArgumentException;

/**
 * Bidirectional field mapper for translating data between Canvas and external systems.
 *
 * Define a mapper once with a plain array of field rows, then use it to translate
 * data two-way between any pair of systems, or merge data from three or more systems
 * into a single record with per-field priority-based conflict resolution.
 *
 * ## Defining a mapper
 *
 * Each row in the definition array describes one logical field across systems.
 * Reserved keys per row:
 * - Any system name (string): maps to the field name in that system
 * - 'priority' (array): ordered system names; first system with a value wins in merge().
 *                        Omit to defer to the global priority passed to merge().
 * - 'transforms' (array): direction-keyed callables. Keys: 'to_{system}', 'from_{system}'.
 *                          'to_{system}' is applied when translating/projecting outbound.
 *                          'from_{system}' is applied when ingesting during merge().
 *
 * ## Loading from config
 *
 * Define named adapter templates in config/canvas.php under the 'adapters' key,
 * then load by name: ResourceMapper::fromConfig('user').
 *
 * ## Example
 *
 * ```php
 * $mapper = ResourceMapper::define([
 *     ['canvas' => 'name',        'salesforce' => 'Full_Name__c',  'sql' => 'full_name',
 *      'priority' => ['canvas', 'salesforce', 'sql']],
 *
 *     ['canvas' => 'start_at',    'salesforce' => 'Start_Date__c', 'sql' => 'start_date',
 *      'priority' => ['salesforce', 'canvas'],
 *      'transforms' => ['to_salesforce' => fn($v) => date('Y-m-d', strtotime($v))]],
 * ]);
 *
 * // Two-way translation
 * $sfPayload    = $mapper->from('canvas', $canvasData)->to('salesforce');
 * $canvasFields = $mapper->from('salesforce', $sfPayload)->to('canvas');
 *
 * // Three-way merge
 * $record = $mapper->merge([
 *     'canvas'     => $canvasData,
 *     'salesforce' => $sfData,
 *     'sql'        => $sqlRow,
 * ]);
 * $record->for('salesforce'); // ['Full_Name__c' => '...', ...]
 * ```
 */
class ResourceMapper
{
    /** @var FieldMapping[] */
    private array $mappings;

    /**
     * @param  FieldMapping[]  $mappings
     */
    private function __construct(array $mappings)
    {
        $this->mappings = $mappings;
    }

    /**
     * Build a mapper from a plain array of field rows.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public static function define(array $rows): static
    {
        $mappings = array_map(function (array $row): FieldMapping {
            $priority   = $row['priority'] ?? [];
            $transforms = $row['transforms'] ?? [];
            $fields     = array_diff_key($row, array_flip(['priority', 'transforms']));

            return new FieldMapping($fields, $priority, $transforms);
        }, $rows);

        return new static(array_values($mappings));
    }

    /**
     * Build a mapper from a named template in config/canvas.php under the 'adapters' key.
     *
     * Requires the 'adapters' section to be defined in your published canvas config:
     *   config('canvas.adapters.user') → array of field rows
     *
     * @param  string  $key  The adapter name (e.g. 'user', 'course')
     *
     * @throws InvalidArgumentException if the key is not found in config
     */
    public static function fromConfig(string $key): static
    {
        $rows = config("canvas.adapters.{$key}");

        if (!is_array($rows)) {
            throw new InvalidArgumentException(
                "No adapter mapping found for '{$key}' in config('canvas.adapters'). " .
                "Add a '{$key}' entry to the 'adapters' section of config/canvas.php."
            );
        }

        return static::define($rows);
    }

    /**
     * Begin a two-way translation from the given source system.
     *
     * Chain ->to('targetSystem') on the returned object to complete the translation.
     *
     * @param  string  $system  The source system name (e.g. 'canvas', 'salesforce')
     * @param  array   $data    Key-value data in the source system's field names
     */
    public function from(string $system, array $data): PendingTranslation
    {
        return new PendingTranslation($this->mappings, $system, $data);
    }

    /**
     * Merge data from multiple systems into a single canonical record.
     *
     * Each logical field resolves independently using its own priority list (defined per row).
     * For fields without a per-field priority, the $priority parameter serves as the fallback.
     * If $priority is also empty, insertion order of $sources is used.
     *
     * @param  array<string, array>  $sources   System-keyed data: ['canvas' => [...], 'salesforce' => [...]]
     * @param  string[]              $priority  Global fallback priority (descending); first system wins on conflict
     */
    public function merge(array $sources, array $priority = []): MappedRecord
    {
        if (empty($priority)) {
            $priority = array_keys($sources);
        }

        return MappedRecord::resolve($this->mappings, $sources, $priority);
    }
}
