<?php

declare(strict_types=1);

namespace PulsePHP\Contracts;

interface StorageInterface
{
    /** @param array<string, list<array<string, mixed>>> $buffer */
    public function writeBatch(array $buffer): void;
}