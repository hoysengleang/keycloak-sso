# keycloak-sso

A small, reusable **Keycloak SSO login service for Laravel**. Drop it into any
project to authenticate users against Keycloak with the OpenID Connect
*Resource Owner Password* grant — token exchange, userinfo, refresh, and logout
— with clean, typed error handling.

Extracted from the PH AB API so every project shares one battle-tested SSO
implementation instead of copy-pasting the controller logic.

## Features

- One-call login: `KeycloakSso::login($username, $password)`.
- Typed exceptions that separate **wrong password** from **SSO is down** from
  **SSO is unreachable** — so your UI can show the right message and HTTP code.
- **Pluggable config resolver** — reads `.env` out of the box; swap in your own
  (e.g. a `system_configs` DB table) without touching the package.
- **Optional DB-password fallback hook** — when Keycloak rejects a credential,
  let the app try its own legacy `users` table.
- Structured logging to a configurable channel.
- Token `refresh()`, `logout()`, and standalone `userInfo()`.

## Requirements

- PHP `^8.2`
- Laravel 11 or 12

## Installation

```bash
composer require ph-ab/keycloak-sso
```

The service provider and `KeycloakSso` facade are auto-discovered. Publish the
config file if you want to edit it:

```bash
php artisan vendor:publish --tag=keycloak-sso-config
```

## Configuration

Set credentials in `.env`. The active connection is picked by
`app()->environment()`, falling back to the `default` connection.

```dotenv
# Simple single-environment setup
KEYCLOAK_BASE_URL=https://sso.example.com
KEYCLOAK_REALM=my-realm
KEYCLOAK_CLIENT_ID=my-client
KEYCLOAK_CLIENT_SECRET=super-secret

# Optional per-environment overrides (used when APP_ENV matches)
KEYCLOAK_BASE_URL_PROD=...
KEYCLOAK_REALM_PROD=...
KEYCLOAK_CLIENT_ID_PROD=...
KEYCLOAK_CLIENT_SECRET_PROD=...

KEYCLOAK_SCOPE="openid profile email"
KEYCLOAK_HTTP_TIMEOUT=10
KEYCLOAK_LOG_CHANNEL=auth
```

## Usage

```php
use PhAb\KeycloakSso\Facades\KeycloakSso;
use PhAb\KeycloakSso\Exceptions\InvalidCredentialsException;
use PhAb\KeycloakSso\Exceptions\SsoServiceException;
use PhAb\KeycloakSso\Exceptions\SsoConnectionException;

public function login(Request $request)
{
    $request->validate(['username' => 'required', 'password' => 'required']);

    try {
        $result = KeycloakSso::login($request->username, $request->password);

        return response()->json([
            'success' => true,
            'source'  => $result->source,   // "keycloak" or "fallback"
            'token'   => $result->token,    // raw Keycloak token payload (null on fallback)
            'user'    => $result->user,     // userinfo array (or your fallback's return value)
        ]);
    } catch (InvalidCredentialsException $e) {
        return response()->json(['success' => false, 'message' => $e->getMessage()], 401);
    } catch (SsoConnectionException $e) {
        return response()->json(['success' => false, 'message' => $e->getMessage()], 503);
    } catch (SsoServiceException $e) {
        return response()->json(['success' => false, 'message' => $e->getMessage()], 502);
    }
}
```

Other operations:

```php
$new  = KeycloakSso::refresh($refreshToken);   // array of new tokens
$info = KeycloakSso::userInfo($accessToken);   // userinfo array
KeycloakSso::logout($refreshToken);            // bool
```

## Optional: DB-password fallback

When Keycloak positively rejects a credential, the manager can ask your app to
verify it locally (handy while migrating users into Keycloak). Point the
`fallback` config at an invokable class:

```php
// config/keycloak-sso.php
'fallback' => \App\Auth\KeycloakDbFallback::class,
```

```php
namespace App\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

class KeycloakDbFallback
{
    /** Return a truthy value to accept the login, or null to reject. */
    public function __invoke(string $username, string $password): ?User
    {
        $user = User::where('username', $username)->orWhere('email', $username)->first();

        return $user && Hash::check($password, $user->password) ? $user : null;
    }
}
```

You can also set it at runtime: `KeycloakSso::fallbackUsing(fn ($u, $p) => ...)`.

## Load credentials from the database (or anywhere)

The package never touches your database schema. When you want credentials to
come from a DB table (or an API, a secrets manager, …), **you provide the
query and the package just calls it.** Pick whichever style you prefer.

> No migration, no table, no column config. Your schema stays entirely yours.

### Option A — a callback (quickest)

Set the resolver to `CallbackConnectionResolver` and write your query under
`credentials`. It receives the environment and returns the four values:

```php
// config/keycloak-sso.php
'resolver' => \PhAb\KeycloakSso\Resolvers\CallbackConnectionResolver::class,

'credentials' => function (string $environment) {
    $rows = \DB::table('system_configs')
        ->where('group_name', 'KEYCLOAK')
        ->where('environment', $environment)
        ->pluck('value', 'key_name');

    return [
        'base_url'      => $rows['KEYCLOAK_BASE_URL'],
        'realm'         => $rows['KEYCLOAK_REALM'],
        'client_id'     => $rows['KEYCLOAK_CLIENT_ID'],
        'client_secret' => $rows['KEYCLOAK_CLIENT_SECRET'],
    ];
},
```

That's the whole integration — `KeycloakSso::login()` now reads from your
table. The callback may also return a `ConnectionConfig` directly.

**Priority:** by default **`.env` wins** — the env-driven `connections` are
used first, and your `credentials` callback only fills in what `.env` is
missing. This is safe for gradual migration and local overrides. Leave
`credentials` `null` and `.env` is used exactly as before.

```php
// config/keycloak-sso.php
'resolver'    => \PhAb\KeycloakSso\Resolvers\CallbackConnectionResolver::class,
'credentials' => fn ($env) => [...],   // fills in only what .env lacks
// 'credentials' => null,              // → plain .env, callback disabled
```

**Want the callback (DB) to win instead?** Set `credentials_priority => true`.
Then your query is used first and `.env` becomes the fallback.

```php
'credentials_priority' => true, // callback wins; .env fills the gaps
```

Either way, the same resolver covers both sources — no need to switch
`resolver` back and forth.

If you use `php artisan config:cache`, point `credentials` at an **invokable
class name** instead of a closure (closures can't be cached):

```php
'credentials' => \App\Sso\KeycloakCredentials::class,
```

```php
namespace App\Sso;

use Illuminate\Support\Facades\DB;

class KeycloakCredentials
{
    public function __invoke(string $environment): array
    {
        $rows = DB::table('system_configs')
            ->where('group_name', 'KEYCLOAK')
            ->where('environment', $environment)
            ->pluck('value', 'key_name');

        return [
            'base_url'      => $rows['KEYCLOAK_BASE_URL'],
            'realm'         => $rows['KEYCLOAK_REALM'],
            'client_id'     => $rows['KEYCLOAK_CLIENT_ID'],
            'client_secret' => $rows['KEYCLOAK_CLIENT_SECRET'],
        ];
    }
}
```

### Option B — a full resolver class (most control)

For caching, decryption, fallbacks, or anything custom, implement
`ConfigResolver` yourself and point `resolver` at it:

```php
// config/keycloak-sso.php
'resolver' => \App\Auth\SystemConfigsResolver::class,
```

```php
namespace App\Auth;

use Illuminate\Support\Facades\DB;
use PhAb\KeycloakSso\Contracts\ConfigResolver;
use PhAb\KeycloakSso\Data\ConnectionConfig;

class SystemConfigsResolver implements ConfigResolver
{
    public function resolve(string $environment): ConnectionConfig
    {
        $rows = DB::table('system_configs')
            ->where('group_name', 'KEYCLOAK')
            ->where('environment', $environment)
            ->pluck('value', 'key_name');

        return ConnectionConfig::fromArray([
            'base_url'      => $rows['KEYCLOAK_BASE_URL'] ?? null,
            'realm'         => $rows['KEYCLOAK_REALM'] ?? null,
            'client_id'     => $rows['KEYCLOAK_CLIENT_ID'] ?? null,
            'client_secret' => $rows['KEYCLOAK_CLIENT_SECRET'] ?? null,
        ]);
    }
}
```

Both options are validated by `ConnectionConfig::fromArray()`, so a missing
value fails loudly instead of silently logging in against a broken config.

## Publishing to Packagist

1. Push this directory to its own public Git repository.
2. Tag a release: `git tag v1.0.0 && git push --tags`.
3. Submit the repo URL at <https://packagist.org/packages/submit> and enable the
   GitHub/Bitbucket hook so new tags auto-update.

> The package contains **no secrets** — all credentials come from the consuming
> app's `.env` or config — so it is safe to publish publicly.

## License

MIT
