<?php

// The SDK against a fake transport that answers from the shared contract. No dev dependency:
// `php tests/run.php` exits 1 on the first failure.

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Technas\\Accounts\\';
    if (str_starts_with($class, $prefix)) {
        require __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});

use Technas\Accounts\TechnasAccounts;
use Technas\Accounts\TechnasException;
use Technas\Accounts\Transport;

$contract = json_decode((string) file_get_contents(__DIR__ . '/../contract/accounts-contract.v1.json'), true, 64, JSON_THROW_ON_ERROR);
$routes = [];
foreach ($contract['routes'] as $route) {
    $routes[$route['method'] . ' ' . $route['path']] = $route;
}
const SECRET = 'tps_test_pk_demo12345678.whsec_demo';
$uid = $contract['user_id'];
$count = 0;

function check(bool $ok, string $what): void
{
    global $count;
    $count++;
    if (!$ok) {
        fwrite(STDERR, "FAILED: $what\n");
        exit(1);
    }
}

final class Fake implements Transport
{
    /** @var list<array{method: string, path: string, query: array<string,string>, headers: array<string,string>, body: ?string}> */
    public array $seen = [];

    /** @param array<string, array<string,mixed>> $routes @param array{status:int, body:mixed}|null $answer */
    public function __construct(private array $routes, private ?array $answer = null, private bool $down = false)
    {
    }

    public function send(string $method, string $url, array $headers, ?string $body): array
    {
        if ($this->down) {
            throw new TechnasException('unavailable', 'Technas could not be reached');
        }
        $path = (string) parse_url($url, PHP_URL_PATH);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->seen[] = ['method' => $method, 'path' => $path, 'query' => $query, 'headers' => $headers, 'body' => $body];
        if ($this->answer !== null) {
            return ['status' => $this->answer['status'], 'body' => json_encode($this->answer['body'])];
        }
        $route = $this->routes["$method $path"] ?? null;
        check($route !== null, "in the contract: $method $path");
        if (isset($route['request'])) {
            check(json_decode((string) $body, true) == $route['request'], $route['name'] . ' body');
        }
        if (isset($route['query'])) {
            check($query == $route['query'], $route['name'] . ' query');
        }
        return ['status' => $route['status'], 'body' => json_encode($route['response'])];
    }
}

function refused(callable $call, ?string $code = null): TechnasException
{
    try {
        $call();
    } catch (TechnasException $e) {
        check($code === null || $e->errorCode === $code, "expected $code, got {$e->errorCode}");
        return $e;
    }
    check(false, 'an exception was expected' . ($code ? " ($code)" : ''));
    exit(1);
}

// Every route of the contract is spoken.
$fake = new Fake($routes);
$t = new TechnasAccounts(SECRET, transport: $fake);
$config = $t->config();
check(str_ends_with($config['issuer'], '/realms/app-demo') && $config['methods'] === ['email', 'whatsapp'], 'config');
$token = $t->tokens->verify('eyJhbGciOiJSUzI1NiJ9.demo.signature');
check($token['valid'] === true && $token['user_id'] === $uid && $token['roles'] === ['admin'], 'tokens.verify');
$resolved = $t->users->resolve(phone: '+22890000002', proof: 'verified');
check($resolved['id'] === $uid && $resolved['created'] === true && $resolved['phone_verified'] === true, 'users.resolve');
$page = $t->users->list(search: 'awa', pageSize: 10);
check($page['total'] === 1 && $page['users'][0]['name'] === 'Awa Mensah', 'users.list');
$one = $t->users->get($uid);
check($one['roles'] === ['admin'] && $one['open_sessions'] === 1, 'users.get');
check($t->users->setRoles($uid, ['admin']) === ['admin'], 'users.setRoles');
$t->users->disable($uid);
check($t->users->revokeSessions($uid) === 2, 'users.revokeSessions');
check($t->roles->list() === ['admin', 'staff'] && $t->roles->declare(['staff']) === ['admin', 'staff'], 'roles');
$spoken = array_unique(array_map(static fn (array $r): string => $r['method'] . ' ' . $r['path'], $fake->seen));
sort($spoken);
$expected = array_keys($routes);
sort($expected);
check($spoken === $expected, 'a route of the contract has no SDK method (or the reverse)');

// Only the API key part of the secret travels, never in the URL; nothing prints the secret.
check($fake->seen[0]['headers']['Authorization'] === 'Bearer pk_demo12345678', 'bearer');
check(!str_contains(json_encode($fake->seen), 'whsec_demo'), 'webhook secret never sent');
check(!str_contains(print_r($t, true), 'pk_demo12345678') && $t->isTestMode(), 'debug output hides the secret');

// A malformed secret is refused before any call and never quoted.
putenv('TECHNAS_SECRET');
putenv('TECHNAS_PAY_SECRET');
$none = new Fake($routes);
$e = refused(static fn () => (new TechnasAccounts('not-a-secret-value', transport: $none))->roles->list(), 'invalid_secret');
check(!str_contains($e->getMessage(), 'not-a-secret-value') && $none->seen === [], 'secret not quoted, no call');
putenv('TECHNAS_PAY_SECRET=' . SECRET);
check((new TechnasAccounts(transport: new Fake($routes)))->isTestMode(), 'secret from the environment');
refused(static fn () => new TechnasAccounts(SECRET, 'http://example.com'), 'invalid_base_url');

// Errors carry a stable code.
foreach ($contract['errors'] as $error) {
    $e = refused(static fn () => (new TechnasAccounts(SECRET, transport: new Fake($routes, $error)))->users->get($uid), $error['body']['code']);
    check($e->status === $error['status'] && $e->isRetryable() === $error['retryable'], 'error ' . $error['body']['code']);
}

// Nothing leaves for a bad argument.
$quiet = new Fake($routes);
$t = new TechnasAccounts(SECRET, transport: $quiet);
refused(static fn () => $t->users->get('../balance'), 'invalid_request');
refused(static fn () => $t->users->resolve(), 'invalid_request');
refused(static fn () => $t->users->resolve(phone: '+22890000002', email: 'a@b.fr'), 'invalid_request');
refused(static fn () => $t->users->list(pageSize: 500), 'invalid_request');
check($t->tokens->verify('short') === ['valid' => false, 'reason' => 'malformed'] && $quiet->seen === [], 'no call for a bad argument');

// A rejected token is an answer, not an exception; an unreachable server is retryable.
$t = new TechnasAccounts(SECRET, transport: new Fake($routes, ['status' => 200, 'body' => ['valid' => false, 'reason' => 'expired']]));
check($t->tokens->verify(str_repeat('x', 40)) === ['valid' => false, 'reason' => 'expired'], 'rejected token');
$e = refused(static fn () => (new TechnasAccounts(SECRET, transport: new Fake($routes, null, true)))->roles->list(), 'unavailable');
check($e->isRetryable(), 'unreachable is retryable');

echo "OK ($count checks)\n";
