<?php

namespace App\Services\Qa;

class QaTargetGuard
{
    /** @param array<int,string> $allowedHosts */
    public function __construct(private readonly array $allowedHosts) {}

    /** Returns the URL if its host is an allowed dev host; throws otherwise. */
    public function assertAllowed(string $url): string
    {
        if (str_contains($url, '\\')) {
            throw new \RuntimeException("QA target rejected — URL contains a backslash: {$url}");
        }
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new \RuntimeException("QA target rejected — scheme must be http/https: {$url}");
        }
        $host = parse_url($url, PHP_URL_HOST);
        if ($host === null || $host === false || $host === '') {
            throw new \RuntimeException("QA target rejected — unparseable URL: {$url}");
        }
        if (! in_array($host, $this->allowedHosts, true)) {
            throw new \RuntimeException("QA target rejected — host '{$host}' is not an allowed dev host. QA never runs against non-dev hosts.");
        }

        return $url;
    }
}
