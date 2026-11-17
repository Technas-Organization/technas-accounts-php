<?php

declare(strict_types=1);

namespace Technas\Accounts;

/** Sends one HTTP request. Injectable: a fake transport serves the tests. */
interface Transport
{
    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string}
     * @throws TechnasException `unavailable` when Technas cannot be reached
     */
    public function send(string $method, string $url, array $headers, ?string $body): array;
}
