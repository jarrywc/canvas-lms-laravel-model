<?php

namespace JarredCain\CanvasLms\Adapters;

/**
 * The result of a multi-system merge via ResourceMapper::merge().
 *
 * Holds a canonical representation of the merged data (indexed by mapping position)
 * and projects it into any system's field names on demand via for().
 *
 * Usage:
 *   $record = $mapper->merge([
 *       'canvas'      => $canvasData,
 *       'salesforce'  => $sfData,
 *       'sql'         => $sqlRow,
 *   ], priority: ['canvas', 'salesforce', 'sql']);
 *
 *   $record->for('canvas');      // ['name' => '...', ...]
 *   $record->for('salesforce');  // ['Full_Name__c' => '...', ...]
 */
class MappedRecord
{
    /**
     * Canonical store keyed by FieldMapping array index.
     *
     * The integer key corresponds to the position of the mapping in the definition array.
     * No canonical field name concept is exposed — each system uses its own names.
     *
     * @var array<int, mixed>
     */
    private array $canonical;

    /**
     * @param  FieldMapping[]  $mappings
     * @param  array<int, mixed>  $canonical
     */
    private function __construct(
        private readonly array $mappings,
        array $canonical,
    ) {
        $this->canonical = $canonical;
    }

    /**
     * Resolve multi-system sources into a canonical record using per-field priority.
     *
     * For each logical field:
     * - Use the field's own priority list if defined, otherwise fall back to $globalPriority.
     * - The first system in the effective priority list that has the field set wins.
     * - Uses array_key_exists (not isset) so an explicit null from a high-priority system
     *   correctly beats a non-null value from a lower-priority system.
     * - Applies 'from_{system}' transforms on ingest.
     *
     * @param  FieldMapping[]        $mappings
     * @param  array<string, array>  $sources        ['canvas' => [...], 'salesforce' => [...]]
     * @param  string[]              $globalPriority  Fallback priority for fields without their own
     */
    public static function resolve(array $mappings, array $sources, array $globalPriority): static
    {
        $canonical = [];

        foreach ($mappings as $index => $mapping) {
            $effectivePriority = !empty($mapping->priority) ? $mapping->priority : $globalPriority;

            foreach ($effectivePriority as $system) {
                if (!isset($sources[$system])) {
                    continue;
                }

                $field = $mapping->fieldFor($system);

                if ($field === null) {
                    continue;
                }

                if (array_key_exists($field, $sources[$system])) {
                    $value = $sources[$system][$field];

                    $transformKey = 'from_' . $system;
                    if ($mapping->hasTransform($transformKey)) {
                        $value = $mapping->applyTransform($transformKey, $value);
                    }

                    $canonical[$index] = $value;
                    break;
                }
            }
        }

        return new static($mappings, $canonical);
    }

    /**
     * Project the merged record into the given system's field names.
     *
     * Applies 'to_{system}' transforms where defined.
     * Fields not present in the canonical store (no source provided them) are omitted.
     *
     * @param  string  $system  e.g. 'canvas', 'salesforce', 'sql'
     * @return array<string, mixed>
     */
    public function for(string $system): array
    {
        $result = [];

        foreach ($this->mappings as $index => $mapping) {
            $field = $mapping->fieldFor($system);

            if ($field === null) {
                continue;
            }

            if (!array_key_exists($index, $this->canonical)) {
                continue;
            }

            $value = $this->canonical[$index];

            $transformKey = 'to_' . $system;
            if ($mapping->hasTransform($transformKey)) {
                $value = $mapping->applyTransform($transformKey, $value);
            }

            $result[$field] = $value;
        }

        return $result;
    }

    /**
     * Return the raw canonical store keyed by mapping index.
     * Useful for debugging or passing resolved values to further processing.
     *
     * @return array<int, mixed>
     */
    public function toArray(): array
    {
        return $this->canonical;
    }
}
