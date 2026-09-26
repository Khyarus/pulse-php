<?php

declare(strict_types=1);

namespace PulsePHP\Storage;

final class CatalogEngine
{
    public static function normalizeSql(string $sql): string
    {
        $normalized = preg_replace(
            "/'(?:''|\\\\.|[^'\\\\])*'|\"(?:\"\"|\\\\.|[^\"\\\\])*\"|(?<![\\w.])[-+]?(?:\\d+(?:\\.\\d*)?|\\.\\d+)(?:[eE][-+]?\\d+)?(?![\\w.])/",
            '?',
            $sql
        );

        return preg_replace('/\s+/', ' ', trim($normalized ?? $sql));
    }

    public static function hashSql(string $normalizedSql): string
    {
        return md5($normalizedSql);
    }
}