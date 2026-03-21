<?php

namespace JarredCain\CanvasLms\Adapters;

/**
 * Internal value object representing one logical field and its equivalents across systems.
 *
 * A "logical field" is a single piece of information (e.g., a user's name) that may be
 * stored under different key names in different systems (Canvas, Salesforce, SQL, etc.).
 *
 * @internal Not part of the public API — constructed by ResourceMapper::define()
 */
readonly class FieldMapping
{
    /**
     * @param  array  $fields      System-to-field-name map, e.g. ['canvas' => 'name', 'salesforce' => 'Full_Name__c']
     * @param  array  $priority    Ordered system names; first system with a value wins in merge().
     *                             Empty array means "defer to the global priority passed to merge()".
     * @param  array  $transforms  Direction-keyed callables, e.g. ['to_salesforce' => fn($v) => strtoupper($v)].
     *                             Keys follow the convention: 'to_{system}' or 'from_{system}'.
     */
    public function __construct(
        public array $fields,
        public array $priority,
        public array $transforms,
    ) {
    }

    /**
     * Return the field name for the given system, or null if this mapping has no entry for it.
     */
    public function fieldFor(string $system): ?string
    {
        return $this->fields[$system] ?? null;
    }

    /**
     * Check whether a transform callable is defined for the given direction key.
     */
    public function hasTransform(string $key): bool
    {
        return isset($this->transforms[$key]);
    }

    /**
     * Apply the transform for the given direction key to a value and return the result.
     */
    public function applyTransform(string $key, mixed $value): mixed
    {
        return ($this->transforms[$key])($value);
    }
}
