<?php

namespace App\Services;

/**
 * What a run sends, when it is not the whole collection in file order
 * (decisions.md D-038). Chosen per run; nothing is saved on the collection.
 *
 * `items` are request ids in the order to send them: Postman CLI keeps the
 * order of repeated `-i <id>`, also across folders (verified in 1.71.0).
 */
final class RunOptions
{
    /**
     * @param  list<array{id: string, name: string}>  $items  empty = every request, in collection order
     * @param  bool  $keepVariables  save what scripts set with pm.environment.set() to the environment (D-039)
     */
    public function __construct(
        public readonly array $items = [],
        public readonly bool $bail = false,
        public readonly bool $keepVariables = false,
    ) {}

    /**
     * @param  array<string, mixed>|null  $stored  runs.options
     */
    public static function fromArray(?array $stored): self
    {
        $items = [];
        foreach ((array) ($stored['items'] ?? []) as $item) {
            if (is_array($item) && is_string($item['id'] ?? null)) {
                $items[] = ['id' => $item['id'], 'name' => (string) ($item['name'] ?? '')];
            }
        }

        return new self($items, (bool) ($stored['bail'] ?? false), (bool) ($stored['keep'] ?? false));
    }

    /**
     * The selection a full run would make anyway counts as no selection.
     *
     * @param  list<string>  $allRequestIds  collection order
     */
    public static function forSelection(array $items, array $allRequestIds, bool $bail, bool $keepVariables = false): self
    {
        $ids = array_column($items, 'id');

        return new self($ids === $allRequestIds ? [] : array_values($items), $bail, $keepVariables);
    }

    public function isDefault(): bool
    {
        return $this->items === [] && ! $this->bail && ! $this->keepVariables;
    }

    /** Whether the run sent something other than the whole collection, or bailed. */
    public function changesSequence(): bool
    {
        return $this->items !== [] || $this->bail;
    }

    /**
     * @return list<string>
     */
    public function itemIds(): array
    {
        return array_column($this->items, 'id');
    }

    /**
     * @return array{items: list<array{id: string, name: string}>, bail: bool, keep: bool}|null
     */
    public function toArray(): ?array
    {
        return $this->isDefault() ? null : ['items' => $this->items, 'bail' => $this->bail, 'keep' => $this->keepVariables];
    }
}
