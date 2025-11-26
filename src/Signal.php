<?php

declare(strict_types=1);

namespace Mbolli\PhpVia;

/**
 * Signal represents a reactive value synchronized between server and browser.
 *
 * Signals are bound to HTML inputs and automatically synced when actions are triggered.
 */
class Signal {
    private string $id;
    private mixed $value;
    private bool $changed = true;

    public function __construct(string $id, mixed $initialValue) {
        $this->id = $id;
        $this->setValue($initialValue, true);
    }

    /**
     * Get the signal ID.
     */
    public function id(): string {
        return $this->id;
    }

    /**
     * Get the signal value.
     */
    public function getValue(): mixed {
        return $this->value;
    }

    /**
     * Set the signal value.
     */
    public function setValue(mixed $value, bool $markChanged = true): void {
        // Convert arrays/objects to JSON for complex types
        if (\is_array($value) || \is_object($value)) {
            $this->value = json_encode($value);
        } else {
            $this->value = $value;
        }

        if ($markChanged) {
            $this->changed = true;
        }
    }

    /**
     * Check if signal has changed.
     */
    public function hasChanged(): bool {
        return $this->changed;
    }

    /**
     * Mark signal as synced.
     */
    public function markSynced(): void {
        $this->changed = false;
    }

    /**
     * Get value as string.
     */
    public function string(): string {
        return (string) $this->value;
    }

    /**
     * Get value as integer.
     */
    public function int(): int {
        return (int) $this->value;
    }

    /**
     * Get value as float.
     */
    public function float(): float {
        return (float) $this->value;
    }

    /**
     * Get value as boolean.
     */
    public function bool(): bool {
        $val = mb_strtolower((string) $this->value);

        return \in_array($val, ['true', '1', 'yes', 'on'], true);
    }

    /**
     * Bind this signal to an HTML input element
     * Returns the data-bind attribute.
     */
    public function bind(): string {
        return 'data-bind="' . $this->id . '"';
    }

    /**
     * Display this signal's value as text in an HTML element
     * Returns a span element with the signal binding.
     */
    public function text(): string {
        return '<span data-text="$' . $this->id . '"></span>';
    }
}
