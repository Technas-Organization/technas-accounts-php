<?php

declare(strict_types=1);

namespace Technas\Accounts;

final class Tokens
{
    /** @internal */
    public function __construct(private readonly TechnasAccounts $client)
    {
    }

    /**
     * Is this access token one of YOUR users', and still valid? Never throws for a bad token.
     *
     * @return array{valid: bool, reason?: string, user_id?: string, client_id?: ?string, email?: ?string, email_verified?: bool, phone?: ?string, phone_verified?: bool, roles?: list<string>, expires_at?: ?int}
     */
    public function verify(#[\SensitiveParameter] string $token): array
    {
        // A token that cannot be one never leaves your server.
        if (strlen($token) < 20) {
            return ['valid' => false, 'reason' => 'malformed'];
        }
        return $this->client->call('POST', '/v1/accounts/tokens/verify', ['token' => $token]);
    }
}
