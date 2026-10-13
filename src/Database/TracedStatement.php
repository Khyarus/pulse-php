<?php

declare(strict_types=1);

namespace PulsePHP\Database;

use Closure;
use PDOStatement;

/** Statement class installed by {@see TracedPDO}; times each execute(). */
class TracedStatement extends PDOStatement
{
    /** PDO requires statement classes to have a non-public constructor. */
    protected function __construct(private Closure $recorder)
    {
    }

    public function execute(?array $params = null): bool
    {
        $startedAt = hrtime(true);

        try {
            return parent::execute($params);
        } finally {
            ($this->recorder)($this->queryString, (hrtime(true) - $startedAt) / 1_000_000);
        }
    }
}
