<?php

namespace JarredCain\CanvasLms\Adapters;

/**
 * Intermediate object returned by ResourceMapper::from().
 *
 * Holds the captured source system and data until to() is called to complete
 * the two-way translation into the target system's field names.
 *
 * Usage:
 *   $sfPayload = $mapper->from('canvas', $canvasUser->toArray())->to('salesforce');
 */
class PendingTranslation
{
    /**
     * @param  FieldMapping[]  $mappings
     * @param  string          $sourceSystem  e.g. 'canvas', 'salesforce'
     * @param  array           $sourceData    Key-value data in the source system's field names
     */
    public function __construct(
        private readonly array  $mappings,
        private readonly string $sourceSystem,
        private readonly array  $sourceData,
    ) {
    }

    /**
     * Translate the source data into the target system's field names.
     *
     * - Fields absent from $sourceData are silently skipped (partial payloads are safe).
     * - Fields not defined for either system in a mapping are skipped.
     * - Applies 'to_{targetSystem}' transforms where defined.
     *
     * @param  string  $targetSystem  e.g. 'salesforce', 'sql'
     * @return array<string, mixed>
     */
    public function to(string $targetSystem): array
    {
        $result = [];

        foreach ($this->mappings as $mapping) {
            $sourceField = $mapping->fieldFor($this->sourceSystem);
            $targetField = $mapping->fieldFor($targetSystem);

            if ($sourceField === null || $targetField === null) {
                continue;
            }

            if (!array_key_exists($sourceField, $this->sourceData)) {
                continue;
            }

            $value = $this->sourceData[$sourceField];

            $transformKey = 'to_' . $targetSystem;
            if ($mapping->hasTransform($transformKey)) {
                $value = $mapping->applyTransform($transformKey, $value);
            }

            $result[$targetField] = $value;
        }

        return $result;
    }
}
