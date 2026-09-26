<?php

declare(strict_types=1);

namespace PulsePHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PulsePHP\Storage\CatalogEngine;

final class CatalogEngineTest extends TestCase
{
    public function test_it_normalizes_literals_and_whitespace(): void
    {
        $first = CatalogEngine::normalizeSql(
            " SELECT *  FROM users WHERE id = 42 AND email = 'first@example.test' "
        );
        $second = CatalogEngine::normalizeSql(
            "SELECT * FROM users WHERE id = 7 AND email = 'second@example.test'"
        );

        self::assertSame('SELECT * FROM users WHERE id = ? AND email = ?', $first);
        self::assertSame($first, $second);
    }

    public function test_it_generates_a_consistent_md5_hash(): void
    {
        $normalizedSql = 'SELECT * FROM users WHERE id = ?';

        self::assertSame(md5($normalizedSql), CatalogEngine::hashSql($normalizedSql));
        self::assertSame(
            CatalogEngine::hashSql($normalizedSql),
            CatalogEngine::hashSql($normalizedSql)
        );
    }
}