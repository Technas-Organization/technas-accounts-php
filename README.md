# Technas Accounts for PHP

Manage your app's users from your server: find or create the account behind a phone number or an
e-mail, check the access token a user presents, read users, give roles, disable an account.
Same secret as Technas Pay. [Lire en français](README.fr.md).

This is the **thin package for accounts only**. Want every Technas product in one install? Use
[`technas/technas`](https://github.com/Technas-Organization/technas-php), which bundles this package and the payment one behind one client.

## Install

```bash
composer config repositories.technas-accounts vcs https://github.com/Technas-Organization/technas-accounts-php
composer require technas/accounts:^0.1
```

No Composer dependency: PHP 8.1+, `ext-curl`, `ext-json`.

## Before you start

1. In the [console](https://console.technas.fr), open your app, then **Users › Sign-in**, and
   enable sign-in for the environment you want (sandbox first).
2. Copy your secret (**Developers › Keys**) into `TECHNAS_SECRET`. A `tps_test_…` secret reads
   and writes **sandbox users only**; a `tps_live_…` secret, production users only.

## Use

```php
use Technas\Accounts\TechnasAccounts;

$accounts = new TechnasAccounts();              // reads TECHNAS_SECRET (or TECHNAS_PAY_SECRET)

// A user wrote to your WhatsApp bot: you already know the number is theirs.
$user = $accounts->users->resolve(phone: '+22890123456', proof: 'verified');

// Your API receives "Authorization: Bearer <access token>" from your app.
$check = $accounts->tokens->verify($accessToken);
if (!$check['valid']) { http_response_code(401); exit; }   // see $check['reason']
echo $check['user_id'], ' ', $check['phone'];

$accounts->roles->declare(['admin', 'staff']);
$accounts->users->setRoles($user['id'], ['staff']);   // exactly these roles, at the next token
$page = $accounts->users->list(search: 'awa', pageSize: 25);
$accounts->users->disable($user['id']);               // signed out everywhere, nothing deleted
```

| Call | What it does |
|---|---|
| `users->resolve(phone \| email, proof)` | The account for this identifier: found, or created. A phone needs `proof: 'verified'` |
| `users->get($id)` / `users->list(search, page, pageSize)` | Read one user (with roles) / a page of users |
| `users->setRoles($id, $roles)` | The user's roles, exactly |
| `users->disable($id)` / `users->enable($id)` | Block or restore sign-in |
| `users->revokeSessions($id)` | Sign the user out everywhere |
| `roles->list()` / `roles->declare($roles)` | The roles your app knows (declaring never removes one) |
| `tokens->verify($token)` | Is this token one of your users', and still valid? Never throws for a bad token |
| `config()` | Issuer, client id and discovery URL to plug into your app (OpenID Connect, PKCE) |

## Errors

Every failure is a `TechnasException` with a stable `code`, the HTTP `status` and `retryable`.

| `code` | Meaning | What to do |
|---|---|---|
| `invalid_secret` | Secret missing or malformed (caught before any call) | Check `TECHNAS_SECRET` |
| `invalid_api_key` | The key was revoked or belongs to another environment | Create a key in the console |
| `accounts_not_enabled` | Sign-in is not enabled for this environment | Console › Users › Sign-in |
| `user_not_found` | No such user in this app and environment | Check the id and the secret's mode |
| `phone_proof_required` | A phone number is only stored once verified | Pass proof `verified` once you verified it |
| `unknown_role` | The role was never declared | Declare it first |
| `conflict` | Several accounts match, or the identifier belongs to another account | Search the users |
| `rate_limited`, `unavailable` | Too many calls, or a temporary failure | Retry later (`retryable` is true) |

## Tests

```bash
composer test
```

The tests run against a fake server that answers from `contract/accounts-contract.v1.json`, the
same file every Technas SDK is tested against.

MIT licence.
