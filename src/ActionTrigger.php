<?php

declare(strict_types=1);

namespace Mbolli\PhpVia;

/**
 * ActionTrigger represents a server-side action that can be triggered from the browser.
 */
class ActionTrigger {
    public function __construct(private string $id) {
        $this->id = $id;
    }

    /**
     * Get the action ID.
     */
    public function id(): string {
        return $this->id;
    }
}
