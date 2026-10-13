<?php

declare(strict_types=1);

namespace PulsePHP\Alerts;

use InvalidArgumentException;
use RuntimeException;

/** Minimal JSON POST over PHP streams, so alerts need no HTTP client dependency. */
final class WebhookSender
{
    /** @param array<string, mixed> $payload */
    public static function post(string $url, array $payload, int $timeoutSeconds = 5): void
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('Alert webhooks must use http or https.');
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nUser-Agent: PulsePHP\r\n",
                'content' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'timeout' => max(1, $timeoutSeconds),
                'ignore_errors' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        // PHP 8.4+ exposes the headers through a function; the magic local
        // variable is deprecated in 8.5, so older versions read it indirectly.
        $headers = function_exists('http_get_last_response_headers')
            ? (http_get_last_response_headers() ?? [])
            : (get_defined_vars()['http_response_header'] ?? []);
        $statusLine = (string) ($headers[0] ?? '');
        if ($body === false || preg_match('#^HTTP/\S+\s+(\d{3})#', $statusLine, $matches) !== 1) {
            throw new RuntimeException('Alert webhook request failed.');
        }

        $status = (int) $matches[1];
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException(sprintf('Alert webhook responded with HTTP %d.', $status));
        }
    }
}
