<?php

declare(strict_types=1);

namespace Technas\Accounts;

/**
 * An error returned by Technas, or caught before any call (`status` is then 0).
 * Test `errorCode`, not the message. The message never quotes the secret.
 */
final class TechnasException extends \RuntimeException
{
    /** @param array<int, array<string, string>> $errors */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 0,
        public readonly array $errors = [],
    ) {
        parent::__construct($message);
    }

    /** Does sending THE SAME call again make sense? */
    public function isRetryable(): bool
    {
        return in_array($this->errorCode, ['rate_limited', 'unavailable'], true) || $this->status >= 500;
    }
}
