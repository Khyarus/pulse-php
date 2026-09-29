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

    public function test_it_keeps_escaped_quotes_inside_string_literals(): void
    {
        self::assertSame(
            'SELECT * FROM t WHERE name = ?',
            CatalogEngine::normalizeSql("SELECT * FROM t WHERE name = 'O''Brien'")
        );

        self::assertSame(
            'SELECT * FROM t WHERE name = ?',
            CatalogEngine::normalizeSql('SELECT * FROM t WHERE name = \'O\\\'Brien\'')
        );

        self::assertSame(
            'SELECT * FROM t WHERE label = ?',
            CatalogEngine::normalizeSql('SELECT * FROM t WHERE label = "a \\" b"')
        );
    }

    public function test_it_does_not_replace_numbers_glued_to_identifiers(): void
    {
        // Column names ending in digits must survive untouched.
        self::assertSame(
            'SELECT col1, col2 FROM t2 WHERE id = ?',
            CatalogEngine::normalizeSql('SELECT col1, col2 FROM t2 WHERE id = 42')
        );

        // A digit qualified by a table alias (e.g. t.2) is part of an identifier.
        self::assertSame(
            'SELECT t.2 FROM t WHERE t.2 = ?',
            CatalogEngine::normalizeSql('SELECT t.2 FROM t WHERE t.2 = 42')
        );

        // Hexadecimal/version-like tokens glued to a word char are not literals.
        self::assertSame(
            'SELECT v1e2 FROM t',
            CatalogEngine::normalizeSql('SELECT v1e2 FROM t')
        );
    }

    public function test_it_normalizes_numeric_variants_consistently(): void
    {
        foreach (['42', '-42', '+42', '3.14', '.5', '4.', '1e10', '1.2E-3'] as $literal) {
            self::assertSame(
                'SELECT ? AS n',
                CatalogEngine::normalizeSql("SELECT {$literal} AS n"),
                "Literal {$literal} was not normalized"
            );
        }
    }
}
