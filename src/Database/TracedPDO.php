<?php

declare(strict_types=1);

namespace PulsePHP\Database;

use Closure;
use PDO;
use PDOStatement;
use PulsePHP\Pulse;

/**
 * Drop-in PDO replacement that records every query in Pulse.
 *
 *     $pdo = new TracedPDO('mysql:host=localhost;dbname=app', $user, $password);
 *
 * Only the SQL text (normalized, literals removed) and its duration are
 * stored — bound values never leave the application.
 */
class TracedPDO extends PDO
{
    private Closure $recorder;

    /** @param array<int, mixed>|null $options */
    public function __construct(
        string $dsn,
        ?string $username = null,
        #[\SensitiveParameter]
        ?string $password = null,
        ?array $options = null,
        ?Pulse $pulse = null
    ) {
        parent::__construct($dsn, $username, $password, $options);

        $this->recorder = static function (string $sql, float $durationMs) use ($pulse): void {
            $instance = $pulse ?? (Pulse::isInitialized() ? Pulse::getInstance() : null);
            $instance?->recordQuery($sql, $durationMs);
        };
        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [TracedStatement::class, [$this->recorder]]);
    }

    public function exec(string $statement): int|false
    {
        $startedAt = hrtime(true);

        try {
            return parent::exec($statement);
        } finally {
            ($this->recorder)($statement, (hrtime(true) - $startedAt) / 1_000_000);
        }
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $startedAt = hrtime(true);

        try {
            return $fetchMode === null
                ? parent::query($query)
                : parent::query($query, $fetchMode, ...$fetchModeArgs);
        } finally {
            ($this->recorder)($query, (hrtime(true) - $startedAt) / 1_000_000);
        }
    }
}
