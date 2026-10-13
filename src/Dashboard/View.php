<?php

declare(strict_types=1);

namespace PulsePHP\Dashboard;

/**
 * Minimal PHP template renderer for the dashboard. Templates run with `$this`
 * bound to the view, so helpers are available as `$this->e()`, `$this->url()`…
 */
final class View
{
    private const JSON_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    /** @param array<string, mixed> $scope */
    public function __construct(private string $directory, private array $scope)
    {
    }

    /** @param array<string, mixed> $variables */
    public function render(string $template, array $variables = []): string
    {
        $path = $this->directory . '/' . $template . '.php';
        extract($variables, EXTR_SKIP);
        ob_start();

        try {
            require $path;
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }

    /** @return array<string, mixed> */
    public function scope(): array
    {
        return $this->scope;
    }

    public function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Relative URL that keeps the current period/service/route scope.
     *
     * @param array<string, scalar|null> $parameters
     */
    public function url(array $parameters = []): string
    {
        $query = array_filter([
            'view' => null,
            'period' => $this->scope['period'] ?? null,
            'service' => $this->scope['service'] ?? null,
            'route' => $this->scope['route'] ?? null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
        foreach ($parameters as $key => $value) {
            if ($value === null || $value === '') {
                unset($query[$key]);
            } else {
                $query[$key] = $value;
            }
        }
        if (($query['view'] ?? null) === 'overview') {
            unset($query['view']);
        }

        return '?' . http_build_query($query);
    }

    public function asset(string $name, string $version): string
    {
        return '?' . http_build_query(['asset' => $name, 'v' => $version]);
    }

    /** A UTC timestamp rendered as <time>; the browser rewrites it in local time. */
    public function time(?string $utc, string $style = 'short'): string
    {
        if ($utc === null || $utc === '') {
            return '<span class="muted">—</span>';
        }
        $iso = str_replace(' ', 'T', $utc) . 'Z';

        return '<time datetime="' . $this->e($iso) . '" data-local="' . $this->e($style) . '">'
            . $this->e(substr($utc, 0, 16)) . ' UTC</time>';
    }

    public function ms(float|int|string|null $value): string
    {
        $value = (float) $value;
        if ($value >= 1000) {
            return number_format($value / 1000, 2) . ' s';
        }

        return number_format($value, $value < 10 ? 1 : 0) . ' ms';
    }

    public function count(float|int|string|null $value): string
    {
        return number_format((float) $value);
    }

    public function bytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $value = (float) $bytes;
        $unit = 0;
        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return number_format($value, $unit === 0 ? 0 : 1) . ' ' . $units[$unit];
    }

    public function json(mixed $value): string
    {
        return json_encode($value, self::JSON_FLAGS);
    }

    public function httpStatus(int|string $status): string
    {
        $status = (int) $status;

        return '<span class="http" data-class="' . intdiv($status, 100) . '">'
            . ($status === 0 ? 'failed' : $status) . '</span>';
    }

    /** Status of an exception group as a labelled dot. */
    public function errorStatus(string $status): string
    {
        [$tone, $label] = match ($status) {
            'regressed' => ['crit', 'Regressed'],
            'resolved' => ['ok', 'Resolved'],
            'ignored' => ['', 'Ignored'],
            default => ['warn', 'Open'],
        };

        return '<span class="status" data-tone="' . $tone . '">' . $label . '</span>';
    }

    /** Short class name for display; the full name goes in the title attribute. */
    public function shortClass(string $class): string
    {
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }
}
