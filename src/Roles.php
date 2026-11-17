<?php

declare(strict_types=1);

namespace Technas\Accounts;

final class Roles
{
    /** @internal */
    public function __construct(private readonly TechnasAccounts $client)
    {
    }

    /** @return list<string> */
    public function list(): array
    {
        return $this->client->call('GET', '/v1/accounts/roles')['roles'] ?? [];
    }

    /**
     * Adds roles. Never removes one: that would take it away from every user who carries it.
     *
     * @param list<string> $roles
     * @return list<string>
     */
    public function declare(array $roles): array
    {
        return $this->client->call('PUT', '/v1/accounts/roles', ['roles' => array_values($roles)])['roles'] ?? [];
    }
}
