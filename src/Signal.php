<?php

declare(strict_types=1);

namespace Mbolli\PhpVia;

/**
 * Signal represents a reactive value synchronized between server and browser.
 *
 * Signals can be TAB-scoped (per-context) or shared across a scope.
 */
class Signal {
    private string $id;
    private mixed $value = null;
    private bool $changed = true;
    private ?string $scope = null;
    private bool $autoBroadcast = true;
    private bool $clientWritable = false;
    private ?Via $app = null;

    public function __construct(
        string $id,
        mixed $initialValue,
        ?string $scope = null,
        bool $autoBroadcast = true,
        bool $clientWritable = false,
        ?Via $app = null
    ) {
        $this->id = $id;
        $this->scope = $scope;
        $this->autoBroadcast = $autoBroadcast;
        $this->clientWritable = $clientWritable;
        $this->app = $app;
        $this->setValue($initialValue, false); // Don't trigger broadcast on init
        $this->changed = true; // But mark as changed for initial sync
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
     *
     * @param mixed $value       The new value to set
     * @param bool  $markChanged Whether to mark signal as changed for sync
     * @param bool  $broadcast   Whether to auto-broadcast (only applies if markChanged=true)
     */
    public function setValue(mixed $value, bool $markChanged = true, bool $broadcast = true): void {
        // Check if value actually changed
        $oldValue = $this->value;

        $this->value = $value;

        if ($markChanged) {
            $this->changed = true;

            // Auto-broadcast for scoped signals (if enabled, broadcast=true, and value changed)
            if ($broadcast
                && $this->isScoped()
                && $this->autoBroadcast
                && $this->app !== null
                && $oldValue !== $this->value) {
                $this->app->broadcast($this->scope);
            }
        }
    }

    /**
     * Check if this signal is scoped (non-TAB scope).
     */
    public function isScoped(): bool {
        return $this->scope !== null && $this->scope !== Scope::TAB;
    }

    /**
     * Get the signal's scope.
     */
    public function getScope(): ?string {
        return $this->scope;
    }

    /**
     * Whether this signal accepts values from the client.
     * TAB-scoped signals are always client-writable.
     * Scoped signals default to server-authoritative; opt in with clientWritable: true.
     */
    public function isClientWritable(): bool {
        return $this->clientWritable || !$this->isScoped();
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
        if (\is_array($this->value) || \is_object($this->value)) {
            return json_encode($this->value) ?: '';
        }

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
        if (\is_array($this->value) || \is_object($this->value)) {
            return !empty($this->value);
        }

        $val = mb_strtolower((string) $this->value);

        return \in_array($val, ['true', '1', 'yes', 'on'], true);
    }

    /**
     * Get value as array.
     *
     * @return array<mixed>
     */
    public function array(): array {
        return \is_array($this->value) ? $this->value : (array) $this->value;
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
