<?php

declare(strict_types=1);

namespace Technas\Accounts;

/**
 * Technas Accounts: your app's users, from PHP. Same secret as Technas Pay.
 *
 *     $accounts = new TechnasAccounts();                       // reads TECHNAS_SECRET
 *     $user = $accounts->users->resolve(phone: '+22890123456', proof: 'verified');
 *     $check = $accounts->tokens->verify($accessToken);
 *
 * A `tps_test_` secret reads the sandbox users, a `tps_live_` secret the production ones.
 */
final class TechnasAccounts
{
    public const DEFAULT_BASE_URL = 'https://pay-api.technas.fr';
    public const VERSION = '0.1.0';
    private const SECRET_ENVS = ['TECHNAS_SECRET', 'TECHNAS_PAY_SECRET'];

    public readonly Users $users;
    public readonly Roles $roles;
    public readonly Tokens $tokens;

    /** The secret lives in a closure: neither var_dump, nor a cast, nor a stack trace can print it. */
    private readonly \Closure $secretSource;
    private readonly string $baseUrl;
    private readonly Transport $transport;
    /** @var array{mode: string, apiKey: string}|null */
    private ?array $parsed = null;

    public function __construct(
        #[\SensitiveParameter] ?string $secret = null,
        string $baseUrl = self::DEFAULT_BASE_URL,
        ?Transport $transport = null,
    ) {
        $this->secretSource = static fn (): ?string => $secret;
        $this->baseUrl = rtrim($baseUrl, '/');
        // The key travels in a header: never to a clear-text address, except the local machine (tests).
        $scheme = strtolower((string) parse_url($this->baseUrl, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($this->baseUrl, PHP_URL_HOST));
        if ($scheme !== 'https' && !($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '::1'], true))) {
            throw new TechnasException('invalid_base_url', 'baseUrl must use https');
        }
        $this->transport = $transport ?? new CurlTransport();
        $this->users = new Users($this);
        $this->roles = new Roles($this);
        $this->tokens = new Tokens($this);
    }

    /** The client carries the secret: it does not serialize (cache, session, queue). */
    public function __serialize(): array
    {
        throw new \LogicException('TechnasAccounts cannot be serialized');
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['baseUrl' => $this->baseUrl];
    }

    /** True with a `tps_test_` secret: sandbox users only. */
    public function isTestMode(): bool
    {
        return $this->secret()['mode'] === 'test';
    }

    /**
     * What your app plugs in to sign users in (OpenID Connect, PKCE).
     *
     * @return array{issuer: string, client_id: string, discovery_url: string, authorization_endpoint: ?string, token_endpoint: ?string, jwks_uri: ?string, end_session_endpoint: ?string, pkce: ?string, methods: list<string>, redirect_uris: list<string>}
     */
    public function config(): array
    {
        return $this->call('GET', '/v1/accounts/config');
    }

    /** @return array{mode: string, apiKey: string} */
    private function secret(): array
    {
        if ($this->parsed !== null) {
            return $this->parsed;
        }
        $raw = ($this->secretSource)();
        if ($raw === null) {
            foreach (self::SECRET_ENVS as $name) {
                $value = getenv($name);
                if (is_string($value) && $value !== '') {
                    $raw = $value;
                    break;
                }
            }
        }
        // Never quote the value received: it would end up in logs.
        if (!is_string($raw) || preg_match('/^tps_(live|test)_([^.\s]+)\.([^.\s]+)$/', $raw, $m) !== 1) {
            throw new TechnasException('invalid_secret', 'Technas secret missing or malformed. Check the ' . self::SECRET_ENVS[0] . ' variable.');
        }
        return $this->parsed = ['mode' => $m[1], 'apiKey' => $m[2]];
    }

    /**
     * @internal
     * @param array<string, mixed>|null $body
     * @param array<string, string> $query
     * @return array<string, mixed>
     */
    public function call(string $method, string $path, ?array $body = null, array $query = []): array
    {
        $headers = ['Authorization' => 'Bearer ' . $this->secret()['apiKey'], 'User-Agent' => 'technas-accounts-php/' . self::VERSION];
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        $url = $this->baseUrl . $path . ($query === [] ? '' : '?' . http_build_query($query));
        $response = $this->transport->send($method, $url, $headers, $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR));
        $data = json_decode($response['body'], true);
        if ($response['status'] >= 400) {
            if (is_array($data) && isset($data['code'])) {
                throw new TechnasException((string) $data['code'], (string) ($data['message'] ?? ''), $response['status'], $data['errors'] ?? []);
            }
            throw new TechnasException($response['status'] >= 500 ? 'unavailable' : 'request_refused', 'HTTP ' . $response['status'], $response['status']);
        }
        return is_array($data) ? $data : [];
    }

    /** @internal An identifier placed in a URL path, refused before any call unless [A-Za-z0-9_-]{1,128}. */
    public static function segment(string $id): string
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,128}$/', $id) !== 1) {
            throw new TechnasException('invalid_request', 'Invalid identifier');
        }
        return $id;
    }
}
