<?php
/**
 * Copyright (c) 2016 CTI Digital
 * Copyright (c) 2026 Magebit, Ltd.
 *
 * Licensed under the MIT License; see the LICENSE file in the project root.
 */

declare(strict_types=1);

namespace Magebit\Configurator\Model;

/**
 * The outcome of running a component (or an aggregate of several).
 *
 * Components record what they did; the Processor merges these into a run-level
 * result so commands can report a summary and return a meaningful exit code.
 */
class ComponentResult
{
    private int $created = 0;
    private int $updated = 0;
    private int $skipped = 0;

    /** @var string[] */
    private array $errors = [];

    public function recordCreated(int $count = 1): void
    {
        $this->created += $count;
    }

    public function recordUpdated(int $count = 1): void
    {
        $this->updated += $count;
    }

    public function recordSkipped(int $count = 1): void
    {
        $this->skipped += $count;
    }

    public function addError(string $message): void
    {
        $this->errors[] = $message;
    }

    public function getCreated(): int
    {
        return $this->created;
    }

    public function getUpdated(): int
    {
        return $this->updated;
    }

    public function getSkipped(): int
    {
        return $this->skipped;
    }

    /**
     * @return string[]
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    public function isSuccessful(): bool
    {
        return $this->errors === [];
    }

    /**
     * Fold another result into this one (used for run-level aggregation).
     */
    public function merge(self $other): void
    {
        $this->created += $other->created;
        $this->updated += $other->updated;
        $this->skipped += $other->skipped;
        $this->errors = array_merge($this->errors, $other->errors);
    }

    public function summary(): string
    {
        return sprintf(
            'created %d, updated %d, skipped %d, errors %d',
            $this->created,
            $this->updated,
            $this->skipped,
            count($this->errors)
        );
    }
}
