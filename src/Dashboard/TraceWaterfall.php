<?php

declare(strict_types=1);

namespace PulsePHP\Dashboard;

/**
 * Lays a trace out on a timeline: every span, query and outbound call becomes
 * a bar positioned by its offset from the start of the request.
 */
final class TraceWaterfall
{
    /**
     * @param array<string, mixed> $trace As returned by QueryEngine::getTrace().
     * @return array{total_ms: float, ticks: list<float>, rows: list<array{kind: string, label: string, offset_ms: float, duration_ms: float, left: float, width: float, estimated: bool}>}
     */
    public static function build(array $trace): array
    {
        $items = [];
        foreach ($trace['spans'] as $span) {
            if (str_starts_with((string) $span['name'], 'http.outbound:')) {
                continue; // Shown through the outbound call itself.
            }
            $items[] = ['kind' => 'span', 'label' => (string) $span['name'], 'offset' => $span['offset_ms'], 'duration' => (float) $span['duration_ms']];
        }
        foreach ($trace['queries'] as $query) {
            $items[] = ['kind' => 'query', 'label' => (string) $query['normalized_sql'], 'offset' => $query['offset_ms'], 'duration' => (float) $query['duration_ms']];
        }
        foreach ($trace['outbound'] as $call) {
            $items[] = ['kind' => 'http', 'label' => $call['method'] . ' ' . $call['url'], 'offset' => $call['offset_ms'], 'duration' => (float) $call['duration_ms']];
        }

        // Rows recorded before offsets existed are laid end to end.
        $cursor = 0.0;
        foreach ($items as &$item) {
            $item['estimated'] = $item['offset'] === null;
            $item['offset'] = $item['offset'] === null ? $cursor : (float) $item['offset'];
            $cursor = max($cursor, $item['offset'] + $item['duration']);
        }
        unset($item);
        usort($items, static fn (array $a, array $b): int => $a['offset'] <=> $b['offset']);

        $requestDuration = $trace['request'] !== null ? (float) $trace['request']['duration_ms'] : 0.0;
        $total = max($requestDuration, $cursor, 1.0);

        $rows = [];
        if ($trace['request'] !== null) {
            $rows[] = self::row('request', $trace['request']['method'] . ' ' . $trace['request']['url'], 0.0, $requestDuration, $total, false);
        }
        foreach ($items as $item) {
            $rows[] = self::row($item['kind'], $item['label'], $item['offset'], $item['duration'], $total, $item['estimated']);
        }

        return [
            'total_ms' => $total,
            'ticks' => [0.0, $total / 4, $total / 2, $total * 3 / 4, $total],
            'rows' => $rows,
        ];
    }

    /** @return array{kind: string, label: string, offset_ms: float, duration_ms: float, left: float, width: float, estimated: bool} */
    private static function row(string $kind, string $label, float $offset, float $duration, float $total, bool $estimated): array
    {
        $left = min(100.0, max(0.0, $offset / $total * 100));

        return [
            'kind' => $kind,
            'label' => $label,
            'offset_ms' => $offset,
            'duration_ms' => $duration,
            'left' => round($left, 3),
            'width' => round(min(100.0 - $left, max(0.0, $duration / $total * 100)), 3),
            'estimated' => $estimated,
        ];
    }
}
