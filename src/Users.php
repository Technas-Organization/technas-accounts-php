<?php

declare(strict_types=1);

namespace Technas\Accounts;

final class Users
{
    /** @internal */
    public function __construct(private readonly TechnasAccounts $client)
    {
    }

    /**
     * The account for this phone number or e-mail in your app: found, or created.
     * A phone number is only stored once verified: pass proof 'verified' when YOU verified it.
     *
     * @param 'verified'|'none' $proof
     * @return array{id: string, created: bool, phone: ?string, phone_verified: bool, email: ?string, email_verified: bool}
     */
    public function resolve(?string $phone = null, ?string $email = null, string $proof = 'none'): array
    {
        if (($phone === null) === ($email === null)) {
            throw new TechnasException('invalid_request', 'Give either phone or email, not both');
        }
        if (!in_array($proof, ['verified', 'none'], true)) {
            throw new TechnasException('invalid_request', 'proof is "verified" or "none"');
        }
        return $this->client->call('POST', '/v1/accounts/users/resolve', ['proof' => $proof] + ($phone !== null ? ['phone' => $phone] : ['email' => $email]));
    }

    /**
     * @return array{id: string, name: ?string, email: ?string, email_verified: bool, phone: ?string, phone_verified: bool, enabled: bool, created_at: ?string, methods: list<string>, roles: list<string>, open_sessions: int, last_activity_at: ?string, last_sign_in_at: ?string}
     */
    public function get(string $userId): array
    {
        return $this->client->call('GET', '/v1/accounts/users/' . TechnasAccounts::segment($userId));
    }

    /**
     * @return array{total: int, page: int, page_size: int, users: list<array<string, mixed>>}
     */
    public function list(?string $search = null, int $page = 0, int $pageSize = 25): array
    {
        if ($page < 0) {
            throw new TechnasException('invalid_request', 'page starts at 0');
        }
        if ($pageSize < 1 || $pageSize > 50) {
            throw new TechnasException('invalid_request', 'pageSize is between 1 and 50');
        }
        $query = ['page' => (string) $page, 'page_size' => (string) $pageSize];
        if ($search !== null && $search !== '') {
            $query['search'] = $search;
        }
        return $this->client->call('GET', '/v1/accounts/users', null, $query);
    }

    /**
     * This user's app roles, exactly: adds the missing ones, removes the others. Applies at the next token.
     *
     * @param list<string> $roles
     * @return list<string>
     */
    public function setRoles(string $userId, array $roles): array
    {
        return $this->client->call('PUT', '/v1/accounts/users/' . TechnasAccounts::segment($userId) . '/roles', ['roles' => array_values($roles)])['roles'] ?? [];
    }

    /** The user can no longer sign in and is signed out everywhere. Nothing is deleted. */
    public function disable(string $userId): void
    {
        $this->client->call('PUT', '/v1/accounts/users/' . TechnasAccounts::segment($userId) . '/status', ['enabled' => false]);
    }

    public function enable(string $userId): void
    {
        $this->client->call('PUT', '/v1/accounts/users/' . TechnasAccounts::segment($userId) . '/status', ['enabled' => true]);
    }

    /** Signs the user out everywhere. Returns how many sessions were closed. */
    public function revokeSessions(string $userId): int
    {
        return (int) ($this->client->call('POST', '/v1/accounts/users/' . TechnasAccounts::segment($userId) . '/sessions/revoke')['revoked'] ?? 0);
    }
}
