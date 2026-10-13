<?php

declare(strict_types=1);

namespace PulsePHP\Contracts;

interface StorageInterface
{
    /** @param array<string, list<array<string, mixed>>> $buffer */
    public function writeBatch(array $buffer): void;

    /**
     * Deletes telemetry older than the given number of days.
     *
     * @return array<string, int> Deleted rows per table.
     */
    public function prune(int $retentionDays, bool $vacuum = false): array;
}
